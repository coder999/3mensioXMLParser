<?php
/**
 * Google Analytics 4 Data API client — no composer dependencies.
 *
 * Auth: service-account JWT (RS256 via openssl) exchanged for an OAuth2
 * access token. Configured via GA4_PROPERTY_ID / GA_SERVICE_ACCOUNT_JSON
 * (see ga_config.php). Unlike marktuttlemd/DASH's identical client, this
 * project has no database — the OAuth token and report are cached as
 * JSON files in cache/ (outside the webroot) instead of a site_settings
 * table.
 */
require_once __DIR__ . '/ga_config.php';

const GA_CACHE_SECONDS = 3600;

function ga_cache_dir(): string
{
    $dir = dirname(dirname(dirname(__DIR__))) . '/cache';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    return $dir;
}

function ga_cache_read(string $name): ?array
{
    $file = ga_cache_dir() . "/$name.json";
    if (!is_file($file)) {
        return null;
    }
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? $data : null;
}

function ga_cache_write(string $name, array $data): void
{
    $file = ga_cache_dir() . "/$name.json";
    file_put_contents($file, json_encode($data), LOCK_EX);
    chmod($file, 0600);
}

function ga_configured(): bool
{
    return !empty(GA_SERVICE_ACCOUNT_JSON) && !empty(GA4_PROPERTY_ID);
}

function ga_service_account(): ?array
{
    if (empty(GA_SERVICE_ACCOUNT_JSON)) {
        return null;
    }
    $sa = json_decode(GA_SERVICE_ACCOUNT_JSON, true);
    return (is_array($sa) && !empty($sa['client_email']) && !empty($sa['private_key'])) ? $sa : null;
}

function ga_base64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function ga_http_post_json(string $url, array $body, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => array_merge(['Content-Type: application/json'], $headers),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($resp === false) {
        throw new RuntimeException('Google API request failed: ' . $err);
    }
    $decoded = json_decode($resp, true);
    if ($code >= 400) {
        $msg = $decoded['error']['message'] ?? ($decoded['error_description'] ?? ('HTTP ' . $code));
        throw new RuntimeException('Google API error: ' . $msg);
    }
    return is_array($decoded) ? $decoded : [];
}

function ga_access_token(): string
{
    $cached = ga_cache_read('token');
    if ($cached && !empty($cached['token']) && ($cached['expires'] ?? 0) > time() + 60) {
        return $cached['token'];
    }

    $sa = ga_service_account();
    if ($sa === null) {
        throw new RuntimeException('Service account JSON is missing or invalid.');
    }

    $now = time();
    $header = ga_base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claims = ga_base64url(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/analytics.readonly',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));
    $signature = '';
    if (!openssl_sign($header . '.' . $claims, $signature, $sa['private_key'], 'sha256WithRSAEncryption')) {
        throw new RuntimeException('Could not sign JWT — check the private key in the service account JSON.');
    }
    $jwt = $header . '.' . $claims . '.' . ga_base64url($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $tok = json_decode((string)$resp, true);
    if (empty($tok['access_token'])) {
        $msg = $tok['error_description'] ?? ($tok['error'] ?? 'no response');
        throw new RuntimeException('Token exchange failed: ' . $msg);
    }

    ga_cache_write('token', [
        'token'   => $tok['access_token'],
        'expires' => time() + (int)($tok['expires_in'] ?? 3600),
    ]);
    return $tok['access_token'];
}

function ga_run_report(array $body): array
{
    $property = preg_replace('/^properties\//', '', trim(GA4_PROPERTY_ID));
    $url = 'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode($property) . ':runReport';
    return ga_http_post_json($url, $body, ['Authorization: Bearer ' . ga_access_token()]);
}

/**
 * Everything the dashboard shows, in one cached bundle:
 * daily active users (28d), period totals with previous-period deltas,
 * and the top pages. Returns null when GA isn't configured yet.
 */
function ga_dashboard_data(bool $force = false): ?array
{
    if (!ga_configured()) {
        return null;
    }

    if (!$force) {
        $cached = ga_cache_read('report');
        if ($cached && ($cached['fetched_at'] ?? 0) > time() - GA_CACHE_SECONDS) {
            return $cached;
        }
    }

    // 1) Daily series, last 28 days.
    $daily = ga_run_report([
        'dateRanges' => [['startDate' => '27daysAgo', 'endDate' => 'today']],
        'dimensions' => [['name' => 'date']],
        'metrics'    => [['name' => 'activeUsers'], ['name' => 'sessions'], ['name' => 'screenPageViews']],
        'orderBys'   => [['dimension' => ['dimensionName' => 'date']]],
    ]);
    $series = [];
    foreach ($daily['rows'] ?? [] as $row) {
        $series[] = [
            'date'      => $row['dimensionValues'][0]['value'],
            'users'     => (int)$row['metricValues'][0]['value'],
            'sessions'  => (int)$row['metricValues'][1]['value'],
            'pageviews' => (int)$row['metricValues'][2]['value'],
        ];
    }

    // 2) Totals for this 28-day window and the previous one (for deltas).
    $totals = ga_run_report([
        'dateRanges' => [
            ['startDate' => '27daysAgo', 'endDate' => 'today'],
            ['startDate' => '55daysAgo', 'endDate' => '28daysAgo'],
        ],
        'metrics' => [['name' => 'activeUsers'], ['name' => 'sessions'], ['name' => 'screenPageViews']],
    ]);
    $cur = ['users' => 0, 'sessions' => 0, 'pageviews' => 0];
    $prev = $cur;
    foreach ($totals['rows'] ?? [] as $row) {
        $bucket = ($row['dimensionValues'][0]['value'] ?? 'date_range_0') === 'date_range_0' ? 'cur' : 'prev';
        $vals = [
            'users'     => (int)$row['metricValues'][0]['value'],
            'sessions'  => (int)$row['metricValues'][1]['value'],
            'pageviews' => (int)$row['metricValues'][2]['value'],
        ];
        if ($bucket === 'cur') { $cur = $vals; } else { $prev = $vals; }
    }

    // 3) Top pages this window.
    $pages = ga_run_report([
        'dateRanges' => [['startDate' => '27daysAgo', 'endDate' => 'today']],
        'dimensions' => [['name' => 'pagePath']],
        'metrics'    => [['name' => 'screenPageViews'], ['name' => 'activeUsers']],
        'orderBys'   => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
        'limit'      => 10,
    ]);
    $topPages = [];
    foreach ($pages['rows'] ?? [] as $row) {
        $topPages[] = [
            'path'  => $row['dimensionValues'][0]['value'],
            'views' => (int)$row['metricValues'][0]['value'],
            'users' => (int)$row['metricValues'][1]['value'],
        ];
    }

    $data = [
        'daily'      => $series,
        'totals'     => $cur,
        'prev'       => $prev,
        'top_pages'  => $topPages,
        'fetched_at' => time(),
    ];
    ga_cache_write('report', $data);
    return $data;
}
