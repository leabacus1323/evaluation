<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();
$pdo = db();
$totalEvents = (int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn();
$openEvents = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE status='open'")->fetchColumn();
$closedEvents = (int)$pdo->query("SELECT COUNT(*) FROM events WHERE status='closed'")->fetchColumn();
$totalResponses = (int)$pdo->query('SELECT COUNT(*) FROM respondents')->fetchColumn();
$todayResponses = (int)$pdo->query('SELECT COUNT(*) FROM respondents WHERE DATE(submitted_at)=CURDATE()')->fetchColumn();
$avg = (float)($pdo->query("SELECT AVG(answer_numeric) FROM response_answers WHERE answer_numeric IS NOT NULL")->fetchColumn() ?: 0);
$events = $pdo->query("SELECT e.*, COUNT(DISTINCT r.id) AS responses, AVG(ra.answer_numeric) AS mean_score FROM events e LEFT JOIN respondents r ON r.event_id=e.id LEFT JOIN response_answers ra ON ra.respondent_id=r.id GROUP BY e.id ORDER BY e.event_date DESC,e.id DESC LIMIT 8")->fetchAll();
$ranking = $pdo->query("SELECT e.id,e.title,AVG(ra.answer_numeric) AS mean_score,COUNT(DISTINCT r.id) AS responses FROM events e LEFT JOIN respondents r ON r.event_id=e.id LEFT JOIN response_answers ra ON ra.respondent_id=r.id GROUP BY e.id HAVING responses>0 ORDER BY mean_score DESC")->fetchAll();
$highest = $ranking[0] ?? null;
$lowest = $ranking ? $ranking[count($ranking)-1] : null;
$typeRows = $pdo->query('SELECT event_type,COUNT(*) AS total FROM events GROUP BY event_type ORDER BY total DESC')->fetchAll();
$responseTrend = $pdo->query("SELECT DATE(submitted_at) AS d,COUNT(*) AS total FROM respondents WHERE submitted_at>=DATE_SUB(CURDATE(),INTERVAL 13 DAY) GROUP BY DATE(submitted_at) ORDER BY d")->fetchAll();
$pageTitle = 'Admin Dashboard';
$adminArea = true;
require __DIR__ . '/../includes/header.php';
?>
<div class="section-title"><div><h1>Administrator Dashboard</h1><p>Monitor evaluations, responses, ratings, and event performance.</p></div><a class="btn" href="event-create.php">+ Create Event</a></div>
<div class="stats">
  <div class="stat-card"><div class="stat-label">Total Events</div><div class="stat-value"><?= $totalEvents ?></div><div class="stat-note">All created programs</div></div>
  <div class="stat-card"><div class="stat-label">Active Evaluations</div><div class="stat-value"><?= $openEvents ?></div><div class="stat-note">Currently open</div></div>
  <div class="stat-card"><div class="stat-label">Closed Evaluations</div><div class="stat-value"><?= $closedEvents ?></div><div class="stat-note">No longer accepting responses</div></div>
  <div class="stat-card"><div class="stat-label">Total Responses</div><div class="stat-value"><?= $totalResponses ?></div><div class="stat-note">All submitted evaluations</div></div>
  <div class="stat-card"><div class="stat-label">Responses Today</div><div class="stat-value"><?= $todayResponses ?></div><div class="stat-note">Received today</div></div>
  <div class="stat-card"><div class="stat-label">Overall Average</div><div class="stat-value"><?= number_format($avg,2) ?></div><div class="stat-note"><?= h(mean_interpretation($avg)) ?></div></div>
  <div class="stat-card"><div class="stat-label">Highest Rated</div><div class="stat-value" style="font-size:1.1rem"><?= h($highest['title'] ?? '—') ?></div><div class="stat-note"><?= $highest ? number_format((float)$highest['mean_score'],2).' · '.mean_interpretation((float)$highest['mean_score']) : 'No responses yet' ?></div></div>
  <div class="stat-card"><div class="stat-label">Lowest Rated</div><div class="stat-value" style="font-size:1.1rem"><?= h($lowest['title'] ?? '—') ?></div><div class="stat-note"><?= $lowest ? number_format((float)$lowest['mean_score'],2).' · '.mean_interpretation((float)$lowest['mean_score']) : 'No responses yet' ?></div></div>
</div>

<div class="grid grid-2" style="margin-top:16px">
  <div class="card chart-card"><h3 style="margin-top:0">Responses Over Last 14 Days</h3><canvas id="trendChart"></canvas></div>
  <div class="card chart-card"><h3 style="margin-top:0">Event Type Distribution</h3><canvas id="typeChart"></canvas></div>
</div>
<div class="card chart-card" style="margin-top:16px"><h3 style="margin-top:0">Average Rating per Event</h3><canvas id="eventMeanChart"></canvas></div>

<div class="grid grid-2" style="margin-top:16px">
  <div class="card card-pad">
    <div class="section-title" style="margin-top:0"><div><h2>Recent Events</h2><p>Quick access to evaluation management.</p></div></div>
    <?php if (!$events): ?><div class="empty">No events created yet.</div><?php else: ?>
    <div class="table-wrap"><table class="table"><thead><tr><th>Event</th><th>Status</th><th class="text-right">Responses</th><th class="text-right">Average</th></tr></thead><tbody>
    <?php foreach ($events as $e): ?><tr><td><a href="event-edit.php?id=<?= (int)$e['id'] ?>"><strong><?= h($e['title']) ?></strong></a><div class="muted"><?= h($e['event_type']) ?> · <?= h(date('M j, Y',strtotime($e['event_date']))) ?></div></td><td><span class="status <?= h($e['status']) ?>"><?= h(ucfirst($e['status'])) ?></span></td><td class="text-right"><?= (int)$e['responses'] ?></td><td class="text-right"><?= $e['mean_score']!==null ? number_format((float)$e['mean_score'],2) : '—' ?></td></tr><?php endforeach; ?>
    </tbody></table></div><?php endif; ?>
  </div>
  <div class="card card-pad">
    <div class="section-title" style="margin-top:0"><div><h2>Evaluation Performance</h2><p>Events ranked by mean Likert score.</p></div></div>
    <div class="rank-list">
      <?php if (!$ranking): ?><div class="empty">No evaluation data yet.</div><?php else: foreach (array_slice($ranking,0,6) as $i=>$r): ?>
        <div class="rank-row"><div class="rank-no"><?= $i+1 ?></div><div><strong><?= h($r['title']) ?></strong><div class="muted"><?= (int)$r['responses'] ?> responses</div></div><div class="text-right"><strong><?= number_format((float)$r['mean_score'],2) ?></strong><div class="muted"><?= h(mean_interpretation((float)$r['mean_score'])) ?></div></div></div>
      <?php endforeach; endif; ?>
    </div>
    <?php if ($lowest): ?><p class="muted" style="margin-bottom:0;margin-top:14px">Lowest-rated event with responses: <strong><?= h($lowest['title']) ?></strong> (<?= number_format((float)$lowest['mean_score'],2) ?>).</p><?php endif; ?>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
const trendLabels=<?= json_encode(array_column($responseTrend,'d')) ?>;
const trendData=<?= json_encode(array_map('intval',array_column($responseTrend,'total'))) ?>;
const typeLabels=<?= json_encode(array_column($typeRows,'event_type')) ?>;
const typeData=<?= json_encode(array_map('intval',array_column($typeRows,'total'))) ?>;
const eventMeanLabels=<?= json_encode(array_column($ranking,'title')) ?>;
const eventMeanData=<?= json_encode(array_map(fn($r)=>round((float)$r['mean_score'],2),$ranking)) ?>;
new Chart(document.getElementById('trendChart'),{type:'line',data:{labels:trendLabels,datasets:[{label:'Responses',data:trendData,tension:.35,fill:true}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});
new Chart(document.getElementById('typeChart'),{type:'doughnut',data:{labels:typeLabels,datasets:[{data:typeData}]},options:{responsive:true,plugins:{legend:{position:'bottom'}}}});
new Chart(document.getElementById('eventMeanChart'),{type:'bar',data:{labels:eventMeanLabels,datasets:[{label:'Mean Rating',data:eventMeanData}]},options:{responsive:true,scales:{y:{beginAtZero:true,max:5}}}});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
