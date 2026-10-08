<?php
require_once __DIR__ . '/includes/functions.php';
$pageTitle = setting('site_title', 'Event Evaluation and Feedback Management System');
$adminArea = false;
$events = db()->query("SELECT * FROM events WHERE status='open' ORDER BY event_date ASC, start_time ASC")->fetchAll();
require __DIR__ . '/includes/header.php';
?>
<section class="hero">
  <div class="eyebrow" style="color:#d7f5ff">Activity • Seminar • Workshop • Training</div>
  <h1><?= h(setting('welcome_text', 'Your Feedback Helps Us Improve')) ?></h1>
  <p>Share your experience and help organizers improve future programs. Select an open evaluation below, answer the form, and submit your feedback securely.</p>
  <div class="hero-actions">
    <a class="btn light" href="#open-evaluations">View Open Evaluations</a>
    <a class="btn secondary" href="login.php">Administrator Login</a>
  </div>
</section>

<div class="section-title" id="open-evaluations">
  <div><h2>Open Evaluations</h2><p>Currently available activities and programs accepting feedback.</p></div>
  <span class="pill"><?= count($events) ?> available</span>
</div>

<?php if (!$events): ?>
  <div class="card empty">There are no open evaluations at the moment.</div>
<?php else: ?>
  <div class="grid grid-3">
    <?php foreach ($events as $event): ?>
      <article class="card event-card">
        <div class="topline"></div>
        <div class="body">
          <div class="eyebrow"><?= h($event['event_type']) ?></div>
          <h3><?= h($event['title']) ?></h3>
          <div class="event-meta">
            <span>📅 <?= h(date('F j, Y', strtotime($event['event_date']))) ?></span>
            <?php if ($event['venue']): ?><span>📍 <?= h($event['venue']) ?></span><?php endif; ?>
            <?php if ($event['organizer']): ?><span>🏛 <?= h($event['organizer']) ?></span><?php endif; ?>
            <?php if ($event['speaker']): ?><span>🎤 <?= h($event['speaker']) ?></span><?php endif; ?>
          </div>
          <div class="actions" style="margin-top:16px">
            <?php if (event_is_open($event)): ?>
              <a class="btn" href="evaluate.php?event=<?= urlencode($event['code']) ?>">Evaluate Now</a>
              <span class="status open">Open</span>
            <?php else: ?>
              <span class="status closed">Not Available Yet</span>
            <?php endif; ?>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="section-title"><div><h2>How It Works</h2><p>A simple three-step evaluation process.</p></div></div>
<div class="grid grid-3">
  <div class="card card-pad"><div class="eyebrow">Step 1</div><h3>Select an Event</h3><p class="muted">Choose the activity, seminar, workshop, or training you attended.</p></div>
  <div class="card card-pad"><div class="eyebrow">Step 2</div><h3>Complete the Form</h3><p class="muted">Rate the program and provide comments or recommendations.</p></div>
  <div class="card card-pad"><div class="eyebrow">Step 3</div><h3>Submit Feedback</h3><p class="muted">Your response is saved securely and included in the event report.</p></div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
