<?php
// Candidate home. Three counts, a profile prompt, and a list of what
// they can actually do right now.
//
// The next-actions list is driven by attempt_eligibility(), the same
// function the assessment page uses, so the two can never disagree
// about whether an attempt is available.
//
// header-candidate.php has already run sweep_expired_attempts(), so
// any attempt whose clock ran out while they were away is scored and
// routed before these queries read it.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/helpers.php';

require_role('candidate');

$candidateId = current_user_id();

$page_title = 'Dashboard';
$active_nav = 'dashboard';
$extra_css  = ['home.css'];
require __DIR__ . '/includes/header-candidate.php';

$stmt = $pdo->prepare("
    SELECT
      SUM(status = 'applied')   AS in_progress,
      SUM(status = 'grooming')  AS grooming,
      SUM(status = 'qualified') AS qualified
    FROM applications
    WHERE candidate_id = :id
");
$stmt->execute([':id' => $candidateId]);
$counts = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT ap.id, ap.status, j.title, j.deadline, c.company_name
    FROM applications ap
    JOIN jobs j              ON j.id = ap.job_id
    JOIN company_profiles c  ON c.user_id = j.company_id
    WHERE ap.candidate_id = :id
      AND ap.status IN ('applied', 'grooming')
    ORDER BY j.deadline
");
$stmt->execute([':id' => $candidateId]);
$open = $stmt->fetchAll();

// Work out what each open application is actually waiting on.
$actions = [];
foreach ($open as $application) {
    $eligibility = attempt_eligibility($pdo, (int) $application['id']);

    if ($eligibility['action'] === 'blocked') {
        continue;
    }

    $actions[] = [
        'application' => $application,
        'action'      => $eligibility['action'],
        'reason'      => $eligibility['reason'],
    ];
}

$missing = profile_missing_parts($pdo, $candidateId);
?>

<?php if ($missing): ?>
<p class="notice" id="profile-prompt">
    Add <?= e(implode(', ', $missing)) ?> before you apply.
    <a class="link-action" href="candidate-profile.php">Finish your profile</a>
</p>
<?php endif; ?>

<div class="metric-row" id="metrics">

    <div class="metric">
        <span class="metric-value"><?= (int) $counts['in_progress'] ?></span>
        <span class="metric-label">In progress</span>
    </div>

    <div class="metric">
        <span class="metric-value"><?= (int) $counts['grooming'] ?></span>
        <span class="metric-label">In grooming</span>
    </div>

    <div class="metric">
        <span class="metric-value"><?= (int) $counts['qualified'] ?></span>
        <span class="metric-label">Qualified</span>
    </div>

</div>

<div class="panel">

    <div class="panel-head">
        <h2 class="panel-title">What to do next</h2>
        <a class="link-action" href="candidate-applications.php">All applications</a>
    </div>

    <?php if (!$actions): ?>

    <div class="empty" id="no-actions">
        <p class="empty-title">Nothing waiting on you</p>
        <p class="empty-text">Browse open jobs and apply to one to get started.</p>
        <p class="empty-action"><a class="btn-primary" href="candidate-jobs.php">Find jobs</a></p>
    </div>

    <?php else: ?>

    <ul class="action-list" id="next-actions">
    <?php foreach ($actions as $item): ?>
        <li class="action-item">
            <div class="action-main">
                <span class="cell-strong"><?= e($item['application']['title']) ?></span>
                <span class="cell-sub">
                    <?= e($item['application']['company_name']) ?>,
                    closes <?= date('j M Y', strtotime($item['application']['deadline'])) ?>
                </span>
                <?php if ($item['reason'] !== ''): ?>
                <span class="cell-sub"><?= e($item['reason']) ?></span>
                <?php endif; ?>
            </div>

            <div class="action-side">
                <?php if ($item['action'] === 'start_1'): ?>
                <a class="btn-primary" href="candidate-assessment.php?application=<?= (int) $item['application']['id'] ?>">Start assessment</a>
                <?php elseif ($item['action'] === 'start_2'): ?>
                <a class="btn-primary" href="candidate-assessment.php?application=<?= (int) $item['application']['id'] ?>">Second attempt</a>
                <?php elseif ($item['action'] === 'grooming'): ?>
                <a class="btn-primary" href="candidate-grooming.php?application=<?= (int) $item['application']['id'] ?>">Continue grooming</a>
                <?php else: ?>
                <span class="pill pill-amber">Waiting</span>
                <?php endif; ?>
            </div>
        </li>
    <?php endforeach; ?>
    </ul>

    <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>