<?php
// Assessment results for ONE job.
//
//   company-applicants.php?job=12
//
// Every count on this page is filtered by that job_id. A company's
// totals across all its jobs must never appear on a single job's
// page, so there is no unfiltered query anywhere below.
//
// The job id comes from the URL but is immediately checked against
// the session's company. A company cannot open another company's job
// by editing the address.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/helpers.php';

require_role('company');

$companyId = current_user_id();
$jobId     = isset($_GET['job']) ? (int) $_GET['job'] : 0;
$filter    = $_GET['show'] ?? 'all';

$stmt = $pdo->prepare("SELECT * FROM jobs WHERE id = :id AND company_id = :co");
$stmt->execute([':id' => $jobId, ':co' => $companyId]);
$job = $stmt->fetch();

if (!$job) {
    flash('That job does not exist, or it belongs to another company.');
    redirect('company-jobs.php');
}

// Close out any attempt whose clock ran down, so the numbers here
// match what the candidate sees.
sweep_expired_attempts($pdo);

// All four counts scoped to this one job.
$stmt = $pdo->prepare("
    SELECT
        (SELECT COUNT(*) FROM applications ap
          WHERE ap.job_id = :j1)                              AS applicants,

        (SELECT COUNT(*) FROM attempts t
           JOIN applications ap ON ap.id = t.application_id
          WHERE ap.job_id = :j2 AND t.submitted_at IS NOT NULL) AS attempts,

        (SELECT COUNT(*) FROM applications ap
          WHERE ap.job_id = :j3 AND ap.status = 'qualified')  AS passed,

        (SELECT COUNT(*) FROM applications ap
          WHERE ap.job_id = :j4 AND ap.status = 'rejected')   AS failed,

        (SELECT COUNT(*) FROM applications ap
          WHERE ap.job_id = :j5 AND ap.status = 'grooming')   AS grooming
");
$stmt->execute([
    ':j1' => $jobId, ':j2' => $jobId, ':j3' => $jobId,
    ':j4' => $jobId, ':j5' => $jobId,
]);
$stats = $stmt->fetch();

// One row per applicant, with their best score and attempt count for
// THIS job only.
$where = '';
if ($filter === 'passed') {
    $where = " AND ap.status = 'qualified'";
} elseif ($filter === 'failed') {
    $where = " AND ap.status = 'rejected'";
} elseif ($filter === 'pending') {
    $where = " AND ap.status IN ('applied','grooming')";
}

$stmt = $pdo->prepare("
    SELECT ap.id, ap.status, ap.applied_at,
           ap.candidate_id,
           p.full_name, p.headline,
           (SELECT COUNT(*) FROM attempts t
             WHERE t.application_id = ap.id AND t.submitted_at IS NOT NULL) AS attempt_count,
           (SELECT MAX(t.score_percent) FROM attempts t
             WHERE t.application_id = ap.id AND t.submitted_at IS NOT NULL) AS best_score,
           (SELECT MAX(t.submitted_at) FROM attempts t
             WHERE t.application_id = ap.id AND t.submitted_at IS NOT NULL) AS last_submitted
    FROM applications ap
    JOIN candidate_profiles p ON p.user_id = ap.candidate_id
    WHERE ap.job_id = :job" . $where . "
    ORDER BY ap.status = 'qualified' DESC, best_score DESC, p.full_name
");
$stmt->execute([':job' => $jobId]);
$rows = $stmt->fetchAll();

$assessment = $pdo->prepare("SELECT passing_score FROM assessments WHERE job_id = :job");
$assessment->execute([':job' => $jobId]);
$passMark = (int) ($assessment->fetchColumn() ?: 60);

$grooming = grooming_for_job($pdo, $jobId);

$page_title = $job['title'];
$active_nav = 'jobs';
$extra_css  = ['home.css', 'profile.css'];
require __DIR__ . '/includes/header-company.php';
?>

<p class="back-link"><a class="link-action" href="company-jobs.php">Back to all jobs</a></p>

<div class="metric-row" id="job-stats">

    <div class="metric">
        <span class="metric-value"><?= (int) $stats['applicants'] ?></span>
        <span class="metric-label">Applicants</span>
    </div>

    <div class="metric">
        <span class="metric-value"><?= (int) $stats['attempts'] ?></span>
        <span class="metric-label">Assessment attempts</span>
    </div>

    <div class="metric">
        <span class="metric-value"><?= (int) $stats['passed'] ?></span>
        <span class="metric-label">Passed</span>
    </div>

    <div class="metric">
        <span class="metric-value"><?= (int) $stats['failed'] ?></span>
        <span class="metric-label">Failed</span>
    </div>

</div>

<p class="field-help section-help">
    Pass mark <?= $passMark ?>%. Counts are for this job only.
    <?php if ((int) $stats['grooming'] > 0): ?>
    <?= (int) $stats['grooming'] ?> candidate<?= (int) $stats['grooming'] === 1 ? ' is' : 's are' ?> in grooming before a second attempt.
    <?php endif; ?>
</p>

<div class="form-card" id="grooming-config">

    <div class="card-head">
        <h2 class="section-heading">Grooming recommendation</h2>
        <?php if ($job['grooming_type'] === 'custom'): ?>
        <span class="pill pill-blue">Custom</span>
        <?php elseif ($job['grooming_type'] === 'none'): ?>
        <span class="pill pill-grey">None</span>
        <?php else: ?>
        <span class="pill pill-green">Suggested</span>
        <?php endif; ?>
    </div>

    <?php if ($grooming['type'] !== 'none'): ?>
    <p class="cell-strong"><?= e($grooming['title']) ?></p>
    <p class="cell-sub"><?= e($grooming['source']) ?></p>
    <?php else: ?>
    <p class="cell-sub">
        <?= $job['grooming_type'] === 'none'
            ? 'Candidates who fail this assessment see no preparation material.'
            : 'No default matches this job title, so candidates see nothing. Set a custom link.' ?>
    </p>
    <?php endif; ?>

    <div class="form-actions">
        <?php if ($grooming['type'] !== 'none'): ?>
        <a class="btn-plain-border" href="<?= e($grooming['url']) ?>" target="_blank" rel="noopener noreferrer">Open grooming</a>
        <?php endif; ?>
        <?php if ($job['status'] === 'draft'): ?>
        <a class="btn-plain-border" href="company-job-create.php?id=<?= (int) $jobId ?>&edit=grooming">Edit grooming</a>
        <?php else: ?>
        <span class="cell-sub">Published jobs cannot be changed.</span>
        <?php endif; ?>
    </div>

</div>

<div class="filter-row">
    <?php
    $tabs = ['all' => 'All', 'passed' => 'Passed', 'failed' => 'Failed', 'pending' => 'In progress'];
    foreach ($tabs as $key => $label):
    ?>
    <a class="filter-tab<?= $filter === $key ? ' on' : '' ?>"
       href="company-applicants.php?job=<?= (int) $jobId ?>&show=<?= $key ?>"><?= $label ?></a>
    <?php endforeach; ?>
</div>

<?php if (!$rows): ?>

<div class="empty" id="no-applicants">
    <p class="empty-title">Nobody here yet</p>
    <p class="empty-text">
        <?= $filter === 'all'
            ? 'Candidates appear once they apply for this job.'
            : 'No candidates match this filter.' ?>
    </p>
</div>

<?php else: ?>

<table class="data-table" id="results-table">
<thead>
    <tr>
        <th>Candidate</th>
        <th>Attempts</th>
        <th>Best score</th>
        <th>Status</th>
        <th>Completed</th>
    </tr>
</thead>
<tbody>
<?php foreach ($rows as $row): ?>
    <tr>
        <td>
            <?php if ($row['status'] === 'qualified'): ?>
            <a class="link-action cell-strong" href="company-applicant-view.php?application=<?= (int) $row['id'] ?>"><?= e($row['full_name']) ?></a>
            <?php else: ?>
            <span class="cell-strong"><?= e($row['full_name']) ?></span>
            <?php endif; ?>
            <?php if (!empty($row['headline'])): ?>
            <span class="cell-sub"><?= e($row['headline']) ?></span>
            <?php endif; ?>
        </td>
        <td><?= (int) $row['attempt_count'] ?> of 2</td>
        <td>
            <?php if ($row['best_score'] === null): ?>
            <span class="cell-sub">Not sat</span>
            <?php else: ?>
            <?= rtrim(rtrim(number_format((float) $row['best_score'], 2), '0'), '.') ?>%
            <?php endif; ?>
        </td>
        <td>
            <?php if ($row['status'] === 'qualified'): ?>
            <span class="pill pill-green">Passed</span>
            <?php elseif ($row['status'] === 'rejected'): ?>
            <span class="pill pill-grey">Failed, attempts used</span>
            <?php elseif ($row['status'] === 'grooming'): ?>
            <span class="pill pill-amber">In grooming</span>
            <?php else: ?>
            <span class="pill pill-blue">Applied</span>
            <?php endif; ?>
        </td>
        <td>
            <?= $row['last_submitted']
                ? date('j M Y', strtotime($row['last_submitted']))
                : '<span class="cell-sub">&mdash;</span>' ?>
        </td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>

<p class="field-help section-help">Passed candidates are clickable. Their full profile opens.</p>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>