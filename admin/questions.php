<?php
require_once __DIR__ . '/../includes/auth.php';
require_admin();
$pdo = db();
$eventId = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
$event = get_event_by_id($eventId);
if (!$event) { http_response_code(404); exit('Event not found.'); }

function qimport_header_key(string $value): string {
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    return trim($value, '_');
}

function qimport_question_type(string $value, string $default = 'likert'): string {
    $v = strtolower(trim($value));
    $map = [
        'likert' => 'likert', 'rating' => 'likert', 'scale' => 'likert',
        'multiple_choice' => 'multiple_choice', 'multiple choice' => 'multiple_choice', 'mcq' => 'multiple_choice', 'radio' => 'multiple_choice',
        'checkbox' => 'checkbox', 'checkboxes' => 'checkbox', 'multi_select' => 'checkbox', 'multiple select' => 'checkbox',
        'yes_no' => 'yes_no', 'yes/no' => 'yes_no', 'yes no' => 'yes_no',
        'dropdown' => 'dropdown', 'select' => 'dropdown',
        'short_answer' => 'short_answer', 'short answer' => 'short_answer', 'text' => 'short_answer',
        'paragraph' => 'paragraph', 'long_answer' => 'paragraph', 'long answer' => 'paragraph', 'textarea' => 'paragraph',
    ];
    return $map[$v] ?? $default;
}

function qimport_likert_style(string $value, string $default = 'agreement'): string {
    $v = strtolower(trim($value));
    if (in_array($v, ['quality', 'excellent', 'excellent_to_poor', 'excellent-poor'], true)) return 'quality';
    if (in_array($v, ['agreement', 'agree', 'strongly_agree', 'strongly-agree'], true)) return 'agreement';
    return $default;
}

function qimport_required($value, int $default = 1): int {
    $v = strtolower(trim((string)$value));
    if ($v === '') return $default;
    if (in_array($v, ['1','yes','y','true','required'], true)) return 1;
    if (in_array($v, ['0','no','n','false','optional'], true)) return 0;
    return $default;
}

function qimport_options(string $value): ?string {
    $value = trim($value);
    if ($value === '') return null;
    $parts = preg_split('/\s*\|\s*/', $value);
    if (count($parts) <= 1) $parts = preg_split('/\R/', $value);
    $parts = array_values(array_filter(array_map('trim', $parts), fn($x) => $x !== ''));
    return $parts ? json_encode($parts, JSON_UNESCAPED_UNICODE) : null;
}

function qimport_detect_delimiter(string $path): string {
    $line = '';
    $fh = fopen($path, 'r');
    if ($fh) { $line = (string)fgets($fh); fclose($fh); }
    $counts = [',' => substr_count($line, ','), ';' => substr_count($line, ';'), "\t" => substr_count($line, "\t")];
    arsort($counts);
    $delimiter = array_key_first($counts);
    return ($counts[$delimiter] ?? 0) > 0 ? $delimiter : ',';
}

if (isset($_GET['download_template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="question_upload_template.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['category','question_text','question_type','likert_style','required','options','sort_order']);
    fputcsv($out, ['Program Content','The objectives of the activity were clearly presented.','likert','agreement','1','','1']);
    fputcsv($out, ['Program Content','The topics were relevant to the purpose of the activity.','likert','agreement','1','','2']);
    fputcsv($out, ['Speaker / Facilitator','The speaker explained the topic clearly.','likert','quality','1','','3']);
    fputcsv($out, ['Overall Evaluation','Would you recommend a similar activity in the future?','yes_no','','1','','4']);
    fputcsv($out, ['Participant Feedback','What did you like most about the activity?','paragraph','','0','','5']);
    fputcsv($out, ['Participant Profile','Which session did you attend?','multiple_choice','','1','Session 1|Session 2|Both','6']);
    fclose($out);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (isset($_POST['upload_questions'])) {
        $file = $_FILES['question_file'] ?? null;
        $defaultType = qimport_question_type((string)($_POST['default_type'] ?? 'likert'));
        $defaultLikert = qimport_likert_style((string)($_POST['default_likert_style'] ?? 'agreement'));
        $defaultRequired = !empty($_POST['default_required']) ? 1 : 0;
        $replaceExisting = !empty($_POST['replace_existing']);

        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            flash('Please choose a CSV or TXT file to upload.', 'error');
            redirect('questions.php?event_id=' . $eventId);
        }
        if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
            flash('The question file is too large. Maximum size is 2 MB.', 'error');
            redirect('questions.php?event_id=' . $eventId);
        }
        $extension = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv','txt'], true)) {
            flash('Unsupported file type. Upload a .csv or .txt file.', 'error');
            redirect('questions.php?event_id=' . $eventId);
        }

        if ($replaceExisting) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM respondents WHERE event_id=?');
            $st->execute([$eventId]);
            if ((int)$st->fetchColumn() > 0) {
                flash('Existing questions cannot be replaced because this event already has submitted evaluations. Import without replacing, or create a new event.', 'error');
                redirect('questions.php?event_id=' . $eventId);
            }
        }

        $rowsToImport = [];
        $tmp = (string)$file['tmp_name'];

        if ($extension === 'txt') {
            $lines = file($tmp, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $sort = 1;
            foreach ($lines as $line) {
                $question = trim((string)$line);
                if ($question === '') continue;
                if (count($rowsToImport) >= 500) break;
                $rowsToImport[] = [
                    'category' => '', 'question' => $question, 'type' => $defaultType,
                    'likert' => $defaultLikert, 'required' => $defaultRequired,
                    'options' => null, 'sort_order' => $sort++
                ];
            }
        } else {
            $delimiter = qimport_detect_delimiter($tmp);
            $fh = fopen($tmp, 'r');
            if (!$fh) {
                flash('The uploaded CSV could not be read.', 'error');
                redirect('questions.php?event_id=' . $eventId);
            }
            $first = fgetcsv($fh, 0, $delimiter);
            if ($first === false) $first = [];
            $normalized = array_map(fn($x) => qimport_header_key((string)$x), $first);
            $questionHeaders = ['question','question_text','text','item','statement'];
            $hasHeader = count(array_intersect($normalized, $questionHeaders)) > 0;
            $headerMap = [];
            if ($hasHeader) {
                foreach ($normalized as $i => $key) $headerMap[$key] = $i;
            } else {
                rewind($fh);
            }
            $sort = 1;
            while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
                if (count($rowsToImport) >= 500) break;
                if ($hasHeader) {
                    $pick = function(array $keys, $default = '') use ($row, $headerMap) {
                        foreach ($keys as $key) {
                            if (array_key_exists($key, $headerMap)) return $row[$headerMap[$key]] ?? $default;
                        }
                        return $default;
                    };
                    $question = trim((string)$pick($questionHeaders));
                    if ($question === '') continue;
                    $category = trim((string)$pick(['category','section','group']));
                    $typeRaw = (string)$pick(['question_type','type'], $defaultType);
                    $likertRaw = (string)$pick(['likert_style','likert','scale_style'], $defaultLikert);
                    $requiredRaw = $pick(['required','is_required'], $defaultRequired);
                    $optionsRaw = (string)$pick(['options','choices','answers'], '');
                    $sortRaw = (int)$pick(['sort_order','order','number','no'], $sort);
                    $rowsToImport[] = [
                        'category' => $category,
                        'question' => $question,
                        'type' => qimport_question_type($typeRaw, $defaultType),
                        'likert' => qimport_likert_style($likertRaw, $defaultLikert),
                        'required' => qimport_required($requiredRaw, $defaultRequired),
                        'options' => qimport_options($optionsRaw),
                        'sort_order' => $sortRaw > 0 ? $sortRaw : $sort,
                    ];
                } else {
                    $question = trim((string)($row[0] ?? ''));
                    if ($question === '') continue;
                    $rowsToImport[] = [
                        'category' => '', 'question' => $question, 'type' => $defaultType,
                        'likert' => $defaultLikert, 'required' => $defaultRequired,
                        'options' => null, 'sort_order' => $sort
                    ];
                }
                $sort++;
            }
            fclose($fh);
        }

        if (!$rowsToImport) {
            flash('No valid questions were found in the uploaded file.', 'error');
            redirect('questions.php?event_id=' . $eventId);
        }

        try {
            $pdo->beginTransaction();
            if ($replaceExisting) {
                $pdo->prepare('DELETE FROM questions WHERE event_id=?')->execute([$eventId]);
                $pdo->prepare('DELETE FROM evaluation_categories WHERE event_id=?')->execute([$eventId]);
            }

            $existingCats = [];
            $catOrder = 1;
            $st = $pdo->prepare('SELECT id,name,sort_order FROM evaluation_categories WHERE event_id=? ORDER BY sort_order,id');
            $st->execute([$eventId]);
            foreach ($st->fetchAll() as $cat) {
                $existingCats[strtolower(trim($cat['name']))] = (int)$cat['id'];
                $catOrder = max($catOrder, (int)$cat['sort_order'] + 1);
            }

            $catInsert = $pdo->prepare('INSERT INTO evaluation_categories (event_id,name,description,sort_order) VALUES (?,?,NULL,?)');
            $qInsert = $pdo->prepare('INSERT INTO questions (event_id,category_id,question_text,question_type,likert_style,options_json,is_required,sort_order) VALUES (?,?,?,?,?,?,?,?)');
            $imported = 0;
            $createdCategories = 0;

            foreach ($rowsToImport as $item) {
                $catId = null;
                $catName = trim((string)$item['category']);
                if ($catName !== '') {
                    $catKey = strtolower($catName);
                    if (!isset($existingCats[$catKey])) {
                        $catInsert->execute([$eventId, $catName, $catOrder++]);
                        $existingCats[$catKey] = (int)$pdo->lastInsertId();
                        $createdCategories++;
                    }
                    $catId = $existingCats[$catKey];
                }
                $type = qimport_question_type((string)$item['type'], $defaultType);
                $options = $item['options'];
                if (in_array($type, ['multiple_choice','checkbox','dropdown'], true) && !$options) {
                    $options = json_encode(['Option 1','Option 2'], JSON_UNESCAPED_UNICODE);
                }
                $qInsert->execute([
                    $eventId, $catId, $item['question'], $type,
                    qimport_likert_style((string)$item['likert'], $defaultLikert),
                    $options, (int)$item['required'], (int)$item['sort_order']
                ]);
                $imported++;
            }
            $pdo->commit();
            flash($imported . ' questions imported successfully' . ($createdCategories ? ' with ' . $createdCategories . ' new categories.' : '.'));
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            flash('Question import failed. Check the file format and try again.', 'error');
        }
        redirect('questions.php?event_id=' . $eventId);
    }

    if (isset($_POST['load_template'])) {
        try { seed_standard_template($eventId); flash('Standard evaluation template loaded.'); }
        catch (Throwable $e) { flash('Template could not be loaded. It may already contain questions.', 'error'); }
        redirect('questions.php?event_id=' . $eventId);
    }
    if (isset($_POST['add_category'])) {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name !== '') {
            $st = $pdo->prepare('INSERT INTO evaluation_categories (event_id,name,description,sort_order) VALUES (?,?,?,?)');
            $st->execute([$eventId,$name,trim((string)($_POST['description']??''))?:null,(int)($_POST['sort_order']??1)]);
            flash('Category added.');
        }
        redirect('questions.php?event_id=' . $eventId);
    }
    if (isset($_POST['update_category'])) {
        $id=(int)$_POST['category_id'];
        $st=$pdo->prepare('UPDATE evaluation_categories SET name=?,description=?,sort_order=? WHERE id=? AND event_id=?');
        $st->execute([trim((string)$_POST['name']),trim((string)($_POST['description']??''))?:null,(int)$_POST['sort_order'],$id,$eventId]);
        flash('Category updated.'); redirect('questions.php?event_id='.$eventId);
    }
    if (isset($_POST['delete_category'])) {
        $id=(int)$_POST['category_id'];
        $pdo->prepare('DELETE FROM evaluation_categories WHERE id=? AND event_id=?')->execute([$id,$eventId]);
        flash('Category deleted. Questions remain but become uncategorized.'); redirect('questions.php?event_id='.$eventId);
    }
    if (isset($_POST['add_question']) || isset($_POST['update_question'])) {
        $type=in_array($_POST['question_type']??'',['likert','multiple_choice','checkbox','yes_no','dropdown','short_answer','paragraph'],true)?$_POST['question_type']:'likert';
        $options=[];
        if(in_array($type,['multiple_choice','checkbox','dropdown'],true)){
            $lines=preg_split('/\R/',(string)($_POST['options']??''));
            $options=array_values(array_filter(array_map('trim',$lines),fn($v)=>$v!==''));
        }
        $data=[(int)($_POST['category_id']??0)?:null,trim((string)$_POST['question_text']),$type,in_array($_POST['likert_style']??'quality',['quality','agreement'],true)?$_POST['likert_style']:'quality',$options?json_encode($options,JSON_UNESCAPED_UNICODE):null,!empty($_POST['is_required'])?1:0,(int)($_POST['sort_order']??1)];
        if(isset($_POST['add_question'])){
            $st=$pdo->prepare('INSERT INTO questions (event_id,category_id,question_text,question_type,likert_style,options_json,is_required,sort_order) VALUES (?,?,?,?,?,?,?,?)');
            $st->execute(array_merge([$eventId],$data)); flash('Question added.');
        }else{
            $qid=(int)$_POST['question_id'];
            $st=$pdo->prepare('UPDATE questions SET category_id=?,question_text=?,question_type=?,likert_style=?,options_json=?,is_required=?,sort_order=? WHERE id=? AND event_id=?');
            $st->execute(array_merge($data,[$qid,$eventId])); flash('Question updated.');
        }
        redirect('questions.php?event_id='.$eventId);
    }
    if (isset($_POST['delete_question'])) {
        $qid=(int)$_POST['question_id'];
        $pdo->prepare('DELETE FROM questions WHERE id=? AND event_id=?')->execute([$qid,$eventId]);
        flash('Question deleted.'); redirect('questions.php?event_id='.$eventId);
    }
}

$categories=get_categories($eventId);
$questions=get_questions($eventId);
$respStmt=$pdo->prepare('SELECT COUNT(*) FROM respondents WHERE event_id=?');
$respStmt->execute([$eventId]);
$responseCount=(int)$respStmt->fetchColumn();
$pageTitle='Upload Evaluation Questions';
$adminArea=true;
require __DIR__.'/../includes/header.php';
?>
<div class="section-title">
  <div><h1>Upload Evaluation Questions</h1><p><?= h($event['title']) ?> · Upload your questionnaire instead of encoding every question manually.</p></div>
  <div class="actions"><a class="btn ghost" href="event-edit.php?id=<?= $eventId ?>">Event Details</a><a class="btn ghost" target="_blank" href="../evaluate.php?event=<?= urlencode($event['code']) ?>">Preview Form</a><a class="btn ghost" href="qr.php?event_id=<?= $eventId ?>">QR Code</a></div>
</div>

<div class="card card-pad" style="margin-bottom:18px">
  <div class="section-title" style="margin-top:0"><div><h2 style="margin:0">1. Upload Your Questions</h2><p>Fastest option: upload a TXT file with one question per line, or a CSV exported from Excel.</p></div><a class="btn ghost" href="questions.php?event_id=<?= $eventId ?>&download_template=1">Download CSV Template</a></div>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="event_id" value="<?= $eventId ?>">
    <div class="form-grid">
      <div class="field full"><label>Question File *</label><input type="file" name="question_file" accept=".csv,.txt,text/csv,text/plain" required><div class="muted" style="margin-top:6px">Maximum 2 MB · Up to 500 questions per upload.</div></div>
      <div class="field"><label>Default Question Type</label><select name="default_type"><option value="likert">5-Point Likert</option><option value="paragraph">Paragraph / Comment</option><option value="short_answer">Short Answer</option><option value="yes_no">Yes / No</option><option value="multiple_choice">Multiple Choice</option><option value="checkbox">Checkbox</option><option value="dropdown">Dropdown</option></select></div>
      <div class="field"><label>Default Likert Wording</label><select name="default_likert_style"><option value="agreement">Strongly Agree → Strongly Disagree</option><option value="quality">Excellent → Poor</option></select></div>
      <div class="field"><label><input type="checkbox" name="default_required" value="1" checked style="width:auto"> Make imported questions required by default</label></div>
      <div class="field"><label><input type="checkbox" name="replace_existing" value="1" style="width:auto" <?= $responseCount>0?'disabled':'' ?>> Replace all existing questions before import</label><?php if($responseCount>0): ?><div class="muted">Disabled because this event already has <?= $responseCount ?> submitted evaluation(s).</div><?php endif; ?></div>
    </div>
    <div class="actions" style="margin-top:16px"><button name="upload_questions" value="1">Upload &amp; Import Questions</button></div>
  </form>
</div>

<div class="grid grid-2" style="margin-bottom:18px">
  <div class="card card-pad">
    <h3 style="margin-top:0">Simple TXT Format</h3>
    <p class="muted">Create a text file with one question on each line. All questions use the defaults selected above.</p>
    <pre style="white-space:pre-wrap;background:#f8fafc;padding:12px;border-radius:10px">The objectives were clearly presented.
The activity was relevant to my needs.
The facilitator explained the topic clearly.
I can apply what I learned.</pre>
  </div>
  <div class="card card-pad">
    <h3 style="margin-top:0">Advanced CSV Format</h3>
    <p class="muted">Use the template when you need categories or mixed question types. For choices, separate options with the | symbol.</p>
    <div class="muted"><strong>Columns:</strong> category, question_text, question_type, likert_style, required, options, sort_order</div>
  </div>
</div>

<?php if(!$questions): ?>
<div class="alert warning">No questions are configured yet. Upload your file above, or use the standard template as an alternative.</div>
<form method="post" style="margin-bottom:18px"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>"><button class="ghost" name="load_template" value="1">Use Standard Template Instead</button></form>
<?php endif; ?>

<div class="section-title"><div><h2>2. Review Imported Questions</h2><p><?= count($questions) ?> questions configured. You may edit individual items after uploading.</p></div></div>

<div class="grid grid-2">
  <div class="card card-pad">
    <h2 style="margin-top:0">Add Category Manually</h2>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>">
      <div class="field"><label>Category Name</label><input name="name" required placeholder="e.g. Venue and Facilities"></div>
      <div class="field" style="margin-top:10px"><label>Description</label><input name="description"></div>
      <div class="field" style="margin-top:10px"><label>Sort Order</label><input type="number" name="sort_order" value="1"></div>
      <button style="margin-top:12px" name="add_category" value="1">Add Category</button>
    </form>
  </div>
  <div class="card card-pad">
    <h2 style="margin-top:0">Add One Question Manually</h2>
    <form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>">
      <div class="field"><label>Category</label><select name="category_id"><option value="0">Uncategorized</option><?php foreach($categories as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
      <div class="field" style="margin-top:10px"><label>Question</label><textarea name="question_text" required></textarea></div>
      <div class="form-grid" style="margin-top:10px"><div class="field"><label>Question Type</label><select name="question_type"><option value="likert">5-Point Likert</option><option value="multiple_choice">Multiple Choice</option><option value="checkbox">Checkbox</option><option value="yes_no">Yes / No</option><option value="dropdown">Dropdown</option><option value="short_answer">Short Answer</option><option value="paragraph">Paragraph</option></select></div><div class="field"><label>Likert Wording</label><select name="likert_style"><option value="agreement">Strongly Agree → Strongly Disagree</option><option value="quality">Excellent → Poor</option></select></div></div>
      <div class="field" style="margin-top:10px"><label>Options <span class="muted">(one per line for choice fields)</span></label><textarea name="options" placeholder="Option 1&#10;Option 2&#10;Option 3"></textarea></div>
      <div class="form-grid" style="margin-top:10px"><div class="field"><label>Sort Order</label><input type="number" name="sort_order" value="1"></div><div class="field"><label><input type="checkbox" name="is_required" value="1" checked style="width:auto"> Required</label></div></div>
      <button style="margin-top:12px" name="add_question" value="1">Add Question</button>
    </form>
  </div>
</div>

<div class="section-title"><div><h2>Categories</h2><p>Categories created by your CSV are listed here automatically.</p></div></div>
<?php if(!$categories): ?><div class="card empty">No categories yet. Categories are optional.</div><?php else: ?><div class="grid grid-2"><?php foreach($categories as $c): ?><div class="card card-pad"><form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="category_id" value="<?= (int)$c['id'] ?>"><div class="field"><label>Name</label><input name="name" value="<?= h($c['name']) ?>" required></div><div class="field" style="margin-top:8px"><label>Description</label><input name="description" value="<?= h($c['description']) ?>"></div><div class="field" style="margin-top:8px"><label>Sort Order</label><input type="number" name="sort_order" value="<?= (int)$c['sort_order'] ?>"></div><div class="actions" style="margin-top:10px"><button class="small" name="update_category" value="1">Save</button><button class="small danger" name="delete_category" value="1" data-confirm="Delete this category? Its questions will become uncategorized.">Delete</button></div></form></div><?php endforeach; ?></div><?php endif; ?>

<div class="section-title"><div><h2>Questions</h2><p>Review or fine-tune imported questions before opening the evaluation.</p></div></div>
<?php if(!$questions): ?><div class="card empty">No questions have been added.</div><?php else: foreach($questions as $q): $opts=question_options($q); ?>
<div class="card card-pad" style="margin-bottom:12px"><form method="post"><input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="event_id" value="<?= $eventId ?>"><input type="hidden" name="question_id" value="<?= (int)$q['id'] ?>"><div class="eyebrow"><?= h($q['category_name']?:'Uncategorized') ?> · <?= h(str_replace('_',' ',ucfirst($q['question_type']))) ?></div><div class="field" style="margin-top:8px"><label>Question</label><textarea name="question_text" required><?= h($q['question_text']) ?></textarea></div><div class="form-grid" style="margin-top:8px"><div class="field"><label>Category</label><select name="category_id"><option value="0">Uncategorized</option><?php foreach($categories as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$q['category_id']===(int)$c['id']?'selected':'' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></div><div class="field"><label>Type</label><select name="question_type"><?php foreach(['likert','multiple_choice','checkbox','yes_no','dropdown','short_answer','paragraph'] as $t): ?><option value="<?= $t ?>" <?= $q['question_type']===$t?'selected':'' ?>><?= h(ucwords(str_replace('_',' ',$t))) ?></option><?php endforeach; ?></select></div><div class="field"><label>Likert Wording</label><select name="likert_style"><option value="agreement" <?= $q['likert_style']==='agreement'?'selected':'' ?>>Strongly Agree → Strongly Disagree</option><option value="quality" <?= $q['likert_style']==='quality'?'selected':'' ?>>Excellent → Poor</option></select></div><div class="field"><label>Sort Order</label><input type="number" name="sort_order" value="<?= (int)$q['sort_order'] ?>"></div></div><div class="field" style="margin-top:8px"><label>Options</label><textarea name="options"><?= h(implode("\n",$opts)) ?></textarea></div><label style="display:block;margin-top:8px"><input type="checkbox" name="is_required" value="1" style="width:auto" <?= $q['is_required']?'checked':'' ?>> Required</label><div class="actions" style="margin-top:10px"><button class="small" name="update_question" value="1">Save Question</button><button class="small danger" name="delete_question" value="1" data-confirm="Delete this question and its recorded answers?">Delete</button></div></form></div>
<?php endforeach; endif; ?>
<?php require __DIR__.'/../includes/footer.php'; ?>
