<?php
// Browse open jobs, read one, and apply.
//
//   candidate-jobs.php          list of open jobs
//   candidate-jobs.php?id=12    one job in detail
//
// Applying is a POST to this same file. It never starts the
// assessment: the candidate lands back on the job page with the
// rules in front of them and has to press Start themselves.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/helpers.php';

require_role('candidate');

$candidateId = current_user_id();
$jobId       = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// A job is only visible to candidates once it is active, inside its
// deadline, and actually has questions. A published job with an
// empty assessment would send the candidate into a dead end.
const VISIBLE_JOB_SQL = "
    j.status = 'active'
    AND j.deadline > NOW()
    AND EXISTS (
        SELECT 1 FROM questions q
          JOIN assessments a ON a.id = q.assessment_id
         WHERE a.job_id = j.id
    )
";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $applyTo = (int) ($_POST['job_id'] ?? 0);

    $stmt = $pdo->prepare("
        SELECT j.id, j.title
        FROM jobs j
        WHERE j.id = :id AND " . VISIBLE_JOB_SQL
    );
    $stmt->execute([':id' => $applyTo]);
    $target = $stmt->fetch();

    if (!$target) {
        flash('That job is no longer open.');
        redirect('candidate-jobs.php');
    }

    // Re-check every gate server-side. The buttons hide these cases,
    // but a hidden button is not a control.
    $missing = profile_missing_parts($pdo, $candidateId);
    if ($missing) {
        flash('Add ' . implode(', ', $missing) . ' before applying.');
        redirect('candidate-profile.php');
    }

    if (!can_still_apply($pdo, $applyTo)) {
        flash('This job closes too soon to fit the second attempt, so applications are shut.');
        redirect('candidate-jobs.php?id=' . $applyTo);
    }

    $already = $pdo->prepare("
        SELECT id FROM applications
        WHERE job_id = :job AND candidate_id = :cand
    ");
    $already->execute([':job' => $applyTo, ':cand' => $candidateId]);

    if ($already->fetch()) {
        flash('You have already applied for this job.');
        redirect('candidate-jobs.php?id=' . $applyTo);
    }

    try {
        $pdo->prepare("
            INSERT INTO applications (job_id, candidate_id, status)
            VALUES (:job, :cand, 'applied')
        ")->execute([':job' => $applyTo, ':cand' => $candidateId]);

        flash('Applied. Start the assessment when you are ready.');

    } catch (PDOException $ex) {
        // The unique key catches a double submit that slipped past
        // the check above.
        flash('You have already applied for this job.');
    }

    redirect('candidate-jobs.php?id=' . $applyTo);
}


// ---------------------------------------------------------------
// Detail view
// ---------------------------------------------------------------
if ($jobId) {

    $stmt = $pdo->prepare("
        SELECT j.*, c.company_name, c.website, c.industry, c.company_size,
               c.location AS company_location, c.founded_year, c.about,
               c.contact_email, c.logo_path,
               a.time_limit_minutes, a.passing_score,
               (SELECT COUNT(*) FROM questions q WHERE q.assessment_id = a.id) AS question_count
        FROM jobs j
        JOIN company_profiles c ON c.user_id = j.company_id
        JOIN assessments a      ON a.job_id = j.id
        WHERE j.id = :id AND " . VISIBLE_JOB_SQL
    );
    $stmt->execute([':id' => $jobId]);
    $job = $stmt->fetch();

    if (!$job) {
        flash('That job is not open.');
        redirect('candidate-jobs.php');
    }

    $stmt = $pdo->prepare("
        SELECT s.name
        FROM job_skills js
        JOIN skills s ON s.id = js.skill_id
        WHERE js.job_id = :id
        ORDER BY s.name
    ");
    $stmt->execute([':id' => $jobId]);
    $jobSkills = array_column($stmt->fetchAll(), 'name');

    $stmt = $pdo->prepare("
        SELECT id, status FROM applications
        WHERE job_id = :job AND candidate_id = :cand
    ");
    $stmt->execute([':job' => $jobId, ':cand' => $candidateId]);
    $application = $stmt->fetch();

    $missing     = profile_missing_parts($pdo, $candidateId);
    $windowOpen  = can_still_apply($pdo, $jobId);
    $eligibility = $application ? attempt_eligibility($pdo, (int) $application['id']) : null;

    $page_title = $job['title'];
    $active_nav = 'jobs';
    require __DIR__ . '/includes/header-candidate.php';
    ?>

    <p class="back-link"><a class="link-action" href="candidate-jobs.php">Back to all jobs</a></p>

    <div class="panel">

        <div class="panel-head">
            <h2 class="panel-title"><?= e($job['title']) ?></h2>
            <span class="cell-sub"><?= e($job['company_name']) ?></span>
        </div>

        <p class="job-meta">
            <?= e(ucfirst(str_replace('-', ' ', $job['job_type']))) ?> &middot;
            <?= e($job['location']) ?> &middot;
            <?php if ((int) $job['experience_max'] === 0): ?>
            No experience required
            <?php else: ?>
            <?= (int) $job['experience_min'] ?>&ndash;<?= (int) $job['experience_max'] ?> years
            <?php endif; ?>
            &middot; closes <?= date('j M Y', strtotime($job['deadline'])) ?>
        </p>

        <?php if ($job['description'] !== null && $job['description'] !== ''): ?>
        <p class="job-description"><?= nl2br(e($job['description'])) ?></p>
        <?php endif; ?>

        <?php if ($jobSkills): ?>
        <p class="field-label">Skills needed</p>
        <p class="skill-line">
            <?php foreach ($jobSkills as $skill): ?>
            <span class="pill pill-grey"><?= e($skill) ?></span>
            <?php endforeach; ?>
        </p>
        <?php endif; ?>

    </div>

    <?php if (!empty($job['about']) || !empty($job['industry']) || !empty($job['logo_path'])): ?>
    <div class="panel" id="company-panel">

        <div class="company-head">
            <?php if (!empty($job['logo_path'])): ?>
            <img class="company-logo" src="../<?= e($job['logo_path']) ?>" alt="<?= e($job['company_name']) ?> logo">
            <?php endif; ?>
            <div class="company-meta">
                <h2 class="panel-title">About <?= e($job['company_name']) ?></h2>
                <p class="company-facts">
                    <?php
                    $facts = [];
                    if (!empty($job['industry']))         { $facts[] = $job['industry']; }
                    if (!empty($job['company_size']))     { $facts[] = $job['company_size'] . ' people'; }
                    if (!empty($job['company_location'])) { $facts[] = $job['company_location']; }
                    if (!empty($job['founded_year']))     { $facts[] = 'founded ' . (int) $job['founded_year']; }
                    echo e(implode(' - ', $facts));
                    ?>
                </p>
            </div>
        </div>

        <?php if (!empty($job['about'])): ?>
        <p class="job-description"><?= nl2br(e($job['about'])) ?></p>
        <?php endif; ?>

        <?php if (!empty($job['website'])): ?>
        <p class="company-facts">
            <a class="link-action" href="<?= e($job['website']) ?>" target="_blank" rel="noopener noreferrer"><?= e($job['website']) ?></a>
        </p>
        <?php endif; ?>

    </div>
    <?php endif; ?>

    <div class="panel">

        <h2 class="panel-title">How the assessment works</h2>

        <ul class="rule-list">
            <li class="rule-item"><?= (int) $job['question_count'] ?> questions, <?= (int) $job['time_limit_minutes'] ?> minutes, pass mark <?= (int) $job['passing_score'] ?>%.</li>
            <li class="rule-item">The timer keeps running if you close the tab. It does not pause.</li>
            <li class="rule-item">Fail the first attempt and you get grooming videos for the skills above.</li>
            <li class="rule-item">The second attempt unlocks <?= (int) $job['cooldown_days'] === 0 ? 'once grooming is finished' : 'after grooming and a ' . (int) $job['cooldown_days'] . '-day wait' ?>, and you then have <?= (int) $job['attempt_window_hours'] ?> hours to sit it.</li>
            <li class="rule-item">You get two attempts in total. Pass either one and your profile goes to <?= e($job['company_name']) ?>.</li>
        </ul>

        <div class="form-actions">
        <?php if ($application && $eligibility): ?>

            <?php if ($eligibility['action'] === 'start_1'): ?>
            <a class="btn-primary" href="candidate-assessment.php?application=<?= (int) $application['id'] ?>">Start assessment</a>
            <?php elseif ($eligibility['action'] === 'start_2'): ?>
            <a class="btn-primary" href="candidate-assessment.php?application=<?= (int) $application['id'] ?>">Start second attempt</a>
            <?php elseif ($eligibility['action'] === 'grooming'): ?>
            <a class="btn-primary" href="candidate-grooming.php?application=<?= (int) $application['id'] ?>">Continue grooming</a>
            <?php elseif ($application['status'] === 'qualified'): ?>
            <span class="pill pill-green">Passed</span>
            <?php elseif ($application['status'] === 'rejected'): ?>
            <span class="pill pill-grey">Not successful</span>
            <?php else: ?>
            <span class="pill pill-amber">Applied</span>
            <?php endif; ?>

            <?php if ($eligibility['reason'] !== ''): ?>
            <span class="cell-sub"><?= e($eligibility['reason']) ?></span>
            <?php endif; ?>

        <?php elseif ($missing): ?>

            <span class="cell-sub">
                Add <?= e(implode(', ', $missing)) ?> before you can apply.
            </span>
            <a class="btn-primary" href="candidate-profile.php">Finish your profile</a>

        <?php elseif (!$windowOpen): ?>

            <span class="cell-sub">
                This job closes too soon to fit grooming and a second attempt,
                so it is no longer accepting applications.
            </span>

        <?php else: ?>

            <form method="post" class="inline-form">
                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                <button class="btn-primary" type="submit" id="apply-button">Apply for this job</button>
            </form>

        <?php endif; ?>
        </div>

    </div>

    <?php
    require __DIR__ . '/includes/footer.php';
    exit;
}


// ---------------------------------------------------------------
// List view
// ---------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT j.id, j.title, j.job_type, j.location, j.deadline,
           j.experience_min, j.experience_max,
           c.company_name,
           ap.status AS application_status
    FROM jobs j
    JOIN company_profiles c ON c.user_id = j.company_id
    LEFT JOIN applications ap
           ON ap.job_id = j.id AND ap.candidate_id = :cand
    WHERE " . VISIBLE_JOB_SQL . "
    ORDER BY j.deadline
");
$stmt->execute([':cand' => $candidateId]);
$jobs = $stmt->fetchAll();

$missing = profile_missing_parts($pdo, $candidateId);

$page_title = 'Find jobs';
$active_nav = 'jobs';
require __DIR__ . '/includes/header-candidate.php';
?>

<?php if ($missing): ?>
<p class="notice" id="profile-prompt">
    Add <?= e(implode(', ', $missing)) ?> before you apply.
    <a class="link-action" href="candidate-profile.php">Finish your profile</a>
</p>
<?php endif; ?>

<?php if (!$jobs): ?>

<div class="empty" id="no-jobs">
    <p class="empty-title">No open jobs right now</p>
    <p class="empty-text">Jobs appear here once a company publishes one with its assessment ready.</p>
</div>

<?php else: ?>

<table class="data-table" id="jobs-table">
<thead>
    <tr>
        <th>Job</th>
        <th>Type</th>
        <th>Experience</th>
        <th>Closes</th>
        <th>Status</th>
    </tr>
</thead>
<tbody>
<?php foreach ($jobs as $job): ?>
    <tr>
        <td>
            <a class="link-action cell-strong" href="candidate-jobs.php?id=<?= (int) $job['id'] ?>"><?= e($job['title']) ?></a>
            <span class="cell-sub"><?= e($job['company_name']) ?>, <?= e($job['location']) ?></span>
        </td>
        <td><?= e(ucfirst(str_replace('-', ' ', $job['job_type']))) ?></td>
        <td>
            <?php if ((int) $job['experience_max'] === 0): ?>
            None
            <?php else: ?>
            <?= (int) $job['experience_min'] ?>&ndash;<?= (int) $job['experience_max'] ?> yrs
            <?php endif; ?>
        </td>
        <td><?= date('j M Y', strtotime($job['deadline'])) ?></td>
        <td>
            <?php if ($job['application_status'] === null): ?>
            <span class="pill pill-blue">Open</span>
            <?php elseif ($job['application_status'] === 'qualified'): ?>
            <span class="pill pill-green">Qualified</span>
            <?php elseif ($job['application_status'] === 'rejected'): ?>
            <span class="pill pill-grey">Not successful</span>
            <?php elseif ($job['application_status'] === 'grooming'): ?>
            <span class="pill pill-amber">In grooming</span>
            <?php else: ?>
            <span class="pill pill-amber">Applied</span>
            <?php endif; ?>
        </td>
    </tr>
<?php endforeach; ?>
</tbody>
</table>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>