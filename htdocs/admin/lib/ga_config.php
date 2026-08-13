<?php
/**
 * GA4 config — no database in this project, so the property ID and
 * service-account key come straight from GitHub Actions secrets rendered
 * to secrets/ga-config.php at deploy time (same mechanism as
 * identity_config.php's identity-db-config.php), or from env vars for
 * local testing.
 */

$isLocal = is_file(__DIR__ . '/../local.marker');

if ($isLocal) {
    define('GA4_PROPERTY_ID', getenv('GA4_PROPERTY_ID') ?: '');
    define('GA_SERVICE_ACCOUNT_JSON', getenv('GA_SERVICE_ACCOUNT_JSON') ?: '');
} else {
    $secretsFile = dirname(dirname(dirname(__DIR__))) . '/secrets/ga-config.php';
    $secrets = is_file($secretsFile) ? require $secretsFile : [];
    define('GA4_PROPERTY_ID', getenv('GA4_PROPERTY_ID') ?: ($secrets['GA4_PROPERTY_ID'] ?? ''));
    define('GA_SERVICE_ACCOUNT_JSON', getenv('GA_SERVICE_ACCOUNT_JSON') ?: ($secrets['GA_SERVICE_ACCOUNT_JSON'] ?? ''));
}
