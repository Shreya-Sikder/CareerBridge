<?php
// Two jobs on one file:
//
//   action=save    one answer, saved as the candidate picks it.
//                  Called by quiz.js, answers with JSON.
//   action=submit  grade the attempt and show the result.
//
// Saving answers as they are picked is what makes an abandoned
// attempt scoreable: the expiry sweep in helpers.php grades whatever
// reached the table.
//
// Ownership is checked on the attempt row every time. The attempt id
// is the only thing taken from the request, and it is joined back to
// the session's candidate before anything is read or written.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/helpers.php';

require_role('candidate');

$candidateId = current_user_id();
$action      = $_POST['action'] ?? '';
$attemptId   = (int) ($_POST['attempt_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT t.*, ap.id AS application_id, ap.job_id, ap.candidate_id,
           j.title, j.company_id, j.cooldown_days,
           c.company_name,
           a.id AS assessment_id, a.passing_score
    FROM attempts t
    JOIN applications ap    ON ap.id = t.application_id
    JOIN jobs j             ON j.id = ap.job_id
    JOIN company_profiles c ON c.user_id = j.company_id
    JOIN assessments a      ON a.job_id = j.id
    WHERE t.id = :id AND ap.candidate_id = :cand
");
$stmt->execute([':id' => $attemptId, ':cand' => $candidateId]);
$attempt = $stmt->fetch();


// ===============================================================
// Autosave one answer
// ===============================================================
if ($action === 'save') {

    header('Content-Type: application/json');

    if (!$attempt) {
        echo json_encode(['ok' => false, 'reason' => 'not_found']);
        exit;
    }
    if ($attempt['submitted_at'] !== null) {
        echo json_encode(['ok' => false, 'reason' => 'already_submitted']);
        exit;
    }
    if (strtotime($attempt['expires_at']) < time()) {
        echo json_encode(['ok' => false, 'reason' => 'expired']);
        exit;
    }

    $questionId = (int) ($_POST['question_id'] ?? 0);
    $optionId   = (int) ($_POST['option_id'] ?? 0);

    // The question must belong to this attempt's assessment, and the
    // option to that question. Otherwise a crafted post could answer
    // another job's question.
    $check = $pdo->prepare("
        SELECT o.id
        FROM question_options o
        JOIN questions q ON q.id = o.question_id
        WHERE o.id = :opt AND q.id = :q AND q.assessment_id = :a
    ");
    $check->execute([
        ':opt' => $optionId,
        ':q'   => $questionId,
        ':a'   => $attempt['assessment_id'],
    ]);

    if (!$check->fetch()) {
        echo json_encode(['ok' => false, 'reason' => 'bad_option']);
        exit;
    }

    $pdo->prepare("
        INSERT INTO attempt_answers (attempt_id, question_id, option_id)
        VALUES (:t, :q, :o)
        ON DUPLICATE KEY UPDATE option_id = VALUES(option_id)
    ")->execute([':t' => $attemptId, ':q' => $questionId, ':o' => $optionId]);

    $answered = (int) $pdo->query(
        "SELECT COUNT(*) FROM attempt_answers WHERE attempt_id = " . (int) $attemptId
    )->fetchColumn();

    echo json_encode(['ok' => true, 'answered' => $answered]);
    exit;
}


// ===============================================================
// Submit and grade
// ===============================================================
if ($action !== 'submit' || !$attempt) {
    flash('That assessment is not yours, or it does not exist.');
    redirect('candidate-applications.php');
}

// A refresh must not rescore. If it is already submitted, just show
// the result that was recorded.
if ($attempt['submitted_at'] === null) {

    // Take whatever came with the form. This is what makes the page
    // work with JavaScript off, where nothing was autosaved.
    $posted = $_POST['answers'] ?? [];

    if (is_array($posted)) {
        $valid = $pdo->prepare("
            SELECT o.id
            FROM question_options o
            JOIN questions q ON q.id = o.question_id
            WHERE o.id = :opt AND q.id = :q AND q.assessment_id = :a
        ");
        $save = $pdo->prepare("
            INSERT INTO attempt_answers (attempt_id, question_id, option_id)
            VALUES (:t, :q, :o)
            ON DUPLICATE KEY UPDATE option_id = VALUES(option_id)
        ");

        foreach ($posted as $questionId => $optionId) {
            $questionId = (int) $questionId;
            $optionId   = (int) $optionId;

            $valid->execute([
                ':opt' => $optionId,
                ':q'   => $questionId,
                ':a'   => $attempt['assessment_id'],
            ]);

            if ($valid->fetch()) {
                $save->execute([':t' => $attemptId, ':q' => $questionId, ':o' => $optionId]);
            }
        }
    }

    // A submit that arrives after the clock ran out is still graded,
    // but stamped at the expiry time rather than now. Refusing it
    // would punish someone who lost a race by a second, and the
    // stored answers are identical either way.
    $submittedAt = strtotime($attempt['expires_at']) < time()
        ? $attempt['expires_at']
        : date('Y-m-d H:i:s');

    score_attempt($pdo, [
        'id'             => $attempt['id'],
        'application_id' => $attempt['application_id'],
        'attempt_no'     => $attempt['attempt_no'],
        'assessment_id'  => $attempt['assessment_id'],
        'passing_score'  => $attempt['passing_score'],
    ], $submittedAt);

    // Re-read so the result screen shows what was actually stored.
    $stmt = $pdo->prepare("SELECT * FROM attempts WHERE id = :id");
    $stmt->execute([':id' => $attemptId]);
    $attempt = array_merge($attempt, $stmt->fetch());
}

$stmt = $pdo->prepare("SELECT status FROM applications WHERE id = :id");
$stmt->execute([':id' => $attempt['application_id']]);
$applicationStatus = $stmt->fetchColumn();

$grooming   = grooming_for_job($pdo, (int) $attempt['job_id']);
$passed     = (int) $attempt['passed'] === 1;
$attemptNo  = (int) $attempt['attempt_no'];
$lastChance = $attemptNo >= 2;

$page_title = 'Assessment result';
$active_nav = 'applications';
$extra_css  = ['quiz.css'];
require __DIR__ . '/includes/header-candidate.php';
?>

<div class="result-card <?= $passed ? 'result-pass' : 'result-fail' ?>" id="result-card">

    <p class="result-head">Assessment completed</p>
    <p class="result-job"><?= e($attempt['title']) ?> &middot; <?= e($attempt['company_name']) ?></p>

    <p class="result-score" id="result-score"><?= rtrim(rtrim(number_format((float) $attempt['score_percent'], 2), '0'), '.') ?>%</p>
    <p class="result-meta">
        Pass mark <?= (int) $attempt['passing_score'] ?>% &middot; attempt <?= $attemptNo ?> of 2
    </p>

    <p class="result-verdict" id="result-verdict">
        <?= $passed ? 'Passed' : 'Not passed' ?>
    </p>

    <?php if ($passed): ?>

    <p class="result-text">
        Your assessment has been completed successfully.
        Your profile is now visible to <?= e($attempt['company_name']) ?> for this job.
    </p>

    <div class="form-actions">
        <a class="btn-primary" href="candidate-applications.php">My applications</a>
        <a class="btn-plain-border" href="candidate-jobs.php">Find more jobs</a>
    </div>

    <?php elseif (!$lastChance): ?>

    <p class="result-text">
        You have one attempt left for this job.
    </p>
    <?php if ($grooming['type'] !== 'none'): ?>
    <div class="groom-block result-groom">
        <p class="groom-head">Recommended grooming</p>
        <p class="groom-title"><?= e($grooming['label'] ?: $grooming['title']) ?></p>
        <p class="groom-text">Review this material before your next attempt.</p>
        <a class="btn-watch" href="<?= e($grooming['url']) ?>" target="_blank" rel="noopener noreferrer">Watch grooming content</a>
    </div>
    <?php endif; ?>

    <p class="result-text">
        <?php if ((int) $attempt['cooldown_days'] > 0): ?>
        Your second attempt unlocks <?= (int) $attempt['cooldown_days'] ?> days after this one.
        <?php else: ?>
        You can take your second attempt as soon as you are ready.
        <?php endif; ?>
    </p>

    <div class="form-actions">
        <a class="btn-primary" href="candidate-grooming.php?application=<?= (int) $attempt['application_id'] ?>">Grooming and retake</a>
        <a class="btn-plain-border" href="candidate-applications.php">My applications</a>
    </div>

    <?php else: ?>

    <p class="result-text">
        Both attempts for this job are now used, so this application is closed.
    </p>
    <p class="result-text">
        This does not affect any other job. You can still apply for and sit assessments elsewhere.
    </p>

    <div class="form-actions">
        <a class="btn-primary" href="candidate-jobs.php">Find other jobs</a>
        <a class="btn-plain-border" href="candidate-applications.php">My applications</a>
    </div>

    <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>