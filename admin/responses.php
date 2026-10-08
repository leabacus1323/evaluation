<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();
ensure_participant_profile_schema();
$pdo = db();
$eventId = (int)($_GET['event_id'] ?? 0);

if (isset($_GET['export']) && $_GET['export'] === 'csv' && $eventId > 0) {
    $event = get_event_by_id($eventId);
    if (!$event) exit('Event not found.');
    $questions = get_questions($eventId);
    $profileFields = get_participant_fields($eventId, true);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="evaluation-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $event['code']) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");

    $headers = ['Submitted At', 'Anonymous'];
    foreach ($profileFields as $field) $headers[] = $field['label'];
    foreach ($questions as $q) $headers[] = $q['question_text'];
    fputcsv($out, $headers);

    $st = $pdo->prepare('SELECT * FROM respondents WHERE event_id=? ORDER BY submitted_at');
    $st->execute([$eventId]);
    $aStmt = $pdo->prepare('SELECT question_id,answer_text FROM response_answers WHERE respondent_id=?');
    foreach ($st as $r) {
        $profileMap = participant_profile_answer_map((int)$r['id']);
        $aStmt->execute([$r['id']]);
        $answerMap = [];
        foreach ($aStmt as $a) $answerMap[(int)$a['question_id']] = $a['answer_text'];

        $row = [$r['submitted_at'], $r['is_anonymous'] ? 'Yes' : 'No'];
        foreach ($profileFields as $field) {
            $value = $profileMap[(int)$field['id']] ?? legacy_profile_value($r, $field);
            $row[] = display_profile_answer((string)$value);
        }
        foreach ($questions as $q) $row[] = $answerMap[(int)$q['id']] ?? '';
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

$viewId = (int)($_GET['view'] ?? 0);
$events = $pdo->query('SELECT id,title,code FROM events ORDER BY event_date DESC,id DESC')->fetchAll();
$params = [];
$sql = 'SELECT r.*,e.title AS event_title,e.code AS event_code FROM respondents r JOIN events e ON e.id=r.event_id';
if ($eventId > 0) {
    $sql .= ' WHERE r.event_id=?';
    $params[] = $eventId;
}
$sql .= ' ORDER BY r.submitted_at DESC';
$st = $pdo->prepare($sql);
$st->execute($params);
$responses = $st->fetchAll();

$detail = null;
$detailAnswers = [];
$detailProfileFields = [];
$detailProfileMap = [];
if ($viewId > 0) {
    $st = $pdo->prepare('SELECT r.*,e.title AS event_title FROM respondents r JOIN events e ON e.id=r.event_id WHERE r.id=?');
    $st->execute([$viewId]);
    $detail = $st->fetch();
    if ($detail) {
        $detailProfileFields = get_participant_fields((int)$detail['event_id'], true);
        $detailProfileMap = participant_profile_answer_map($viewId);
        $st = $pdo->prepare('SELECT q.question_text,q.question_type,c.name category_name,ra.answer_text,ra.answer_numeric FROM response_answers ra JOIN questions q ON q.id=ra.question_id LEFT JOIN evaluation_categories c ON c.id=q.category_id WHERE ra.respondent_id=? ORDER BY COALESCE(c.sort_order,9999),q.sort_order,q.id');
        $st->execute([$viewId]);
        $detailAnswers = $st->fetchAll();
    }
}

$pageTitle = 'Responses';
$adminArea = true;
require __DIR__ . '/../includes/header.php';
?>
<div class="section-title">
  <div><h1>Evaluation Responses</h1><p>Review participant submissions and export response data, including custom Participant Information fields.</p></div>
  <?php if ($eventId > 0): ?><a class="btn" href="responses.php?event_id=<?= $eventId ?>&export=csv">Export CSV</a><?php endif; ?>
</div>
<div class="card card-pad">
  <form method="get" class="search-row">
    <select name="event_id" onchange="this.form.submit()" style="max-width:460px"><option value="0">All Events</option><?php foreach ($events as $e): ?><option value="<?= (int)$e['id'] ?>" <?= $eventId === (int)$e['id'] ? 'selected' : '' ?>><?= h($e['title']) ?></option><?php endforeach; ?></select>
    <input type="search" placeholder="Search responses..." data-table-search="#responsesTable">
  </form>
  <div class="table-wrap" style="margin-top:14px">
    <table class="table" id="responsesTable">
      <thead><tr><th>Submitted</th><th>Event</th><th>Respondent</th><th>Type</th><th>Department / Program</th><th>Action</th></tr></thead>
      <tbody>
      <?php if (!$responses): ?><tr><td colspan="6" class="empty">No responses found.</td></tr><?php else: foreach ($responses as $r): ?>
        <tr>
          <td><?= h(date('M j, Y g:i A', strtotime($r['submitted_at']))) ?></td>
          <td><?= h($r['event_title']) ?></td>
          <td><?= $r['is_anonymous'] ? 'Anonymous' : h($r['name'] ?: 'Unnamed') ?></td>
          <td><?= h($r['participant_type'] ?: '—') ?></td>
          <td><?= h(trim(($r['department'] ?: '') . ' / ' . ($r['program'] ?: ''), ' /') ?: '—') ?></td>
          <td><a class="btn small ghost" href="responses.php?event_id=<?= (int)$r['event_id'] ?>&view=<?= (int)$r['id'] ?>">View</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($detail): ?>
<div class="section-title"><div><h2>Response Detail</h2><p><?= h($detail['event_title']) ?> · <?= h(date('M j, Y g:i A', strtotime($detail['submitted_at']))) ?></p></div></div>
<div class="card card-pad">
  <div class="eyebrow">Participant Information</div>
  <?php if (!$detailProfileFields): ?><p class="muted">No participant profile fields were configured.</p><?php else: ?>
  <div class="grid grid-3" style="margin-top:10px">
    <?php foreach ($detailProfileFields as $field):
      $raw = $detailProfileMap[(int)$field['id']] ?? legacy_profile_value($detail, $field);
      if ($raw === '' && !$field['is_active']) continue;
      $shown = display_profile_answer((string)$raw);
    ?>
      <div><strong><?= h($field['label']) ?></strong><div class="muted"><?= $shown !== '' ? h($shown) : '—' ?><?= !$field['is_active'] ? ' (field removed)' : '' ?></div></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div style="margin-top:12px"><span class="status <?= $detail['is_anonymous'] ? 'closed' : 'open' ?>"><?= $detail['is_anonymous'] ? 'Anonymous submission' : 'Identified submission' ?></span></div>
</div>

<?php $lastCat = ''; foreach ($detailAnswers as $a): if ($lastCat !== $a['category_name']) { $lastCat = $a['category_name']; echo '<div class="category-title"><h3>' . h($lastCat ?: 'General') . '</h3></div>'; } ?>
  <div class="question-block"><div class="question-title"><?= h($a['question_text']) ?></div><div><?= h(display_profile_answer((string)$a['answer_text'])) ?><?= $a['answer_numeric'] !== null ? ' <span class="pill">' . h(mean_interpretation((float)$a['answer_numeric'])) . '</span>' : '' ?></div></div>
<?php endforeach; ?>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
