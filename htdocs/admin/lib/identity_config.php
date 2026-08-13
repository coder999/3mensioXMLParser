<?php
/**
 * Identity (single-auth) configuration — this project has no other app
 * database. Local vs. production is decided by a local.marker file
 * (present in git, excluded from the deploy rsync).
 *
 * IDENTITY_COOKIE_DOMAIN is deliberately the same '.marktuttlemd.com'
 * used by marktuttlemd/mdproductivity: 3mensio.marktuttlemd.com is a
 * subdomain of marktuttlemd.com, so this project rides the same shared
 * session cookie — a user already logged in at marktuttlemd.com/admin is
 * already authenticated here too.
 */

$isLocal = is_file(__DIR__ . '/../local.marker');

if ($isLocal) {
    define('IDENTITY_DB_HOST', getenv('IDENTITY_DB_HOST') ?: 'mariadb');
    define('IDENTITY_DB_PORT', (int)(getenv('IDENTITY_DB_PORT') ?: 3306));
    define('IDENTITY_DB_NAME', getenv('IDENTITY_DB_NAME') ?: 'single_auth');
    define('IDENTITY_DB_USER', getenv('IDENTITY_DB_USER') ?: 'identity_auth');
    define('IDENTITY_DB_PASS', getenv('IDENTITY_DB_PASS') ?: 'ChangeThisIdentityAuthPassword');
    // Local dev is reachable on both *.nexus.local and *.odroid.local —
    // derive the cookie domain from the actual request instead of
    // hardcoding one, or the cookie is rejected by the browser on
    // whichever hostname isn't hardcoded.
    $requestHost = strtolower(explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''), 2)[0]);
    define('IDENTITY_COOKIE_DOMAIN', str_ends_with($requestHost, '.odroid.local') ? '.odroid.local' : '.nexus.local');
    define('IDENTITY_COOKIE_SECURE', !$isLocal);
} else {
    // dirname(__DIR__) is htdocs/admin/lib -> htdocs/admin -> htdocs ->
    // project root. Deliberately __DIR__-based, not
    // $_SERVER['DOCUMENT_ROOT'] — this IONOS account's PHP CLI SAPI
    // doesn't leave DOCUMENT_ROOT unset, it resolves to an empty/root
    // value, so a CLI run here would silently produce a bogus,
    // prefix-less secrets path. __DIR__ is this file's own compile-time
    // location and is unconditionally correct in both web and CLI
    // contexts. Same fix already applied in mdproductivity's,
    // marktuttlemd's, and single-auth's own config files.
    $secretsFile = dirname(dirname(dirname(__DIR__))) . '/secrets/identity-db-config.php';
    $secrets = is_file($secretsFile) ? require $secretsFile : [];

    $need = function (string $key) use ($secrets, $secretsFile) {
        $value = getenv($key) ?: ($secrets[$key] ?? null);
        if ($value === null || $value === '') {
            throw new RuntimeException("Missing required identity DB config: $key (set env var $key, or provide it in $secretsFile)");
        }
        return $value;
    };

    define('IDENTITY_DB_HOST', $need('IDENTITY_DB_HOST'));
    define('IDENTITY_DB_PORT', (int)$need('IDENTITY_DB_PORT'));
    define('IDENTITY_DB_NAME', $need('IDENTITY_DB_NAME'));
    define('IDENTITY_DB_USER', $need('IDENTITY_DB_USER'));
    define('IDENTITY_DB_PASS', $need('IDENTITY_DB_PASS'));
    define('IDENTITY_COOKIE_DOMAIN', '.marktuttlemd.com');
    define('IDENTITY_COOKIE_SECURE', !$isLocal);
}
