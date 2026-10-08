<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();
$st=db()->query("SELECT e.id,e.code,e.title,e.event_type,e.status,e.event_date,COUNT(DISTINCT c.id) AS categories,COUNT(DISTINCT q.id) AS questions FROM events e LEFT JOIN evaluation_categories c ON c.event_id=e.id LEFT JOIN questions q ON q.event_id=e.id GROUP BY e.id,e.code,e.title,e.event_type,e.status,e.event_date ORDER BY e.event_date DESC,e.id DESC");
$events=$st->fetchAll();
$pageTitle='Evaluation Forms';$adminArea=true;require __DIR__.'/../includes/header.php';
?>
<div class="section-title"><div><h1>Evaluation Forms</h1><p>Upload a question file for each event, then review or edit the imported questionnaire.</p></div></div>
<div class="grid grid-3">
<?php if(!$events): ?><div class="card empty">No events available.</div><?php else: foreach($events as $e): ?>
<div class="card event-card"><div class="topline"></div><div class="body"><div class="eyebrow"><?= h($e['event_type']) ?> · <?= h($e['code']) ?></div><h3><?= h($e['title']) ?></h3><p class="muted"><?= (int)$e['categories'] ?> categories · <?= (int)$e['questions'] ?> questions</p><div class="actions"><a class="btn ghost" href="participant-fields.php?event_id=<?= (int)$e['id'] ?>">Participant Information</a><a class="btn" href="questions.php?event_id=<?= (int)$e['id'] ?>">Upload / Manage Questions</a><a class="btn ghost" target="_blank" href="../evaluate.php?event=<?= urlencode($e['code']) ?>">Preview</a><a class="btn ghost" href="google-forms.php?event_id=<?= (int)$e['id'] ?>">Google Form</a></div></div></div>
<?php endforeach; endif; ?>
</div>
<?php require __DIR__.'/../includes/footer.php'; ?>
