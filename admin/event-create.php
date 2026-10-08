<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $title=trim((string)($_POST['title']??''));$date=$_POST['event_date']??'';
  if($title===''||$date===''){flash('Event title and date are required.','error');redirect('event-create.php');}
  $status=in_array($_POST['status']??'',['draft','open','closed'],true)?$_POST['status']:'draft';
  $stmt=db()->prepare('INSERT INTO events (code,title,event_type,theme,description,event_date,start_time,end_time,venue,organizer,speaker,target_participants,expected_participants,status,evaluation_start,evaluation_deadline,allow_anonymous,collect_email) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $stmt->execute([event_code(),$title,trim((string)($_POST['event_type']??'Activity')),trim((string)($_POST['theme']??''))?:null,trim((string)($_POST['description']??''))?:null,$date,$_POST['start_time']?:null,$_POST['end_time']?:null,trim((string)($_POST['venue']??''))?:null,trim((string)($_POST['organizer']??''))?:null,trim((string)($_POST['speaker']??''))?:null,trim((string)($_POST['target_participants']??''))?:null,($_POST['expected_participants']??'')!==''?(int)$_POST['expected_participants']:null,$status,$_POST['evaluation_start']?:null,$_POST['evaluation_deadline']?:null,!empty($_POST['allow_anonymous'])?1:0,0]);
  $id=(int)db()->lastInsertId();
  seed_default_participant_fields($id,true);
  flash('Event created. Review the participant information fields, then upload your evaluation questions.');
  redirect('participant-fields.php?event_id='.$id);
}
$pageTitle='Create Event';$adminArea=true;require __DIR__.'/../includes/header.php';
?>
<div class="section-title"><div><h1>Create Event</h1><p>Step 1 of 3: enter the event details. Next, customize Participant Information, then upload the evaluation questions.</p></div><a class="btn ghost" href="events.php">Back to Events</a></div>
<div class="card card-pad"><form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><div class="form-grid">
<div class="field full"><label>Event Title *</label><input name="title" required placeholder="e.g. Leadership Development Workshop"></div>
<div class="field"><label>Event Type</label><select name="event_type"><?php foreach(['Activity','Seminar','Workshop','Training','Conference','Orientation','Other'] as $t): ?><option><?= h($t) ?></option><?php endforeach; ?></select></div>
<div class="field"><label>Theme</label><input name="theme"></div>
<div class="field full"><label>Description</label><textarea name="description"></textarea></div>
<div class="field"><label>Date *</label><input type="date" name="event_date" required></div><div class="field"><label>Venue</label><input name="venue"></div>
<div class="field"><label>Start Time</label><input type="time" name="start_time"></div><div class="field"><label>End Time</label><input type="time" name="end_time"></div>
<div class="field"><label>Organizer</label><input name="organizer"></div><div class="field"><label>Speaker / Trainer / Facilitator</label><input name="speaker"></div>
<div class="field"><label>Target Participants</label><input name="target_participants"></div><div class="field"><label>Expected Participants</label><input type="number" min="0" name="expected_participants"></div>
<div class="field"><label>Evaluation Status</label><select name="status"><option value="draft">Draft</option><option value="open">Open</option><option value="closed">Closed</option></select></div><div class="field"><label>Evaluation Start</label><input type="datetime-local" name="evaluation_start"></div>
<div class="field"><label>Evaluation Deadline</label><input type="datetime-local" name="evaluation_deadline"></div>
<div class="field full"><label><input type="checkbox" name="allow_anonymous" value="1" checked style="width:auto"> Allow anonymous responses</label><p class="muted" style="margin:6px 0 0">Name, email, department, participant type, and other profile fields are fully editable in the next step.</p></div>
</div><div class="actions" style="margin-top:18px"><button>Create Event &amp; Configure Participant Information</button><a class="btn ghost" href="events.php">Cancel</a></div></form></div>
<?php require __DIR__.'/../includes/footer.php'; ?>
