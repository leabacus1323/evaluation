<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();
ensure_participant_profile_schema();

$pdo = db();
$eventId = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$event = $eventId > 0 ? get_event_by_id($eventId) : null;
if (!$event) {
    http_response_code(404);
    exit('Event not found.');
}

$allowedTypes = ['text','email','number','select','radio','checkbox','textarea'];

function parse_profile_options(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;
    $parts = preg_split('/\r\n|\r|\n|\|/', $raw);
    $parts = array_values(array_unique(array_filter(array_map('trim', $parts), fn($v) => $v !== '')));
    return $parts ? json_encode($parts, JSON_UNESCAPED_UNICODE) : null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (isset($_POST['add_field'])) {
        $label = trim((string)($_POST['label'] ?? ''));
        $type = (string)($_POST['field_type'] ?? 'text');
        if (!in_array($type, $allowedTypes, true)) $type = 'text';
        if ($label === '') {
            flash('Field label is required.', 'error');
        } else {
            $key = participant_field_key($label, $eventId);
            $order = max(1, (int)($_POST['sort_order'] ?? 1));
            $options = in_array($type, ['select','radio','checkbox'], true) ? parse_profile_options((string)($_POST['options'] ?? '')) : null;
            $stmt = $pdo->prepare('INSERT INTO participant_fields (event_id,field_key,label,field_type,placeholder,options_json,is_required,hide_when_anonymous,is_active,sort_order) VALUES (?,?,?,?,?,?,?,?,1,?)');
            $stmt->execute([
                $eventId, $key, $label, $type,
                trim((string)($_POST['placeholder'] ?? '')) ?: null,
                $options,
                !empty($_POST['is_required']) ? 1 : 0,
                !empty($_POST['hide_when_anonymous']) ? 1 : 0,
                $order,
            ]);
            flash('Participant information field added.');
        }
        redirect('participant-fields.php?event_id=' . $eventId);
    }

    if (isset($_POST['update_field'])) {
        $fieldId = (int)($_POST['field_id'] ?? 0);
        $label = trim((string)($_POST['label'] ?? ''));
        $type = (string)($_POST['field_type'] ?? 'text');
        if (!in_array($type, $allowedTypes, true)) $type = 'text';
        if ($fieldId <= 0 || $label === '') {
            flash('Unable to update the field. Check the field label.', 'error');
        } else {
            $options = in_array($type, ['select','radio','checkbox'], true) ? parse_profile_options((string)($_POST['options'] ?? '')) : null;
            $stmt = $pdo->prepare('UPDATE participant_fields SET label=?,field_type=?,placeholder=?,options_json=?,is_required=?,hide_when_anonymous=?,sort_order=? WHERE id=? AND event_id=?');
            $stmt->execute([
                $label, $type,
                trim((string)($_POST['placeholder'] ?? '')) ?: null,
                $options,
                !empty($_POST['is_required']) ? 1 : 0,
                !empty($_POST['hide_when_anonymous']) ? 1 : 0,
                max(1, (int)($_POST['sort_order'] ?? 1)),
                $fieldId, $eventId,
            ]);
            flash('Participant information field updated.');
        }
        redirect('participant-fields.php?event_id=' . $eventId);
    }

    if (isset($_POST['remove_field'])) {
        $fieldId = (int)($_POST['field_id'] ?? 0);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM respondent_profile_answers WHERE field_id=?');
        $stmt->execute([$fieldId]);
        $hasAnswers = (int)$stmt->fetchColumn() > 0;
        if ($hasAnswers) {
            $pdo->prepare('UPDATE participant_fields SET is_active=0 WHERE id=? AND event_id=?')->execute([$fieldId, $eventId]);
            flash('Field removed from the public form. Existing answers were preserved.');
        } else {
            $pdo->prepare('DELETE FROM participant_fields WHERE id=? AND event_id=?')->execute([$fieldId, $eventId]);
            flash('Field removed.');
        }
        redirect('participant-fields.php?event_id=' . $eventId);
    }

    if (isset($_POST['restore_field'])) {
        $fieldId = (int)($_POST['field_id'] ?? 0);
        $pdo->prepare('UPDATE participant_fields SET is_active=1 WHERE id=? AND event_id=?')->execute([$fieldId, $eventId]);
        flash('Field restored to the participant form.');
        redirect('participant-fields.php?event_id=' . $eventId);
    }

    if (isset($_POST['add_defaults'])) {
        seed_default_participant_fields($eventId, true);
        flash('Any missing standard participant fields were added.');
        redirect('participant-fields.php?event_id=' . $eventId);
    }
}

$fields = get_participant_fields($eventId, true);
$pageTitle = 'Participant Information Fields';
$adminArea = true;
require __DIR__ . '/../includes/header.php';
?>
<div class="section-title">
  <div>
    <h1>Participant Information</h1>
    <p><?= h($event['title']) ?> · Add, edit, remove, restore, or reorder the fields shown before the evaluation questions.</p>
  </div>
  <div class="actions">
    <a class="btn ghost" href="event-edit.php?id=<?= $eventId ?>">Event Details</a>
    <a class="btn ghost" href="questions.php?event_id=<?= $eventId ?>">Questions</a>
    <a class="btn ghost" target="_blank" href="../evaluate.php?event=<?= urlencode($event['code']) ?>">Preview Form</a>
  </div>
</div>

<div class="alert success">
  <strong>Editable section enabled.</strong> Fields marked Active appear under <em>Participant Information</em>. Removing a field that already has responses only hides it from future participants; historical answers are preserved.
</div>

<div class="card card-pad" style="margin-bottom:16px">
  <div class="section-title" style="margin:0 0 12px">
    <div><h2 style="margin:0">Add New Field</h2><p style="margin:3px 0 0">Create a custom participant profile field for this event.</p></div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="event_id" value="<?= $eventId ?>">
      <button class="secondary" name="add_defaults" value="1" type="submit">Add Missing Standard Fields</button>
    </form>
  </div>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="event_id" value="<?= $eventId ?>">
    <div class="form-grid">
      <div class="field"><label>Field Label *</label><input name="label" required placeholder="e.g. Office / Unit"></div>
      <div class="field"><label>Field Type</label><select name="field_type">
        <option value="text">Short Text</option><option value="email">Email</option><option value="number">Number</option><option value="select">Dropdown</option><option value="radio">Single Choice</option><option value="checkbox">Multiple Choice</option><option value="textarea">Paragraph</option>
      </select></div>
      <div class="field"><label>Placeholder</label><input name="placeholder" placeholder="Optional hint shown inside the field"></div>
      <div class="field"><label>Sort Order</label><input type="number" min="1" name="sort_order" value="<?= count($fields) + 1 ?>"></div>
      <div class="field full"><label>Choices <span class="muted">(for Dropdown / Single Choice / Multiple Choice)</span></label><textarea name="options" placeholder="Enter one option per line, or separate options with |"></textarea></div>
      <div class="field full"><label><input type="checkbox" name="is_required" value="1" style="width:auto"> Required</label> &nbsp; <label><input type="checkbox" name="hide_when_anonymous" value="1" style="width:auto"> Treat as identifying information when anonymous</label></div>
    </div>
    <div class="actions" style="margin-top:14px"><button name="add_field" value="1">Add Field</button></div>
  </form>
</div>

<div class="section-title"><div><h2>Current Fields</h2><p>Use Sort Order to control the order shown to participants.</p></div></div>
<div class="grid grid-2">
<?php if (!$fields): ?>
  <div class="card empty">No participant fields yet. Add a field above.</div>
<?php else: foreach ($fields as $field): $opts = participant_field_options($field); ?>
  <div class="card card-pad" style="opacity:<?= $field['is_active'] ? '1' : '.68' ?>">
    <div class="actions" style="justify-content:space-between;margin-bottom:12px">
      <div><strong><?= h($field['label']) ?></strong><div class="muted"><?= h($field['field_key']) ?> · <?= h($field['field_type']) ?></div></div>
      <span class="status <?= $field['is_active'] ? 'open' : 'closed' ?>"><?= $field['is_active'] ? 'Active' : 'Removed' ?></span>
    </div>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="event_id" value="<?= $eventId ?>">
      <input type="hidden" name="field_id" value="<?= (int)$field['id'] ?>">
      <div class="form-grid">
        <div class="field"><label>Label</label><input name="label" value="<?= h($field['label']) ?>" required></div>
        <div class="field"><label>Type</label><select name="field_type"><?php foreach ($allowedTypes as $type): ?><option value="<?= h($type) ?>" <?= $field['field_type'] === $type ? 'selected' : '' ?>><?= h(ucwords(str_replace('_',' ',$type))) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Placeholder</label><input name="placeholder" value="<?= h($field['placeholder']) ?>"></div>
        <div class="field"><label>Sort Order</label><input type="number" min="1" name="sort_order" value="<?= (int)$field['sort_order'] ?>"></div>
        <div class="field full"><label>Choices</label><textarea name="options" placeholder="One option per line"><?= h(implode("\n", $opts)) ?></textarea></div>
        <div class="field full"><label><input type="checkbox" name="is_required" value="1" style="width:auto" <?= $field['is_required'] ? 'checked' : '' ?>> Required</label> &nbsp; <label><input type="checkbox" name="hide_when_anonymous" value="1" style="width:auto" <?= $field['hide_when_anonymous'] ? 'checked' : '' ?>> Identifying / omit for anonymous responses</label></div>
      </div>
      <div class="actions" style="margin-top:12px">
        <button name="update_field" value="1">Save</button>
        <?php if ($field['is_active']): ?>
          <button class="danger" name="remove_field" value="1" data-confirm="Remove this field from the participant form? Existing answers will be preserved when necessary.">Remove</button>
        <?php else: ?>
          <button class="secondary" name="restore_field" value="1">Restore</button>
        <?php endif; ?>
        <?php if ((int)$field['answer_count'] > 0): ?><span class="muted"><?= (int)$field['answer_count'] ?> saved answer<?= (int)$field['answer_count'] === 1 ? '' : 's' ?></span><?php endif; ?>
      </div>
    </form>
  </div>
<?php endforeach; endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
