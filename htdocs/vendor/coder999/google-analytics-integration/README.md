# ga4

GA4 Data API reporting client, gtag renderer, and property provisioning CLI. No runtime dependencies, no database.

## Consumer entry points

- `Client` — GA4 Data API client. Signs a service-account JWT, exchanges it for an OAuth token, and calls `runReport`.
- `Dashboard` — builds the report bundle admin pages consume (28-day daily series, period totals with a previous-period comparison, top pages, top locations) on top of `Client`, with its own cache and TTL.
- `Tag` — renders the `gtag.js` page snippet for a given measurement ID. Tracks no events itself; it emits the boilerplate that lets GA start collecting them.
- `bin/ga-provision` — CLI that creates a GA4 property and web data stream under an existing GA account, then prints the two `.env` lines a site needs.

## Installation

Add to `composer.json`:

```json
{
    "require": { "coder999/google-analytics-integration": "^0.1.0" },
    "repositories": [
        { "type": "vcs", "url": "https://github.com/coder999/google-analytics-integration" }
    ]
}
```

Then run `composer install`.

## Getting a Dashboard

This is the full construction chain a site's admin page needs — the same shape every adapter should copy.

```php
use Mtmd\Ga4\Cache\FileCache;
use Mtmd\Ga4\Client;
use Mtmd\Ga4\Credentials;
use Mtmd\Ga4\Dashboard;
use Mtmd\Ga4\Http\CurlHttp;
use Mtmd\Ga4\ServiceAccount;
use Mtmd\Ga4\TokenSource;

// 1. Decode the service-account key (the JSON Google gives you for the
//    service account, not the GA4 measurement ID or property ID).
$account = ServiceAccount::fromJson(file_get_contents('/path/outside/webroot/service-account.json'));

// 2. The GA4 property this site reports on. Numeric property ID, not the
//    "G-XXXXXXXXXX" measurement ID -- Credentials rejects that mistake.
$credentials = new Credentials($account, '123456789');

// 3. A cache. FileCache for a site with no database; a ~10-line adapter
//    around the site's own setting_get()/setting_set() for a DB-backed
//    site. Either way it implements CacheInterface -- see "Design" below.
//    Keep the directory outside the web root and not world-writable.
$cache = new FileCache('/path/outside/webroot/ga4-cache');

// 4. Transport. CurlHttp is the only HttpInterface implementation this
//    package ships; swap in a fake for tests.
$http = new CurlHttp();

// 5. Token exchange, cached until just before expiry. Defaults to the
//    analytics.readonly scope, which is what Dashboard needs.
$tokens = new TokenSource($account, $cache, $http);

// 6. The Data API client, and the report bundle built on top of it.
$client = new Client($credentials, $tokens, $http);
$dashboard = new Dashboard($client, $cache);

$data = $dashboard->data();
// ['daily' => [...], 'totals' => [...], 'prev' => [...],
//  'top_pages' => [...], 'top_locations' => [...], 'fetched_at' => 1700000000]
```

`Dashboard::data()` serves a cached bundle until `ttlSeconds` (default 3600) elapses, then refetches automatically. Pass `true` to force a refetch (`$dashboard->data(true)`).

## Design

The four sites this package replaces each had their own hand-rolled `ga.php`, forked because the original hardcoded its own storage: one used a settings table, one used constants, one had no database at all and cached to JSON files. Copy-paste happened because there was no shared seam for "where does this live."

`CacheInterface` (`get(string $key): ?array` / `set(string $key, array $value): void`) is that seam. It is the only storage-shaped thing this package touches — nothing under `src/` references PDO, a database connection, a settings table, `$_SESSION`, `$_GET`, or `getenv()`. Configuration arrives through constructor arguments; caching goes through `CacheInterface`; HTTP goes through `HttpInterface`. That is what lets one package serve:

- a DB-backed site, via a thin adapter wrapping its own `setting_get()`/`setting_set()` for caching only (config still arrives through the constructor, not the database);
- a site with no database at all, via the bundled `FileCache`;
- and `bin/ga-provision`, a CLI with no persistent process, using the same `FileCache`;

without any of them forking the client to get there.

**Cache security note:** the cache directory holds an OAuth access token (and, for the provisioning CLI, one scoped to `analytics.edit`). Keep it outside the web root, and make sure it is not world-writable — a world-writable directory lets another local user pre-create the cache file (or a symlink in its place) before this package ever writes to it. `FileCache` creates files at mode `0600` and creates its directory at `0700`, but neither of those helps if the parent directory itself is writable by anyone else.

## Tests

`bin/dev test` (Docker; the host has no PHP or Composer). Nothing in the suite touches the network — `Client`, `Dashboard`, and `Admin` are tested against a `FakeHttp` double.

## Verified behaviour

- `dataStreams.create` returns `webStreamData.measurementId` directly in
  its create response; the read-back fallback in
  `Admin::createWebDataStream` did not fire (verified 2026-09-02 against
  the live GA4 Admin API). The fallback is kept because Google's
  published reference does not promise the field either way.
