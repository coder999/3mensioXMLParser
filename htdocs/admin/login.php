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
