# 3mensioXMLParser admin section: single-auth login + Google Analytics dashboard

**Date:** 2026-08-13
**Status:** approved, ready for implementation planning

## Purpose

`3mensioXMLParser` is currently a fully static site (`htdocs/index.html` +
templates, no PHP, no database, no CI beyond a manual rsync deploy). Add an
`admin/` section, gated by the shared `coder999/single-auth` login (the same
mechanism already live for `marktuttlemd` and `mdproductivity`), whose only
content is a Google Analytics dashboard — modeled on the GA dashboards
already shipped at `marktuttlemd.com/admin` and `denverstructuralheart.org/admin`.

Production domain: `https://3mensio.marktuttlemd.com/`, hosted as sibling
directory `3mensio` directly under the IONOS account root (per
[[ionos-hosting-layout]]), **not** nested inside `marktuttlemd`'s own
directory. Because `3mensio.marktuttlemd.com` is a subdomain of
`marktuttlemd.com`, the shared single-auth session cookie
(`.marktuttlemd.com`) covers it automatically — a user already logged in at
`marktuttlemd.com/admin` is already authenticated here too. This is a bonus
of the domain choice, not something the code needs to special-case.

## Scope decisions (already made)

Two design forks were resolved with the project owner before writing this
spec:

1. **No project-owned database.** GA4 property ID + service-account JSON
   key are supplied via GitHub Actions secrets and rendered to a config file
   outside the webroot at deploy time (same mechanism as
   `identity-db-config.php`), rather than a DB-backed `site_settings` table
   editable from a `settings.php` UI (as `marktuttlemd`/`DASH` do). This
   avoids introducing dbmate, migrations, and a new IONOS database into a
   project that has never needed one. Trade-off: changing GA credentials
   later requires updating the GitHub secret and redeploying, not a form
   submission.
2. **No password-change / admin-user-management UI.** `single-auth` users
   are already shared and manageable from `marktuttlemd.com/admin`'s
   `settings.php` (same `users` table). Duplicating that UI here would be
   redundant. This project's admin section is dashboard-only.

GA secrets are already set on the `3mensioXMLParser` GitHub repo:
`GA4_PROPERTY_ID`, `GA_SERVICE_ACCOUNT_JSON`.

## Components

### Composer / dependency

New `composer.json` at the project root:

```json
{
    "name": "coder999/3mensioxmlparser",
    "type": "project",
    "require": {
        "coder999/single-auth": "^0.2.0"
    },
    "repositories": [
        {"type": "vcs", "url": "https://github.com/coder999/single-auth"}
    ],
    "config": {"vendor-dir": "htdocs/vendor"},
    "minimum-stability": "stable"
}
```

Run `composer install` locally; `htdocs/vendor/` is committed to git (same
convention as `marktuttlemd` — no CI composer-install step exists or is
needed).

### `htdocs/admin/` layout

```
htdocs/admin/
  local.marker                # empty marker file; present in git, excluded from deploy rsync
  lib/
    identity_config.php       # local vs. prod identity-DB + cookie config
    auth.php                  # identity_pdo(), auth(), require_login(), csrf_*, logout(), etc.
    ga_config.php             # local vs. prod GA credential config (new — see below)
    ga.php                    # GA4 Data API client (adapted — see below)
    ui.php                    # admin_header()/admin_footer(), h(), flash_set()/flash_render()
  index.php                   # the GA dashboard (only page besides login/logout)
  login.php
  logout.php
  assets/
    admin.css                 # copied verbatim from marktuttlemd's admin skin
```

### `lib/identity_config.php` — copied verbatim from `marktuttlemd`'s

Same local/production branching (`local.marker` presence check, `.marker`-based
DB credentials for local docker, secrets-file-based for production), **except**:

- Production `IDENTITY_COOKIE_DOMAIN` = `.marktuttlemd.com` (shared with
  `marktuttlemd`/`mdproductivity` — this is what gives 3mensio real SSO).
- Secrets file path: `dirname(dirname(dirname(__DIR__))) . '/secrets/identity-db-config.php'`
  (same `__DIR__`-based, CLI-safe pattern already fixed project-wide per
  [[ionos-hosting-layout]] — never `$_SERVER['DOCUMENT_ROOT']`).

### `lib/auth.php` — copied verbatim from `marktuttlemd`'s

`identity_pdo()`, `auth()` (with the same `cookie_secure` derived from
`IDENTITY_COOKIE_SECURE`, never sniffed from `$_SERVER['HTTPS']`),
`start_session()`, `current_user()`, `require_login()`, `csrf_token()`/
`csrf_field()`/`csrf_check()`, `client_ip()`, `login_throttled()`,
`attempt_login()`, `logout()`.

### `lib/ga_config.php` — new, mirrors `identity_config.php`'s shape

```php
<?php
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
```

Local dev leaves both empty unless the developer exports the env vars —
`ga_configured()` then returns `false` and the dashboard shows its "not
connected" onboarding panel, which is an acceptable default local state.

### `lib/ga.php` — adapted from the reference `ga.php`

Same GA4 Data API client logic (service-account JWT → OAuth2 token exchange,
`ga_run_report()`, `ga_dashboard_data()` assembling the 28-day daily series +
period totals/deltas + top pages) as `marktuttlemd`/`DASH`'s, **verbatim
except for the storage backend**:

- `ga_configured()` / `ga_service_account()` read `GA4_PROPERTY_ID`/
  `GA_SERVICE_ACCOUNT_JSON` constants instead of `setting_get()`.
- Token cache and report cache (previously `setting_get`/`setting_set`
  against `site_settings`) become two small JSON files in a `cache/`
  directory one level above `htdocs/` (sibling to `secrets/`), e.g.
  `ga_cache_dir() . '/token.json'` and `.../report.json'`. A `ga_cache_dir()`
  helper resolves the path the same local/production way as
  `ga_config.php` and lazily `mkdir($dir, 0700, true)`s it on first write —
  no deploy-time provisioning needed, since it's pure runtime state.
- Cache TTL (1 hour) and force-refresh (`?refresh=1`) behavior unchanged.

### `lib/ui.php` — trimmed from `marktuttlemd`'s

`admin_header()`/`admin_footer()`, `h()`, `flash_set()`/`flash_render()` —
same shapes, but the nav only has **Dashboard**, **View site**, and
**Sign out** (no Content/Items/Settings links, since none of those pages
exist here). `admin_header()` no longer `require_once`s a `content.php` it
doesn't have.

### `index.php`

Same dashboard as `marktuttlemd`'s `index.php` (tiles, 28-day line chart
with hover tooltip, top-pages table, daily-detail table, the three-state
connected/error/not-configured GA panel), **minus** the bottom "Edit
content" / "Lists & cards" cards (no CMS in this project). The "not
connected yet" onboarding copy's last step changes from "paste into
Settings" to "set the `GA4_PROPERTY_ID` and `GA_SERVICE_ACCOUNT_JSON`
secrets on the GitHub repo and redeploy."

### `login.php` / `logout.php`

Same shape as `marktuttlemd`'s. The login card uses a text wordmark ("3mensio
XML Parser") instead of an `<img>` logo, since this project has no logo
asset.

## Deploy pipeline (`.github/workflows/deploy.yml`)

Current workflow is checkout → SSH setup → SSH test → rsync. Add:

- To the rsync step: `--exclude 'secrets/'`, `--exclude 'cache/'`,
  `--exclude 'htdocs/admin/local.marker'`.
- **Render identity secrets file** step (env: `SINGLE_AUTH_DB_{HOST,PORT,NAME,USER,PASS}`)
  → `deploy/secrets/identity-db-config.php.template` rendered via `envsubst`
  → `/tmp/identity-db-config.php`, same fail-loudly `: "${VAR:?}"` guard
  pattern as `marktuttlemd`'s.
- **Render GA secrets file** step (env: `GA4_PROPERTY_ID`, `GA_SERVICE_ACCOUNT_JSON`)
  → `deploy/secrets/ga-config.php.template` rendered via `envsubst` →
  `/tmp/ga-config.php`, same fail-loudly guard.
- **Ship identity secrets file outside webroot** and **Ship GA secrets file
  outside webroot** steps — sftp batch (`-mkdir`, `put`, `chmod 700` dir /
  `chmod 600` file), `PROJECT_DIR=3mensio`, `REMOTE_SECRETS_PATH="$PROJECT_DIR/$IONOS_SECRETS_PATH"` —
  copied from `marktuttlemd`'s pattern.
- No dbmate build step, no migrations step — this project has no
  project-owned database.

New template files, copied from `marktuttlemd`'s exactly (same GitHub
secret names, same PHP array key names, so `identity_config.php` needs no
changes to the key names it already reads):

`deploy/secrets/identity-db-config.php.template`:
```php
<?php
return [
    'IDENTITY_DB_HOST' => '$SINGLE_AUTH_DB_HOST',
    'IDENTITY_DB_PORT' => $SINGLE_AUTH_DB_PORT,
    'IDENTITY_DB_NAME' => '$SINGLE_AUTH_DB_NAME',
    'IDENTITY_DB_USER' => '$SINGLE_AUTH_DB_USER',
    'IDENTITY_DB_PASS' => '$SINGLE_AUTH_DB_PASS',
];
```

`deploy/secrets/ga-config.php.template` (new — key names match what
`ga_config.php` reads):
```php
<?php
return [
    'GA4_PROPERTY_ID' => '$GA4_PROPERTY_ID',
    'GA_SERVICE_ACCOUNT_JSON' => '$GA_SERVICE_ACCOUNT_JSON',
];
```

### New GitHub Actions secrets needed (not yet set)

- `SINGLE_AUTH_DB_HOST`, `SINGLE_AUTH_DB_PORT`, `SINGLE_AUTH_DB_NAME`,
  `SINGLE_AUTH_DB_USER`, `SINGLE_AUTH_DB_PASS` — same values already used by
  `marktuttlemd`/`mdproductivity` (from the `IONOS-SINGLE-AUTH` 1Password
  item), since all three apps read/write the one shared `single_auth` DB.
- `IONOS_SECRETS_PATH` — `"secrets"` (same convention as the other repos).

Already set: `GA4_PROPERTY_ID`, `GA_SERVICE_ACCOUNT_JSON`, and the
pre-existing `IONOS_{HOST,PORT,USER,SSH_KEY,TARGET}`.

## Local development

Already works through the existing generic PHP vhost in
`nginx/conf.d/local.conf` (fastcgi-routes any `html-local/<project>/htdocs`)
at `http://3mensioxmlparser.nexus.local:8082/admin/` — no nginx changes
needed. Connects to the pre-existing local `single_auth` MariaDB database
using the same `identity_auth` credentials `marktuttlemd`/`mdproductivity`
use locally.

## Error handling

- Missing/invalid identity DB config throws loudly at boot (`RuntimeException`
  from the `$need()` closure), matching the existing convention — no silent
  degradation.
- GA API errors (bad property ID, revoked service-account access, etc.)
  are caught in `index.php` and shown as a "Couldn't load analytics" card,
  same as the reference sites.
- CSRF and login-throttling behavior unchanged from `single-auth`'s built-in
  `csrfCheck()`/`loginThrottled()`.

## Testing

Manual, matching how Plans B/C were verified:

1. Local: confirm `require_login()` redirects to `login.php` when logged
   out; log in with an existing `single_auth` test user; confirm the
   dashboard renders its "not connected" GA panel (no local GA secrets by
   default); confirm CSRF rejection on a login POST with a missing/invalid
   token; confirm `logout.php` clears the session.
2. Post-deploy on production: confirm `3mensio.marktuttlemd.com/admin`
   redirects to login when logged out; confirm a session already
   authenticated at `marktuttlemd.com/admin` is *already* authenticated
   here (real SSO via the shared `.marktuttlemd.com` cookie); confirm the
   GA dashboard pulls real data (tiles, chart, top pages) using the
   already-set `GA4_PROPERTY_ID`/`GA_SERVICE_ACCOUNT_JSON` secrets.
