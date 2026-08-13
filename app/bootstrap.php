<?php

declare(strict_types=1);

define('CPE_ROOT', dirname(__DIR__));
define('CPE_DATA', CPE_ROOT . '/data');

spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = CPE_ROOT . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

if (!is_dir(CPE_DATA)) {
    mkdir(CPE_DATA, 0775, true);
}

function cpe_config(?string $key = null, mixed $default = null): mixed
{
    static $config = null;
    if ($config === null) {
        $config = require CPE_ROOT . '/config/defaults.php';
        $config['workflows'] = require CPE_ROOT . '/config/workflows.php';
        $config['modules'] = require CPE_ROOT . '/config/modules.php';
        $config['capabilities'] = require CPE_ROOT . '/config/capabilities.php';
    }
    if ($key === null) {
        return $config;
    }
    $value = $config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function cpe_path(string $path = ''): string
{
    return CPE_ROOT . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

function cpe_data_path(string $path = ''): string
{
    return CPE_DATA . ($path === '' ? '' : '/' . ltrim($path, '/'));
}

function cpe_setting(string $key, mixed $default = ''): string
{
    if (!\App\Support\Database::isInstalled()) {
        return (string) cpe_config('settings.' . $key, $default);
    }
    try {
        return \App\Core\Portal::context()->settings()->get($key, (string) $default);
    } catch (Throwable) {
        return (string) $default;
    }
}

function cpe_context(): \App\Core\ApplicationContext
{
    return \App\Core\Portal::context();
}

function cpe_term(string $key): string
{
    $defaults = [
        'candidate' => 'Candidate',
        'candidates' => 'Candidates',
        'company' => 'Company',
        'companies' => 'Companies',
    ];
    $default = $defaults[$key] ?? ucfirst($key);
    $value = trim(cpe_setting('terminology_' . $key . '_label', $default));
    return $value !== '' ? $value : $default;
}

function cpe_site_name(): string
{
    $default = (string) cpe_config('app.name', 'Campus Placement Engine');
    $value = trim(cpe_setting('site_name', $default));
    return $value !== '' ? $value : $default;
}

function cpe_site_tagline(): string
{
    return trim(cpe_setting('site_tagline', ''));
}

function cpe_public_placements_title(): string
{
    $value = trim(cpe_setting('public_placements_title', 'Public Placements'));
    return $value !== '' ? $value : 'Public Placements';
}

function cpe_candidate_status_title(): string
{
    $value = trim(cpe_setting('candidate_status_title', ''));
    return $value !== '' ? $value : cpe_term('candidate') . ' Status';
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cpe_now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function cpe_https_detected(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if (in_array($https, ['on', '1', 'true'], true)) {
        return true;
    }
    if ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    if (!filter_var(getenv('CPE_TRUST_PROXY_HEADERS') ?: '0', FILTER_VALIDATE_BOOL)) {
        return false;
    }
    $forwarded = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return $forwarded === 'https';
}

function cpe_session_secure_cookie(): bool
{
    $mode = strtolower((string) (getenv('CPE_SESSION_SECURE') ?: cpe_config('security.session_secure', 'auto')));
    return match ($mode) {
        '1', 'true', 'on', 'yes', 'force' => true,
        '0', 'false', 'off', 'no', 'never' => false,
        default => cpe_https_detected(),
    };
}

function cpe_session_cookie_options(): array
{
    $sameSite = (string) cpe_config('security.session_samesite', 'Lax');
    if (!in_array($sameSite, ['Lax', 'Strict', 'None'], true)) {
        $sameSite = 'Lax';
    }
    return [
        'lifetime' => 0,
        'path' => '/',
        'secure' => cpe_session_secure_cookie(),
        'httponly' => true,
        'samesite' => $sameSite,
    ];
}

function cpe_security_headers(): array
{
    $headers = [
        'Content-Security-Policy' => "default-src 'self'; base-uri 'self'; frame-ancestors 'self'; form-action 'self'; object-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'",
        'X-Frame-Options' => 'SAMEORIGIN',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        'X-Permitted-Cross-Domain-Policies' => 'none',
        'Cache-Control' => 'no-store, private',
        'Pragma' => 'no-cache',
    ];
    if (cpe_https_detected()) {
        $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
    }
    return $headers;
}

function cpe_send_security_headers(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    foreach (cpe_security_headers() as $name => $value) {
        header($name . ': ' . $value);
    }
}

function cpe_start_session(): void
{
    if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', (string) cpe_session_cookie_options()['samesite']);
    ini_set('session.cookie_secure', cpe_session_cookie_options()['secure'] ? '1' : '0');
    session_cache_limiter('');
    $lifetime = max(300, min(86400, (int) (getenv('CPE_SESSION_LIFETIME') ?: 7200)));
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    session_name(cpe_config('security.session_name', 'cpe_session'));
    session_set_cookie_params(cpe_session_cookie_options());
    $driver = strtolower(trim((string) (getenv('CPE_SESSION_DRIVER') ?: (\App\Hosted\HostedContext::isActive() ? 'database' : 'files'))));
    if ($driver === 'database' && \App\Support\Database::isInstalled()) {
        session_set_save_handler(new \App\Security\DatabaseSessionHandler(\App\Support\Database::connection(), $lifetime), true);
    } elseif ($driver !== 'files') {
        throw new RuntimeException('CPE_SESSION_DRIVER must be files or database.');
    }
    session_start();
}

function redirect(string $url): never
{
    header('Location: ' . $url, true, 302);
    exit;
}

function url(string $route = 'board', array $params = []): string
{
    $params = array_merge(['r' => $route], $params);
    return '/?' . http_build_query($params);
}

function view(string $template, array $data = []): void
{
    extract($data, EXTR_SKIP);
    $viewFile = cpe_path('app/Views/' . $template . '.php');
    if (!is_file($viewFile)) {
        throw new RuntimeException('View not found: ' . $template);
    }
    require $viewFile;
}

cpe_send_security_headers();
if (!defined('CPE_SKIP_HTTP_BOOTSTRAP')) {
    try {
        \App\Hosted\HostedBootstrap::resolveHttpRequest();
    } catch (\App\Hosted\Tenant\HostedResolutionException $e) {
        error_log('Hosted tenant resolution failed: ' . $e->getMessage());
        if (!headers_sent()) {
            http_response_code($e->httpStatus());
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo $e->httpStatus() === 404 ? "Hosted site not found.\n" : "Hosted site temporarily unavailable.\n";
        exit;
    }
    cpe_start_session();
    \App\Hosted\HostedBootstrap::bindSession();
}
