<?php
// Sit the assessment for one application.
//
//   candidate-assessment.php?application=12
//
// Only the application id is taken from the URL, and it is checked
// against the session's candidate before anything else happens. The
// job and company are read from that row, never from the URL, so
// editing the address cannot reach another job or another candidate.
//
// Starting versus resuming: if an unsubmitted attempt exists and its
// clock has not run out, it is reused. Closing the tab and coming
// back must not hand out a fresh timer or a third attempt row.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/helpers.php';

require_role('candidate');

$candidateId   = current_user_id();
$applicationId = isset($_GET['application']) ? (int) $_GET['application'] : 0;

// Close out anything that expired while they were away, so the
// eligibility check below reads settled state.
sweep_expired_attempts($pdo);

$stmt = $pdo->prepare("
    SELECT ap.id, ap.status, ap.job_id,
           j.title, j.company_id, j.deadline, j.status AS job_status,
           c.company_name,
           a.id AS assessment_id, a.time_limit_minutes, a.passing_score
    FROM applications ap
    JOIN jobs j             ON j.id = ap.job_id
    JOIN company_profiles c ON c.user_id = j.company_id
    JOIN assessments a      ON a.job_id = j.id
    WHERE ap.id = :app AND ap.candidate_id = :cand
");
$stmt->execute([':app' => $applicationId, ':cand' => $candidateId]);
$application = $stmt->fetch();

if (!$application) {
    flash('That assessment is not yours, or it does not exist.');
    redirect('candidate-applications.php');
}

$eligibility = attempt_eligibility($pdo, $applicationId);

if (!in_array($eligibility['action'], ['start_1', 'start_2'], true)) {

    if ($eligibility['action'] === 'grooming') {
        flash($eligibility['reason']);
        redirect('candidate-grooming.php?application=' . $applicationId);
    }

    flash($eligibility['reason'] !== '' ? $eligibility['reason'] : 'You cannot sit this assessment now.');
    redirect('candidate-jobs.php?id=' . (int) $application['job_id']);
}

// Resume an attempt that is still running, otherwise open a new one.
$stmt = $pdo->prepare("
    SELECT * FROM attempts
    WHERE application_id = :app AND submitted_at IS NULL
    ORDER BY attempt_no DESC LIMIT 1
");
$stmt->execute([':app' => $applicationId]);
$attempt = $stmt->fetch();

if (!$attempt) {

    $questionCount = (int) $pdo->query(
        "SELECT COUNT(*) FROM questions WHERE assessment_id = " . (int) $application['assessment_id']
    )->fetchColumn();

    if ($questionCount === 0) {
        flash('This job has no assessment questions yet. Try again later.');
        redirect('candidate-jobs.php?id=' . (int) $application['job_id']);
    }

    $used = (int) $pdo->query(
        "SELECT COUNT(*) FROM attempts WHERE application_id = " . (int) $applicationId
    )->fetchColumn();

    try {
        $attemptId = start_attempt($pdo, $applicationId, $used + 1);
    } catch (RuntimeException $ex) {
        flash($ex->getMessage());
        redirect('candidate-jobs.php?id=' . (int) $application['job_id']);
    }

    $stmt = $pdo->prepare("SELECT * FROM attempts WHERE id = :id");
    $stmt->execute([':id' => $attemptId]);
    $attempt = $stmt->fetch();
}

// Questions in the order the company set.
$stmt = $pdo->prepare("
    SELECT id, question_text, points
    FROM questions
    WHERE assessment_id = :a
    ORDER BY position, id
");
$stmt->execute([':a' => $application['assessment_id']]);
$questions = $stmt->fetchAll();

$optionStmt = $pdo->prepare("
    SELECT id, option_text
    FROM question_options
    WHERE question_id = :q
    ORDER BY id
");

// Anything already chosen in this attempt, so a reload restores it.
$stmt = $pdo->prepare("SELECT question_id, option_id FROM attempt_answers WHERE attempt_id = :t");
$stmt->execute([':t' => $attempt['id']]);
$saved = [];
foreach ($stmt->fetchAll() as $row) {
    $saved[(int) $row['question_id']] = (int) $row['option_id'];
}

foreach ($questions as $i => $question) {
    $optionStmt->execute([':q' => $question['id']]);
    $questions[$i]['options'] = $optionStmt->fetchAll();
    $questions[$i]['chosen']  = $saved[(int) $question['id']] ?? 0;
}

$totalQuestions = count($questions);
$answeredCount  = count($saved);

// The client counts down to this instant. It is sent as a UTC epoch
// rather than "minutes left", which would drift if the page sits.
$expiresEpoch = strtotime($attempt['expires_at']);

$page_title = 'Assessment: ' . $application['title'];
$active_nav = 'applications';
$extra_css  = ['quiz.css'];
require __DIR__ . '/includes/header-candidate.php';
?>

<div class="exam" id="exam"
     data-attempt="<?= (int) $attempt['id'] ?>"
     data-expires="<?= (int) $expiresEpoch ?>"
     data-now="<?= time() ?>"
     data-total="<?= $totalQuestions ?>">

    <div class="exam-bar">
        <div class="exam-meta">
            <span class="exam-job"><?= e($application['title']) ?></span>
            <span class="exam-sub"><?= e($application['company_name']) ?> &middot; attempt <?= (int) $attempt['attempt_no'] ?> of 2 &middot; pass mark <?= (int) $application['passing_score'] ?>%</span>
        </div>
        <div class="exam-clock" id="exam-clock">--:--</div>
    </div>

    <div class="progress-wrap">
        <div class="progress-track">
            <div class="progress-fill" id="progress-fill"></div>
        </div>
        <p class="progress-text" id="progress-text">
            Question 1 of <?= $totalQuestions ?> &middot; <span id="answered-count"><?= $answeredCount ?></span> answered
        </p>
    </div>

    <form method="post" action="candidate-assessment-submit.php" id="exam-form">
        <input type="hidden" name="action" value="submit">
        <input type="hidden" name="attempt_id" value="<?= (int) $attempt['id'] ?>">

        <?php foreach ($questions as $i => $question): ?>
        <div class="question-card" data-index="<?= $i ?>" data-question="<?= (int) $question['id'] ?>"<?= $i > 0 ? ' hidden' : '' ?>>

            <p class="question-count">Question <?= $i + 1 ?> of <?= $totalQuestions ?></p>
            <p class="question-text"><?= nl2br(e($question['question_text'])) ?></p>

            <div class="answer-list">
                <?php foreach ($question['options'] as $option): ?>
                <label class="answer<?= (int) $question['chosen'] === (int) $option['id'] ? ' chosen' : '' ?>">
                    <input class="answer-radio" type="radio"
                           name="answers[<?= (int) $question['id'] ?>]"
                           value="<?= (int) $option['id'] ?>"
                           <?= (int) $question['chosen'] === (int) $option['id'] ? 'checked' : '' ?>>
                    <span class="answer-text"><?= e($option['option_text']) ?></span>
                </label>
                <?php endforeach; ?>
            </div>

            <p class="question-points"><?= (int) $question['points'] ?> point<?= (int) $question['points'] === 1 ? '' : 's' ?></p>

        </div>
        <?php endforeach; ?>

        <div class="exam-nav">
            <button class="btn-plain-border" type="button" id="prev-question" disabled>Previous</button>
            <button class="btn-primary" type="button" id="next-question">Next</button>
            <button class="btn-submit" type="button" id="open-submit" hidden>Submit Exam</button>
        </div>

        <!-- Shown by the Review button, and by the timer when it runs out. -->
        <div class="confirm-panel" id="confirm-panel" hidden>
            <p class="confirm-title">Submit this assessment?</p>
            <p class="confirm-text" id="confirm-text"></p>
            <p class="confirm-text">You cannot change your answers after submitting.</p>
            <div class="form-actions">
                <button class="btn-submit" type="submit" id="confirm-submit">Yes, submit my exam</button>
                <button class="btn-plain-border" type="button" id="cancel-submit">Keep answering</button>
            </div>
        </div>

        <noscript>
            <div class="confirm-panel">
                <p class="confirm-text">JavaScript is off, so every question is shown at once.</p>
                <button class="btn-submit" type="submit">Submit Exam</button>
            </div>
        </noscript>
    </form>

</div>

<script src="../js/quiz.js"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>