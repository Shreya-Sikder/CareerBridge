<?php
// Every job this company has posted, with its publish state and
// applicant counts.
//
// Handles three actions by POST: publish, close, reopen.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/helpers.php';

require_role('company');

$companyId = current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';
    $jobId  = (int) ($_POST['job_id'] ?? 0);

    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE id = :id AND company_id = :co");
    $stmt->execute([':id' => $jobId, ':co' => $companyId]);
    $job = $stmt->fetch();

    if (!$job) {
        flash('That job does not exist, or it belongs to another company.');
        redirect('company-jobs.php');
    }

    if ($action === 'publish') {
        // A job with no questions would let candidates apply and then
        // hit an empty assessment.
        $questions = (int) $pdo->query("
            SELECT COUNT(*) FROM questions q
            JOIN assessments a ON a.id = q.assessment_id
            WHERE a.job_id = " . (int) $jobId
        )->fetchColumn();

        if ($questions === 0) {
            flash('Add at least one assessment question before publishing.');
            redirect('company-quiz-builder.php?job=' . $jobId);
        }

        if (strtotime($job['deadline']) <= time()) {
            flash('The closing date has passed. Change it before publishing.');
            redirect('company-job-create.php?id=' . $jobId);
        }

        $pdo->prepare("UPDATE jobs SET status = 'active' WHERE id = :id")
            ->execute([':id' => $jobId]);
        flash('Job published. Candidates can see it now.');

    } elseif ($action === 'close') {
        $pdo->prepare("UPDATE jobs SET status = 'closed' WHERE id = :id")
            ->execute([':id' => $jobId]);
        flash('Job closed. Candidates part-way through lose their second attempt.');

    } elseif ($action === 'reopen') {
        if (strtotime($job['deadline']) <= time()) {
            flash('The closing date has passed. Change it before reopening.');
            redirect('company-job-create.php?id=' . $jobId);
        }
        $pdo->prepare("UPDATE jobs SET status = 'active' WHERE id = :id")
            ->execute([':id' => $jobId]);
        flash('Job reopened.');
    }

    redirect('company-jobs.php');
}

$jobs = $pdo->prepare("
    SELECT j.*,
           (SELECT COUNT(*) FROM questions q
             JOIN assessments a ON a.id = q.assessment_id
            WHERE a.job_id = j.id) AS question_count,
           (SELECT COUNT(*) FROM applications ap
            WHERE ap.job_id = j.id) AS applicant_count,
           (SELECT COUNT(*) FROM applications ap
            WHERE ap.job_id = j.id AND ap.status = 'qualified') AS qualified_count,
           (SELECT a.locked_at FROM assessments a WHERE a.job_id = j.id) AS locked_at
    FROM jobs j
    WHERE j.company_id = :co
    ORDER BY j.created_at DESC
");
$jobs->execute([':co' => $companyId]);
$jobs = $jobs->fetchAll();

$page_title = 'Jobs';
$active_nav = 'jobs';
require __DIR__ . '/includes/header-company.php';
?>

<div class="page-actions">
    <a class="btn-primary" href="company-job-create.php" id="new-job">Post a job</a>
</div>

<?php if (!$jobs): ?>

<div class="empty" id="no-jobs">
    <p class="empty-title">No jobs yet</p>
    <p class="empty-text">Post a job, add its assessment questions, then publish it to start receiving applications.</p>
</div>

<?php else: ?>

<table class="data-table" id="jobs-table">
<thead>
    <tr>
        <th>Job</th>
        <th>Closes</th>
        <th>Questions</th>
        <th>Applicants</th>
        <th>Qualified</th>
        <th>State</th>
        <th>Actions</th>
    </tr>
</thead>
<tbody>
<?php foreach ($jobs as $job): ?>
    <tr>
        <td>
            <span class="cell-strong"><?= e($job['title']) ?></span>
            <span class="cell-sub"><?= e(ucfirst(str_replace('-', ' ', $job['job_type']))) ?>, <?= e($job['location']) ?></span>
        </td>
        <td><?= date('j M Y', strtotime($job['deadline'])) ?></td>
        <td><?= (int) $job['question_count'] ?></td>
        <td><?= (int) $job['applicant_count'] ?></td>
        <td><?= (int) $job['qualified_count'] ?></td>
        <td>
            <?php if ($job['status'] === 'active' && strtotime($job['deadline']) <= time()): ?>
            <span class="pill pill-grey">Expired</span>
            <?php elseif ($job['status'] === 'active'): ?>
            <span class="pill pill-green">Live</span>
            <?php elseif ($job['status'] === 'closed'): ?>
            <span class="pill pill-grey">Closed</span>
            <?php else: ?>
            <span class="pill pill-amber">Draft</span>
            <?php endif; ?>
        </td>
        <td class="cell-actions">
            <a class="link-action" href="company-applicants.php?job=<?= (int) $job['id'] ?>">Applicants</a>

            <?php if ($job['locked_at']): ?>
            <span class="link-muted" title="Candidates have already sat this assessment">Questions locked</span>
            <?php else: ?>
            <a class="link-action" href="company-quiz-builder.php?job=<?= (int) $job['id'] ?>">Questions</a>
            <?php endif; ?>

            <a class="link-action" href="company-job-create.php?id=<?= (int) $job['id'] ?>">Edit</a>

            <?php if ($job['status'] === 'draft'): ?>
            <form method="post" class="inline-form">
                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                <input type="hidden" name="action" value="publish">
                <button class="link-button" type="submit">Publish</button>
            </form>
            <?php elseif ($job['status'] === 'active'): ?>
            <form method="post" class="inline-form">
                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                <input type="hidden" name="action" value="close">
                <button class="link-button" type="submit">Close</button>
            </form>
            <?php else: ?>
            <form method="post" class="inline-form">
                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                <input type="hidden" name="action" value="reopen">
                <button class="link-button" type="submit">Reopen</button>
            </form>
            <?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>