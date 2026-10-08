<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();
$pdo = db();
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    $id=(int)($_POST['id']??0);
    if (isset($_POST['delete']) && $id>0) {
        $stmt=$pdo->prepare('DELETE FROM events WHERE id=?');$stmt->execute([$id]);
        flash('Event deleted successfully.');redirect('events.php');
    }
    if (isset($_POST['set_status']) && $id>0) {
        $status=in_array($_POST['status']??'',['draft','open','closed'],true)?$_POST['status']:'draft';
        $stmt=$pdo->prepare('UPDATE events SET status=? WHERE id=?');$stmt->execute([$status,$id]);
        flash('Event status updated.');redirect('events.php');
    }
}
$events=$pdo->query("SELECT e.*,COUNT(DISTINCT r.id) responses,AVG(ra.answer_numeric) mean_score FROM events e LEFT JOIN respondents r ON r.event_id=e.id LEFT JOIN response_answers ra ON ra.respondent_id=r.id GROUP BY e.id ORDER BY e.event_date DESC,e.id DESC")->fetchAll();
$pageTitle='Events';$adminArea=true;require __DIR__.'/../includes/header.php';
?>
<div class="section-title"><div><h1>Events</h1><p>Create and manage activities, seminars, workshops, training programs, and their evaluations.</p></div><a class="btn" href="event-create.php">+ Create Event</a></div>
<div class="card card-pad">
  <div class="search-row"><input type="search" placeholder="Search events..." data-table-search="#eventsTable"><span class="pill"><?= count($events) ?> events</span></div>
  <div class="table-wrap" style="margin-top:14px"><table class="table" id="eventsTable"><thead><tr><th>Event</th><th>Date</th><th>Venue</th><th>Status</th><th class="text-right">Responses</th><th class="text-right">Average</th><th>Actions</th></tr></thead><tbody>
  <?php if(!$events): ?><tr><td colspan="7" class="empty">No events yet.</td></tr><?php else: foreach($events as $e): ?>
  <tr><td><strong><?= h($e['title']) ?></strong><div class="muted"><?= h($e['event_type']) ?> · <?= h($e['code']) ?></div></td><td><?= h(date('M j, Y',strtotime($e['event_date']))) ?></td><td><?= h($e['venue']?:'—') ?></td><td><span class="status <?= h($e['status']) ?>"><?= h(ucfirst($e['status'])) ?></span></td><td class="text-right"><?= (int)$e['responses'] ?></td><td class="text-right"><?= $e['mean_score']!==null?number_format((float)$e['mean_score'],2):'—' ?></td><td><div class="actions"><a class="btn small" href="event-edit.php?id=<?= (int)$e['id'] ?>">Edit</a><a class="btn small ghost" href="participant-fields.php?event_id=<?= (int)$e['id'] ?>">Participant Info</a><a class="btn small ghost" href="questions.php?event_id=<?= (int)$e['id'] ?>">Questions</a><a class="btn small ghost" href="google-forms.php?event_id=<?= (int)$e['id'] ?>">Google Form</a><a class="btn small ghost" href="analytics.php?event_id=<?= (int)$e['id'] ?>">Analytics</a><a class="btn small ghost" href="reports.php?event_id=<?= (int)$e['id'] ?>">Report</a><a class="btn small ghost" href="qr.php?event_id=<?= (int)$e['id'] ?>">QR</a><form method="post" style="display:inline"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="status" value="<?= $e['status']==='open'?'closed':'open' ?>"><button class="small <?= $e['status']==='open'?'secondary':'' ?>" name="set_status" value="1"><?= $e['status']==='open'?'Close':'Open' ?></button></form></div></td></tr>
  <?php endforeach; endif; ?></tbody></table></div>
</div>
<?php require __DIR__.'/../includes/footer.php'; ?>
