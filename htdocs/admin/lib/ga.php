<?php

declare(strict_types=1);

/**
 * 3mensioXMLParser's adapter onto coder999/google-analytics-integration.
 *
 * This site has no database, which is why it originally forked the shared
 * client. It now uses the package's FileCache instead, pointed at
 * /var/www/storage -- the site's own data volume, which is outside the
 * git-tracked tree as well as outside the web root. A cache under the repo
 * root would be one `git add -A` from committing a live OAuth token.
 *
 * Config comes from .env. The old secrets/ga-config.php mechanism and its
 * local.marker branch (a convention this fleet replaced with APP_ENV) are
 * both gone.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Coder999\Ga4\Cache\FileCache;
use Coder999\Ga4\Client;
use Coder999\Ga4\Credentials;
use Coder999\Ga4\Dashboard;
use Coder999\Ga4\Http\CurlHttp;
use Coder999\Ga4\ServiceAccount;
use Coder999\Ga4\TokenSource;

function ga_service_account_json(): string
{
    $file = (string) (getenv('GA_SERVICE_ACCOUNT_FILE') ?: '');

    if ($file !== '') {
        if (!is_readable($file)) {
            // Loud on purpose. Falling back silently here would serve a
            // stale per-repo secret and look like everything still works.
            error_log('GA: GA_SERVICE_ACCOUNT_FILE is set but not readable: ' . $file);
        } else {
            $contents = (string) file_get_contents($file);
            if (trim($contents) !== '') {
                return $contents;
            }
            error_log('GA: GA_SERVICE_ACCOUNT_FILE is empty or whitespace: ' . $file);
        }
    }

    return (string) (getenv('GA_SERVICE_ACCOUNT_JSON') ?: '');
}

function ga_measurement_id(): string
{
    return (string) (getenv('GA4_MEASUREMENT_ID') ?: '');
}

function ga_configured(): bool
{
    return (getenv('GA4_PROPERTY_ID') ?: '') !== ''
        && ga_service_account_json() !== '';
}

/** The bundle the admin page renders. Null when GA is not configured. */
function ga_dashboard_data(bool $force = false): ?array
{
    if (!ga_configured()) {
        return null;
    }

    $account = ServiceAccount::fromJson(ga_service_account_json());
    $cache   = new FileCache('/var/www/storage/ga-cache');
    $http    = new CurlHttp();
    $tokens  = new TokenSource($account, $cache, $http, TokenSource::SCOPE_READONLY);
    $client  = new Client(
        new Credentials($account, (string) getenv('GA4_PROPERTY_ID')),
        $tokens,
        $http
    );

    return (new Dashboard($client, $cache))->data($force);
}
