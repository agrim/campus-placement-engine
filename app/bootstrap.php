<?php

declare(strict_types=1);

define('CPE_ROOT', dirname(__DIR__));
define('CPE_DATA', CPE_ROOT . '/data');

if (PHP_SAPI !== 'cli') {
    @ini_set('display_errors', '0');
    @ini_set('display_startup_errors', '0');
    @ini_set('html_errors', '0');
    @ini_set('log_errors', '0');
}

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

function cpe_report_incident(
    Throwable $exception,
    string $diagnosticCode,
    string $sourceCategory,
    array $safeContext = [],
): string {
    try {
        return \App\Support\IncidentReporter::report(
            $exception,
            $diagnosticCode,
            $sourceCategory,
            $safeContext,
        );
    } catch (Throwable) {
        return 'inc_unavailable';
    }
}

function cpe_emit_opaque_web_failure(string $incidentId): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store, private');
    echo 'Request failed. Reference: ' . $incidentId . "\n";
}

function cpe_register_web_error_boundary(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        static $handling = false;
        if ((error_reporting() & $severity) === 0 || $handling) {
            return true;
        }
        $handling = true;
        try {
            if (in_array($severity, [E_USER_ERROR, E_RECOVERABLE_ERROR], true)) {
                throw new Error('Fatal runtime diagnostic');
            }
            cpe_report_incident(
                new Error('Runtime diagnostic'),
                'CPE_WEB_RUNTIME_DIAGNOSTIC',
                'bootstrap',
                ['phase' => 'error_handler'],
            );
            return true;
        } finally {
            $handling = false;
        }
    });
    set_exception_handler(static function (Throwable $exception): void {
        $GLOBALS['cpe_uncaught_exception_handled'] = true;
        $incidentId = cpe_report_incident(
            $exception,
            'CPE_WEB_UNCAUGHT_EXCEPTION',
            'bootstrap',
            ['phase' => 'uncaught'],
        );
        cpe_emit_opaque_web_failure($incidentId);
    });
    register_shutdown_function(static function (): void {
        if (($GLOBALS['cpe_uncaught_exception_handled'] ?? false) === true) {
            return;
        }
        $lastError = error_get_last();
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!is_array($lastError) || !in_array((int) ($lastError['type'] ?? 0), $fatalTypes, true)) {
            return;
        }
        $incidentId = cpe_report_incident(
            new Error('Fatal runtime error'),
            'CPE_WEB_FATAL_ERROR',
            'bootstrap',
            ['phase' => 'shutdown'],
        );
        cpe_emit_opaque_web_failure($incidentId);
    });
}

cpe_register_web_error_boundary();

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
        'Referrer-Policy' => defined('CPE_SETUP_HTTP_REQUEST') ? 'no-referrer' : 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
        'X-Permitted-Cross-Domain-Policies' => 'none',
        'Cache-Control' => 'no-store, private',
        'Pragma' => 'no-cache',
    ];
    if (defined('CPE_SETUP_HTTP_REQUEST')) {
        $headers['X-Robots-Tag'] = 'noindex, nofollow, noarchive';
    }
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

function cpe_start_setup_session(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        throw new RuntimeException(
            'Browser setup refuses a pre-started PHP session. Disable session.auto_start or run php placement install from the CLI.',
        );
    }
    $configuredDriver = getenv('CPE_SESSION_DRIVER');
    $driver = strtolower(trim(is_string($configuredDriver) ? $configuredDriver : 'files'));
    if ($driver === 'database') {
        throw new RuntimeException(
            'Browser setup cannot preserve the administrator login with CPE_SESSION_DRIVER=database before installation. Run php placement install from the CLI, then start the site.',
        );
    }
    if ($driver !== '' && $driver !== 'files') {
        throw new RuntimeException('CPE_SESSION_DRIVER must be files for browser setup. Run php placement install from the CLI.');
    }
    if (ini_set('session.save_handler', 'files') === false) {
        throw new RuntimeException('Browser setup could not select file-backed PHP sessions. Run php placement install from the CLI.');
    }

    $setupSecureMode = strtolower((string) (getenv('CPE_SESSION_SECURE') ?: 'auto'));
    $options = [
        'lifetime' => 0,
        'path' => '/',
        'secure' => \App\Security\SetupHttp::directHttpsDetected($_SERVER) || $setupSecureMode === 'force',
        'httponly' => true,
        'samesite' => 'Strict',
    ];
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.cookie_secure', $options['secure'] ? '1' : '0');
    session_cache_limiter('');
    $lifetime = max(\App\Security\SetupAuthorization::GRANT_TTL_SECONDS, min(86400, (int) (getenv('CPE_SESSION_LIFETIME') ?: 7200)));
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    session_name(cpe_config('security.session_name', 'cpe_session'));
    session_set_cookie_params($options);
    if (!session_start()) {
        throw new RuntimeException('Browser setup could not start a secure file-backed session. Run php placement install from the CLI.');
    }
}

function redirect(string $url, int $status = 302): never
{
    header('Location: ' . $url, true, $status);
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

function cpe_load_platform_bootstrap(): void
{
    $file = trim((string) (getenv('CPE_PLATFORM_BOOTSTRAP') ?: ''));
    if ($file === '') {
        return;
    }
    if (!str_starts_with($file, DIRECTORY_SEPARATOR) || !is_file($file) || !is_readable($file)) {
        throw new RuntimeException('CPE_PLATFORM_BOOTSTRAP must identify a readable absolute file.');
    }
    require_once $file;
}

function cpe_resolve_hosted_http_request(): void
{
    cpe_load_platform_bootstrap();
    \App\Hosted\HostedBootstrap::resolveHttpRequest();
}

cpe_send_security_headers();
if (!defined('CPE_SKIP_HTTP_BOOTSTRAP')) {
    try {
        cpe_resolve_hosted_http_request();
    } catch (\App\Hosted\Tenant\HostedResolutionException $e) {
        cpe_report_incident(
            $e,
            'CPE_HOSTED_RESOLUTION_FAILED',
            'bootstrap',
            ['status' => $e->httpStatus(), 'operation' => 'host_resolution'],
        );
        if (defined('CPE_SETUP_HTTP_REQUEST')) {
            if (!headers_sent()) {
                http_response_code(503);
                header('Content-Type: text/plain; charset=UTF-8');
            }
            echo "Hosted setup is unavailable.\n";
            exit;
        }
        if (!headers_sent()) {
            http_response_code($e->httpStatus());
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo $e->httpStatus() === 404 ? "Hosted site not found.\n" : "Hosted site temporarily unavailable.\n";
        exit;
    } catch (Throwable $e) {
        cpe_report_incident(
            $e,
            'CPE_HOSTED_BOOTSTRAP_FAILED',
            'bootstrap',
            ['operation' => 'host_bootstrap'],
        );
        if (defined('CPE_SETUP_HTTP_REQUEST')) {
            if (!headers_sent()) {
                http_response_code(503);
                header('Content-Type: text/plain; charset=UTF-8');
            }
            echo "Hosted setup is unavailable.\n";
            exit;
        }
        if (!headers_sent()) {
            http_response_code(503);
            header('Content-Type: text/plain; charset=UTF-8');
        }
        echo "Hosted site temporarily unavailable.\n";
        exit;
    }
    if (!defined('CPE_DEFER_HTTP_SESSION')) {
        cpe_start_session();
        \App\Hosted\HostedBootstrap::bindSession();
    }
}
