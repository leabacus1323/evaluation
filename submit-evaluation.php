<?php
require_once __DIR__ . '/includes/functions.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('index.php');
verify_csrf();
if (!empty($_POST['website'])) exit('Invalid submission.');

$event = get_event_by_code(trim((string)($_POST['event_code'] ?? '')));
if (!$event || !event_is_open($event)) {
    flash('This evaluation is not currently accepting responses.', 'error');
    redirect('index.php');
}
$eventId = (int)$event['id'];
if (!empty($_SESSION['submitted_events'][$eventId])) {
    flash('A response for this event has already been submitted from this browser session.', 'warning');
    redirect('evaluate.php?event=' . urlencode($event['code']));
}

$questions = get_questions($eventId);
$answers = $_POST['q'] ?? [];
$profileFields = get_participant_fields($eventId);
$profileInput = $_POST['profile'] ?? [];
$isAnonymous = !empty($_POST['is_anonymous']) && !empty($event['allow_anonymous']);
$errors = [];

foreach ($questions as $q) {
    if (!$q['is_required']) continue;
    $id = (int)$q['id'];
    $value = $answers[$id] ?? null;
    $empty = $value === null || $value === '' || (is_array($value) && count(array_filter($value, fn($v) => trim((string)$v) !== '')) === 0);
    if ($empty) $errors[] = 'Please answer all required evaluation questions.';
}

foreach ($profileFields as $field) {
    $fid = (int)$field['id'];
    $value = $profileInput[$fid] ?? null;
    if ($isAnonymous && !empty($field['hide_when_anonymous'])) continue;
    $empty = $value === null || $value === '' || (is_array($value) && count(array_filter($value, fn($v) => trim((string)$v) !== '')) === 0);
    if (!empty($field['is_required']) && $empty) {
        $errors[] = 'Please complete the required participant information fields.';
    }
    if (!$empty && $field['field_type'] === 'email' && !is_array($value) && !filter_var(trim((string)$value), FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
}

if ($errors) {
    flash($errors[0], 'error');
    redirect('evaluate.php?event=' . urlencode($event['code']));
}

// Normalize dynamic profile values and mirror standard keys into the legacy
// respondents columns so existing analytics and reports remain compatible.
$profileValues = [];
$legacy = [
    'name' => null,
    'email' => null,
    'department' => null,
    'program' => null,
    'year_level' => null,
    'participant_type' => null,
];
foreach ($profileFields as $field) {
    $fid = (int)$field['id'];
    if ($isAnonymous && !empty($field['hide_when_anonymous'])) continue;
    if (!array_key_exists($fid, $profileInput)) continue;
    $value = $profileInput[$fid];
    if (is_array($value)) {
        $clean = array_values(array_filter(array_map('trim', array_map('strval', $value)), fn($v) => $v !== ''));
        if (!$clean) continue;
        $stored = json_encode($clean, JSON_UNESCAPED_UNICODE);
    } else {
        $stored = trim((string)$value);
        if ($stored === '') continue;
    }
    $profileValues[$fid] = $stored;
    $key = (string)$field['field_key'];
    if (array_key_exists($key, $legacy)) {
        $legacy[$key] = is_array($value) ? implode(', ', $clean) : trim((string)$value);
    }
}

if ($isAnonymous) {
    $legacy['name'] = null;
    $legacy['email'] = null;
}

$token = hash('sha256', bin2hex(random_bytes(16)) . '|' . microtime(true));
$pdo = db();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO respondents (event_id,name,email,department,program,year_level,participant_type,is_anonymous,submission_token) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([
        $eventId,
        $legacy['name'] ?: null,
        $legacy['email'] ?: null,
        $legacy['department'] ?: null,
        $legacy['program'] ?: null,
        $legacy['year_level'] ?: null,
        $legacy['participant_type'] ?: null,
        $isAnonymous ? 1 : 0,
        $token,
    ]);
    $respondentId = (int)$pdo->lastInsertId();

    if ($profileValues) {
        $pInsert = $pdo->prepare('INSERT INTO respondent_profile_answers (respondent_id,field_id,answer_text) VALUES (?,?,?)');
        foreach ($profileValues as $fid => $stored) {
            $pInsert->execute([$respondentId, (int)$fid, $stored]);
        }
    }

    $insert = $pdo->prepare('INSERT INTO response_answers (respondent_id,question_id,answer_text,answer_numeric) VALUES (?,?,?,?)');
    foreach ($questions as $q) {
        $qid = (int)$q['id'];
        if (!array_key_exists($qid, $answers)) continue;
        $value = $answers[$qid];
        if (is_array($value)) {
            $value = array_values(array_filter(array_map('trim', array_map('strval', $value)), fn($v) => $v !== ''));
            if (!$value) continue;
            $text = json_encode($value, JSON_UNESCAPED_UNICODE);
            $insert->execute([$respondentId, $qid, $text, null]);
        } else {
            $text = trim((string)$value);
            if ($text === '') continue;
            $numeric = $q['question_type'] === 'likert' ? max(1, min(5, (int)$text)) : null;
            $insert->execute([$respondentId, $qid, $text, $numeric]);
        }
    }
    $pdo->commit();
    $_SESSION['submitted_events'][$eventId] = true;
    $_SESSION['last_submission_event'] = $event['code'];
    redirect('thank-you.php?event=' . urlencode($event['code']));
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('Your evaluation could not be saved. Please try again.', 'error');
    redirect('evaluate.php?event=' . urlencode($event['code']));
}
