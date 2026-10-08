<?php
require_once __DIR__ . '/includes/functions.php';
$code = trim((string)($_GET['event'] ?? ''));
$event = $code !== '' ? get_event_by_code($code) : null;
if (!$event) {
    http_response_code(404);
    $pageTitle = 'Evaluation Not Found';
    $adminArea = false;
    require __DIR__ . '/includes/header.php';
    echo '<div class="card empty"><h2>Evaluation not found</h2><p>The evaluation link is invalid or the event no longer exists.</p><a class="btn" href="index.php">Return Home</a></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$pageTitle = $event['title'] . ' - Evaluation';
$adminArea = false;
$questions = get_questions((int)$event['id']);
$profileFields = get_participant_fields((int)$event['id']);
$grouped = [];
foreach ($questions as $q) {
    $cat = $q['category_name'] ?: 'General Evaluation';
    $grouped[$cat][] = $q;
}
require __DIR__ . '/includes/header.php';
?>
<div class="card card-pad">
  <div class="eyebrow"><?= h($event['event_type']) ?> · <?= h($event['code']) ?></div>
  <h1 style="margin:5px 0 8px"><?= h($event['title']) ?></h1>
  <?php if ($event['theme']): ?><p><strong>Theme:</strong> <?= h($event['theme']) ?></p><?php endif; ?>
  <div class="grid grid-3" style="margin-top:14px">
    <div><strong>Date</strong><div class="muted"><?= h(date('F j, Y', strtotime($event['event_date']))) ?></div></div>
    <div><strong>Venue</strong><div class="muted"><?= h($event['venue'] ?: 'Not specified') ?></div></div>
    <div><strong>Organizer</strong><div class="muted"><?= h($event['organizer'] ?: 'Not specified') ?></div></div>
  </div>
</div>

<?php if (!event_is_open($event)): ?>
  <div class="alert warning" style="margin-top:16px">This evaluation is currently closed or outside its evaluation schedule.</div>
<?php elseif (!$questions): ?>
  <div class="alert warning" style="margin-top:16px">The administrator has not added evaluation questions yet.</div>
<?php elseif (!empty($_SESSION['submitted_events'][(int)$event['id']])): ?>
  <div class="alert success" style="margin-top:16px">A response for this event has already been submitted from this browser session.</div>
<?php else: ?>
<form method="post" action="submit-evaluation.php" style="margin-top:16px">
  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
  <input type="hidden" name="event_code" value="<?= h($event['code']) ?>">
  <input type="text" name="website" value="" tabindex="-1" autocomplete="off" style="position:absolute;left:-9999px" aria-hidden="true">

  <div class="card card-pad">
    <h2 style="margin-top:0">Participant Information</h2>
    <p class="muted">Your information will only be used for evaluation reporting. The fields below are configured by the event administrator.</p>
    <div class="form-grid">
      <?php if ($event['allow_anonymous']): ?>
        <div class="field full"><label><input id="anonymousToggle" type="checkbox" name="is_anonymous" value="1" style="width:auto"> Submit this evaluation anonymously</label></div>
      <?php endif; ?>
      <?php if (!$profileFields): ?>
        <div class="field full muted">No participant information is required for this evaluation.</div>
      <?php else: foreach ($profileFields as $field): $fid=(int)$field['id']; $opts=participant_field_options($field); $required=!empty($field['is_required']); $identifying=!empty($field['hide_when_anonymous']); ?>
        <div class="field <?= $field['field_type']==='textarea'?'full':'' ?>" data-profile-field data-identifying="<?= $identifying ? '1' : '0' ?>">
          <label><?= h($field['label']) ?><?= $required ? ' <span class="required">*</span>' : ' <span class="muted">(optional)</span>' ?></label>
          <?php if ($field['field_type'] === 'textarea'): ?>
            <textarea name="profile[<?= $fid ?>]" <?= $required ? 'required' : '' ?> placeholder="<?= h($field['placeholder']) ?>"></textarea>
          <?php elseif ($field['field_type'] === 'select'): ?>
            <select name="profile[<?= $fid ?>]" <?= $required ? 'required' : '' ?>><option value="">Select</option><?php foreach($opts as $opt): ?><option value="<?= h($opt) ?>"><?= h($opt) ?></option><?php endforeach; ?></select>
          <?php elseif ($field['field_type'] === 'radio'): ?>
            <div class="radio-list"><?php foreach($opts as $opt): ?><label><input type="radio" name="profile[<?= $fid ?>]" value="<?= h($opt) ?>" <?= $required ? 'required' : '' ?>> <?= h($opt) ?></label><?php endforeach; ?></div>
          <?php elseif ($field['field_type'] === 'checkbox'): ?>
            <div class="checkbox-list"><?php foreach($opts as $opt): ?><label><input type="checkbox" name="profile[<?= $fid ?>][]" value="<?= h($opt) ?>"> <?= h($opt) ?></label><?php endforeach; ?></div>
          <?php else: ?>
            <input type="<?= in_array($field['field_type'], ['email','number'], true) ? h($field['field_type']) : 'text' ?>" name="profile[<?= $fid ?>]" <?= $required ? 'required' : '' ?> placeholder="<?= h($field['placeholder']) ?>">
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <?php foreach ($grouped as $category => $items): ?>
    <div class="category-title"><h3><?= h($category) ?></h3><p>Please answer each item based on your actual experience.</p></div>
    <?php foreach ($items as $q): $qid = (int)$q['id']; $opts = question_options($q); ?>
      <div class="question-block">
        <div class="question-title"><?= h($q['question_text']) ?><?= $q['is_required'] ? ' <span class="required">*</span>' : '' ?></div>
        <?php if ($q['question_type'] === 'likert'): ?>
          <div class="likert">
            <?php for ($i=5;$i>=1;$i--): ?>
              <label><input type="radio" name="q[<?= $qid ?>]" value="<?= $i ?>" <?= $q['is_required'] ? 'required' : '' ?>> <strong><?= $i ?></strong> — <?= h(likert_label($i, $q['likert_style'])) ?></label>
            <?php endfor; ?>
          </div>
        <?php elseif ($q['question_type'] === 'multiple_choice' || $q['question_type'] === 'yes_no'): ?>
          <?php $opts = $q['question_type'] === 'yes_no' ? ['Yes','No'] : $opts; ?>
          <div class="radio-list"><?php foreach ($opts as $opt): ?><label><input type="radio" name="q[<?= $qid ?>]" value="<?= h($opt) ?>" <?= $q['is_required'] ? 'required' : '' ?>> <?= h($opt) ?></label><?php endforeach; ?></div>
        <?php elseif ($q['question_type'] === 'checkbox'): ?>
          <div class="checkbox-list"><?php foreach ($opts as $opt): ?><label><input type="checkbox" name="q[<?= $qid ?>][]" value="<?= h($opt) ?>"> <?= h($opt) ?></label><?php endforeach; ?></div>
        <?php elseif ($q['question_type'] === 'dropdown'): ?>
          <select name="q[<?= $qid ?>]" <?= $q['is_required'] ? 'required' : '' ?>><option value="">Select an option</option><?php foreach ($opts as $opt): ?><option value="<?= h($opt) ?>"><?= h($opt) ?></option><?php endforeach; ?></select>
        <?php elseif ($q['question_type'] === 'short_answer'): ?>
          <input type="text" name="q[<?= $qid ?>]" <?= $q['is_required'] ? 'required' : '' ?>>
        <?php else: ?>
          <textarea name="q[<?= $qid ?>]" <?= $q['is_required'] ? 'required' : '' ?> placeholder="Type your response here..."></textarea>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endforeach; ?>
  <div class="card card-pad" style="margin-top:18px"><button class="full" type="submit">Submit Evaluation</button><p class="muted center" style="margin-bottom:0">Please review your answers before submitting.</p></div>
</form>
<?php endif; ?>
<?php if ($event['allow_anonymous']): ?>
<script>
(function(){
  const toggle=document.getElementById('anonymousToggle');
  if(!toggle)return;
  function sync(){
    document.querySelectorAll('[data-profile-field][data-identifying="1"]').forEach(function(box){
      box.style.display=toggle.checked?'none':'';
      box.querySelectorAll('input,select,textarea').forEach(function(el){
        if(toggle.checked){el.dataset.wasRequired=el.required?'1':'0';el.required=false;if(el.type==='checkbox'||el.type==='radio')el.checked=false;else el.value='';}
        else if(el.dataset.wasRequired==='1'){el.required=true;}
      });
    });
  }
  toggle.addEventListener('change',sync);sync();
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
