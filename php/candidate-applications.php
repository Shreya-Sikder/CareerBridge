<?php
// Every job this candidate has applied for, and where each one
// stands.
//
// Nothing is read from the URL. The list is built from the session's
// candidate id, so there is no id to tamper with.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/helpers.php';

require_role('candidate');

$candidateId = current_user_id();

// Close out anything whose clock ran down while they were away, so
// the statuses below are settled rather than mid-flight.
sweep_expired_attempts($pdo);

$filter = $_GET['show'] ?? 'all';

$where = '';
if ($filter === 'passed') {
    $where = " AND ap.status = 'qualified'";
} elseif ($filter === 'active') {
    $where = " AND ap.status IN ('applied','grooming')";
} elseif ($filter === 'closed') {
    $where = " AND ap.status = 'rejected'";
}

$stmt = $pdo->prepare("
    SELECT ap.id, ap.status, ap.applied_at, ap.job_id,
           j.title, j.deadline, j.status AS job_status,
           c.company_name,
           a.passing_score,
           (SELECT COUNT(*) FROM attempts t
             WHERE t.application_id = ap.id AND t.submitted_at IS NOT NULL) AS attempts_used,
           (SELECT MAX(t.score_percent) FROM attempts t
             WHERE t.application_id = ap.id AND t.submitted_at IS NOT NULL) AS best_score,
           (SELECT MAX(t.submitted_at) FROM attempts t
             WHERE t.application_id = ap.id AND t.submitted_at IS NOT NULL) AS last_sat,
           (SELECT t.id FROM attempts t
             WHERE t.application_id = ap.id AND t.submitted_at IS NULL
             ORDER BY t.attempt_no DESC LIMIT 1) AS open_attempt
    FROM applications ap
    JOIN jobs j             ON j.id = ap.job_id
    JOIN company_profiles c ON c.user_id = j.company_id
    JOIN assessments a      ON a.job_id = j.id
    WHERE ap.candidate_id = :cand" . $where . "
    ORDER BY ap.applied_at DESC
");
$stmt->execute([':cand' => $candidateId]);
$applications = $stmt->fetchAll();

// Counts for the tabs, always across everything regardless of filter.
$counts = $pdo->prepare("
    SELECT
        COUNT(*)                                                   AS total,
        SUM(status = 'qualified')                                  AS passed,
        SUM(status IN ('applied','grooming'))                      AS active,
        SUM(status = 'rejected')                                   AS closed
    FROM applications WHERE candidate_id = :cand
");
$counts->execute([':cand' => $candidateId]);
$counts = $counts->fetch();

$page_title = 'My applications';
$active_nav = 'applications';
$extra_css  = ['home.css', 'profile.css'];
require __DIR__ . '/includes/header-candidate.php';
?>

<div class="metric-row" id="application-stats">
    <div class="metric">
        <span class="metric-value"><?= (int) $counts['total'] ?></span>
        <span class="metric-label">Applications</span>
    </div>
    <div class="metric">
        <span class="metric-value"><?= (int) $counts['active'] ?></span>
        <span class="metric-label">In progress</span>
    </div>
    <div class="metric">
        <span class="metric-value"><?= (int) $counts['passed'] ?></span>
        <span class="metric-label">Passed</span>
    </div>
    <div class="metric">
        <span class="metric-value"><?= (int) $counts['closed'] ?></span>
        <span class="metric-label">Closed</span>
    </div>
</div>

<div class="filter-row">
    <?php
    $tabs = ['all' => 'All', 'active' => 'In progress', 'passed' => 'Passed', 'closed' => 'Closed'];
    foreach ($tabs as $key => $label):
    ?>
    <a class="filter-tab<?= $filter === $key ? ' on' : '' ?>"
       href="candidate-applications.php?show=<?= $key ?>"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<?php if (!$applications): ?>

<div class="empty" id="no-applications">
    <p class="empty-title">
        <?= $filter === 'all' ? 'You have not applied for anything yet' : 'Nothing matches this filter' ?>
    </p>
    <p class="empty-text">Browse open jobs and apply. Each one has its own assessment.</p>
    <p class="empty-action"><a class="btn-primary" href="candidate-jobs.php">Find jobs</a></p>
</div>

<?php else: ?>

<table class="data-table" id="applications-table">
<thead>
    <tr>
        <th>Job</th>
        <th>Applied</th>
        <th>Exam</th>
        <th>Result</th>
        <th>Status</th>
        <th>Next step</th>
    </tr>
</thead>
<tbody>
<?php foreach ($applications as $app): ?>
    <?php $eligibility = attempt_eligibility($pdo, (int) $app['id']); ?>
    <tr>
        <td>
            <a class="link-action cell-strong" href="candidate-jobs.php?id=<?= (int) $app['job_id'] ?>"><?= e($app['title']) ?></a>
            <span class="cell-sub"><?= e($app['company_name']) ?></span>
        </td>

        <td><?= date('j M Y', strtotime($app['applied_at'])) ?></td>

        <td>
            <?php if ($app['open_attempt']): ?>
            <span class="cell-strong">In progress</span>
            <span class="cell-sub">attempt <?= (int) $app['attempts_used'] + 1 ?> of 2</span>
            <?php elseif ((int) $app['attempts_used'] === 0): ?>
            <span class="cell-sub">Not sat</span>
            <?php else: ?>
            <span class="cell-strong"><?= (int) $app['attempts_used'] ?> of 2 used</span>
            <?php if ($app['last_sat']): ?>
            <span class="cell-sub"><?= date('j M Y', strtotime($app['last_sat'])) ?></span>
            <?php endif; ?>
            <?php endif; ?>
        </td>

        <td>
            <?php if ($app['best_score'] === null): ?>
            <span class="cell-sub">&mdash;</span>
            <?php else: ?>
            <span class="cell-strong"><?= rtrim(rtrim(number_format((float) $app['best_score'], 2), '0'), '.') ?>%</span>
            <span class="cell-sub">pass mark <?= (int) $app['passing_score'] ?>%</span>
            <?php endif; ?>
        </td>

        <td>
            <?php if ($app['status'] === 'qualified'): ?>
            <span class="pill pill-green">Passed</span>
            <?php elseif ($app['status'] === 'rejected'): ?>
            <span class="pill pill-grey">Not successful</span>
            <?php elseif ($app['status'] === 'grooming'): ?>
            <span class="pill pill-amber">In grooming</span>
            <?php else: ?>
            <span class="pill pill-blue">Applied</span>
            <?php endif; ?>
        </td>

        <td class="cell-actions">
            <?php if ($eligibility['action'] === 'start_1'): ?>
            <a class="link-action" href="candidate-assessment.php?application=<?= (int) $app['id'] ?>">Start assessment</a>

            <?php elseif ($eligibility['action'] === 'start_2'): ?>
            <a class="link-action" href="candidate-assessment.php?application=<?= (int) $app['id'] ?>">Second attempt</a>

            <?php elseif ($eligibility['action'] === 'grooming'): ?>
            <a class="link-action" href="candidate-grooming.php?application=<?= (int) $app['id'] ?>">Grooming</a>

            <?php elseif ($eligibility['action'] === 'wait'): ?>
            <span class="cell-sub"><?= e($eligibility['reason']) ?></span>

            <?php elseif ($app['status'] === 'qualified'): ?>
            <span class="cell-sub">Your profile is with the company</span>

            <?php else: ?>
            <span class="cell-sub"><?= e($eligibility['reason']) ?></span>
            <?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>