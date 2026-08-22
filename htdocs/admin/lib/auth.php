<?php
require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/identity_config.php';

use Mtmd\SingleAuth\Auth;
use Mtmd\SingleAuth\DbSessionHandler;

function identity_pdo(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', IDENTITY_DB_HOST, IDENTITY_DB_PORT, IDENTITY_DB_NAME);
        $pdo = new PDO($dsn, IDENTITY_DB_USER, IDENTITY_DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Required -- single-auth-mariadb enforces
            // require_secure_transport=ON for every connection, this
            // IONOS-to-VPS remote hop included. Options proven working in
            // vps-infra's marktuttlemd-migration plan, and already
            // applied to marktuttlemd's and mdproductivity's own
            // identity_pdo() in that plan's Task 4 / Task 8.
            PDO::MYSQL_ATTR_SSL_CA                 => IDENTITY_DB_SSL_CA,
            PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true,
        ]);
        session_set_save_handler(new DbSessionHandler($pdo), true);
    }
    return $pdo;
}

function auth(): Auth
{
    static $auth = null;
    if ($auth === null) {
        $auth = new Auth(identity_pdo(), [
            'cookie_domain' => IDENTITY_COOKIE_DOMAIN,
            // cookie_secure is derived from the same local/production split
            // as everything else in identity_config.php, rather than
            // sniffed from $_SERVER['HTTPS'] — on IONOS shared hosting TLS
            // is terminated upstream, so that header may never be set,
            // which would silently ship the shared SSO session cookie
            // without the Secure flag in production.
            'cookie_secure' => IDENTITY_COOKIE_SECURE,
        ]);
    }
    return $auth;
}

function start_session(): void
{
    auth()->sessionStart();
}

function current_user(): ?array
{
    return auth()->currentUser();
}

function require_login(): array
{
    return auth()->requireLogin('login.php');
}

function csrf_token(): string
{
    return auth()->csrfToken();
}

function csrf_field(): string
{
    return auth()->csrfField();
}

function csrf_check(): void
{
    auth()->csrfCheck();
}

function client_ip(): string
{
    return auth()->clientIp();
}

function login_throttled(): bool
{
    return auth()->loginThrottled();
}

function attempt_login(string $username, string $password): bool
{
    return auth()->attemptLogin($username, $password);
}

function logout(): void
{
    auth()->logout();
}
