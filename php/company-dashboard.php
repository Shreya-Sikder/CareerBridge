<?php
// Company home. Four counts and the most recent jobs.
//
// Everything here is queried, not hardcoded. If a number looks wrong,
// the query below is the only place to look.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/helpers.php';

require_role('company');

$companyId = current_user_id();

// A job counts as live only if it is active AND still inside its
// deadline. An active job past its deadline is expired, not live,
// and the job list labels it that way too.
$stmt = $pdo->prepare("
    SELECT
      (SELECT COUNT(*) FROM jobs j
        WHERE j.company_id = :co1
          AND j.status = 'active'
          AND j.deadline > NOW())                        AS live_jobs,

      (SELECT COUNT(*) FROM applications ap
         JOIN jobs j ON j.id = ap.job_id
        WHERE j.company_id = :co2)                       AS applicants,

      (SELECT COUNT(*) FROM applications ap
         JOIN jobs j ON j.id = ap.job_id
        WHERE j.company_id = :co3
          AND ap.status = 'qualified')                   AS qualified,

      (SELECT COUNT(*) FROM jobs j
        WHERE j.company_id = :co4
          AND j.status = 'draft'
          AND NOT EXISTS (
              SELECT 1 FROM questions q
                JOIN assessments a ON a.id = q.assessment_id
               WHERE a.job_id = j.id
          ))                                             AS needs_questions
");
$stmt->execute([
    ':co1' => $companyId, ':co2' => $companyId,
    ':co3' => $companyId, ':co4' => $companyId,
]);
$counts = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT j.id, j.title, j.status, j.deadline,
           (SELECT COUNT(*) FROM applications ap WHERE ap.job_id = j.id) AS applicant_count,
           (SELECT COUNT(*) FROM applications ap
             WHERE ap.job_id = j.id AND ap.status = 'qualified')         AS qualified_count,
           (SELECT COUNT(*) FROM questions q
              JOIN assessments a ON a.id = q.assessment_id
             WHERE a.job_id = j.id)                                      AS question_count
    FROM jobs j
    WHERE j.company_id = :co
    ORDER BY j.created_at DESC
    LIMIT 5
");
$stmt->execute([':co' => $companyId]);
$recent = $stmt->fetchAll();

$page_title = 'Dashboard';
$active_nav = 'dashboard';
$extra_css  = ['home.css'];
require __DIR__ . '/includes/header-company.php';
?>

<div class="metric-row" id="metrics">

    <div class="metric">
        <span class="metric-value"><?= (int) $counts['live_jobs'] ?></span>
        <span class="metric-label">Live jobs</span>
    </div>

    <div class="metric">
        <span class="metric-value"><?= (int) $counts['applicants'] ?></span>
        <span class="metric-label">Applicants</span>
    </div>

    <div class="metric">
        <span class="metric-value"><?= (int) $counts['qualified'] ?></span>
        <span class="metric-label">Qualified</span>
    </div>

    <div class="metric<?= (int) $counts['needs_questions'] > 0 ? ' metric-flag' : '' ?>">
        <span class="metric-value"><?= (int) $counts['needs_questions'] ?></span>
        <span class="metric-label">Need questions</span>
    </div>

</div>

<?php if ((int) $counts['needs_questions'] > 0): ?>
<p class="notice" id="draft-warning">
    Some jobs have no assessment questions yet and cannot be published.
    Open a job below and add them.
</p>
<?php endif; ?>

<div class="panel">

    <div class="panel-head">
        <h2 class="panel-title">Recent jobs</h2>
        <a class="link-action" href="company-jobs.php">View all</a>
    </div>

    <?php if (!$recent): ?>

    <div class="empty" id="no-jobs">
        <p class="empty-title">No jobs yet</p>
        <p class="empty-text">Post a job, add its assessment questions, then publish it to start receiving applications.</p>
        <p class="empty-action"><a class="btn-primary" href="company-job-create.php">Post a job</a></p>
    </div>

    <?php else: ?>

    <table class="data-table" id="recent-jobs">
    <thead>
        <tr>
            <th>Job</th>
            <th>Closes</th>
            <th>Applicants</th>
            <th>Qualified</th>
            <th>State</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($recent as $job): ?>
        <tr>
            <td>
                <a class="link-action" href="company-applicants.php?job=<?= (int) $job['id'] ?>"><?= e($job['title']) ?></a>
            </td>
            <td><?= date('j M Y', strtotime($job['deadline'])) ?></td>
            <td><?= (int) $job['applicant_count'] ?></td>
            <td><?= (int) $job['qualified_count'] ?></td>
            <td>
                <?php if ($job['status'] === 'active' && strtotime($job['deadline']) <= time()): ?>
                <span class="pill pill-grey">Expired</span>
                <?php elseif ($job['status'] === 'active'): ?>
                <span class="pill pill-green">Live</span>
                <?php elseif ($job['status'] === 'closed'): ?>
                <span class="pill pill-grey">Closed</span>
                <?php elseif ((int) $job['question_count'] === 0): ?>
                <span class="pill pill-amber">Needs questions</span>
                <?php else: ?>
                <span class="pill pill-amber">Draft</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    </table>

    <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>