<?php
// One applicant's profile, for the company that posted the job.
//
//   company-applicant-view.php?application=12
//
// The application id is the only thing taken from the URL. It is
// joined back to jobs.company_id and checked against the session
// before anything is read, so a company cannot view an applicant of
// a job it does not own by changing the number.
//
// Privacy: the full profile including contact details is only shown
// once the candidate has qualified for THIS job. Applying is not
// consent to hand over a phone number; passing the assessment is the
// point at which the platform forwards them.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/helpers.php';

require_role('company');

$companyId     = current_user_id();
$applicationId = isset($_GET['application']) ? (int) $_GET['application'] : 0;

$stmt = $pdo->prepare("
    SELECT ap.id, ap.status, ap.applied_at, ap.candidate_id, ap.job_id,
           j.title, j.company_id,
           u.email,
           p.full_name, p.headline, p.phone, p.location, p.summary,
           p.expected_title, p.resume_path, p.resume_name, p.photo_path,
           a.passing_score
    FROM applications ap
    JOIN jobs j                ON j.id = ap.job_id
    JOIN users u               ON u.id = ap.candidate_id
    JOIN candidate_profiles p  ON p.user_id = ap.candidate_id
    JOIN assessments a         ON a.job_id = j.id
    WHERE ap.id = :app AND j.company_id = :co
");
$stmt->execute([':app' => $applicationId, ':co' => $companyId]);
$candidate = $stmt->fetch();

if (!$candidate) {
    flash('That applicant does not exist, or they applied to another company.');
    redirect('company-jobs.php');
}

$qualified = $candidate['status'] === 'qualified';

// Attempt history for this job only.
$stmt = $pdo->prepare("
    SELECT attempt_no, score_percent, passed, submitted_at
    FROM attempts
    WHERE application_id = :app AND submitted_at IS NOT NULL
    ORDER BY attempt_no
");
$stmt->execute([':app' => $applicationId]);
$attempts = $stmt->fetchAll();

$bestScore = null;
$lastSat   = null;
foreach ($attempts as $a) {
    if ($bestScore === null || (float) $a['score_percent'] > $bestScore) {
        $bestScore = (float) $a['score_percent'];
    }
    $lastSat = $a['submitted_at'];
}

// Profile detail is only loaded when they have qualified.
$skills = $education = $experience = $projects = $achievements = [];

if ($qualified) {
    $stmt = $pdo->prepare("
        SELECT s.name FROM candidate_skills cs
        JOIN skills s ON s.id = cs.skill_id
        WHERE cs.candidate_id = :id ORDER BY s.name
    ");
    $stmt->execute([':id' => $candidate['candidate_id']]);
    $skills = array_column($stmt->fetchAll(), 'name');

    $stmt = $pdo->prepare("
        SELECT * FROM candidate_education
        WHERE candidate_id = :id ORDER BY end_year DESC, id
    ");
    $stmt->execute([':id' => $candidate['candidate_id']]);
    $education = $stmt->fetchAll();

    $stmt = $pdo->prepare("
        SELECT * FROM candidate_experience
        WHERE candidate_id = :id ORDER BY start_date DESC, id
    ");
    $stmt->execute([':id' => $candidate['candidate_id']]);
    $experience = $stmt->fetchAll();

    // Read live from the candidate's own rows. There is no copy, so
    // anything they change shows here the next time this page loads.
    $stmt = $pdo->prepare("SELECT * FROM candidate_projects WHERE candidate_id = :id ORDER BY position, id");
    $stmt->execute([':id' => $candidate['candidate_id']]);
    $projects = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT * FROM candidate_achievements WHERE candidate_id = :id ORDER BY achieved_on DESC, id");
    $stmt->execute([':id' => $candidate['candidate_id']]);
    $achievements = $stmt->fetchAll();
}

$page_title = $candidate['full_name'];
$active_nav = 'jobs';
$extra_css  = ['profile.css', 'community.css'];
require __DIR__ . '/includes/header-company.php';
?>

<p class="back-link">
    <a class="link-action" href="company-applicants.php?job=<?= (int) $candidate['job_id'] ?>">Back to applicants for <?= e($candidate['title']) ?></a>
</p>

<!-- Assessment outcome -->
<div class="form-card" id="assessment-card">

    <div class="card-head">
        <h2 class="section-heading">Assessment</h2>
        <?php if ($qualified): ?>
        <span class="pill pill-green">Passed</span>
        <?php elseif ($candidate['status'] === 'rejected'): ?>
        <span class="pill pill-grey">Failed, attempts used</span>
        <?php elseif ($candidate['status'] === 'grooming'): ?>
        <span class="pill pill-amber">In grooming</span>
        <?php else: ?>
        <span class="pill pill-blue">Applied</span>
        <?php endif; ?>
    </div>

    <dl class="detail-list">
        <div class="detail-pair">
            <dt class="detail-key">Job</dt>
            <dd class="detail-value"><?= e($candidate['title']) ?></dd>
        </div>
        <div class="detail-pair">
            <dt class="detail-key">Applied</dt>
            <dd class="detail-value"><?= date('j M Y', strtotime($candidate['applied_at'])) ?></dd>
        </div>
        <div class="detail-pair">
            <dt class="detail-key">Attempts used</dt>
            <dd class="detail-value"><?= count($attempts) ?> of 2</dd>
        </div>
        <div class="detail-pair">
            <dt class="detail-key">Best score</dt>
            <dd class="detail-value">
                <?= $bestScore === null
                    ? 'Not sat yet'
                    : rtrim(rtrim(number_format($bestScore, 2), '0'), '.') . '% against a ' . (int) $candidate['passing_score'] . '% pass mark' ?>
            </dd>
        </div>
        <?php if ($lastSat): ?>
        <div class="detail-pair">
            <dt class="detail-key">Completed</dt>
            <dd class="detail-value"><?= date('j M Y, g:ia', strtotime($lastSat)) ?></dd>
        </div>
        <?php endif; ?>
    </dl>

    <?php if ($attempts): ?>
    <ul class="entry-list">
        <?php foreach ($attempts as $a): ?>
        <li class="entry-item">
            <span class="cell-strong">
                Attempt <?= (int) $a['attempt_no'] ?> &middot;
                <?= rtrim(rtrim(number_format((float) $a['score_percent'], 2), '0'), '.') ?>%
                <?= (int) $a['passed'] === 1 ? 'passed' : 'not passed' ?>
            </span>
            <span class="cell-sub"><?= date('j M Y, g:ia', strtotime($a['submitted_at'])) ?></span>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

</div>

<?php if (!$qualified): ?>

<div class="form-card" id="not-qualified">
    <p class="field-help section-help">
        This candidate's full profile and contact details become visible once they
        pass the assessment for this job.
        <?php if ($candidate['status'] === 'grooming'): ?>
        They are working through grooming material before a second attempt.
        <?php elseif ($candidate['status'] === 'rejected'): ?>
        Both attempts have been used.
        <?php endif; ?>
    </p>
</div>

<?php else: ?>

<!-- Contact -->
<div class="form-card" id="contact-card">

    <div class="card-head">
        <h2 class="section-heading">Candidate</h2>
    </div>

    <?php if (!empty($candidate['photo_path'])): ?>
    <div class="photo-row">
        <img class="photo-preview" src="../<?= e($candidate['photo_path']) ?>" alt="<?= e($candidate['full_name']) ?>">
    </div>
    <?php endif; ?>

    <dl class="detail-list">
        <div class="detail-pair">
            <dt class="detail-key">Name</dt>
            <dd class="detail-value"><?= e($candidate['full_name']) ?></dd>
        </div>
        <?php if (!empty($candidate['headline'])): ?>
        <div class="detail-pair">
            <dt class="detail-key">Headline</dt>
            <dd class="detail-value"><?= e($candidate['headline']) ?></dd>
        </div>
        <?php endif; ?>
        <?php if (!empty($candidate['expected_title'])): ?>
        <div class="detail-pair">
            <dt class="detail-key">Role wanted</dt>
            <dd class="detail-value"><?= e($candidate['expected_title']) ?></dd>
        </div>
        <?php endif; ?>
        <div class="detail-pair">
            <dt class="detail-key">Email</dt>
            <dd class="detail-value"><?= e($candidate['email']) ?></dd>
        </div>
        <?php if (!empty($candidate['phone'])): ?>
        <div class="detail-pair">
            <dt class="detail-key">Phone</dt>
            <dd class="detail-value"><?= e($candidate['phone']) ?></dd>
        </div>
        <?php endif; ?>
        <?php if (!empty($candidate['location'])): ?>
        <div class="detail-pair">
            <dt class="detail-key">Location</dt>
            <dd class="detail-value"><?= e($candidate['location']) ?></dd>
        </div>
        <?php endif; ?>
        <?php if (!empty($candidate['summary'])): ?>
        <div class="detail-pair">
            <dt class="detail-key">About</dt>
            <dd class="detail-value"><?= nl2br(e($candidate['summary'])) ?></dd>
        </div>
        <?php endif; ?>
        <?php if (!empty($candidate['resume_path'])): ?>
        <div class="detail-pair">
            <dt class="detail-key">CV</dt>
            <dd class="detail-value">
                <a class="link-action" href="../<?= e($candidate['resume_path']) ?>" target="_blank"><?= e($candidate['resume_name'] ?: 'Download CV') ?></a>
            </dd>
        </div>
        <?php endif; ?>
    </dl>

</div>

<!-- Skills -->
<?php if ($skills): ?>
<div class="form-card" id="skills-card">
    <div class="card-head">
        <h2 class="section-heading">Skills</h2>
    </div>
    <div class="chip-list">
        <?php foreach ($skills as $skill): ?>
        <span class="chip"><span class="chip-label"><?= e($skill) ?></span></span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Education -->
<?php if ($education): ?>
<div class="form-card" id="education-card">
    <div class="card-head">
        <h2 class="section-heading">Education</h2>
    </div>
    <ul class="entry-list">
        <?php foreach ($education as $row): ?>
        <li class="entry-item">
            <span class="cell-strong"><?= e($row['degree']) ?></span>
            <span class="cell-sub">
                <?= e($row['institution']) ?><?php
                if ($row['start_year'] || $row['end_year']) {
                    echo ', ' . e((string) $row['start_year']) . ' to ' . e((string) $row['end_year']);
                }
                ?>
            </span>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Experience -->
<?php if ($experience): ?>
<div class="form-card" id="experience-card">
    <div class="card-head">
        <h2 class="section-heading">Experience</h2>
    </div>
    <ul class="entry-list">
        <?php foreach ($experience as $row): ?>
        <li class="entry-item">
            <span class="cell-strong"><?= e($row['job_title']) ?></span>
            <span class="cell-sub">
                <?= e($row['company']) ?><?php
                if ($row['start_date'] || $row['end_date']) {
                    echo ', '
                       . ($row['start_date'] ? date('M Y', strtotime($row['start_date'])) : '')
                       . ' to '
                       . ($row['end_date'] ? date('M Y', strtotime($row['end_date'])) : 'now');
                }
                ?>
            </span>
            <?php if (!empty($row['description'])): ?>
            <span class="entry-text"><?= nl2br(e($row['description'])) ?></span>
            <?php endif; ?>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Projects -->
<?php if ($projects): ?>
<div class="form-card" id="projects-card">
    <div class="card-head">
        <h2 class="section-heading">Projects</h2>
    </div>
    <?php foreach ($projects as $project): ?>
    <div class="item-row">
        <?php if (!empty($project['image_path'])): ?>
        <img class="item-thumb" src="../<?= e($project['image_path']) ?>" alt="">
        <?php endif; ?>
        <div class="item-body">
            <span class="cell-strong"><?= e($project['title']) ?></span>
            <?php if (!empty($project['technologies'])): ?>
            <span class="cell-sub"><?= e($project['technologies']) ?></span>
            <?php endif; ?>
            <?php if (!empty($project['description'])): ?>
            <p class="entry-text"><?= nl2br(e($project['description'])) ?></p>
            <?php endif; ?>
            <p class="item-links">
                <?php if (!empty($project['project_url'])): ?>
                <a class="link-action" href="<?= e($project['project_url']) ?>" target="_blank" rel="noopener noreferrer">View project</a>
                <?php endif; ?>
                <?php if (!empty($project['video_url'])): ?>
                <a class="link-action" href="<?= e($project['video_url']) ?>" target="_blank" rel="noopener noreferrer">Watch demo</a>
                <?php endif; ?>
            </p>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Achievements -->
<?php if ($achievements): ?>
<div class="form-card" id="achievements-card">
    <div class="card-head">
        <h2 class="section-heading">Achievements</h2>
    </div>
    <?php foreach ($achievements as $achievement): ?>
    <div class="item-row">
        <?php if (!empty($achievement['image_path'])): ?>
        <img class="item-thumb" src="../<?= e($achievement['image_path']) ?>" alt="">
        <?php endif; ?>
        <div class="item-body">
            <span class="cell-strong"><?= e($achievement['title']) ?></span>
            <span class="cell-sub">
                <?= e((string) $achievement['organization']) ?><?php
                if (!empty($achievement['achieved_on'])) {
                    echo ($achievement['organization'] ? ', ' : '') . date('M Y', strtotime($achievement['achieved_on']));
                }
                ?>
            </span>
            <?php if (!empty($achievement['description'])): ?>
            <p class="entry-text"><?= nl2br(e($achievement['description'])) ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>