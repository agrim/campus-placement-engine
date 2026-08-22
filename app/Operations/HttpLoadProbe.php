<?php

declare(strict_types=1);

namespace App\Operations;

use RuntimeException;

final class HttpLoadProbe
{
    public function run(
        string $baseUrl,
        string $path = '/?r=public',
        int $requests = 50,
        int $concurrency = 5,
        int $timeoutSeconds = 10,
    ): array {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $parts = parse_url($baseUrl);
        if (!filter_var($baseUrl, FILTER_VALIDATE_URL)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('Load probe requires a valid http(s) base URL without embedded credentials.');
        }
        if (!str_starts_with($path, '/') || str_contains($path, "\r") || str_contains($path, "\n")) {
            throw new RuntimeException('Load probe path must be an absolute HTTP path.');
        }
        $requests = max(1, min(5000, $requests));
        $concurrency = max(1, min(50, $concurrency, $requests));
        $timeoutSeconds = max(1, min(60, $timeoutSeconds));
        $url = $baseUrl . $path;
        $started = microtime(true);
        $samples = function_exists('curl_multi_init')
            ? $this->curlSamples($url, $requests, $concurrency, $timeoutSeconds)
            : $this->streamSamples($url, $requests, $timeoutSeconds);
        $elapsed = max(0.000001, microtime(true) - $started);
        $durations = array_map(fn (array $sample): float => (float) $sample['duration_ms'], $samples);
        sort($durations, SORT_NUMERIC);
        $successful = count(array_filter($samples, fn (array $sample): bool => $sample['status'] >= 200 && $sample['status'] < 400));
        $statusCounts = [];
        $errors = [];
        foreach ($samples as $sample) {
            $status = (string) $sample['status'];
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
            if ($sample['error'] !== '') {
                $errors[$sample['error']] = ($errors[$sample['error']] ?? 0) + 1;
            }
        }
        ksort($statusCounts, SORT_NATURAL);
        arsort($errors);
        return [
            'url' => $url,
            'transport' => function_exists('curl_multi_init') ? 'curl_multi' : 'streams',
            'requests' => count($samples),
            'concurrency' => function_exists('curl_multi_init') ? $concurrency : 1,
            'successful' => $successful,
            'failed' => count($samples) - $successful,
            'success_rate' => count($samples) > 0 ? $successful / count($samples) : 0.0,
            'elapsed_ms' => round($elapsed * 1000, 2),
            'requests_per_second' => round(count($samples) / $elapsed, 2),
            'p50_ms' => round($this->percentile($durations, 0.50), 2),
            'p95_ms' => round($this->percentile($durations, 0.95), 2),
            'max_ms' => round($durations === [] ? 0.0 : max($durations), 2),
            'status_counts' => $statusCounts,
            'errors' => array_slice($errors, 0, 10, true),
        ];
    }

    private function curlSamples(string $url, int $requests, int $concurrency, int $timeoutSeconds): array
    {
        $samples = [];
        for ($offset = 0; $offset < $requests; $offset += $concurrency) {
            $batchSize = min($concurrency, $requests - $offset);
            $multi = curl_multi_init();
            $handles = [];
            for ($i = 0; $i < $batchSize; $i++) {
                $handle = curl_init($url);
                curl_setopt_array($handle, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
                    CURLOPT_TIMEOUT => $timeoutSeconds,
                    CURLOPT_USERAGENT => 'CareerServicesPortal-LoadProbe/' . (string) cpe_config('app.version', '0.0.0'),
                    CURLOPT_HTTPHEADER => ['Accept: text/html,application/json;q=0.9,*/*;q=0.1'],
                ]);
                curl_multi_add_handle($multi, $handle);
                $handles[] = ['handle' => $handle, 'started' => microtime(true)];
            }
            do {
                $status = curl_multi_exec($multi, $active);
                if ($active) {
                    curl_multi_select($multi, 0.25);
                }
            } while ($active && $status === CURLM_OK);
            foreach ($handles as $entry) {
                $handle = $entry['handle'];
                $samples[] = [
                    'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                    'duration_ms' => (microtime(true) - $entry['started']) * 1000,
                    'error' => (string) curl_error($handle),
                ];
                curl_multi_remove_handle($multi, $handle);
            }
            curl_multi_close($multi);
        }
        return $samples;
    }

    private function streamSamples(string $url, int $requests, int $timeoutSeconds): array
    {
        $samples = [];
        for ($i = 0; $i < $requests; $i++) {
            $started = microtime(true);
            $headers = [];
            $context = stream_context_create(['http' => [
                'method' => 'GET',
                'header' => "Accept: text/html,application/json;q=0.9,*/*;q=0.1\r\nUser-Agent: CareerServicesPortal-LoadProbe/" . (string) cpe_config('app.version', '0.0.0'),
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
            ]]);
            $body = @file_get_contents($url, false, $context);
            $headers = $http_response_header ?? [];
            $status = 0;
            if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $matches)) {
                $status = (int) $matches[1];
            }
            $samples[] = [
                'status' => $status,
                'duration_ms' => (microtime(true) - $started) * 1000,
                'error' => $body === false ? 'HTTP stream request failed' : '',
            ];
        }
        return $samples;
    }

    private function percentile(array $sorted, float $percentile): float
    {
        if ($sorted === []) {
            return 0.0;
        }
        $index = max(0, (int) ceil(count($sorted) * $percentile) - 1);
        return (float) $sorted[$index];
    }
}
