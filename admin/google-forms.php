<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();
ensure_google_integration_schema();
$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $eventId = (int)($_POST['event_id'] ?? 0);
    if ($eventId <= 0 || !get_event_by_id($eventId)) {
        flash('Event not found.', 'error');
        redirect('google-forms.php');
    }

    if (isset($_POST['generate_google_form'])) {
        $result = generate_google_form_for_event($eventId);
        if (!empty($result['ok'])) {
            flash('Google Form and linked Google Sheets response file created successfully.');
        } else {
            flash('Google Form generation failed: ' . ($result['error'] ?? 'Unknown error.'), 'error');
        }
        redirect('google-forms.php?event_id=' . $eventId);
    }

    if (isset($_POST['sync_google_status'])) {
        $result = sync_google_form_status($eventId);
        if (!empty($result['ok'])) {
            flash($result['message'] ?? 'Google Form status synchronized.');
        } else {
            flash('Could not synchronize Google Form status: ' . ($result['error'] ?? 'Unknown error.'), 'error');
        }
        redirect('google-forms.php?event_id=' . $eventId);
    }
}

$events = $pdo->query("SELECT e.*,
    (SELECT COUNT(*) FROM questions q WHERE q.event_id=e.id) AS question_count,
    (SELECT COUNT(*) FROM respondents r WHERE r.event_id=e.id) AS local_responses
    FROM events e ORDER BY e.event_date DESC,e.id DESC")->fetchAll();

$focusId = (int)($_GET['event_id'] ?? 0);
$pageTitle = 'Google Forms';
$adminArea = true;
require __DIR__ . '/../includes/header.php';
?>
<div class="section-title">
  <div><h1>Google Forms &amp; Drive</h1><p>Generate a Google Form from an event's uploaded questions and automatically link responses to Google Sheets in Drive.</p></div>
  <a class="btn ghost" href="settings.php#google-integration">Google Settings</a>
</div>

<?php if (!google_integration_configured()): ?>
<div class="alert warning">
  Google integration is not configured yet. Open <a href="settings.php#google-integration"><strong>Settings → Google Forms &amp; Drive Integration</strong></a>, then add the Apps Script Web App URL and shared secret.
</div>
<?php endif; ?>

<div class="card card-pad" style="margin-bottom:16px">
  <div class="grid grid-3">
    <div><div class="eyebrow">How it works</div><strong>1. Configure Form</strong><div class="muted">Edit Participant Information and upload the questionnaire.</div></div>
    <div><div class="eyebrow">Generate</div><strong>2. Create Google Form</strong><div class="muted">Participant fields and evaluation questions are converted automatically to Google Forms items.</div></div>
    <div><div class="eyebrow">Google Drive</div><strong>3. Collect Responses</strong><div class="muted">Google Form responses are linked to a Google Sheets file in Drive.</div></div>
  </div>
</div>

<div class="grid grid-2">
<?php if (!$events): ?>
  <div class="card empty">No events available. Create an event first.</div>
<?php else: foreach ($events as $e): $generated = !empty($e['google_form_id']); ?>
  <div class="card event-card" id="event-<?= (int)$e['id'] ?>" style="<?= $focusId === (int)$e['id'] ? 'outline:3px solid var(--brand);outline-offset:2px' : '' ?>">
    <div class="topline"></div>
    <div class="body">
      <div class="eyebrow"><?= h($e['event_type']) ?> · <?= h($e['code']) ?></div>
      <h3><?= h($e['title']) ?></h3>
      <p class="muted"><?= h(date('M j, Y', strtotime($e['event_date']))) ?> · <?= (int)$e['question_count'] ?> questions · Local status: <strong><?= h(ucfirst($e['status'])) ?></strong></p>

      <?php if ($generated): ?>
        <div class="alert success" style="margin:12px 0">
          <strong>Google Form generated.</strong><br>
          <span class="muted">Last generated: <?= $e['google_generated_at'] ? h(date('M j, Y g:i A', strtotime($e['google_generated_at']))) : '—' ?></span>
        </div>
        <div class="actions" style="margin-bottom:12px">
          <?php if (!empty($e['google_form_url'])): ?><a class="btn small" target="_blank" rel="noopener" href="<?= h($e['google_form_url']) ?>">Open Form</a><?php endif; ?>
          <?php if (!empty($e['google_form_edit_url'])): ?><a class="btn small ghost" target="_blank" rel="noopener" href="<?= h($e['google_form_edit_url']) ?>">Edit Form</a><?php endif; ?>
          <?php if (!empty($e['google_sheet_url'])): ?><a class="btn small ghost" target="_blank" rel="noopener" href="<?= h($e['google_sheet_url']) ?>">Response Sheet</a><?php endif; ?>
          <?php if (!empty($e['google_form_summary_url'])): ?><a class="btn small ghost" target="_blank" rel="noopener" href="<?= h($e['google_form_summary_url']) ?>">Summary</a><?php endif; ?>
        </div>
      <?php else: ?>
        <div class="alert warning" style="margin:12px 0">No Google Form has been generated for this event.</div>
      <?php endif; ?>

      <div class="actions">
        <a class="btn small ghost" href="participant-fields.php?event_id=<?= (int)$e['id'] ?>">Participant Info</a><a class="btn small ghost" href="questions.php?event_id=<?= (int)$e['id'] ?>">Manage Questions</a>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
          <button class="small" name="generate_google_form" value="1" <?= !$e['question_count'] || !google_integration_configured() ? 'disabled' : '' ?> <?= $generated ? 'data-confirm="Generate a new Google Form and response Sheet? The old Google files will remain in Drive."' : '' ?>><?= $generated ? 'Regenerate Google Form' : 'Generate Google Form' ?></button>
        </form>
        <?php if ($generated): ?>
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
          <button class="small secondary" name="sync_google_status" value="1">Sync <?= $e['status'] === 'open' ? 'Open' : 'Closed' ?> Status</button>
        </form>
        <?php endif; ?>
      </div>
      <?php if (!$e['question_count']): ?><p class="muted" style="margin-bottom:0">Upload questions before generating a Google Form.</p><?php endif; ?>
    </div>
  </div>
<?php endforeach; endif; ?>
</div>

<div class="card card-pad" style="margin-top:16px">
  <h3 style="margin-top:0">Important</h3>
  <p class="muted" style="margin-bottom:0">The Google Form is a separate response channel. Responses submitted through the website remain in MySQL; responses submitted through the generated Google Form are stored in the linked Google Sheets workbook in Google Drive. Use the Google Sheet for Google Form responses.</p>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
