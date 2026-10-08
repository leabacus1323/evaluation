<?php
require_once __DIR__ . '/includes/functions.php';
$event = get_event_by_code(trim((string)($_GET['event'] ?? '')));
$pageTitle = 'Thank You';
$adminArea = false;
require __DIR__ . '/includes/header.php';
?>
<div class="login-wrap">
  <div class="card card-pad center">
    <div style="font-size:3rem">✓</div>
    <h1>Thank you for your feedback.</h1>
    <p class="muted">Your evaluation has been successfully submitted<?= $event ? ' for <strong>' . h($event['title']) . '</strong>' : '' ?>. Your response will help improve future programs and activities.</p>
    <a class="btn" href="index.php">Return to Home</a>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
