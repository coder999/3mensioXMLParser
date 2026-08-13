# 3mensioXMLParser admin section (single-auth login + GA dashboard) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `/admin` section to `3mensioXMLParser` — currently a fully static site — gated by the shared `coder999/single-auth` login, whose only page is a Google Analytics dashboard.

**Architecture:** Port the proven `marktuttlemd.com/admin` GA dashboard and single-auth wiring wholesale, but with two deliberate simplifications already approved in the spec: no project-owned database (GA config comes from GitHub Actions secrets rendered to a file outside the webroot; the report/token cache is two JSON files instead of a DB table), and no password-change/user-management UI (that already lives at `marktuttlemd.com/admin`, same shared `users` table).

**Tech Stack:** PHP 8.1+ (matches `coder999/single-auth`'s floor), Composer, `coder999/single-auth` ^0.2.0, MariaDB (only for the pre-existing shared `single_auth` identity DB — no new database), plain PDO, curl + openssl PHP extensions (already present in this repo's `php` container). No test framework exists for PHP admin panels anywhere in this project family (only `single-auth` itself has PHPUnit) — verification throughout is manual, via `curl`/`docker exec`/browser, matching how Plans A/B/C for the sibling `marktuttlemd`/`mdproductivity` projects were verified.

**Spec:** `docs/superpowers/specs/2026-08-13-admin-single-auth-ga-design.md`

## Global Constraints

- PHP `>=8.1`, `ext-pdo` (required by `coder999/single-auth`).
- `coder999/single-auth` pinned to `^0.2.0` (current tag; schema uses `users`/`sessions`/`login_attempts` table names).
- No project-owned database — GA config and caches must not touch MySQL.
- Production `IDENTITY_COOKIE_DOMAIN` is `.marktuttlemd.com` (3mensio.marktuttlemd.com is a subdomain of marktuttlemd.com — this gives real SSO with `marktuttlemd`/`mdproductivity`, not just shared credentials).
- Production sibling directory name on IONOS is `3mensio` (not `3mensioxmlparser`) — every deploy-time path (`IONOS_TARGET`, `PROJECT_DIR` in workflow steps) must use that exact name.
- Config file paths use `dirname(dirname(dirname(__DIR__)))`, never `$_SERVER['DOCUMENT_ROOT']` (this IONOS account's CLI SAPI resolves `DOCUMENT_ROOT` to a bogus empty/root value).
- `cookie_secure` is derived from `IDENTITY_COOKIE_SECURE`, never sniffed from `$_SERVER['HTTPS']` (IONOS terminates TLS upstream; that header may never be set).
- Repo: `html-local/3mensioxmlparser` (bind-mounted into the `php`/`nginx` containers at `/var/www/html-local/3mensioxmlparser`), branch `admin-single-auth-ga` (already created off `main`).

---

## Task 1: Composer dependency, local-dev scaffold, cache directory

**Files:**
- Create: `composer.json`
- Create: `htdocs/admin/local.marker` (empty file)
- Create: `.gitignore` (new — project has none yet)
- Modify: `/home/mark/docker/nginx-mariadb/docker-compose.yml` (nginx service's network aliases)

**Interfaces:**
- Produces: `htdocs/vendor/autoload.php` (committed), `Mtmd\SingleAuth\Auth` and `Mtmd\SingleAuth\DbSessionHandler` classes available to later tasks.

- [ ] **Step 1: Write `composer.json`**

```json
{
    "name": "coder999/3mensioxmlparser",
    "type": "project",
    "require": {
        "coder999/single-auth": "^0.2.0"
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/coder999/single-auth"
        }
    ],
    "config": {
        "vendor-dir": "htdocs/vendor"
    },
    "minimum-stability": "stable"
}
```

- [ ] **Step 2: Install the dependency via the project's own PHP container (has Composer built in)**

Run:
```bash
docker exec -w /var/www/html-local/3mensioxmlparser php composer install --no-interaction
```
Expected: Composer resolves `coder999/single-auth` from GitHub and writes `htdocs/vendor/` (including `htdocs/vendor/coder999/single-auth`, `htdocs/vendor/autoload.php`, `composer.lock`).

- [ ] **Step 3: Verify the autoloader resolves the library's classes**

Run:
```bash
docker exec -w /var/www/html-local/3mensioxmlparser php php -r "require 'htdocs/vendor/autoload.php'; var_dump(class_exists('Mtmd\SingleAuth\Auth'), class_exists('Mtmd\SingleAuth\DbSessionHandler'));"
```
Expected: `bool(true)` twice.

- [ ] **Step 4: Create the local-dev marker file**

```bash
mkdir -p htdocs/admin
touch htdocs/admin/local.marker
```

- [ ] **Step 5: Add `.gitignore`** (this project has none yet; the runtime cache directory Task 4 introduces must never be committed)

```
/cache/
```

- [ ] **Step 6: Add this project's local hostnames to the shared nginx container's Docker-network aliases**

Every other project in `/home/mark/docker/nginx-mariadb/docker-compose.yml`'s `nginx` service has both its `.nexus.local` and `.odroid.local` aliases listed (needed so the KasmVNC remote-browser container can resolve them — see the `nexus-local-sites-need-8082-remote-browser` note). Edit the `networks.default.aliases` list under the `nginx` service, adding two lines after the existing `demo.odroid.local` entry:

```yaml
          - demo.nexus.local
          - demo.odroid.local
          - 3mensioxmlparser.nexus.local
          - 3mensioxmlparser.odroid.local
          - testa.nexus.local
          - testa.odroid.local
```

(i.e. insert the new pair anywhere in the list — alphabetical position isn't load-bearing, just match the existing two-line-per-project pattern.)

- [ ] **Step 7: Recreate the nginx container to pick up the alias change and verify the vhost serves the (still-static) site**

Run:
```bash
cd /home/mark/docker/nginx-mariadb && docker compose up -d nginx
curl -s -o /dev/null -w "%{http_code}\n" -H "Host: 3mensioxmlparser.nexus.local" http://localhost/
```
Expected: `200`.

- [ ] **Step 8: Commit**

```bash
cd /home/mark/docker/nginx-mariadb/html-local/3mensioxmlparser
git add composer.json composer.lock htdocs/vendor htdocs/admin/local.marker .gitignore
git commit -m "Add coder999/single-auth dependency and local-dev scaffold"
cd /home/mark/docker/nginx-mariadb
git add docker-compose.yml
git commit -m "Add 3mensioxmlparser local-dev hostnames to nginx container aliases"
```

(Two separate commits, two separate repos — `docker-compose.yml` lives in the outer `nginx-mariadb` repo, not the `3mensioxmlparser` repo.)

---

## Task 2: Identity config + auth wiring

**Files:**
- Create: `htdocs/admin/lib/identity_config.php`
- Create: `htdocs/admin/lib/auth.php`

**Interfaces:**
- Consumes: `Mtmd\SingleAuth\Auth`, `Mtmd\SingleAuth\DbSessionHandler` (Task 1), constants `IDENTITY_DB_HOST`/`IDENTITY_DB_PORT`/`IDENTITY_DB_NAME`/`IDENTITY_DB_USER`/`IDENTITY_DB_PASS`/`IDENTITY_COOKIE_DOMAIN`/`IDENTITY_COOKIE_SECURE`.
- Produces (for later tasks): `identity_pdo(): PDO`, `auth(): Auth`, `start_session(): void`, `current_user(): ?array`, `require_login(): array`, `csrf_token(): string`, `csrf_field(): string`, `csrf_check(): void`, `client_ip(): string`, `login_throttled(): bool`, `attempt_login(string, string): bool`, `logout(): void`.

- [ ] **Step 1: Write `htdocs/admin/lib/identity_config.php`**

```php
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
```

- [ ] **Step 2: Write `htdocs/admin/lib/auth.php`**

```php
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
```

- [ ] **Step 3: Verify `identity_pdo()` connects to the local `single_auth` database**

Run:
```bash
docker exec -w /var/www/html-local/3mensioxmlparser/htdocs/admin php php -r "
require 'lib/auth.php';
\$rows = identity_pdo()->query('SELECT id, username FROM users')->fetchAll();
var_dump(\$rows);
"
```
Expected: an array containing at least one row (the existing local test user `mark`) — confirms the DSN/credentials/table name are all correct and `session_set_save_handler` didn't throw.

- [ ] **Step 4: Set a known password on the local test user, for deterministic login testing in Task 3**

```bash
docker exec php php -r "echo password_hash('LocalTestPassword123', PASSWORD_DEFAULT), PHP_EOL;"
```
Copy the printed hash, then:
```bash
docker exec mariadb mariadb -u identity_auth -pChangeThisIdentityAuthPassword single_auth \
  -e "UPDATE users SET password_hash = '<paste hash here>' WHERE username = 'mark';"
```
This is the shared local `single_auth` DB already used by `marktuttlemd`/`mdproductivity` locally — resetting this local-only password does not touch production credentials.

- [ ] **Step 5: Commit**

```bash
git add htdocs/admin/lib/identity_config.php htdocs/admin/lib/auth.php
git commit -m "Wire up single-auth identity config and auth helpers"
```

---

## Task 3: Login/logout pages, admin UI shell, and CSS

**Files:**
- Create: `htdocs/admin/lib/ui.php`
- Create: `htdocs/admin/login.php`
- Create: `htdocs/admin/logout.php`
- Create: `htdocs/admin/assets/admin.css`

**Interfaces:**
- Consumes: everything from Task 2 (`start_session`, `current_user`, `require_login`, `csrf_field`, `csrf_check`, `login_throttled`, `attempt_login`, `logout`).
- Produces (for later tasks): `h(?string): string`, `admin_header(string $title, string $active = ''): void`, `admin_footer(): void`, `flash_set(string, string): void`, `flash_render(): void`.

- [ ] **Step 1: Write `htdocs/admin/lib/ui.php`**

```php
<?php
require_once __DIR__ . '/auth.php';

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function admin_header(string $title, string $active = ''): void
{
    $user = current_user();
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> — Admin · 3mensio XML Parser</title>
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<header class="topbar">
  <div class="topbar-inner">
    <a class="brand" href="index.php"><span>3mensioxmlparser <em>admin</em></span></a>
    <?php if ($user): ?>
    <nav>
      <a href="index.php" class="<?= $active === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
      <a href="../" target="_blank" rel="noopener">View site ↗</a>
      <a href="logout.php" class="muted"><?= h($user['username']) ?> · Sign out</a>
    </nav>
    <?php endif; ?>
  </div>
</header>
<main class="wrap">
<?php
}

function admin_footer(): void
{
    ?></main>
<footer class="pagefoot">3mensio XML Parser — site administration</footer>
</body>
</html><?php
}

function flash_set(string $type, string $msg): void
{
    start_session();
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function flash_render(): void
{
    start_session();
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<div class="flash flash-' . h($f['type']) . '">' . h($f['msg']) . '</div>';
    }
}
```

- [ ] **Step 2: Write `htdocs/admin/login.php`**

```php
<?php
require_once __DIR__ . '/lib/ui.php';

start_session();
if (current_user() !== null) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (login_throttled()) {
        $error = 'Too many failed attempts. Please wait 15 minutes and try again.';
    } elseif (attempt_login(trim($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''))) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'Incorrect username or password.';
    }
}

admin_header('Sign in');
?>
<div class="login-shell">
  <div class="card login-card">
    <div class="brandmark"><strong style="font-size:20px;">3mensio XML Parser</strong></div>
    <h1 style="text-align:center;font-size:20px;margin-bottom:16px;">Site administration</h1>
    <?php if ($error): ?><div class="flash flash-err"><?= h($error) ?></div><?php endif; ?>
    <form method="post" class="stack">
      <?= csrf_field() ?>
      <label class="f"><b>Username</b>
        <input type="text" name="username" autocomplete="username" required autofocus>
      </label>
      <label class="f"><b>Password</b>
        <input type="password" name="password" autocomplete="current-password" required>
      </label>
      <button class="btn" type="submit">Sign in</button>
    </form>
  </div>
</div>
<?php admin_footer();
```

- [ ] **Step 3: Write `htdocs/admin/logout.php`**

```php
<?php
require_once __DIR__ . '/lib/auth.php';
logout();
header('Location: login.php');
exit;
```

- [ ] **Step 4: Write `htdocs/admin/assets/admin.css`** (verbatim copy of `marktuttlemd`'s admin skin — self-contained, light/dark aware, no project-specific branding baked in)

```css
/* Admin UI — self-contained, light/dark aware. Chart chrome follows the
   validated reference palette (series blue #2a78d6 light / #3987e5 dark). */
:root {
  --page: #f9f9f7;
  --surface: #fcfcfb;
  --ink: #0b0b0b;
  --ink-2: #52514e;
  --muted: #898781;
  --grid: #e1e0d9;
  --baseline: #c3c2b7;
  --border: rgba(11, 11, 11, 0.10);
  --series: #2a78d6;
  --series-wash: rgba(42, 120, 214, 0.10);
  --good: #006300;
  --bad: #d03b3b;
  --accent: #0f172a;
  --accent-ink: #ffffff;
}
@media (prefers-color-scheme: dark) {
  :root {
    --page: #0d0d0d;
    --surface: #1a1a19;
    --ink: #ffffff;
    --ink-2: #c3c2b7;
    --muted: #898781;
    --grid: #2c2c2a;
    --baseline: #383835;
    --border: rgba(255, 255, 255, 0.10);
    --series: #3987e5;
    --series-wash: rgba(57, 135, 229, 0.12);
    --good: #0ca30c;
    --bad: #e66767;
    --accent: #e2e8f0;
    --accent-ink: #0b0b0b;
  }
}

* { box-sizing: border-box; }
body {
  margin: 0;
  background: var(--page);
  color: var(--ink);
  font: 15px/1.55 system-ui, -apple-system, "Segoe UI", sans-serif;
}
a { color: inherit; }

.topbar {
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  position: sticky; top: 0; z-index: 20;
}
.topbar-inner {
  max-width: 1100px; margin: 0 auto; padding: 0 20px;
  min-height: 56px; display: flex; align-items: center; gap: 24px; flex-wrap: wrap;
}
.brand { display: flex; align-items: center; gap: 10px; font-weight: 600; text-decoration: none; }
.brand img { height: 30px; width: auto; }
.brand em { font-style: normal; color: var(--muted); font-weight: 500; }
.topbar nav { display: flex; gap: 4px; flex-wrap: wrap; margin-left: auto; }
.topbar nav a {
  text-decoration: none; padding: 6px 12px; border-radius: 8px;
  color: var(--ink-2); font-size: 14px;
}
.topbar nav a:hover { background: var(--series-wash); }
.topbar nav a.active { background: var(--accent); color: var(--accent-ink); }
.topbar nav a.muted { color: var(--muted); }

.wrap { max-width: 1100px; margin: 0 auto; padding: 28px 20px 60px; }
.pagefoot { max-width: 1100px; margin: 0 auto; padding: 0 20px 40px; color: var(--muted); font-size: 13px; }

h1 { font-size: 24px; margin: 0 0 4px; letter-spacing: -0.01em; }
h2 { font-size: 17px; margin: 0 0 12px; }
.sub { color: var(--ink-2); margin: 0 0 24px; font-size: 14px; }

.card {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 14px;
  padding: 20px;
}
.grid { display: grid; gap: 16px; }
.cols-2 { grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); }

/* Stat tiles */
.tiles { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); margin-bottom: 16px; }
.tile { background: var(--surface); border: 1px solid var(--border); border-radius: 14px; padding: 16px 18px; }
.tile .label { font-size: 13px; color: var(--ink-2); }
.tile .value { font-size: 30px; font-weight: 600; letter-spacing: -0.01em; margin-top: 2px; }
.tile .delta { font-size: 13px; margin-top: 2px; }
.tile .delta.up { color: var(--good); }
.tile .delta.down { color: var(--bad); }
.tile .delta .vs { color: var(--muted); }

/* Chart */
.chart-card { padding: 20px 20px 8px; }
.chart-head { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
.chart-head .when { color: var(--muted); font-size: 12px; }
.chart-wrap { position: relative; }
.chart-wrap svg { display: block; width: 100%; height: auto; }
.chart-tip {
  position: absolute; pointer-events: none; display: none;
  background: var(--surface); border: 1px solid var(--border); border-radius: 8px;
  box-shadow: 0 4px 14px rgba(0,0,0,.12);
  padding: 8px 10px; font-size: 12.5px; white-space: nowrap; z-index: 5;
}
.chart-tip .d { color: var(--ink-2); }
.chart-tip .v { font-weight: 600; }

/* Tables */
table.data { width: 100%; border-collapse: collapse; font-size: 14px; }
table.data th {
  text-align: left; color: var(--muted); font-weight: 500; font-size: 12.5px;
  padding: 6px 8px; border-bottom: 1px solid var(--grid);
}
table.data td { padding: 8px; border-bottom: 1px solid var(--grid); }
table.data td.num { text-align: right; font-variant-numeric: tabular-nums; }
table.data th.num { text-align: right; }
table.data tr:last-child td { border-bottom: 0; }

/* Forms */
form.stack { display: grid; gap: 14px; }
label.f { display: grid; gap: 5px; font-size: 13.5px; color: var(--ink-2); }
label.f b { color: var(--ink); font-weight: 600; }
input[type=text], input[type=password], input[type=url], input[type=number], textarea, select {
  width: 100%; padding: 9px 11px; border-radius: 9px;
  border: 1px solid var(--baseline); background: var(--page); color: var(--ink);
  font: inherit;
}
textarea { min-height: 90px; resize: vertical; }
input:focus, textarea:focus, select:focus { outline: 2px solid var(--series); outline-offset: 1px; border-color: transparent; }
.btn {
  display: inline-flex; align-items: center; gap: 6px; cursor: pointer;
  background: var(--accent); color: var(--accent-ink);
  border: 0; border-radius: 10px; padding: 9px 16px; font: inherit; font-weight: 600; font-size: 14px;
  text-decoration: none;
}
.btn:hover { opacity: .9; }
.btn.secondary { background: transparent; color: var(--ink); border: 1px solid var(--baseline); font-weight: 500; }
.btn.danger { background: transparent; color: var(--bad); border: 1px solid var(--bad); font-weight: 500; }
.btn.small { padding: 5px 11px; font-size: 13px; border-radius: 8px; }

.flash { border-radius: 10px; padding: 11px 14px; margin-bottom: 18px; font-size: 14px; }
.flash-ok { background: rgba(12, 163, 12, .12); color: var(--good); }
.flash-err { background: rgba(208, 59, 59, .12); color: var(--bad); }

/* Login */
.login-shell { min-height: calc(100vh - 120px); display: grid; place-items: center; }
.login-card { width: 100%; max-width: 380px; }
.login-card .brandmark { text-align: center; margin-bottom: 18px; }
.login-card .brandmark img { height: 46px; }

/* Setup-instructions list */
ol.steps { margin: 8px 0 0; padding-left: 20px; display: grid; gap: 6px; font-size: 14px; color: var(--ink-2); }
```

- [ ] **Step 5: Verify the login gate redirects when logged out**

`index.php` doesn't exist yet (Task 5), so verify against `login.php` itself instead — that unauthenticated `GET /admin/login.php` renders the form (not a fatal error), and that a POST with a bad CSRF token is rejected:

```bash
curl -s -o /dev/null -w "%{http_code}\n" -H "Host: 3mensioxmlparser.nexus.local" http://localhost/admin/login.php
# Expected: 200

curl -s -o /dev/null -w "%{http_code}\n" -H "Host: 3mensioxmlparser.nexus.local" \
  -X POST -d "username=mark&password=wrong" http://localhost/admin/login.php
# Expected: 403 (csrf_check() rejects the missing csrf token and exits)
```

- [ ] **Step 6: Verify a real login succeeds end-to-end**

```bash
curl -s -c /tmp/3mensio-cookies.txt -H "Host: 3mensioxmlparser.nexus.local" http://localhost/admin/login.php -o /tmp/login-form.html
CSRF=$(grep -oP 'name="csrf" value="\K[^"]+' /tmp/login-form.html)
curl -s -b /tmp/3mensio-cookies.txt -c /tmp/3mensio-cookies.txt -H "Host: 3mensioxmlparser.nexus.local" \
  -X POST -d "csrf=$CSRF&username=mark&password=LocalTestPassword123" \
  -D - -o /dev/null http://localhost/admin/login.php
```
Expected: response headers include `Location: index.php` (a 302, or PHP's default 200 with a Location header depending on how curl reports it — confirm the `Location:` header is present, which means `attempt_login()` succeeded).

- [ ] **Step 7: Commit**

```bash
git add htdocs/admin/lib/ui.php htdocs/admin/login.php htdocs/admin/logout.php htdocs/admin/assets/admin.css
git commit -m "Add admin login/logout pages and UI shell"
```

---

## Task 4: GA4 client with file-based config and cache

**Files:**
- Create: `htdocs/admin/lib/ga_config.php`
- Create: `htdocs/admin/lib/ga.php`

**Interfaces:**
- Consumes: nothing from earlier tasks (self-contained; only needs `curl`/`openssl` PHP extensions, both already present).
- Produces (for Task 5): `ga_configured(): bool`, `ga_dashboard_data(bool $force = false): ?array` returning `['daily' => [['date','users','sessions','pageviews'],...], 'totals' => ['users','sessions','pageviews'], 'prev' => [...], 'top_pages' => [['path','views','users'],...], 'fetched_at' => int]` or `null` when unconfigured.

- [ ] **Step 1: Write `htdocs/admin/lib/ga_config.php`**

```php
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
```

- [ ] **Step 2: Write `htdocs/admin/lib/ga.php`**

```php
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
```

- [ ] **Step 3: Verify the config/cache plumbing works standalone (no live GA call — no local secrets are set)**

```bash
docker exec -w /var/www/html-local/3mensioxmlparser/htdocs/admin php php -r "
require 'lib/ga.php';
var_dump(ga_configured());               // expect: bool(false) — no local GA secrets set
var_dump(ga_dashboard_data());           // expect: NULL
ga_cache_write('smoke_test', ['ok' => true]);
var_dump(ga_cache_read('smoke_test'));   // expect: array(1) { [\"ok\"]=> bool(true) }
var_dump(is_dir(ga_cache_dir()));        // expect: bool(true)
"
rm -f /home/mark/docker/nginx-mariadb/html-local/3mensioxmlparser/cache/smoke_test.json
```
Expected output as annotated above; deleting the scratch `smoke_test.json` afterward keeps the untracked `cache/` directory clean (it's gitignored, so this is just tidiness, not required).

- [ ] **Step 4: Commit**

```bash
git add htdocs/admin/lib/ga_config.php htdocs/admin/lib/ga.php
git commit -m "Add file-cache-backed GA4 Data API client"
```

---

## Task 5: Dashboard page

**Files:**
- Create: `htdocs/admin/index.php`

**Interfaces:**
- Consumes: `require_login()`, `admin_header()`/`admin_footer()`, `h()`, `flash_render()` (Task 3); `ga_dashboard_data()` (Task 4).

- [ ] **Step 1: Write `htdocs/admin/index.php`**

```php
<?php
require_once __DIR__ . '/lib/ui.php';
require_once __DIR__ . '/lib/ga.php';

$user = require_login();

$gaError = null;
$ga = null;
try {
    $ga = ga_dashboard_data(isset($_GET['refresh']));
} catch (Throwable $e) {
    $gaError = $e->getMessage();
}

function fmt_num(int $n): string
{
    if ($n >= 1000000) return round($n / 1000000, 1) . 'M';
    if ($n >= 10000)   return round($n / 1000, 1) . 'K';
    return number_format($n);
}

function delta_html(int $cur, int $prev): string
{
    if ($prev <= 0) {
        return '<div class="delta"><span class="vs">no prior-period data</span></div>';
    }
    $pct = ($cur - $prev) / $prev * 100;
    $cls = $pct >= 0 ? 'up' : 'down';
    $sign = $pct >= 0 ? '+' : '−';
    return '<div class="delta ' . $cls . '">' . $sign . number_format(abs($pct), 1) . '%'
         . ' <span class="vs">vs previous 28 days</span></div>';
}

admin_header('Dashboard', 'dashboard');
?>
<h1>Dashboard</h1>
<p class="sub">Welcome back<?= $user['username'] ? ', ' . h($user['username']) : '' ?>. Keep an eye on site traffic.</p>
<?php flash_render(); ?>

<?php if ($ga !== null): ?>
  <?php
    $t = $ga['totals'];
    $p = $ga['prev'];
    $days = max(1, count($ga['daily']));
    $avg = (int)round($t['users'] / $days);
  ?>
  <div class="tiles">
    <div class="tile">
      <div class="label">Unique users, last 28 days</div>
      <div class="value"><?= fmt_num($t['users']) ?></div>
      <?= delta_html($t['users'], $p['users']) ?>
    </div>
    <div class="tile">
      <div class="label">Avg unique users per day</div>
      <div class="value"><?= fmt_num($avg) ?></div>
    </div>
    <div class="tile">
      <div class="label">Sessions</div>
      <div class="value"><?= fmt_num($t['sessions']) ?></div>
      <?= delta_html($t['sessions'], $p['sessions']) ?>
    </div>
    <div class="tile">
      <div class="label">Page views</div>
      <div class="value"><?= fmt_num($t['pageviews']) ?></div>
      <?= delta_html($t['pageviews'], $p['pageviews']) ?>
    </div>
  </div>

  <?php
    // ---- Unique-users-per-day line chart (inline SVG + hover tooltip) ----
    $daily = $ga['daily'];
    $n = count($daily);
    $W = 1000; $H = 300;
    $padL = 46; $padR = 14; $padT = 16; $padB = 30;
    $plotW = $W - $padL - $padR;
    $plotH = $H - $padT - $padB;

    $maxV = 0;
    foreach ($daily as $d) { $maxV = max($maxV, $d['users']); }
    $yTop = 5;
    if ($maxV > 0) {
        $mag = pow(10, floor(log10($maxV)));
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($m * $mag >= $maxV) { $yTop = (int)ceil($m * $mag); break; }
        }
    }

    $pts = [];
    foreach ($daily as $i => $d) {
        $x = $padL + ($n > 1 ? $plotW * $i / ($n - 1) : $plotW / 2);
        $y = $padT + $plotH * (1 - ($yTop > 0 ? $d['users'] / $yTop : 0));
        $pts[] = [round($x, 1), round($y, 1)];
    }
    $linePath = '';
    foreach ($pts as $i => $pt) {
        $linePath .= ($i === 0 ? 'M' : 'L') . $pt[0] . ' ' . $pt[1];
    }
    $baselineY = $padT + $plotH;
    $areaPath = $linePath . 'L' . $pts[$n - 1][0] . ' ' . $baselineY . 'L' . $pts[0][0] . ' ' . $baselineY . 'Z';

    $tipData = array_map(function ($d, $pt) {
        return [
            'x' => $pt[0], 'y' => $pt[1],
            'date'  => preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', $d['date']),
            'users' => $d['users'], 'pageviews' => $d['pageviews'],
        ];
    }, $daily, $pts);
  ?>
  <div class="card chart-card" style="margin-bottom:16px;">
    <div class="chart-head">
      <h2>Unique users per day — last 28 days</h2>
      <span class="when">
        Updated <?= h(date('M j, g:i a', $ga['fetched_at'])) ?> ·
        <a href="?refresh=1">refresh</a>
      </span>
    </div>
    <div class="chart-wrap" id="usersChart">
      <svg viewBox="0 0 <?= $W ?> <?= $H ?>" role="img" aria-label="Line chart of unique users per day for the last 28 days">
        <?php for ($g = 0; $g <= 4; $g++):
            $gy = round($padT + $plotH * $g / 4, 1);
            $gv = (int)round($yTop * (4 - $g) / 4); ?>
          <line x1="<?= $padL ?>" y1="<?= $gy ?>" x2="<?= $W - $padR ?>" y2="<?= $gy ?>"
                stroke="var(--grid)" stroke-width="1"></line>
          <text x="<?= $padL - 8 ?>" y="<?= $gy + 4 ?>" text-anchor="end"
                font-size="11" fill="var(--muted)"><?= number_format($gv) ?></text>
        <?php endfor; ?>
        <line x1="<?= $padL ?>" y1="<?= $baselineY ?>" x2="<?= $W - $padR ?>" y2="<?= $baselineY ?>"
              stroke="var(--baseline)" stroke-width="1"></line>

        <?php foreach ($tipData as $i => $d):
            if ($i % 7 !== 0 && $i !== $n - 1) continue;
            if ($i === $n - 1 && ($n - 1) % 7 < 3 && $n > 7) continue; ?>
          <text x="<?= $d['x'] ?>" y="<?= $H - 8 ?>" text-anchor="middle"
                font-size="11" fill="var(--muted)"><?= h(date('M j', strtotime($d['date']))) ?></text>
        <?php endforeach; ?>

        <path d="<?= $areaPath ?>" fill="var(--series)" opacity="0.10"></path>
        <path d="<?= $linePath ?>" fill="none" stroke="var(--series)" stroke-width="2"
              stroke-linejoin="round" stroke-linecap="round"></path>
        <?php $last = $pts[$n - 1]; ?>
        <circle cx="<?= $last[0] ?>" cy="<?= $last[1] ?>" r="6.5" fill="var(--surface)"></circle>
        <circle cx="<?= $last[0] ?>" cy="<?= $last[1] ?>" r="4.5" fill="var(--series)"></circle>
        <text x="<?= min($last[0] + 10, $W - 4) ?>" y="<?= max($last[1] - 8, 12) ?>"
              font-size="12" font-weight="600" fill="var(--ink)"
              text-anchor="<?= $last[0] > $W - 60 ? 'end' : 'start' ?>"><?= number_format($daily[$n - 1]['users']) ?></text>

        <line id="crosshair" x1="0" y1="<?= $padT ?>" x2="0" y2="<?= $baselineY ?>"
              stroke="var(--baseline)" stroke-width="1" style="display:none"></line>
        <g id="hoverDot" style="display:none">
          <circle r="6.5" fill="var(--surface)"></circle>
          <circle r="4.5" fill="var(--series)"></circle>
        </g>
        <rect x="<?= $padL ?>" y="<?= $padT ?>" width="<?= $plotW ?>" height="<?= $plotH ?>"
              fill="transparent" id="hoverPad"></rect>
      </svg>
      <div class="chart-tip" id="chartTip"></div>
    </div>
  </div>
  <script>
  (function () {
    const data = <?= json_encode(array_values($tipData)) ?>;
    const wrap = document.getElementById('usersChart');
    const svg = wrap.querySelector('svg');
    const pad = document.getElementById('hoverPad');
    const cross = document.getElementById('crosshair');
    const dot = document.getElementById('hoverDot');
    const tip = document.getElementById('chartTip');
    const VBW = <?= $W ?>;

    function show(ev) {
      const rect = svg.getBoundingClientRect();
      const vx = (ev.clientX - rect.left) * VBW / rect.width;
      let best = 0, bestD = Infinity;
      data.forEach((d, i) => {
        const dd = Math.abs(d.x - vx);
        if (dd < bestD) { bestD = dd; best = i; }
      });
      const d = data[best];
      cross.setAttribute('x1', d.x); cross.setAttribute('x2', d.x);
      cross.style.display = '';
      dot.setAttribute('transform', 'translate(' + d.x + ',' + d.y + ')');
      dot.style.display = '';
      const dateStr = new Date(d.date + 'T12:00:00')
        .toLocaleDateString(undefined, { weekday: 'short', month: 'short', day: 'numeric' });
      tip.innerHTML = '<div class="d">' + dateStr + '</div>' +
        '<div><span class="v">' + d.users.toLocaleString() + '</span> unique users</div>' +
        '<div class="d">' + d.pageviews.toLocaleString() + ' page views</div>';
      tip.style.display = 'block';
      const px = d.x * rect.width / VBW;
      const py = d.y * rect.height / <?= $H ?>;
      const tw = tip.offsetWidth;
      tip.style.left = Math.min(Math.max(px - tw / 2, 0), rect.width - tw) + 'px';
      tip.style.top = Math.max(py - tip.offsetHeight - 14, 0) + 'px';
    }
    function hide() {
      cross.style.display = 'none';
      dot.style.display = 'none';
      tip.style.display = 'none';
    }
    pad.addEventListener('mousemove', show);
    pad.addEventListener('mouseleave', hide);
  })();
  </script>

  <div class="grid cols-2">
    <div class="card">
      <h2>Top pages — last 28 days</h2>
      <?php if (empty($ga['top_pages'])): ?>
        <p class="hint">No page data reported yet.</p>
      <?php else: ?>
      <table class="data">
        <thead><tr><th>Page</th><th class="num">Views</th><th class="num">Users</th></tr></thead>
        <tbody>
        <?php foreach ($ga['top_pages'] as $pg): ?>
          <tr>
            <td style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= h($pg['path']) ?></td>
            <td class="num"><?= number_format($pg['views']) ?></td>
            <td class="num"><?= number_format($pg['users']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
    <div class="card">
      <h2>Daily detail</h2>
      <div style="max-height:340px;overflow-y:auto;">
        <table class="data">
          <thead><tr><th>Date</th><th class="num">Users</th><th class="num">Sessions</th><th class="num">Views</th></tr></thead>
          <tbody>
          <?php foreach (array_reverse($ga['daily']) as $d): ?>
            <tr>
              <td><?= h(date('D, M j', strtotime(preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', $d['date'])))) ?></td>
              <td class="num"><?= number_format($d['users']) ?></td>
              <td class="num"><?= number_format($d['sessions']) ?></td>
              <td class="num"><?= number_format($d['pageviews']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

<?php elseif ($gaError !== null): ?>
  <div class="card" style="margin-bottom:16px;">
    <h2>Google Analytics</h2>
    <div class="flash flash-err" style="margin:0 0 10px;">Couldn't load analytics: <?= h($gaError) ?></div>
    <p class="hint">Check the <code class="k">GA4_PROPERTY_ID</code> and <code class="k">GA_SERVICE_ACCOUNT_JSON</code> GitHub Actions secrets and confirm the service account has Viewer access on the GA4 property.</p>
  </div>
<?php else: ?>
  <div class="card" style="margin-bottom:16px;">
    <h2>Google Analytics — not connected yet</h2>
    <p class="hint">Once connected, this dashboard shows unique users per day, sessions, page views, and top pages.</p>
    <ol class="steps">
      <li>In <a href="https://analytics.google.com/" target="_blank" rel="noopener">Google Analytics</a>, find the GA4 property for 3mensio.marktuttlemd.com (measurement ID <code class="k">G-8LDBKP7S8S</code>) and note its numeric <b>Property ID</b> (Admin → Property settings).</li>
      <li>In <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a>, create a project, enable the <b>Google Analytics Data API</b>, and create a <b>service account</b> (no roles needed). Create a <b>JSON key</b> for it and download it.</li>
      <li>Back in Google Analytics: Admin → Property access management → add the service account's email address with <b>Viewer</b> access.</li>
      <li>Set the <code class="k">GA4_PROPERTY_ID</code> and <code class="k">GA_SERVICE_ACCOUNT_JSON</code> secrets on the <code class="k">3mensioXMLParser</code> GitHub repo and redeploy.</li>
    </ol>
  </div>
<?php endif; ?>
<?php admin_footer();
```

- [ ] **Step 2: Verify the dashboard renders the "not connected" state when logged in (no local GA secrets are set — Task 4's verification confirmed `ga_configured()` is `false` locally)**

Reuse the cookie jar from Task 3 Step 6 (already holds an authenticated session):
```bash
curl -s -b /tmp/3mensio-cookies.txt -H "Host: 3mensioxmlparser.nexus.local" http://localhost/admin/index.php \
  | grep -o "Google Analytics — not connected yet"
```
Expected: the string is found (confirms `require_login()` let the authenticated request through, `ga_dashboard_data()` returned `null`, and the onboarding panel rendered without a fatal error).

- [ ] **Step 3: Verify logged-out access still redirects**

```bash
curl -s -o /dev/null -w "%{http_code} %{redirect_url}\n" -H "Host: 3mensioxmlparser.nexus.local" http://localhost/admin/index.php
```
Expected: `302 http://3mensioxmlparser.nexus.local/admin/login.php` (or similar — confirms `require_login()` redirects unauthenticated requests).

- [ ] **Step 4: Commit**

```bash
git add htdocs/admin/index.php
git commit -m "Add GA dashboard page"
```

---

## Task 6: Deploy pipeline — secrets templates and `deploy.yml`

**Files:**
- Create: `deploy/secrets/identity-db-config.php.template`
- Create: `deploy/secrets/ga-config.php.template`
- Modify: `.github/workflows/deploy.yml`

**Interfaces:**
- Produces at deploy time: `secrets/identity-db-config.php` and `secrets/ga-config.php` outside the webroot on IONOS, in the exact shape `identity_config.php`/`ga_config.php` (Tasks 2 and 4) expect.

- [ ] **Step 1: Write `deploy/secrets/identity-db-config.php.template`**

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

- [ ] **Step 2: Write `deploy/secrets/ga-config.php.template`**

```php
<?php
return [
    'GA4_PROPERTY_ID' => '$GA4_PROPERTY_ID',
    'GA_SERVICE_ACCOUNT_JSON' => '$GA_SERVICE_ACCOUNT_JSON',
];
```

- [ ] **Step 3: Replace `.github/workflows/deploy.yml` with the full updated pipeline**

```yaml
name: Deploy to IONOS

on:
  workflow_dispatch:
  ## push:
  ##  branches: [ "main" ]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Show source and target
        run: |
          echo "Repository: $GITHUB_REPOSITORY"
          echo "Branch: $GITHUB_REF_NAME"
          echo "Workspace: $GITHUB_WORKSPACE"
          echo "Target: ${{ secrets.IONOS_TARGET }}"
          ls -la "$GITHUB_WORKSPACE"

      - name: Set up SSH
        run: |
          mkdir -p ~/.ssh
          printf '%s\n' "${{ secrets.IONOS_SSH_KEY }}" > ~/.ssh/ionos_deploy_key
          chmod 600 ~/.ssh/ionos_deploy_key
          ssh-keyscan -p "${{ secrets.IONOS_PORT || 22 }}" "${{ secrets.IONOS_HOST }}" >> ~/.ssh/known_hosts

      - name: Test SSH
        run: |
          ssh -i ~/.ssh/ionos_deploy_key -o IdentitiesOnly=yes -o BatchMode=yes \
            -p "${{ secrets.IONOS_PORT || 22 }}" \
            "${{ secrets.IONOS_USER }}@${{ secrets.IONOS_HOST }}" \
            "echo SSH_OK && whoami"

      - name: Deploy via rsync
        run: |
          rsync -az --delete \
            --exclude '.git/' \
            --exclude '.github/' \
            --exclude 'deploy/' \
            --exclude 'secrets/' \
            --exclude 'cache/' \
            --exclude 'htdocs/admin/local.marker' \
            -e "ssh -i ~/.ssh/ionos_deploy_key -o IdentitiesOnly=yes -p ${{ secrets.IONOS_PORT || 22 }}" \
            ./ "${{ secrets.IONOS_USER }}@${{ secrets.IONOS_HOST }}:${{ secrets.IONOS_TARGET }}/"

      - name: Render identity secrets file
        env:
          SINGLE_AUTH_DB_HOST: ${{ secrets.SINGLE_AUTH_DB_HOST }}
          SINGLE_AUTH_DB_PORT: ${{ secrets.SINGLE_AUTH_DB_PORT }}
          SINGLE_AUTH_DB_NAME: ${{ secrets.SINGLE_AUTH_DB_NAME }}
          SINGLE_AUTH_DB_USER: ${{ secrets.SINGLE_AUTH_DB_USER }}
          SINGLE_AUTH_DB_PASS: ${{ secrets.SINGLE_AUTH_DB_PASS }}
        run: |
          set -euo pipefail
          : "${SINGLE_AUTH_DB_HOST:?}" "${SINGLE_AUTH_DB_PORT:?}" "${SINGLE_AUTH_DB_NAME:?}" "${SINGLE_AUTH_DB_USER:?}" "${SINGLE_AUTH_DB_PASS:?}"
          command -v envsubst >/dev/null || (sudo apt-get update && sudo apt-get install -y gettext-base)
          envsubst '$SINGLE_AUTH_DB_HOST $SINGLE_AUTH_DB_PORT $SINGLE_AUTH_DB_NAME $SINGLE_AUTH_DB_USER $SINGLE_AUTH_DB_PASS' \
            < deploy/secrets/identity-db-config.php.template > /tmp/identity-db-config.php

      - name: Render GA secrets file
        env:
          GA4_PROPERTY_ID: ${{ secrets.GA4_PROPERTY_ID }}
          GA_SERVICE_ACCOUNT_JSON: ${{ secrets.GA_SERVICE_ACCOUNT_JSON }}
        run: |
          set -euo pipefail
          : "${GA4_PROPERTY_ID:?}" "${GA_SERVICE_ACCOUNT_JSON:?}"
          envsubst '$GA4_PROPERTY_ID $GA_SERVICE_ACCOUNT_JSON' \
            < deploy/secrets/ga-config.php.template > /tmp/ga-config.php

      - name: Ship identity secrets file outside webroot
        env:
          IONOS_SECRETS_PATH: ${{ secrets.IONOS_SECRETS_PATH }}
          PROJECT_DIR: 3mensio
        run: |
          REMOTE_SECRETS_PATH="$PROJECT_DIR/$IONOS_SECRETS_PATH"
          printf -- '-mkdir %s\nchmod 700 %s\nput /tmp/identity-db-config.php %s/identity-db-config.php\nchmod 600 %s/identity-db-config.php\n' \
            "$REMOTE_SECRETS_PATH" "$REMOTE_SECRETS_PATH" "$REMOTE_SECRETS_PATH" "$REMOTE_SECRETS_PATH" \
            > /tmp/sftp-batch-identity.txt
          sftp -i ~/.ssh/ionos_deploy_key -o IdentitiesOnly=yes -o BatchMode=yes \
            -P "${{ secrets.IONOS_PORT || 22 }}" \
            -b /tmp/sftp-batch-identity.txt \
            "${{ secrets.IONOS_USER }}@${{ secrets.IONOS_HOST }}"

      - name: Ship GA secrets file outside webroot
        env:
          IONOS_SECRETS_PATH: ${{ secrets.IONOS_SECRETS_PATH }}
          PROJECT_DIR: 3mensio
        run: |
          REMOTE_SECRETS_PATH="$PROJECT_DIR/$IONOS_SECRETS_PATH"
          printf -- '-mkdir %s\nchmod 700 %s\nput /tmp/ga-config.php %s/ga-config.php\nchmod 600 %s/ga-config.php\n' \
            "$REMOTE_SECRETS_PATH" "$REMOTE_SECRETS_PATH" "$REMOTE_SECRETS_PATH" "$REMOTE_SECRETS_PATH" \
            > /tmp/sftp-batch-ga.txt
          sftp -i ~/.ssh/ionos_deploy_key -o IdentitiesOnly=yes -o BatchMode=yes \
            -P "${{ secrets.IONOS_PORT || 22 }}" \
            -b /tmp/sftp-batch-ga.txt \
            "${{ secrets.IONOS_USER }}@${{ secrets.IONOS_HOST }}"
```

- [ ] **Step 4: Lint the workflow YAML**

Run:
```bash
docker run --rm -v "$PWD:/repo" -w /repo rhysd/actionlint:latest .github/workflows/deploy.yml
```
Expected: no errors. (If `actionlint` isn't available/pullable in this environment, at minimum validate it parses as YAML: `python3 -c "import yaml; yaml.safe_load(open('.github/workflows/deploy.yml'))"` should exit 0.)

- [ ] **Step 5: Commit**

```bash
git add deploy/secrets/identity-db-config.php.template deploy/secrets/ga-config.php.template .github/workflows/deploy.yml
git commit -m "Add secrets-rendering deploy steps for identity DB and GA config"
```

---

## Task 7: Set remaining GitHub secrets, deploy, and verify production

`GA4_PROPERTY_ID` and `GA_SERVICE_ACCOUNT_JSON` are already set on the repo. `SINGLE_AUTH_DB_*` and `IONOS_SECRETS_PATH` are not.

**Files:** none (operational task).

- [ ] **Step 1: Pull the shared single-auth DB credentials from 1Password and set them as secrets on `coder999/3mensioXMLParser`**

```bash
for field in DB_HOST DB_PORT DB_NAME DB_USER DB_PASS; do
  value=$(op item get "IONOS-SINGLE-AUTH" --vault CLI --fields "$field" --reveal)
  gh secret set "SINGLE_AUTH_$field" --repo coder999/3mensioXMLParser --body "$value"
done
gh secret set IONOS_SECRETS_PATH --repo coder999/3mensioXMLParser --body "secrets"
```

- [ ] **Step 2: Verify all required secrets are present**

```bash
gh secret list --repo coder999/3mensioXMLParser
```
Expected: `SINGLE_AUTH_DB_HOST`, `SINGLE_AUTH_DB_PORT`, `SINGLE_AUTH_DB_NAME`, `SINGLE_AUTH_DB_USER`, `SINGLE_AUTH_DB_PASS`, `IONOS_SECRETS_PATH`, `GA4_PROPERTY_ID`, `GA_SERVICE_ACCOUNT_JSON`, plus the pre-existing `IONOS_HOST`/`IONOS_PORT`/`IONOS_SSH_KEY`/`IONOS_TARGET`/`IONOS_USER`.

- [ ] **Step 3: Push the branch and open a PR**

```bash
git push -u origin admin-single-auth-ga
gh pr create --repo coder999/3mensioXMLParser --base main \
  --title "Add admin section: single-auth login + GA dashboard" \
  --body "Implements docs/superpowers/specs/2026-08-13-admin-single-auth-ga-design.md. See docs/superpowers/plans/2026-08-13-admin-single-auth-ga.md for task-by-task detail."
```

- [ ] **Step 4: Confirm with the project owner before triggering a production deploy**

This runs `workflow_dispatch` against production IONOS hosting and writes real credentials there — pause here and get an explicit go-ahead before running Step 5, even though the design was already approved.

- [ ] **Step 5: Merge, then trigger the deploy workflow**

```bash
gh pr merge --repo coder999/3mensioXMLParser --squash
gh workflow run deploy.yml --repo coder999/3mensioXMLParser --ref main
gh run watch --repo coder999/3mensioXMLParser
```
Expected: the run completes successfully (green checkmarks on all steps, including the two new secret-rendering/shipping steps).

- [ ] **Step 6: Verify production — logged out**

```bash
curl -s -o /dev/null -w "%{http_code} %{redirect_url}\n" https://3mensio.marktuttlemd.com/admin/
```
Expected: a redirect to `login.php`.

- [ ] **Step 7: Verify production — real SSO and live GA data (requires the project owner, since it depends on their own already-authenticated browser session)**

Ask the project owner to, while already logged in at `marktuttlemd.com/admin`, visit `https://3mensio.marktuttlemd.com/admin/` and confirm:
- they land on the dashboard directly, without seeing a login form (proves the shared `.marktuttlemd.com` cookie carried the session over), and
- the GA tiles/chart/top-pages table show real data (proves `GA4_PROPERTY_ID`/`GA_SERVICE_ACCOUNT_JSON` are correct and the service account has Viewer access).

If the GA panel instead shows "Couldn't load analytics", the error message names the problem (bad property ID vs. permission-denied vs. malformed JSON) — fix and redeploy.
