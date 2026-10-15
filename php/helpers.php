<?php
// CareerBridge — shared helpers.
//
// Include from any page: require __DIR__ . '/helpers.php';
// This file assumes $pdo from db.php is available to the caller.

if (!defined('CAREERBRIDGE')) {
    define('CAREERBRIDGE', true);
}

// ---------------------------------------------------------------
// Small utilities
// ---------------------------------------------------------------

// Escape for HTML output. Use on every value that came from the
// database or a form, e.g. inside a template echo e($job['title']).
function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

// Character count that does not require the mbstring extension.
// mbstring is usually enabled but is not guaranteed, and a missing
// mb_strlen() is a fatal error rather than a graceful failure.
function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
}

// Truncate for a preview without needing mbstring.
function mb_substr_safe(string $value, int $limit): string
{
    $cut = function_exists('mb_substr')
        ? mb_substr($value, 0, $limit)
        : substr($value, 0, $limit);

    return $cut . (text_length($value) > $limit ? '...' : '');
}

// Redirect and stop. Always exit after header('Location:').
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

// One-shot message across a redirect.
function flash(?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'] = $message;
        return null;
    }
    $out = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $out;
}


// ---------------------------------------------------------------
// Expired-attempt sweep
//
// An attempt that times out has submitted_at, score_percent and
// passed all NULL. Nothing moves the application forward, and the
// attempt-2 cooldown has no timestamp to anchor on.
//
// Call this at the top of every candidate page that reads
// application or attempt state:
//   candidate-dashboard.php
//   candidate-applications.php
//   candidate-assessment.php
//   candidate-grooming.php
//
// Scoring an expired attempt uses whatever is already in
// attempt_answers, and stamps submitted_at = expires_at so the
// cooldown starts from when the clock actually ran out.
// ---------------------------------------------------------------
function sweep_expired_attempts(PDO $pdo): void
{
    $stale = $pdo->query("
        SELECT t.id, t.application_id, t.attempt_no, t.expires_at,
               a.id AS assessment_id, a.passing_score
        FROM attempts t
        JOIN applications ap ON ap.id = t.application_id
        JOIN assessments  a  ON a.job_id = ap.job_id
        WHERE t.submitted_at IS NULL
          AND t.expires_at < NOW()
    ")->fetchAll();

    foreach ($stale as $row) {
        score_attempt($pdo, $row, $row['expires_at']);
    }
}


// ---------------------------------------------------------------
// Scoring
//
// Shared by the sweep above and the normal submit handler, so a
// timed-out attempt and a submitted one are scored by identical
// logic. $row needs: id, application_id, attempt_no,
// assessment_id, passing_score.
// ---------------------------------------------------------------
function score_attempt(PDO $pdo, array $row, string $submittedAt): void
{
    // Unanswered questions contribute to the total but earn nothing,
    // so the LEFT JOINs are deliberate — an INNER JOIN would score
    // an abandoned attempt out of only the questions it answered.
    $q = $pdo->prepare("
        SELECT COALESCE(SUM(q.points), 0) AS total,
               COALESCE(SUM(CASE WHEN o.is_correct = 1 THEN q.points ELSE 0 END), 0) AS earned
        FROM questions q
        LEFT JOIN attempt_answers aa
               ON aa.question_id = q.id
              AND aa.attempt_id  = :attempt
        LEFT JOIN question_options o
               ON o.id = aa.option_id
        WHERE q.assessment_id = :assessment
    ");
    $q->execute([
        ':attempt'    => $row['id'],
        ':assessment' => $row['assessment_id'],
    ]);
    $s = $q->fetch();

    $percent = $s['total'] > 0
        ? round($s['earned'] / $s['total'] * 100, 2)
        : 0.00;

    $passed = $percent >= $row['passing_score'] ? 1 : 0;

    $pdo->beginTransaction();

    $pdo->prepare("
        UPDATE attempts
        SET submitted_at = :sub, score_percent = :pct, passed = :passed
        WHERE id = :id AND submitted_at IS NULL
    ")->execute([
        ':sub'    => $submittedAt,
        ':pct'    => $percent,
        ':passed' => $passed,
        ':id'     => $row['id'],
    ]);

    if ($passed) {
        $status = 'qualified';
    } elseif ((int) $row['attempt_no'] === 1) {
        $status = 'grooming';
    } else {
        $status = 'rejected';
    }

    $pdo->prepare("UPDATE applications SET status = :st WHERE id = :id")
        ->execute([':st' => $status, ':id' => $row['application_id']]);

    // Freeze the assessment the first time anyone submits against
    // it, so later edits can't make scores incomparable.
    $pdo->prepare("
        UPDATE assessments
        SET locked_at = NOW()
        WHERE id = :id AND locked_at IS NULL
    ")->execute([':id' => $row['assessment_id']]);

    $pdo->commit();
}


// ---------------------------------------------------------------
// Grooming
//
// There is no "grooming done" flag. It is complete when every
// resource matched to the job's required skills has a completed_at
// row for this application. One source of truth, nothing to drift.
// ---------------------------------------------------------------
function grooming_resources_for(PDO $pdo, int $applicationId): array
{
    $stmt = $pdo->prepare("
        SELECT r.id, r.title, r.video_url, r.min_watch_seconds,
               s.name AS skill,
               COALESCE(p.watched_seconds, 0) AS watched_seconds,
               p.completed_at
        FROM applications ap
        JOIN job_skills js         ON js.job_id   = ap.job_id
        JOIN skills s              ON s.id        = js.skill_id
        JOIN grooming_resources r  ON r.skill_id  = s.id
        LEFT JOIN grooming_progress p
               ON p.resource_id    = r.id
              AND p.application_id = ap.id
        WHERE ap.id = :app
        ORDER BY s.name, r.id
    ");
    $stmt->execute([':app' => $applicationId]);
    return $stmt->fetchAll();
}

function grooming_is_complete(PDO $pdo, int $applicationId): bool
{
    $resources = grooming_resources_for($pdo, $applicationId);

    if (!$resources) {
        // The job lists no skills, or its skills have no material.
        // Don't trap the candidate — let them through.
        return true;
    }

    foreach ($resources as $r) {
        if ($r['completed_at'] === null) {
            return false;
        }
    }
    return true;
}

// Record watch progress and mark complete once the threshold is met.
// Call from the grooming page when the candidate reports progress or
// clicks "Mark as complete".
function record_grooming_progress(PDO $pdo, int $applicationId, int $resourceId, int $watchedSeconds): void
{
    $min = (int) $pdo->query(
        "SELECT min_watch_seconds FROM grooming_resources WHERE id = " . (int) $resourceId
    )->fetchColumn();

    $complete = $watchedSeconds >= $min ? 'NOW()' : 'NULL';

    $pdo->prepare("
        INSERT INTO grooming_progress (application_id, resource_id, watched_seconds, completed_at)
        VALUES (:app, :res, :sec, $complete)
        ON DUPLICATE KEY UPDATE
            watched_seconds = GREATEST(watched_seconds, VALUES(watched_seconds)),
            completed_at    = COALESCE(completed_at, VALUES(completed_at))
    ")->execute([
        ':app' => $applicationId,
        ':res' => $resourceId,
        ':sec' => $watchedSeconds,
    ]);
}


// ---------------------------------------------------------------
// Eligibility
//
// Every gate in one place, so the dashboard, the applications list
// and the assessment page can never disagree about what a candidate
// is allowed to do next.
//
// Returns ['action' => ..., 'reason' => ...] where action is one of:
//   start_1   sit attempt 1
//   grooming  work through resources first
//   start_2   sit attempt 2
//   wait      cooldown has not elapsed
//   blocked   nothing further is possible
// ---------------------------------------------------------------
function attempt_eligibility(PDO $pdo, int $applicationId): array
{
    $stmt = $pdo->prepare("
        SELECT ap.id, ap.status, j.deadline, j.cooldown_days, j.status AS job_status,
               (SELECT COUNT(*) FROM attempts t WHERE t.application_id = ap.id) AS attempt_count,
               (SELECT t.submitted_at FROM attempts t
                 WHERE t.application_id = ap.id AND t.attempt_no = 1) AS first_submitted,
               (SELECT t.id FROM attempts t
                 WHERE t.application_id = ap.id AND t.submitted_at IS NULL
                 ORDER BY t.attempt_no DESC LIMIT 1) AS open_attempt
        FROM applications ap
        JOIN jobs j ON j.id = ap.job_id
        WHERE ap.id = :app
    ");
    $stmt->execute([':app' => $applicationId]);
    $a = $stmt->fetch();

    if (!$a) {
        return ['action' => 'blocked', 'reason' => 'Application not found.'];
    }

    // An attempt already in progress beats every other consideration.
    if ($a['open_attempt']) {
        return ['action' => $a['attempt_count'] > 1 ? 'start_2' : 'start_1',
                'reason' => 'You have an attempt in progress.'];
    }

    if ($a['status'] === 'qualified') {
        return ['action' => 'blocked', 'reason' => 'You have already passed. Your profile is with the company.'];
    }
    if ($a['status'] === 'rejected') {
        return ['action' => 'blocked', 'reason' => 'Both attempts are used for this job.'];
    }
    if ($a['job_status'] === 'closed' || strtotime($a['deadline']) < time()) {
        return ['action' => 'blocked', 'reason' => 'This job has closed.'];
    }

    if ((int) $a['attempt_count'] === 0) {
        return ['action' => 'start_1', 'reason' => ''];
    }

    // Attempt 1 is done and was failed.
    // Grooming is a recommendation, not a gate. The role-based model
    // is a single link with no completion to measure, so the cooldown
    // below is what actually paces the retake.
    $unlocksAt = strtotime($a['first_submitted']) + ((int) $a['cooldown_days'] * 86400);
    if (time() < $unlocksAt) {
        return ['action' => 'wait',
                'reason' => 'Attempt 2 unlocks on ' . date('j M Y, g:ia', $unlocksAt) . '.'];
    }

    return ['action' => 'start_2', 'reason' => ''];
}


// ---------------------------------------------------------------
// Apply-time window check
//
// Blocks applying to a job the candidate structurally cannot
// finish: if they fail attempt 1 today, the cooldown plus the
// attempt window would run past the deadline.
// ---------------------------------------------------------------
function can_still_apply(PDO $pdo, int $jobId): bool
{
    $stmt = $pdo->prepare("
        SELECT deadline, cooldown_days, attempt_window_hours, status
        FROM jobs WHERE id = :job
    ");
    $stmt->execute([':job' => $jobId]);
    $j = $stmt->fetch();

    if (!$j || $j['status'] !== 'active') {
        return false;
    }

    $needed = time()
        + ((int) $j['cooldown_days'] * 86400)
        + ((int) $j['attempt_window_hours'] * 3600);

    return $needed <= strtotime($j['deadline']);
}


// ---------------------------------------------------------------
// Starting an attempt
//
// Writes expires_at once, from the assessment's time limit. It is
// never extended, so closing the tab does not pause the clock.
// ---------------------------------------------------------------
function start_attempt(PDO $pdo, int $applicationId, int $attemptNo): int
{
    // The database no longer caps this — the CHECK constraint was
    // removed for MySQL portability, so the rule lives here.
    if (!in_array($attemptNo, [1, 2], true)) {
        throw new RuntimeException('Only two attempts are allowed.');
    }

    $used = (int) $pdo->query(
        "SELECT COUNT(*) FROM attempts WHERE application_id = " . (int) $applicationId
    )->fetchColumn();

    if ($used >= 2) {
        throw new RuntimeException('Both attempts have already been used.');
    }

    $stmt = $pdo->prepare("
        SELECT a.id, a.time_limit_minutes
        FROM applications ap
        JOIN assessments a ON a.job_id = ap.job_id
        WHERE ap.id = :app
    ");
    $stmt->execute([':app' => $applicationId]);
    $a = $stmt->fetch();

    if (!$a) {
        throw new RuntimeException('This job has no assessment.');
    }

    $pdo->prepare("
        INSERT INTO attempts (application_id, attempt_no, started_at, expires_at)
        VALUES (:app, :no, NOW(), DATE_ADD(NOW(), INTERVAL :mins MINUTE))
    ")->execute([
        ':app'  => $applicationId,
        ':no'   => $attemptNo,
        ':mins' => $a['time_limit_minutes'],
    ]);

    return (int) $pdo->lastInsertId();
}


// ---------------------------------------------------------------
// Quiz builder validation
//
// The database allows at most one correct option per question but
// cannot require at least one. Run this before saving.
// Returns an array of error strings; empty means valid.
// ---------------------------------------------------------------
function validate_questions(array $questions): array
{
    $errors = [];

    if (!$questions) {
        $errors[] = 'Add at least one question.';
        return $errors;
    }

    foreach ($questions as $i => $q) {
        $n = $i + 1;

        if (trim($q['question_text'] ?? '') === '') {
            $errors[] = "Question $n: text is empty.";
        }

        $options = $q['options'] ?? [];
        $filled  = array_filter($options, fn($o) => trim($o['option_text'] ?? '') !== '');

        if (count($filled) < 2) {
            $errors[] = "Question $n: needs at least two options.";
        }

        $correct = array_filter($options, fn($o) => !empty($o['is_correct']));

        if (count($correct) === 0) {
            $errors[] = "Question $n: mark one option as correct.";
        } elseif (count($correct) > 1) {
            $errors[] = "Question $n: only one option can be correct.";
        }

        if (isset($q['points']) && (int) $q['points'] < 1) {
            $errors[] = "Question $n: points must be at least 1.";
        }
    }

    return $errors;
}



// ---------------------------------------------------------------
// Role-based grooming
//
// Job titles are free text, so a title is normalised then matched
// against grooming_role_aliases. Both the roles and the aliases are
// rows, not code: adding a new job title spelling is an INSERT.
// ---------------------------------------------------------------

// Lower case, punctuation to spaces, single spaces. "Full-Stack
// Developer (Remote)" becomes "full stack developer remote".
function normalize_role(string $title): string
{
    $title = strtolower($title);
    $title = preg_replace('/[^a-z0-9]+/', ' ', $title);
    return trim(preg_replace('/\s+/', ' ', $title));
}

// The first alias contained in the title wins. Ordering puts
// specialisations before the generic software role, and longer
// aliases before shorter ones, so "Backend Software Engineer"
// resolves to backend rather than software.
function match_grooming_role(PDO $pdo, string $title): ?array
{
    $needle = normalize_role($title);
    if ($needle === '') {
        return null;
    }

    $rows = $pdo->query("
        SELECT a.alias, r.role_key, r.label, r.title, r.url
        FROM grooming_role_aliases a
        JOIN grooming_roles r ON r.role_key = a.role_key
        ORDER BY a.priority DESC, CHAR_LENGTH(a.alias) DESC
    ")->fetchAll();

    foreach ($rows as $row) {
        if (strpos($needle, $row['alias']) !== false) {
            return $row;
        }
    }
    return null;
}

// What a candidate who failed THIS job should be shown.
//
//   custom    -> the company's own link for this job
//   suggested -> resolved from the role mapping at call time, so
//                updating a playlist reaches every job at once
//   none      -> nothing
//
// Always keyed on job_id, so two jobs with the same title can carry
// different grooming.
function grooming_for_job(PDO $pdo, int $jobId): array
{
    $stmt = $pdo->prepare("SELECT title, grooming_type, grooming_url FROM jobs WHERE id = :id");
    $stmt->execute([':id' => $jobId]);
    $job = $stmt->fetch();

    $none = ['type' => 'none', 'url' => null, 'title' => null, 'label' => null, 'source' => null];

    if (!$job) {
        return $none;
    }

    if ($job['grooming_type'] === 'none') {
        return $none;
    }

    if ($job['grooming_type'] === 'custom') {
        if (empty($job['grooming_url'])) {
            return $none;
        }
        return [
            'type'   => 'custom',
            'url'    => $job['grooming_url'],
            'title'  => 'Preparation material chosen by the company',
            'label'  => $job['title'],
            'source' => 'Company provided',
        ];
    }

    $role = match_grooming_role($pdo, $job['title']);

    if (!$role) {
        return $none;
    }

    return [
        'type'   => 'suggested',
        'url'    => $role['url'],
        'title'  => $role['title'],
        'label'  => $role['label'],
        'source' => 'Default for ' . $role['label'],
    ];
}

// Accepts a YouTube video or playlist link, nothing else. Checked by
// host rather than by pattern matching the whole string, so a URL
// like https://evil.example.com/?x=youtube.com cannot slip through.
function is_youtube_url(string $url): bool
{
    $parts = parse_url(trim($url));

    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return false;
    }
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
        return false;
    }

    $host = strtolower($parts['host']);
    $host = preg_replace('/^www\./', '', $host);

    return in_array($host, ['youtube.com', 'm.youtube.com', 'youtu.be', 'music.youtube.com'], true);
}



// ---------------------------------------------------------------
// Image uploads
//
// Profile photos, project images, achievement certificates and
// community post images all go through this one function, so the
// hardening is written once rather than four times.
//
// SVG is deliberately refused: it can carry script and would be
// served from this site's own origin.
//
// Returns ['path' => 'uploads/x/y.png', 'name' => 'original.png']
// or ['error' => 'message'].
// ---------------------------------------------------------------
function save_image_upload(array $file, string $dirName, string $prefix, int $ownerId, int $maxBytes = 2097152): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['error' => 'Choose an image to upload.'];
    }
    if (in_array($file['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        return ['error' => 'That image is too large.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => 'The upload did not complete. Try again.'];
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        return ['error' => 'The upload could not be verified.'];
    }
    if ($file['size'] > $maxBytes) {
        return ['error' => 'That image is too large. The limit is ' . round($maxBytes / 1048576, 1) . ' MB.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($file['tmp_name']);

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    if (!isset($allowed[$mime])) {
        return ['error' => 'Upload a JPG, PNG or WEBP. Other image types are not accepted.'];
    }
    if (@getimagesize($file['tmp_name']) === false) {
        return ['error' => 'That file is not a readable image.'];
    }

    $dir = __DIR__ . '/../uploads/' . $dirName . '/';

    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return ['error' => 'The uploads/' . $dirName . ' folder is missing and could not be created.'];
    }
    if (!is_writable($dir)) {
        return ['error' => 'The uploads/' . $dirName . ' folder is not writable by the web server.'];
    }

    // The uploaded name is never used to build the path.
    $newName = $prefix . '_' . $ownerId . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];

    if (!move_uploaded_file($file['tmp_name'], $dir . $newName)) {
        return ['error' => 'The image could not be saved. Check folder permissions.'];
    }

    return [
        'path' => 'uploads/' . $dirName . '/' . $newName,
        'name' => basename($file['name']),
    ];
}

// Delete a stored upload, given the relative path held in the
// database. Ignores anything that is not under uploads/.
function delete_upload(?string $relativePath): void
{
    if (!$relativePath || strpos($relativePath, 'uploads/') !== 0) {
        return;
    }
    if (strpos($relativePath, '..') !== false) {
        return;
    }

    $full = __DIR__ . '/../' . $relativePath;
    if (is_file($full)) {
        @unlink($full);
    }
}

// Display name and role for anyone who posts in the community. One
// query rather than joining both profile tables everywhere.
function community_author(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare("
        SELECT u.id, u.role,
               COALESCE(cp.full_name, co.company_name, u.email) AS name,
               cp.headline, cp.photo_path,
               co.industry, co.logo_path
        FROM users u
        LEFT JOIN candidate_profiles cp ON cp.user_id = u.id
        LEFT JOIN company_profiles   co ON co.user_id = u.id
        WHERE u.id = :id
    ");
    $stmt->execute([':id' => $userId]);
    return $stmt->fetch() ?: [];
}


// ---------------------------------------------------------------
// Profile completeness
//
// A qualified candidate's profile is what gets forwarded to the
// company, so it has to carry more than a name before they can
// apply.
//
// Experience and a CV stay optional on purpose: students applying
// for internships often have neither, and requiring them would lock
// out the people this platform is for.
// ---------------------------------------------------------------
function profile_missing_parts(PDO $pdo, int $candidateId): array
{
    $stmt = $pdo->prepare("
        SELECT full_name, location
        FROM candidate_profiles
        WHERE user_id = :id
    ");
    $stmt->execute([':id' => $candidateId]);
    $profile = $stmt->fetch();

    $missing = [];

    if (!$profile || trim((string) $profile['full_name']) === '') {
        $missing[] = 'your full name';
    }
    if (!$profile || trim((string) $profile['location']) === '') {
        $missing[] = 'your location';
    }

    $skills = (int) $pdo->query(
        "SELECT COUNT(*) FROM candidate_skills WHERE candidate_id = " . (int) $candidateId
    )->fetchColumn();
    if ($skills === 0) {
        $missing[] = 'at least one skill';
    }

    $education = (int) $pdo->query(
        "SELECT COUNT(*) FROM candidate_education WHERE candidate_id = " . (int) $candidateId
    )->fetchColumn();
    if ($education === 0) {
        $missing[] = 'at least one education entry';
    }

    return $missing;
}

function profile_is_complete(PDO $pdo, int $candidateId): bool
{
    return profile_missing_parts($pdo, $candidateId) === [];
}


// ---------------------------------------------------------------
// Skill options for forms
//
// Job create/edit and candidate profile both populate their skill
// selector from here. No free-text skill input anywhere, or job
// skills and grooming resources stop matching.
// ---------------------------------------------------------------
function all_skills(PDO $pdo): array
{
    return $pdo->query("SELECT id, name FROM skills ORDER BY name")->fetchAll();
}