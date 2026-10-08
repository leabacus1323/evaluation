<?php
require_once __DIR__ . '/database.php';

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function base_url(string $path = ''): string
{
    if (BASE_URL !== '') {
        return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $basePath = preg_replace('#/(admin/)?[^/]+$#', '', $script);
    return $scheme . '://' . $host . rtrim($basePath, '/') . '/' . ltrim($path, '/');
}

function setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT setting_key, setting_value FROM settings') as $row) {
                $cache[$row['setting_key']] = (string)$row['setting_value'];
            }
        } catch (Throwable $e) {
            return $default;
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function set_setting(string $key, string $value): void
{
    $stmt = db()->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
    $stmt->execute([$key, $value]);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        exit('Invalid request token. Refresh the page and try again.');
    }
}

function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function get_flash(): array
{
    $data = $_SESSION['flash'] ?? ['message' => '', 'type' => 'success'];
    unset($_SESSION['flash']);
    return $data;
}

function event_code(): string
{
    $year = date('Y');
    $prefix = 'EVT' . $year . '-';
    $stmt = db()->prepare('SELECT code FROM events WHERE code LIKE ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    $next = 1;
    if ($last && preg_match('/(\d{3,})$/', (string)$last, $m)) {
        $next = ((int)$m[1]) + 1;
    }
    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

function event_is_open(array $event): bool
{
    if (($event['status'] ?? '') !== 'open') {
        return false;
    }
    $now = time();
    if (!empty($event['evaluation_start'])) {
        $start = strtotime((string)$event['evaluation_start']);
        if ($start && $now < $start) return false;
    }
    if (!empty($event['evaluation_deadline'])) {
        $end = strtotime((string)$event['evaluation_deadline']);
        if ($end && $now > $end) return false;
    }
    return true;
}

function mean_interpretation(float $mean): string
{
    if ($mean >= 4.21) return 'Excellent';
    if ($mean >= 3.41) return 'Very Good';
    if ($mean >= 2.61) return 'Good';
    if ($mean >= 1.81) return 'Fair';
    if ($mean > 0) return 'Poor';
    return 'No Data';
}

function likert_label(int $value, string $style = 'quality'): string
{
    $quality = [5 => 'Excellent', 4 => 'Very Good', 3 => 'Good', 2 => 'Fair', 1 => 'Poor'];
    $agreement = [5 => 'Strongly Agree', 4 => 'Agree', 3 => 'Neutral', 2 => 'Disagree', 1 => 'Strongly Disagree'];
    $set = $style === 'agreement' ? $agreement : $quality;
    return $set[$value] ?? (string)$value;
}

function question_options(array $question): array
{
    $raw = $question['options_json'] ?? '';
    if ($raw === null || $raw === '') return [];
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded), fn($v) => trim($v) !== '')) : [];
}

function event_url(array $event): string
{
    return base_url('evaluate.php?event=' . urlencode((string)$event['code']));
}

function get_event_by_code(string $code): ?array
{
    $stmt = db()->prepare('SELECT * FROM events WHERE code=? LIMIT 1');
    $stmt->execute([$code]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_event_by_id(int $id): ?array
{
    $stmt = db()->prepare('SELECT * FROM events WHERE id=? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_categories(int $eventId): array
{
    $stmt = db()->prepare('SELECT * FROM evaluation_categories WHERE event_id=? ORDER BY sort_order,id');
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

function get_questions(int $eventId): array
{
    $stmt = db()->prepare('SELECT q.*, c.name AS category_name FROM questions q LEFT JOIN evaluation_categories c ON c.id=q.category_id WHERE q.event_id=? ORDER BY COALESCE(c.sort_order,9999), q.sort_order, q.id');
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}


// -----------------------------------------------------------------------------
// EDITABLE PARTICIPANT INFORMATION / PROFILE FIELDS
// Each event can define its own participant-information fields. Existing
// installations are upgraded automatically by creating the two tables below.
// Standard keys continue to mirror values into the legacy respondents columns
// so existing reports remain compatible.
// -----------------------------------------------------------------------------
function ensure_participant_profile_schema(): void
{
    static $done = false;
    if ($done) return;
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS participant_fields (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        event_id INT UNSIGNED NOT NULL,
        field_key VARCHAR(80) NOT NULL,
        label VARCHAR(160) NOT NULL,
        field_type ENUM('text','email','number','select','radio','checkbox','textarea') NOT NULL DEFAULT 'text',
        placeholder VARCHAR(255) NULL,
        options_json TEXT NULL,
        is_required TINYINT(1) NOT NULL DEFAULT 0,
        hide_when_anonymous TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_participant_field_key (event_id,field_key),
        INDEX idx_participant_fields_event_sort (event_id,is_active,sort_order),
        CONSTRAINT fk_participant_field_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS respondent_profile_answers (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        respondent_id BIGINT UNSIGNED NOT NULL,
        field_id INT UNSIGNED NOT NULL,
        answer_text TEXT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_profile_answer (respondent_id,field_id),
        INDEX idx_profile_answers_field (field_id),
        CONSTRAINT fk_profile_answer_respondent FOREIGN KEY (respondent_id) REFERENCES respondents(id) ON DELETE CASCADE,
        CONSTRAINT fk_profile_answer_field FOREIGN KEY (field_id) REFERENCES participant_fields(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Existing events from older versions did not have editable profile fields.
    // Seed the former default layout only when an event has no fields at all.
    try {
        $events = $pdo->query('SELECT id,collect_email FROM events ORDER BY id')->fetchAll();
        $count = $pdo->prepare('SELECT COUNT(*) FROM participant_fields WHERE event_id=?');
        foreach ($events as $event) {
            $count->execute([(int)$event['id']]);
            if ((int)$count->fetchColumn() === 0) {
                seed_default_participant_fields((int)$event['id'], !empty($event['collect_email']));
            }
        }
    } catch (Throwable $e) {
        // Fresh schema setup may still be in progress. The page can retry later.
    }
    $done = true;
}

function seed_default_participant_fields(int $eventId, bool $includeEmail = true): void
{
    $pdo = db();
    $defaults = [
        ['name', 'Name', 'text', '', [], 0, 1],
    ];
    if ($includeEmail) {
        $defaults[] = ['email', 'Email', 'email', '', [], 0, 1];
    }
    $defaults = array_merge($defaults, [
        ['department', 'Department / Office', 'text', '', [], 0, 0],
        ['program', 'Program / Course', 'text', '', [], 0, 0],
        ['year_level', 'Year Level', 'text', 'e.g. 2nd Year', [], 0, 0],
        ['participant_type', 'Participant Type', 'select', '', ['Student','Faculty','Staff','Guest','External Participant'], 0, 0],
    ]);
    $stmt = $pdo->prepare('INSERT IGNORE INTO participant_fields (event_id,field_key,label,field_type,placeholder,options_json,is_required,hide_when_anonymous,is_active,sort_order) VALUES (?,?,?,?,?,?,?,?,1,?)');
    $order = 1;
    foreach ($defaults as $d) {
        $stmt->execute([
            $eventId, $d[0], $d[1], $d[2], $d[3] !== '' ? $d[3] : null,
            $d[4] ? json_encode($d[4], JSON_UNESCAPED_UNICODE) : null,
            $d[5], $d[6], $order++
        ]);
    }
}

function get_participant_fields(int $eventId, bool $includeInactive = false): array
{
    ensure_participant_profile_schema();
    $sql = 'SELECT pf.*, (SELECT COUNT(*) FROM respondent_profile_answers rpa WHERE rpa.field_id=pf.id) AS answer_count FROM participant_fields pf WHERE pf.event_id=?';
    if (!$includeInactive) $sql .= ' AND pf.is_active=1';
    $sql .= ' ORDER BY pf.sort_order,pf.id';
    $stmt = db()->prepare($sql);
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

function participant_field_options(array $field): array
{
    $raw = $field['options_json'] ?? '';
    if (!$raw) return [];
    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded), fn($v) => trim($v) !== '')) : [];
}

function participant_field_key(string $label, int $eventId): string
{
    $base = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $label), '_'));
    if ($base === '') $base = 'field';
    $base = substr($base, 0, 50);
    $key = 'custom_' . $base;
    $stmt = db()->prepare('SELECT COUNT(*) FROM participant_fields WHERE event_id=? AND field_key=?');
    $i = 1;
    while (true) {
        $candidate = $i === 1 ? $key : $key . '_' . $i;
        $stmt->execute([$eventId, $candidate]);
        if ((int)$stmt->fetchColumn() === 0) return $candidate;
        $i++;
    }
}

function participant_profile_answer_map(int $respondentId): array
{
    ensure_participant_profile_schema();
    $stmt = db()->prepare('SELECT field_id,answer_text FROM respondent_profile_answers WHERE respondent_id=?');
    $stmt->execute([$respondentId]);
    $map = [];
    foreach ($stmt as $row) $map[(int)$row['field_id']] = (string)($row['answer_text'] ?? '');
    return $map;
}

function legacy_profile_value(array $respondent, array $field): string
{
    $key = (string)($field['field_key'] ?? '');
    $allowed = ['name','email','department','program','year_level','participant_type'];
    if (in_array($key, $allowed, true)) return (string)($respondent[$key] ?? '');
    return '';
}

function display_profile_answer(string $value): string
{
    $decoded = json_decode($value, true);
    if (is_array($decoded)) return implode(', ', array_map('strval', $decoded));
    return $value;
}

function response_count(int $eventId): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM respondents WHERE event_id=?');
    $stmt->execute([$eventId]);
    return (int)$stmt->fetchColumn();
}

function event_overall_mean(int $eventId): float
{
    $stmt = db()->prepare("SELECT AVG(ra.answer_numeric) FROM response_answers ra JOIN questions q ON q.id=ra.question_id JOIN respondents r ON r.id=ra.respondent_id WHERE r.event_id=? AND q.question_type='likert' AND ra.answer_numeric IS NOT NULL");
    $stmt->execute([$eventId]);
    return (float)($stmt->fetchColumn() ?: 0);
}

function event_category_means(int $eventId): array
{
    $stmt = db()->prepare("SELECT c.id,c.name,c.sort_order,AVG(CASE WHEN r.id IS NOT NULL THEN ra.answer_numeric END) AS mean_score,COUNT(CASE WHEN r.id IS NOT NULL THEN ra.id END) AS answer_count FROM evaluation_categories c LEFT JOIN questions q ON q.category_id=c.id AND q.question_type='likert' LEFT JOIN response_answers ra ON ra.question_id=q.id LEFT JOIN respondents r ON r.id=ra.respondent_id AND r.event_id=? WHERE c.event_id=? GROUP BY c.id,c.name,c.sort_order ORDER BY c.sort_order,c.id");
    $stmt->execute([$eventId, $eventId]);
    return $stmt->fetchAll();
}

function event_question_stats(int $eventId): array
{
    $stmt = db()->prepare("SELECT q.id,q.question_text,q.question_type,q.sort_order,c.name AS category_name,AVG(ra.answer_numeric) AS mean_score,COUNT(ra.id) AS answer_count FROM questions q LEFT JOIN evaluation_categories c ON c.id=q.category_id LEFT JOIN response_answers ra ON ra.question_id=q.id LEFT JOIN respondents r ON r.id=ra.respondent_id WHERE q.event_id=? GROUP BY q.id,q.question_text,q.question_type,q.sort_order,c.name ORDER BY COALESCE(c.sort_order,9999),q.sort_order,q.id");
    $stmt->execute([$eventId]);
    return $stmt->fetchAll();
}

function rating_distribution(int $eventId): array
{
    $result = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    $stmt = db()->prepare("SELECT CAST(ra.answer_numeric AS UNSIGNED) AS rating,COUNT(*) AS total FROM response_answers ra JOIN respondents r ON r.id=ra.respondent_id JOIN questions q ON q.id=ra.question_id WHERE r.event_id=? AND q.question_type='likert' AND ra.answer_numeric BETWEEN 1 AND 5 GROUP BY CAST(ra.answer_numeric AS UNSIGNED)");
    $stmt->execute([$eventId]);
    foreach ($stmt as $row) {
        $rating = (int)$row['rating'];
        if (isset($result[$rating])) $result[$rating] = (int)$row['total'];
    }
    return $result;
}

function keyword_frequency(array $comments, int $limit = 12): array
{
    $stop = array_flip(['the','and','that','this','with','from','were','was','are','for','but','have','has','had','very','more','most','our','your','you','they','their','its','not','too','can','could','would','should','about','into','than','then','also','all','really','just','activity','seminar','training','workshop','event','speaker','facilitator','session','program','good','great']);
    $freq = [];
    foreach ($comments as $comment) {
        $text = mb_strtolower(strip_tags((string)$comment));
        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($words as $word) {
            if (mb_strlen($word) < 4 || isset($stop[$word]) || is_numeric($word)) continue;
            $freq[$word] = ($freq[$word] ?? 0) + 1;
        }
    }
    arsort($freq);
    return array_slice($freq, 0, $limit, true);
}

function ensure_default_admin(): void
{
    try {
        $count = (int)db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        if ($count === 0) {
            $hash = '$2y$12$QElQy7PbCBBK4zkLIgt69.O8RCm6TDoUiLijaTs1yafbnGKGptmRC'; // admin123
            $stmt = db()->prepare('INSERT INTO admins (username,password_hash,display_name) VALUES (?,?,?)');
            $stmt->execute([DEFAULT_ADMIN_USERNAME, $hash, 'System Administrator']);
        }
    } catch (Throwable $e) {
        // schema may not be imported yet; database.php will still show the main error if needed.
    }
}

function seed_standard_template(int $eventId): void
{
    $pdo = db();
    $existing = $pdo->prepare('SELECT COUNT(*) FROM questions WHERE event_id=?');
    $existing->execute([$eventId]);
    if ((int)$existing->fetchColumn() > 0) {
        return;
    }

    $template = [
        'Program / Activity Content' => [
            'The objectives of the activity were clearly presented.',
            'The topics were relevant to the purpose of the activity.',
            'The content was useful and applicable.',
            'The program was well organized.',
            'The activity met my expectations.',
        ],
        'Speaker / Trainer / Facilitator' => [
            'The speaker demonstrated knowledge of the topic.',
            'The speaker explained the topic clearly.',
            'The speaker encouraged participant interaction.',
            'The speaker responded effectively to questions.',
            'The speaker maintained the interest of the participants.',
        ],
        'Learning and Engagement' => [
            'The activity increased my knowledge of the topic.',
            'The activities encouraged active participation.',
            'The learning activities were appropriate and meaningful.',
            'I can apply what I learned from this activity.',
            'The activity encouraged further learning.',
        ],
        'Organization and Management' => [
            'Registration and attendance procedures were organized.',
            'The schedule and time allocation were appropriate.',
            'The organizers provided clear instructions.',
            'The program flow was properly managed.',
            'The organizers responded effectively to participants’ needs.',
        ],
        'Venue and Facilities' => [
            'The venue was appropriate for the activity.',
            'The facilities were comfortable and suitable.',
            'Audio-visual equipment functioned effectively.',
            'The venue supported meaningful participation.',
        ],
        'Overall Evaluation' => [
            'Overall, I am satisfied with the activity.',
            'The activity achieved its intended objectives.',
            'I would recommend similar activities in the future.',
        ],
    ];

    $pdo->beginTransaction();
    try {
        $catStmt = $pdo->prepare('INSERT INTO evaluation_categories (event_id,name,sort_order) VALUES (?,?,?)');
        $qStmt = $pdo->prepare("INSERT INTO questions (event_id,category_id,question_text,question_type,likert_style,options_json,is_required,sort_order) VALUES (?,?,?,'likert','quality',NULL,1,?)");
        $catOrder = 1;
        foreach ($template as $category => $questions) {
            $catStmt->execute([$eventId, $category, $catOrder]);
            $categoryId = (int)$pdo->lastInsertId();
            $qOrder = 1;
            foreach ($questions as $text) {
                $qStmt->execute([$eventId, $categoryId, $text, $qOrder++]);
            }
            $catOrder++;
        }

        $openCat = $pdo->prepare('INSERT INTO evaluation_categories (event_id,name,sort_order) VALUES (?,?,?)');
        $openCat->execute([$eventId, 'Participant Comments and Recommendations', 99]);
        $openCatId = (int)$pdo->lastInsertId();
        $openQ = $pdo->prepare("INSERT INTO questions (event_id,category_id,question_text,question_type,likert_style,options_json,is_required,sort_order) VALUES (?,?,?,'paragraph','quality',NULL,0,?)");
        $openTexts = [
            'What did you like most about the activity?',
            'What important knowledge or skills did you gain?',
            'What aspects of the activity should be improved?',
            'What topics would you recommend for future activities?',
            'Other comments, suggestions, or recommendations:',
        ];
        foreach ($openTexts as $i => $text) {
            $openQ->execute([$eventId, $openCatId, $text, $i + 1]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

ensure_default_admin();
ensure_participant_profile_schema();

// -----------------------------------------------------------------------------
// GOOGLE FORMS / GOOGLE DRIVE INTEGRATION
// Uses a Google Apps Script Web App as a secure bridge. This avoids storing
// Google OAuth access tokens on shared hosting. The Apps Script executes as
// the Google account that owns the script and creates the Form + response Sheet.
// -----------------------------------------------------------------------------
function ensure_google_integration_schema(): void
{
    static $done = false;
    if ($done) return;
    $pdo = db();
    $columns = [
        'google_form_id' => "VARCHAR(180) NULL",
        'google_form_url' => "TEXT NULL",
        'google_form_edit_url' => "TEXT NULL",
        'google_form_summary_url' => "TEXT NULL",
        'google_sheet_id' => "VARCHAR(180) NULL",
        'google_sheet_url' => "TEXT NULL",
        'google_generated_at' => "DATETIME NULL",
    ];
    foreach ($columns as $name => $definition) {
        $st = $pdo->prepare("SHOW COLUMNS FROM events LIKE ?");
        $st->execute([$name]);
        if (!$st->fetch()) {
            $pdo->exec("ALTER TABLE events ADD COLUMN `$name` $definition");
        }
    }
    $done = true;
}

function google_integration_configured(): bool
{
    return trim(setting('google_apps_script_url', '')) !== ''
        && trim(setting('google_apps_script_secret', '')) !== '';
}

function google_bridge_request(array $payload, int $timeout = 45): array
{
    $url = trim(setting('google_apps_script_url', ''));
    $secret = trim(setting('google_apps_script_secret', ''));
    if ($url === '' || $secret === '') {
        return ['ok' => false, 'error' => 'Google integration is not configured. Add the Apps Script Web App URL and shared secret in Settings.'];
    }

    $payload['secret'] = $secret;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        return ['ok' => false, 'error' => 'Unable to encode the Google Forms request.'];
    }

    $body = false;
    $httpCode = 0;
    $curlError = '';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'EventEvaluationSystem/1.0',
        ]);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = (string)curl_error($ch);
        curl_close($ch);
    } else {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                'content' => $json,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('#HTTP/\S+\s+(\d{3})#', $header, $m)) $httpCode = (int)$m[1];
            }
        }
    }

    if ($body === false || trim((string)$body) === '') {
        $extra = $curlError !== '' ? ' cURL: ' . $curlError : '';
        return ['ok' => false, 'error' => 'No response was received from Google Apps Script.' . $extra];
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) {
        $preview = trim(strip_tags((string)$body));
        if (strlen($preview) > 220) $preview = substr($preview, 0, 220) . '…';
        return ['ok' => false, 'error' => 'Google returned an unexpected response' . ($httpCode ? " (HTTP $httpCode)" : '') . ': ' . $preview];
    }

    if ($httpCode >= 400 && !isset($decoded['ok'])) {
        $decoded['ok'] = false;
        $decoded['error'] = $decoded['error'] ?? ('Google request failed with HTTP ' . $httpCode . '.');
    }
    return $decoded;
}

function google_event_payload(int $eventId): array
{
    ensure_google_integration_schema();
    $event = get_event_by_id($eventId);
    if (!$event) throw new RuntimeException('Event not found.');
    $questions = get_questions($eventId);

    $questionPayload = [];
    foreach ($questions as $q) {
        $questionPayload[] = [
            'id' => (int)$q['id'],
            'category' => (string)($q['category_name'] ?: 'General Evaluation'),
            'text' => (string)$q['question_text'],
            'type' => (string)$q['question_type'],
            'likert_style' => (string)$q['likert_style'],
            'required' => !empty($q['is_required']),
            'options' => question_options($q),
        ];
    }

    $participantFields = [];
    foreach (get_participant_fields($eventId) as $field) {
        $participantFields[] = [
            'id' => (int)$field['id'],
            'key' => (string)$field['field_key'],
            'label' => (string)$field['label'],
            'type' => (string)$field['field_type'],
            'placeholder' => (string)($field['placeholder'] ?? ''),
            'required' => !empty($field['is_required']),
            'hide_when_anonymous' => !empty($field['hide_when_anonymous']),
            'options' => participant_field_options($field),
        ];
    }

    return [
        'action' => 'create_form',
        'organization_name' => setting('organization_name', 'Your Institution'),
        'drive_folder_id' => trim(setting('google_drive_folder_id', '')),
        'event' => [
            'id' => (int)$event['id'],
            'code' => (string)$event['code'],
            'title' => (string)$event['title'],
            'event_type' => (string)$event['event_type'],
            'theme' => (string)($event['theme'] ?? ''),
            'description' => (string)($event['description'] ?? ''),
            'event_date' => (string)$event['event_date'],
            'start_time' => (string)($event['start_time'] ?? ''),
            'end_time' => (string)($event['end_time'] ?? ''),
            'venue' => (string)($event['venue'] ?? ''),
            'organizer' => (string)($event['organizer'] ?? ''),
            'speaker' => (string)($event['speaker'] ?? ''),
            'target_participants' => (string)($event['target_participants'] ?? ''),
            'status' => (string)$event['status'],
            'allow_anonymous' => !empty($event['allow_anonymous']),
            'collect_email' => !empty($event['collect_email']),
        ],
        'participant_fields' => $participantFields,
        'questions' => $questionPayload,
    ];
}

function generate_google_form_for_event(int $eventId): array
{
    ensure_google_integration_schema();
    $payload = google_event_payload($eventId);
    if (empty($payload['questions'])) {
        return ['ok' => false, 'error' => 'This event has no evaluation questions. Upload or create questions first.'];
    }

    $result = google_bridge_request($payload, 60);
    if (!empty($result['ok'])) {
        $stmt = db()->prepare('UPDATE events SET google_form_id=?,google_form_url=?,google_form_edit_url=?,google_form_summary_url=?,google_sheet_id=?,google_sheet_url=?,google_generated_at=NOW() WHERE id=?');
        $stmt->execute([
            $result['form_id'] ?? null,
            $result['form_url'] ?? null,
            $result['edit_url'] ?? null,
            $result['summary_url'] ?? null,
            $result['sheet_id'] ?? null,
            $result['sheet_url'] ?? null,
            $eventId,
        ]);
    }
    return $result;
}

function sync_google_form_status(int $eventId): array
{
    ensure_google_integration_schema();
    $event = get_event_by_id($eventId);
    if (!$event) return ['ok' => false, 'error' => 'Event not found.'];
    if (empty($event['google_form_id'])) return ['ok' => false, 'error' => 'No Google Form has been generated for this event.'];

    return google_bridge_request([
        'action' => 'set_form_status',
        'form_id' => (string)$event['google_form_id'],
        'accepting_responses' => (($event['status'] ?? '') === 'open'),
    ]);
}
