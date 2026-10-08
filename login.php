<?php
require_once __DIR__ . '/includes/functions.php';
if (!empty($_SESSION['admin_id'])) redirect('admin/dashboard.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $stmt = db()->prepare('SELECT * FROM admins WHERE username=? LIMIT 1');
    $stmt->execute([$username]);
    $admin = $stmt->fetch();
    if ($admin && password_verify($password, $admin['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$admin['id'];
        flash('Welcome back, ' . $admin['display_name'] . '.');
        redirect('admin/dashboard.php');
    }
    flash('Invalid username or password.', 'error');
    redirect('login.php');
}

$pageTitle = 'Administrator Login';
$adminArea = false;
require __DIR__ . '/includes/header.php';
?>
<div class="login-wrap">
  <div class="card card-pad">
    <div class="eyebrow">Secure Access</div>
    <h1 style="margin:5px 0 6px">Administrator Login</h1>
    <p class="muted">Create events, build evaluation forms, review responses, and generate reports.</p>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <div class="field"><label>Username</label><input type="text" name="username" required autocomplete="username"></div>
      <div class="field" style="margin-top:12px"><label>Password</label><input type="password" name="password" required autocomplete="current-password"></div>
      <button class="full" style="margin-top:16px">Log In</button>
    </form>
    <p class="muted" style="font-size:.84rem;margin-bottom:0">Default after installation: <strong>admin</strong> / <strong>admin123</strong>. Change the password immediately in Settings.</p>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
