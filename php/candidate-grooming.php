<?php
// Grooming material after a failed attempt.
//
//   candidate-grooming.php?application=12
//
// The application id is the only thing read from the URL, and it is
// matched against the session's candidate before anything else. The
// job is taken from that row, so the grooming shown always belongs
// to the job that was actually failed. Changing the number in the
// address cannot reach another candidate or another job.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/helpers.php';

require_role('candidate');

$candidateId   = current_user_id();
$applicationId = isset($_GET['application']) ? (int) $_GET['application'] : 0;

sweep_expired_attempts($pdo);

$stmt = $pdo->prepare("
    SELECT ap.id, ap.status, ap.job_id,
           j.title, j.cooldown_days, j.deadline, j.status AS job_status,
           c.company_name,
           a.passing_score
    FROM applications ap
    JOIN jobs j             ON j.id = ap.job_id
    JOIN company_profiles c ON c.user_id = j.company_id
    JOIN assessments a      ON a.job_id = j.id
    WHERE ap.id = :app AND ap.candidate_id = :cand
");
$stmt->execute([':app' => $applicationId, ':cand' => $candidateId]);
$application = $stmt->fetch();

if (!$application) {
    flash('That page is not yours, or it does not exist.');
    redirect('candidate-applications.php');
}

// Attempts for THIS job only.
$stmt = $pdo->prepare("
    SELECT attempt_no, score_percent, passed, submitted_at
    FROM attempts
    WHERE application_id = :app AND submitted_at IS NOT NULL
    ORDER BY attempt_no
");
$stmt->execute([':app' => $applicationId]);
$attempts = $stmt->fetchAll();

if (!$attempts) {
    flash('Sit the assessment first.');
    redirect('candidate-jobs.php?id=' . (int) $application['job_id']);
}

$last        = end($attempts);
$attemptsUsed = count($attempts);
$remaining   = max(0, 2 - $attemptsUsed);

$grooming    = grooming_for_job($pdo, (int) $application['job_id']);
$eligibility = attempt_eligibility($pdo, $applicationId);

$page_title = 'Grooming: ' . $application['title'];
$active_nav = 'applications';
$extra_css  = ['quiz.css'];
require __DIR__ . '/includes/header-candidate.php';
?>

<div class="groom-card" id="grooming-card">

    <p class="result-head">
        <?= (int) $last['passed'] === 1 ? 'Assessment passed' : 'Assessment not passed' ?>
    </p>

    <h2 class="groom-job"><?= e($application['title']) ?></h2>
    <p class="groom-company"><?= e($application['company_name']) ?></p>

    <div class="groom-stats">
        <div class="groom-stat">
            <span class="groom-value"><?= rtrim(rtrim(number_format((float) $last['score_percent'], 2), '0'), '.') ?>%</span>
            <span class="groom-label">Your score</span>
        </div>
        <div class="groom-stat">
            <span class="groom-value"><?= (int) $application['passing_score'] ?>%</span>
            <span class="groom-label">Pass mark</span>
        </div>
        <div class="groom-stat">
            <span class="groom-value"><?= $attemptsUsed ?> / 2</span>
            <span class="groom-label">Attempts used</span>
        </div>
        <div class="groom-stat">
            <span class="groom-value"><?= $remaining ?></span>
            <span class="groom-label">Remaining</span>
        </div>
    </div>

    <?php if ($grooming['type'] !== 'none'): ?>

    <div class="groom-block">
        <p class="groom-head">Recommended grooming</p>
        <p class="groom-title"><?= e($grooming['title']) ?></p>
        <p class="groom-source"><?= e($grooming['source']) ?></p>

        <p class="groom-text">
            <?php if ($remaining > 0): ?>
            Work through this before your second attempt. It opens on YouTube in a new tab.
            <?php else: ?>
            This material stays available even though your attempts for this job are used up.
            <?php endif; ?>
        </p>

        <a class="btn-watch" href="<?= e($grooming['url']) ?>" target="_blank" rel="noopener noreferrer">
            Watch on YouTube
        </a>
    </div>

    <?php else: ?>

    <div class="groom-block">
        <p class="groom-head">No grooming material</p>
        <p class="groom-text">
            This employer has not set preparation material for this job.
            <?php if ($remaining > 0): ?>
            Revise the skills the job listed, then come back for your second attempt.
            <?php endif; ?>
        </p>
    </div>

    <?php endif; ?>

    <div class="form-actions groom-actions">
        <?php if ($eligibility['action'] === 'start_2'): ?>

        <a class="btn-primary" href="candidate-assessment.php?application=<?= (int) $applicationId ?>">Start second attempt</a>

        <?php elseif ($eligibility['action'] === 'wait'): ?>

        <span class="pill pill-amber">Retake locked</span>
        <span class="cell-sub"><?= e($eligibility['reason']) ?></span>

        <?php elseif ($eligibility['action'] === 'blocked'): ?>

        <span class="cell-sub"><?= e($eligibility['reason']) ?></span>

        <?php endif; ?>

        <a class="btn-plain-border" href="candidate-jobs.php?id=<?= (int) $application['job_id'] ?>">Back to the job</a>
    </div>

    <?php if ($attemptsUsed >= 2 && (int) $last['passed'] !== 1): ?>
    <p class="groom-text groom-closed">
        You have used all 2 attempts for this job and cannot retake it.
        This does not affect any other job, and you can still apply elsewhere.
    </p>
    <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>