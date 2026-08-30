<?php

declare(strict_types=1);

namespace App\Api\Http;

use App\Api\Operations\ApiRateLimiter;
use App\Api\Operations\ApiRequestAuditService;
use App\Api\Security\ApiAuthenticationUnavailable;
use App\Api\Security\ApiAuthorizationUnavailable;
use App\Api\Security\ApiKeyring;
use App\Api\Security\ApiPrincipal;
use App\Api\Security\ApiScopePolicy;
use App\Api\Security\ApiTokenAuthenticator;
use App\Api\Security\InvalidApiCredential;
use App\Core\Install\InstallationStateUnavailable;
use App\Hosted\Tenant\HostedResolutionException;
use App\Operations\RequestTelemetry;
use App\Support\Database;
use App\Support\IncidentReporter;
use PDO;
use Throwable;

/** Sessionless, read-only HTTP boundary for the version-one public API. */
final class ApiV1Kernel
{
    private const AUTHENTICATE_HEADER = 'Bearer realm="Campus Placement Engine API"';

    /** @param array<string, mixed> $server */
    public static function handle(array $server): void
    {
        $requestId = self::requestId();
        $telemetryRoute = self::telemetryRoute(self::rawPath($server));
        $method = is_string($server['REQUEST_METHOD'] ?? null)
            ? strtoupper((string) $server['REQUEST_METHOD'])
            : 'INVALID';
        RequestTelemetry::start($telemetryRoute, $method, $requestId);

        $request = null;
        $route = null;
        $principal = null;
        $keyring = null;
        $pdo = null;
        $source = ApiHttpRequest::sourceFromServer($server);
        try {
            try {
                if (!Database::hasInstalledMarkerStrict() || Database::pendingMigrations() !== []) {
                    throw new ApiStorageUnavailable();
                }
                $pdo = Database::connection();
            } catch (ApiStorageUnavailable $failure) {
                throw $failure;
            } catch (InstallationStateUnavailable $failure) {
                throw new ApiStorageUnavailable('API installation state is unavailable.', $failure);
            } catch (Throwable $failure) {
                throw new ApiStorageUnavailable('API storage is unavailable.', $failure);
            }

            if (!self::apiEnabled($pdo)) {
                try {
                    self::classifyRequest($server, $requestId, $request, $route);
                } catch (ApiHttpException $failure) {
                    self::sendClassifiedFailure($failure, $requestId, self::isHead($server));
                    return;
                }
                self::invalidCredentials($requestId, self::isHead($server));
                return;
            }

            try {
                $keyring = ApiKeyring::fromEnvironment();
            } catch (Throwable $failure) {
                throw new ApiAuthenticationUnavailable('The external API keyring is unavailable.', 0, $failure);
            }

            try {
                $preAuthRate = (new ApiRateLimiter($pdo, $keyring))->consumePreAuth($source, $requestId);
            } catch (Throwable $failure) {
                throw new ApiStorageUnavailable('API pre-authentication rate-limit state is unavailable.', $failure);
            }
            if (!$preAuthRate['allowed']) {
                ApiHttpResponse::error(
                    429,
                    'rate_limit_exceeded',
                    'The API rate limit has been exceeded.',
                    $requestId,
                    self::isHead($server),
                    ['Retry-After' => (string) $preAuthRate['retry_after_seconds']],
                );
                return;
            }

            $parameters = self::classifyRequest($server, $requestId, $request, $route);

            try {
                $principal = (new ApiTokenAuthenticator($pdo, $keyring))->authenticate($request->bearerToken());
            } catch (InvalidApiCredential $failure) {
                throw new ApiHttpException(
                    401,
                    'invalid_credentials',
                    'Valid API credentials are required.',
                    'CREDENTIALS_INVALID',
                    ['WWW-Authenticate' => self::AUTHENTICATE_HEADER],
                );
            }

            $requiredScope = (string) ($route['scope'] ?? '');
            if ($requiredScope !== '' && !(new ApiScopePolicy($pdo))->allows($principal, $requiredScope)) {
                throw new ApiHttpException(
                    403,
                    'insufficient_scope',
                    'The API credential lacks the required scope.',
                    'SCOPE_DENIED',
                );
            }

            try {
                $rate = (new ApiRateLimiter($pdo, $keyring))->consume(
                    $principal,
                    (string) $route['audit_route'],
                    $request->source(),
                );
            } catch (Throwable $failure) {
                throw new ApiStorageUnavailable('API rate-limit state is unavailable.', $failure);
            }
            if (!$rate['allowed']) {
                throw new ApiHttpException(
                    429,
                    'rate_limit_exceeded',
                    'The API rate limit has been exceeded.',
                    'RATE_LIMITED_' . strtoupper($rate['limited_dimension']),
                    ['Retry-After' => (string) $rate['retry_after_seconds']],
                );
            }

            $read = new ApiReadService($pdo, $keyring);
            $status = 200;
            $responseHeaders = [];
            if ($route['kind'] === 'root') {
                $payload = ['data' => $read->service(), 'meta' => ['request_id' => $requestId]];
            } elseif ($route['kind'] === 'collection') {
                $result = $read->collection(
                    (string) $route['resource'],
                    (string) $route['audit_route'],
                    (int) $parameters['limit'],
                    $parameters['updated_after'],
                    $parameters['cursor'],
                );
                $payload = [
                    'data' => $result['data'],
                    'page' => $result['page'],
                    'meta' => ['request_id' => $requestId],
                ];
            } else {
                $item = $read->item((string) $route['resource'], (string) $route['id']);
                if ($item === null) {
                    throw new ApiHttpException(
                        404,
                        'not_found',
                        'The requested API resource was not found.',
                        'RESOURCE_NOT_FOUND',
                    );
                }
                $etag = self::etag($item);
                $responseHeaders['ETag'] = $etag;
                if (self::etagMatches($request->ifNoneMatch(), $etag)) {
                    $status = 304;
                    $payload = [];
                } else {
                    $payload = ['data' => $item, 'meta' => ['request_id' => $requestId]];
                }
            }

            if (!self::audit(
                $pdo,
                $keyring,
                $principal,
                (string) $route['audit_route'],
                (string) ($route['scope'] ?? ''),
                'succeeded',
                $status,
                $status === 304 ? 'NOT_MODIFIED' : 'OK',
                $request->source(),
                $requestId,
            )) {
                self::unavailable($requestId, $request->isHead());
                return;
            }
            if ($status === 304) {
                ApiHttpResponse::notModified($requestId, $responseHeaders);
                return;
            }
            ApiHttpResponse::send($status, $payload, $requestId, $request->isHead(), $responseHeaders);
        } catch (ApiHttpException $failure) {
            self::classifiedFailure(
                $failure,
                $requestId,
                $request,
                $route,
                $principal,
                $keyring,
                $pdo,
                $source,
                self::isHead($server),
            );
        } catch (ApiAuthenticationUnavailable|ApiAuthorizationUnavailable|ApiStorageUnavailable $failure) {
            self::unavailable($requestId, $request?->isHead() ?? self::isHead($server));
        } catch (Throwable $failure) {
            $incidentId = IncidentReporter::report(
                $failure,
                'CPE_API_REQUEST_FAILED',
                'web',
                [
                    'route' => $telemetryRoute,
                    'method' => $method,
                    'operation' => 'api.request',
                    'api_request_id' => $requestId,
                ],
            );
            ApiHttpResponse::error(
                500,
                'internal_error',
                'The API request could not be completed.',
                $requestId,
                $request?->isHead() ?? self::isHead($server),
                [],
                $incidentId,
            );
        }
    }

    /** @param array<string, mixed> $server */
    public static function bootstrapFailure(Throwable $failure, array $server): void
    {
        $requestId = self::requestId();
        $method = is_string($server['REQUEST_METHOD'] ?? null)
            ? strtoupper((string) $server['REQUEST_METHOD'])
            : 'INVALID';
        RequestTelemetry::start(self::telemetryRoute(self::rawPath($server)), $method, $requestId);
        $head = self::isHead($server);
        if ($failure instanceof HostedResolutionException) {
            $status = $failure->httpStatus();
            if ($status === 404) {
                ApiHttpResponse::error(404, 'not_found', 'The requested API resource was not found.', $requestId, $head);
                return;
            }
            if ($status === 400) {
                ApiHttpResponse::error(400, 'invalid_request', 'The API request is invalid.', $requestId, $head);
                return;
            }
            self::unavailable($requestId, $head);
            return;
        }
        $incidentId = IncidentReporter::report(
            $failure,
            'CPE_API_BOOTSTRAP_FAILED',
            'bootstrap',
            ['method' => $method, 'operation' => 'api.request', 'api_request_id' => $requestId],
        );
        ApiHttpResponse::error(
            500,
            'internal_error',
            'The API request could not be completed.',
            $requestId,
            $head,
            [],
            $incidentId,
        );
    }

    /**
     * Pure bounded request classification shared by disabled and enabled API paths.
     *
     * @param array<string, mixed> $server
     * @param-out ApiHttpRequest|null $request
     * @param-out array<string, mixed>|null $route
     * @return array{limit: int, updated_after: ?string, cursor: ?string}
     */
    private static function classifyRequest(
        array $server,
        string $requestId,
        ?ApiHttpRequest &$request,
        ?array &$route,
    ): array {
        $request = null;
        $route = null;
        $request = ApiHttpRequest::fromServer($server, $requestId);
        $route = self::route($request->path());
        if ($route === null) {
            throw new ApiHttpException(404, 'not_found', 'The requested API resource was not found.', 'ROUTE_NOT_FOUND');
        }
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            throw new ApiHttpException(
                405,
                'method_not_allowed',
                'Only GET and HEAD are supported for this API resource.',
                'METHOD_NOT_ALLOWED',
                ['Allow' => 'GET, HEAD'],
            );
        }
        $request->assertNoBody();
        return self::parameters($request, $route);
    }

    /**
     * @param array<string, mixed> $route
     * @return array{limit: int, updated_after: ?string, cursor: ?string}
     */
    private static function parameters(ApiHttpRequest $request, array $route): array
    {
        if ($route['kind'] !== 'collection') {
            $request->queryParameters([]);
            return ['limit' => 50, 'updated_after' => null, 'cursor' => null];
        }
        $query = $request->queryParameters(['updated_after', 'limit', 'cursor']);
        $limit = 50;
        if (array_key_exists('limit', $query)) {
            if (preg_match('/\A(?:[1-9]|[1-9][0-9]|100)\z/D', $query['limit']) !== 1) {
                throw new ApiHttpException(400, 'invalid_limit', 'limit must be between 1 and 100.', 'LIMIT_INVALID');
            }
            $limit = (int) $query['limit'];
        }
        $cursor = array_key_exists('cursor', $query) ? $query['cursor'] : null;
        if ($cursor !== null && array_key_exists('updated_after', $query)) {
            throw new ApiHttpException(
                400,
                'incompatible_query',
                'cursor cannot be combined with updated_after.',
                'QUERY_INCOMPATIBLE',
            );
        }
        $updatedAfter = array_key_exists('updated_after', $query)
            ? ApiReadService::normalizeUpdatedAfter($query['updated_after'])
            : null;
        return ['limit' => $limit, 'updated_after' => $updatedAfter, 'cursor' => $cursor];
    }

    /**
     * @return null|array{kind: string, resource: string, scope: string, audit_route: string, id?: string}
     */
    private static function route(string $path): ?array
    {
        if ($path === '/api/v1') {
            return ['kind' => 'root', 'resource' => '', 'scope' => '', 'audit_route' => 'api.v1.root'];
        }
        foreach (['opportunities', 'applications'] as $resource) {
            $scope = $resource . '.read';
            if ($path === '/api/v1/' . $resource) {
                return [
                    'kind' => 'collection',
                    'resource' => $resource,
                    'scope' => $scope,
                    'audit_route' => 'api.v1.' . $resource . '.list',
                ];
            }
            $prefix = '/api/v1/' . $resource . '/';
            if (str_starts_with($path, $prefix)
                && strlen($path) > strlen($prefix)
                && !str_contains(substr($path, strlen($prefix)), '/')) {
                return [
                    'kind' => 'item',
                    'resource' => $resource,
                    'scope' => $scope,
                    'audit_route' => 'api.v1.' . $resource . '.item',
                    'id' => substr($path, strlen($prefix)),
                ];
            }
        }
        return null;
    }

    /**
     * @param null|array<string, mixed> $route
     */
    private static function classifiedFailure(
        ApiHttpException $failure,
        string $requestId,
        ?ApiHttpRequest $request,
        ?array $route,
        ?ApiPrincipal $principal,
        ?ApiKeyring $keyring,
        ?PDO $pdo,
        string $source,
        bool $serverHead,
    ): void {
        $head = $request?->isHead() ?? $serverHead;
        $auditRoute = is_array($route) ? (string) $route['audit_route'] : 'api.v1.unknown';
        $scope = is_array($route) ? (string) ($route['scope'] ?? '') : '';
        $outcome = $failure->status() === 429 ? 'rate_limited' : 'denied';
        if ($pdo === null) {
            try {
                if (Database::hasInstalledMarkerStrict() && Database::pendingMigrations() === []) {
                    $pdo = Database::connection();
                }
            } catch (Throwable) {
                $pdo = null;
            }
        }
        if ($pdo === null || !self::audit(
            $pdo,
            $keyring,
            $principal,
            $auditRoute,
            $scope,
            $outcome,
            $failure->status(),
            $failure->auditDetailCode(),
            $source,
            $requestId,
        )) {
            self::unavailable($requestId, $head);
            return;
        }
        self::sendClassifiedFailure($failure, $requestId, $head);
    }

    private static function sendClassifiedFailure(
        ApiHttpException $failure,
        string $requestId,
        bool $head,
    ): void {
        ApiHttpResponse::error(
            $failure->status(),
            $failure->publicCode(),
            $failure->publicMessage(),
            $requestId,
            $head,
            $failure->headers(),
        );
    }

    private static function audit(
        PDO $pdo,
        ?ApiKeyring $keyring,
        ?ApiPrincipal $principal,
        string $route,
        string $scope,
        string $outcome,
        int $status,
        string $detail,
        string $source,
        string $requestId,
    ): bool {
        try {
            (new ApiRequestAuditService($pdo, $keyring))->record(
                $principal,
                $route,
                $scope,
                $outcome,
                $status,
                $detail,
                $source,
                $requestId,
            );
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function unavailable(string $requestId, bool $head): void
    {
        ApiHttpResponse::error(
            503,
            'service_unavailable',
            'The API is temporarily unavailable.',
            $requestId,
            $head,
            ['Retry-After' => '60'],
        );
    }

    private static function invalidCredentials(string $requestId, bool $head): void
    {
        ApiHttpResponse::error(
            401,
            'invalid_credentials',
            'Valid API credentials are required.',
            $requestId,
            $head,
            ['WWW-Authenticate' => self::AUTHENTICATE_HEADER],
        );
    }

    private static function apiEnabled(PDO $pdo): bool
    {
        try {
            $values = $pdo->query("SELECT value FROM settings WHERE key = 'api_enabled'")->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $failure) {
            throw new ApiStorageUnavailable('API availability state is unavailable.', $failure);
        }
        if (count($values) !== 1 || !in_array($values[0], ['0', '1'], true)) {
            throw new ApiStorageUnavailable('API availability state is unavailable.');
        }
        return $values[0] === '1';
    }

    /** @param array<string, mixed> $data */
    private static function etag(array $data): string
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return '"' . hash('sha256', (string) $encoded) . '"';
    }

    private static function etagMatches(string $header, string $etag): bool
    {
        if (trim($header) === '*') {
            return true;
        }
        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if (str_starts_with($candidate, 'W/')) {
                $candidate = substr($candidate, 2);
            }
            if ($candidate !== '' && hash_equals($etag, $candidate)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $server */
    private static function rawPath(array $server): string
    {
        $uri = $server['REQUEST_URI'] ?? '/api';
        if (!is_string($uri) || strlen($uri) > 4096) {
            return '/api';
        }
        return explode('?', $uri, 2)[0];
    }

    private static function telemetryRoute(string $path): string
    {
        if ($path === '/api/v1') {
            return 'api-v1-root';
        }
        foreach (['opportunities', 'applications'] as $resource) {
            if ($path === '/api/v1/' . $resource) {
                return 'api-v1-' . $resource . '-list';
            }
            if (str_starts_with($path, '/api/v1/' . $resource . '/')) {
                return 'api-v1-' . $resource . '-item';
            }
        }
        return 'api-v1-unknown';
    }

    /** @param array<string, mixed> $server */
    private static function isHead(array $server): bool
    {
        return is_string($server['REQUEST_METHOD'] ?? null)
            && strtoupper((string) $server['REQUEST_METHOD']) === 'HEAD';
    }

    private static function requestId(): string
    {
        try {
            return 'req_' . bin2hex(random_bytes(16));
        } catch (Throwable) {
            return 'req_' . str_repeat('0', 32);
        }
    }
}
