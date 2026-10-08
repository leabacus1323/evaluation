<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();
$pdo = db();
$admin = current_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (isset($_POST['save_site'])) {
        set_setting('site_title', trim((string)$_POST['site_title']));
        set_setting('organization_name', trim((string)$_POST['organization_name']));
        $color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)$_POST['primary_color']) ? $_POST['primary_color'] : '#0b6e8e';
        set_setting('primary_color', $color);
        set_setting('welcome_text', trim((string)$_POST['welcome_text']));
        flash('Site settings updated.');
        redirect('settings.php');
    }

    if (isset($_POST['save_google'])) {
        $url = trim((string)($_POST['google_apps_script_url'] ?? ''));
        $folder = trim((string)($_POST['google_drive_folder_id'] ?? ''));
        $secret = trim((string)($_POST['google_apps_script_secret'] ?? ''));

        if ($url !== '' && !preg_match('#^https://script\.google\.com/.*?/exec(?:\?.*)?$#i', $url)) {
            flash('Enter the deployed Google Apps Script Web App URL ending in /exec.', 'error');
            redirect('settings.php#google-integration');
        }
        set_setting('google_apps_script_url', $url);
        set_setting('google_drive_folder_id', $folder);
        if ($secret !== '') {
            if (strlen($secret) < 16) {
                flash('The Google shared secret should be at least 16 characters.', 'error');
                redirect('settings.php#google-integration');
            }
            set_setting('google_apps_script_secret', $secret);
        }
        flash('Google Forms & Drive settings saved.');
        redirect('settings.php#google-integration');
    }

    if (isset($_POST['test_google'])) {
        $result = google_bridge_request(['action' => 'ping']);
        if (!empty($result['ok'])) {
            flash($result['message'] ?? 'Google integration is connected.');
        } else {
            flash('Google connection test failed: ' . ($result['error'] ?? 'Unknown error.'), 'error');
        }
        redirect('settings.php#google-integration');
    }

    if (isset($_POST['clear_google_secret'])) {
        set_setting('google_apps_script_secret', '');
        flash('Google shared secret cleared.');
        redirect('settings.php#google-integration');
    }

    if (isset($_POST['change_password'])) {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        $st = $pdo->prepare('SELECT password_hash FROM admins WHERE id=?');
        $st->execute([$admin['id']]);
        $hash = $st->fetchColumn();
        if (!$hash || !password_verify($current, $hash)) {
            flash('Current password is incorrect.', 'error');
        } elseif (strlen($new) < 8) {
            flash('New password must be at least 8 characters.', 'error');
        } elseif ($new !== $confirm) {
            flash('New passwords do not match.', 'error');
        } else {
            $pdo->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($new, PASSWORD_DEFAULT), $admin['id']]);
            flash('Administrator password changed successfully.');
        }
        redirect('settings.php');
    }
}

$pageTitle = 'Settings';
$adminArea = true;
require __DIR__ . '/../includes/header.php';
$googleUrl = setting('google_apps_script_url', '');
$googleFolder = setting('google_drive_folder_id', '');
$hasGoogleSecret = trim(setting('google_apps_script_secret', '')) !== '';
?>
<div class="section-title"><div><h1>Settings</h1><p>Customize the system identity, Google integration, and administrator security.</p></div></div>

<div class="grid grid-2">
  <div class="card card-pad">
    <h2 style="margin-top:0">Site Settings</h2>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <div class="field"><label>System Title</label><input name="site_title" value="<?= h(setting('site_title','Event Evaluation and Feedback Management System')) ?>" required></div>
      <div class="field" style="margin-top:10px"><label>Organization Name</label><input name="organization_name" value="<?= h(setting('organization_name','Your Institution')) ?>" required></div>
      <div class="field" style="margin-top:10px"><label>Homepage Message</label><input name="welcome_text" value="<?= h(setting('welcome_text','Your Feedback Helps Us Improve')) ?>"></div>
      <div class="field" style="margin-top:10px"><label>Primary Color</label><input type="color" name="primary_color" value="<?= h(setting('primary_color','#0b6e8e')) ?>" style="height:44px"></div>
      <button style="margin-top:14px" name="save_site" value="1">Save Site Settings</button>
    </form>
  </div>

  <div class="card card-pad">
    <h2 style="margin-top:0">Change Administrator Password</h2>
    <p class="muted">Signed in as <?= h($admin['username']) ?>. Use a strong password with at least 8 characters.</p>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <div class="field"><label>Current Password</label><input type="password" name="current_password" required></div>
      <div class="field" style="margin-top:10px"><label>New Password</label><input type="password" name="new_password" minlength="8" required></div>
      <div class="field" style="margin-top:10px"><label>Confirm New Password</label><input type="password" name="confirm_password" minlength="8" required></div>
      <button style="margin-top:14px" name="change_password" value="1">Change Password</button>
    </form>
  </div>
</div>

<div class="card card-pad" id="google-integration" style="margin-top:16px">
  <div class="section-title" style="margin-top:0">
    <div><h2>Google Forms &amp; Drive Integration</h2><p>Generate Google Forms automatically and save Google Form responses to a linked Google Sheets file in Google Drive.</p></div>
    <span class="status <?= google_integration_configured() ? 'open' : 'draft' ?>"><?= google_integration_configured() ? 'Configured' : 'Not Configured' ?></span>
  </div>

  <div class="grid grid-2">
    <div>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <div class="field"><label>Apps Script Web App URL</label><input type="url" name="google_apps_script_url" value="<?= h($googleUrl) ?>" placeholder="https://script.google.com/macros/s/.../exec"></div>
        <div class="field" style="margin-top:10px"><label>Shared Secret</label><input id="googleSecret" type="password" name="google_apps_script_secret" placeholder="<?= $hasGoogleSecret ? 'Secret already saved — leave blank to keep it' : 'Enter the same secret used in Code.gs' ?>" autocomplete="new-password"></div>
        <div class="actions" style="margin-top:8px"><button type="button" class="small ghost" onclick="generateGoogleSecret()">Generate Secret</button><?php if ($hasGoogleSecret): ?><span class="pill">Secret saved</span><?php endif; ?></div>
        <div class="field" style="margin-top:10px"><label>Google Drive Folder ID <span class="muted">(optional)</span></label><input name="google_drive_folder_id" value="<?= h($googleFolder) ?>" placeholder="e.g. 1AbCDEFghiJKLmnoPQR"></div>
        <p class="muted">If a Folder ID is provided, both the generated Google Form and its linked response Sheet are moved into that Drive folder.</p>
        <div class="actions"><button name="save_google" value="1">Save Google Settings</button></div>
      </form>
      <?php if ($hasGoogleSecret): ?>
      <form method="post" style="margin-top:10px">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <button class="small danger" name="clear_google_secret" value="1" data-confirm="Clear the stored Google shared secret? Google generation will stop working until a new secret is saved.">Clear Saved Secret</button>
      </form>
      <?php endif; ?>
    </div>

    <div class="card card-pad" style="box-shadow:none;background:#f8fafc">
      <h3 style="margin-top:0">Setup Checklist</h3>
      <ol class="muted" style="padding-left:20px;line-height:1.8">
        <li>Create a standalone Google Apps Script project.</li>
        <li>Paste <strong>google-apps-script/Code.gs</strong>.</li>
        <li>Set the same long shared secret in Code.gs and here.</li>
        <li>Deploy as a Web App: <strong>Execute as Me</strong>, access <strong>Anyone</strong>.</li>
        <li>Authorize Google Forms, Sheets, and Drive.</li>
        <li>Paste the deployed <strong>/exec</strong> URL here.</li>
      </ol>
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <button name="test_google" value="1" <?= !google_integration_configured() ? 'disabled' : '' ?>>Test Google Connection</button>
      </form>
      <p class="muted" style="margin-bottom:0;margin-top:12px">See <strong>GOOGLE_FORMS_SETUP.txt</strong> in the project package for complete instructions.</p>
    </div>
  </div>
</div>
<script>
function generateGoogleSecret(){
  const bytes=new Uint8Array(24);crypto.getRandomValues(bytes);
  const value=Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');
  const input=document.getElementById('googleSecret');input.type='text';input.value=value;input.select();
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
