<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();
$pdo=db();
$events=$pdo->query('SELECT id,title,code FROM events ORDER BY event_date DESC,id DESC')->fetchAll();
$eventId=(int)($_GET['event_id']??($events[0]['id']??0));
$event=$eventId?get_event_by_id($eventId):null;
$respondents=$event?response_count($eventId):0;
$overall=$event?event_overall_mean($eventId):0;
$categories=$event?event_category_means($eventId):[];
$qstats=$event?event_question_stats($eventId):[];
$distribution=$event?rating_distribution($eventId):[1=>0,2=>0,3=>0,4=>0,5=>0];
$comments=[];$commentMap=[];
if($event){
  $st=$pdo->prepare("SELECT q.question_text,ra.answer_text FROM response_answers ra JOIN questions q ON q.id=ra.question_id JOIN respondents r ON r.id=ra.respondent_id WHERE r.event_id=? AND q.question_type IN ('paragraph','short_answer') AND COALESCE(TRIM(ra.answer_text),'')<>'' ORDER BY q.id,ra.id");
  $st->execute([$eventId]);
  foreach($st as $row){$commentMap[$row['question_text']][]=$row['answer_text'];$comments[]=$row['answer_text'];}
}
$keywords=keyword_frequency($comments);
$likertQs=array_values(array_filter($qstats,fn($q)=>$q['question_type']==='likert'&&$q['mean_score']!==null));
usort($likertQs,fn($a,$b)=>(float)$b['mean_score']<=>(float)$a['mean_score']);
$highest=$likertQs[0]??null;$lowest=$likertQs?$likertQs[count($likertQs)-1]:null;
$responseRate=($event&&!empty($event['expected_participants']))?min(100,($respondents/(int)$event['expected_participants'])*100):null;
$pageTitle='Analytics';$adminArea=true;require __DIR__.'/../includes/header.php';
?>
<div class="section-title"><div><h1>Evaluation Analytics</h1><p>Statistical summary, rating distribution, category performance, and participant comments.</p></div></div>
<div class="card card-pad no-print"><form method="get"><label>Select Event</label><select name="event_id" onchange="this.form.submit()" style="max-width:620px;margin-top:6px"><?php foreach($events as $e): ?><option value="<?= (int)$e['id'] ?>" <?= $eventId===(int)$e['id']?'selected':'' ?>><?= h($e['title']) ?></option><?php endforeach; ?></select></form></div>
<?php if(!$event): ?><div class="card empty" style="margin-top:16px">No events available.</div><?php else: ?>
<div class="section-title"><div><h2><?= h($event['title']) ?></h2><p><?= h($event['code']) ?> · <?= h($event['event_type']) ?></p></div><div class="actions"><a class="btn ghost" href="reports.php?event_id=<?= $eventId ?>">Report</a><a class="btn ghost" href="responses.php?event_id=<?= $eventId ?>">Responses</a></div></div>
<div class="stats">
<div class="stat-card"><div class="stat-label">Respondents</div><div class="stat-value"><?= $respondents ?></div><div class="stat-note"><?= $responseRate!==null?number_format($responseRate,1).'% response rate':'No expected count set' ?></div></div>
<div class="stat-card"><div class="stat-label">Overall Mean</div><div class="stat-value"><?= number_format($overall,2) ?></div><div class="stat-note"><?= h(mean_interpretation($overall)) ?></div></div>
<div class="stat-card"><div class="stat-label">Highest Item</div><div class="stat-value" style="font-size:1.15rem"><?= $highest?number_format((float)$highest['mean_score'],2):'—' ?></div><div class="stat-note"><?= h($highest['question_text']??'No data') ?></div></div>
<div class="stat-card"><div class="stat-label">Lowest Item</div><div class="stat-value" style="font-size:1.15rem"><?= $lowest?number_format((float)$lowest['mean_score'],2):'—' ?></div><div class="stat-note"><?= h($lowest['question_text']??'No data') ?></div></div>
</div>
<div class="grid grid-2" style="margin-top:16px"><div class="card chart-card"><h3 style="margin-top:0">Mean Rating per Category</h3><canvas id="categoryChart"></canvas></div><div class="card chart-card"><h3 style="margin-top:0">Category Performance Radar</h3><canvas id="categoryRadar"></canvas></div></div>
<div class="grid grid-2" style="margin-top:16px"><div class="card chart-card"><h3 style="margin-top:0">Rating Distribution</h3><canvas id="ratingChart"></canvas></div><div class="card chart-card"><h3 style="margin-top:0">Overall Rating</h3><canvas id="overallChart"></canvas></div></div>
<div class="card chart-card" style="margin-top:16px"><h3 style="margin-top:0">Question-Level Mean Ratings</h3><canvas id="questionChart"></canvas></div>
<div class="section-title"><div><h2>Category Summary</h2><p>Mean score and verbal interpretation for each evaluation area.</p></div></div>
<div class="card"><div class="table-wrap"><table class="table"><thead><tr><th>Category</th><th class="text-right">Mean</th><th>Interpretation</th><th class="text-right">Answers</th></tr></thead><tbody><?php foreach($categories as $c): $m=(float)($c['mean_score']?:0); ?><tr><td><strong><?= h($c['name']) ?></strong></td><td class="text-right"><?= $c['mean_score']!==null?number_format($m,2):'—' ?></td><td><?= h(mean_interpretation($m)) ?></td><td class="text-right"><?= (int)$c['answer_count'] ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="section-title"><div><h2>Participant Comments and Recommendations</h2><p>Open-ended responses organized by question.</p></div></div>
<?php if(!$commentMap): ?><div class="card empty">No qualitative comments were submitted.</div><?php else: foreach($commentMap as $question=>$items): ?><div class="card card-pad" style="margin-bottom:12px"><h3 style="margin-top:0"><?= h($question) ?></h3><?php foreach($items as $text): ?><div class="comment-card"><?= nl2br(h($text)) ?></div><?php endforeach; ?></div><?php endforeach; endif; ?>
<div class="section-title"><div><h2>Frequently Mentioned Keywords</h2><p>Simple frequency count from qualitative comments. Common stop words are excluded.</p></div></div>
<div class="card card-pad"><div class="tag-cloud"><?php if(!$keywords): ?><span>No keywords yet</span><?php else: foreach($keywords as $word=>$count): ?><span><?= h($word) ?> · <?= (int)$count ?></span><?php endforeach; endif; ?></div></div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
const catLabels=<?= json_encode(array_column($categories,'name')) ?>;
const catData=<?= json_encode(array_map(fn($x)=>round((float)($x['mean_score']?:0),2),$categories)) ?>;
const distLabels=['1 - Poor','2 - Fair','3 - Good','4 - Very Good','5 - Excellent'];
const distData=<?= json_encode(array_values($distribution)) ?>;
const qLabels=<?= json_encode(array_map(fn($x)=>$x['question_text'],array_values(array_filter($qstats,fn($q)=>$q['question_type']==='likert')))) ?>;
const qData=<?= json_encode(array_map(fn($x)=>round((float)($x['mean_score']?:0),2),array_values(array_filter($qstats,fn($q)=>$q['question_type']==='likert')))) ?>;
new Chart(document.getElementById('categoryChart'),{type:'bar',data:{labels:catLabels,datasets:[{label:'Mean',data:catData}]},options:{responsive:true,scales:{y:{beginAtZero:true,max:5}}}});
new Chart(document.getElementById('categoryRadar'),{type:'radar',data:{labels:catLabels,datasets:[{label:'Category Mean',data:catData}]},options:{responsive:true,scales:{r:{beginAtZero:true,max:5}}}});
new Chart(document.getElementById('ratingChart'),{type:'bar',data:{labels:distLabels,datasets:[{label:'Responses',data:distData}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});
new Chart(document.getElementById('overallChart'),{type:'doughnut',data:{labels:['Overall Mean','Remaining to 5.00'],datasets:[{data:[<?= json_encode(round($overall,2)) ?>,<?= json_encode(round(max(0,5-$overall),2)) ?>]}]},options:{responsive:true,plugins:{legend:{position:'bottom'}}}});
new Chart(document.getElementById('questionChart'),{type:'bar',data:{labels:qLabels,datasets:[{label:'Mean',data:qData}]},options:{indexAxis:'y',responsive:true,scales:{x:{beginAtZero:true,max:5}}}});
</script>
<?php endif; require __DIR__.'/../includes/footer.php'; ?>
