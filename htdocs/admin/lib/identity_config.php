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
    // $_SERVER['DOCUMENT_ROOT'] — this app now runs containerized on the
    // VPS (vps-phpfpm:8.4), but the same caution originally raised on
    // IONOS's shared-hosting PHP CLI SAPI still applies generally:
    // DOCUMENT_ROOT is only ever populated on a real web request, so any
    // CLI invocation would silently produce a bogus, prefix-less secrets
    // path. __DIR__ is this file's own compile-time location and is
    // unconditionally correct in both web and CLI contexts, on this VPS
    // or anywhere else. Same fix already applied in mdproductivity's,
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

// TLS is REQUIRED for every single-auth-mariadb connection -- this path
// must exist regardless of which branch above ran. auth.php's
// identity_pdo() references IDENTITY_DB_SSL_CA unconditionally (both
// PDO::MYSQL_ATTR_SSL_CA and PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT are
// set on every connection, local or production), so this constant must
// be defined either way, or every authenticated request fatals on an
// undefined constant -- this was missed the first time in
// mdproductivity's own identity_config.php (defined only inside its
// production branch), caught in that plan's final review, and applied
// here from the start. See mdproductivity's and marktuttlemd's own
// htdocs/lib/identity_config.php for the same pattern.
if ($isLocal) {
    // 3mensio's identity DB hop for LOCAL dev targets a plain local
    // `mariadb` container (see IDENTITY_DB_HOST above), not the
    // relocated VPS-only single-auth-mariadb -- that DB isn't reachable
    // from a typical local dev machine at all. This value is a
    // placeholder only, never expected to back a real TLS handshake; it
    // exists purely so this constant is defined and identity_pdo()
    // doesn't fatal on an undefined one.
    define('IDENTITY_DB_SSL_CA', dirname(dirname(dirname(__DIR__))) . '/secrets/local-single-auth-ca-placeholder.pem');
} else {
    // 3mensio's identity DB hop is now a same-Docker-network hop to
    // single-auth-mariadb over the `identity` network (see
    // sites/mensioxml/compose.yml in vps-infra) -- not a remote
    // connection over the public internet the way the old IONOS ->
    // VPS-public-IP hop was before this app's own VPS hosting migration.
    // TLS is still required and verified regardless (see the comment
    // above this if/else), same-network or not. single-auth-ca.pem is
    // shipped outside the webroot by this same deploy workflow's
    // identity-secrets step, alongside identity-db-config.php -- see
    // .github/workflows/deploy.yml. Same path base as $secretsFile
    // above: dirname(dirname(dirname(__DIR__))) is htdocs/admin/lib ->
    // htdocs/admin -> htdocs -> project root, secrets/ is a sibling of
    // htdocs/.
    define('IDENTITY_DB_SSL_CA', dirname(dirname(dirname(__DIR__))) . '/secrets/single-auth-ca.pem');
}
