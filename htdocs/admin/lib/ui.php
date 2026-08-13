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
