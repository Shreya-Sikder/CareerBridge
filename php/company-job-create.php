<?php
// Post or edit a job, one block at a time.
//
//   company-job-create.php          start a new job
//   company-job-create.php?id=12    continue or edit job 12
//
// Three blocks, each posting separately:
//
//   1. details  title, type, location, experience, description, deadline
//   2. skills   required skills, added one at a time
//   3. rules    cooldown and second-attempt window
//
// A job row is a single row, unlike the candidate profile's five
// tables, so block 1 has to create the draft before blocks 2 and 3
// have anything to write to. Until then they render locked.
//
// Editing is only possible while the job is a draft. Once it is
// published, changing the required skills would silently change which
// grooming videos applicants receive, so the whole page goes
// read-only.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/helpers.php';

require_role('company');

$companyId = current_user_id();
$jobId     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$errors    = [];
$editing   = $_GET['edit'] ?? '';

$JOB_TYPES = ['full-time', 'part-time', 'internship', 'contract'];

// Defaults for a job that does not exist yet.
$job = [
    'title'                => '',
    'job_type'             => 'full-time',
    'location'             => '',
    'experience_min'       => 0,
    'experience_max'       => 0,
    'description'          => '',
    'deadline'             => '',
    'cooldown_days'        => 3,
    'attempt_window_hours' => 24,
    'status'               => 'draft',
];
$jobSkillIds = [];

if ($jobId) {
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE id = :id AND company_id = :co");
    $stmt->execute([':id' => $jobId, ':co' => $companyId]);
    $found = $stmt->fetch();

    if (!$found) {
        flash('That job does not exist, or it belongs to another company.');
        redirect('company-jobs.php');
    }

    $job = $found;

    $stmt = $pdo->prepare("SELECT skill_id FROM job_skills WHERE job_id = :id");
    $stmt->execute([':id' => $jobId]);
    $jobSkillIds = array_map('intval', array_column($stmt->fetchAll(), 'skill_id'));
}

$isDraft   = $job['status'] === 'draft';
$isLocked  = $jobId && !$isDraft;


// ===============================================================
// POST
// ===============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isLocked) {

    $section = $_POST['section'] ?? '';

    // -----------------------------------------------------------
    if ($section === 'details') {

        $job['title']          = trim($_POST['title'] ?? '');
        $job['job_type']       = $_POST['job_type'] ?? 'full-time';
        $job['location']       = trim($_POST['location'] ?? '');
        $job['experience_min'] = (int) ($_POST['experience_min'] ?? 0);
        $job['experience_max'] = (int) ($_POST['experience_max'] ?? 0);
        $job['description']    = trim($_POST['description'] ?? '');
        $job['deadline']       = $_POST['deadline'] ?? '';

        if ($job['title'] === '') {
            $errors[] = 'Enter a job title.';
        }
        if ($job['location'] === '') {
            $errors[] = 'Enter a location.';
        }
        if (!in_array($job['job_type'], $JOB_TYPES, true)) {
            $errors[] = 'Choose a valid job type.';
        }
        if ($job['experience_min'] < 0 || $job['experience_max'] < 0) {
            $errors[] = 'Experience cannot be negative.';
        }
        if ($job['experience_max'] < $job['experience_min']) {
            $errors[] = 'Maximum experience must be at least the minimum.';
        }
        if ($job['deadline'] === '' || !strtotime($job['deadline'])) {
            $errors[] = 'Choose a closing date.';
        } elseif (strtotime($job['deadline']) <= time()) {
            $errors[] = 'The closing date must be in the future.';
        }

        // The attempt rules live in block 3, so check this deadline
        // against whatever is stored there now.
        if (!$errors) {
            $needed = time()
                + ((int) $job['cooldown_days'] * 86400)
                + ((int) $job['attempt_window_hours'] * 3600);

            if ($needed > strtotime($job['deadline'])) {
                $errors[] = 'The closing date is too soon for the attempt rules you have saved ('
                          . (int) $job['cooldown_days'] . '-day wait plus '
                          . (int) $job['attempt_window_hours'] . ' hours). '
                          . 'Use ' . date('j M Y, g:ia', $needed) . ' or later, '
                          . 'or shorten the wait in the attempt rules below.';
            }
        }

        if (!$errors) {
            $deadline = date('Y-m-d H:i:s', strtotime($job['deadline']));

            if ($jobId) {
                $pdo->prepare("
                    UPDATE jobs SET
                        title = :title, job_type = :type, location = :loc,
                        experience_min = :emin, experience_max = :emax,
                        description = :desc, deadline = :dead
                    WHERE id = :id AND company_id = :co
                ")->execute([
                    ':title' => $job['title'], ':type' => $job['job_type'],
                    ':loc'   => $job['location'], ':emin' => $job['experience_min'],
                    ':emax'  => $job['experience_max'], ':desc' => $job['description'] ?: null,
                    ':dead'  => $deadline, ':id' => $jobId, ':co' => $companyId,
                ]);

                flash('Job details saved.');
                redirect('company-job-create.php?id=' . $jobId);
            }

            $pdo->beginTransaction();

            $pdo->prepare("
                INSERT INTO jobs
                    (company_id, title, job_type, location, experience_min,
                     experience_max, description, deadline, cooldown_days,
                     attempt_window_hours, status)
                VALUES
                    (:co, :title, :type, :loc, :emin, :emax, :desc, :dead,
                     :cool, :win, 'draft')
            ")->execute([
                ':co'   => $companyId, ':title' => $job['title'],
                ':type' => $job['job_type'], ':loc' => $job['location'],
                ':emin' => $job['experience_min'], ':emax' => $job['experience_max'],
                ':desc' => $job['description'] ?: null, ':dead' => $deadline,
                ':cool' => $job['cooldown_days'], ':win' => $job['attempt_window_hours'],
            ]);
            $jobId = (int) $pdo->lastInsertId();

            // Every job needs exactly one assessment row so the quiz
            // builder always has somewhere to write.
            $pdo->prepare("
                INSERT INTO assessments (job_id) VALUES (:job)
                ON DUPLICATE KEY UPDATE job_id = job_id
            ")->execute([':job' => $jobId]);

            $pdo->commit();

            flash('Job details saved. Now add the required skills.');
            redirect('company-job-create.php?id=' . $jobId);
        }

        $editing = 'details';

    // -----------------------------------------------------------
    } elseif ($section === 'skills' && $jobId) {

        $posted = array_map('intval', $_POST['skills'] ?? []);

        if (!$posted) {
            $errors[] = 'Add at least one required skill. Grooming videos are matched from these.';
        }

        if (!$errors) {
            $pdo->beginTransaction();

            $pdo->prepare("DELETE FROM job_skills WHERE job_id = :id")
                ->execute([':id' => $jobId]);

            $insert = $pdo->prepare("INSERT INTO job_skills (job_id, skill_id) VALUES (:job, :skill)");
            foreach (array_unique($posted) as $skillId) {
                $insert->execute([':job' => $jobId, ':skill' => $skillId]);
            }

            $pdo->commit();

            flash('Required skills saved.');
            redirect('company-job-create.php?id=' . $jobId);
        }

        $jobSkillIds = $posted;
        $editing = 'skills';

    // -----------------------------------------------------------
    } elseif ($section === 'grooming' && $jobId) {

        $type = $_POST['grooming_type'] ?? 'suggested';
        $url  = trim($_POST['grooming_url'] ?? '');

        if (!in_array($type, ['suggested', 'custom', 'none'], true)) {
            $errors[] = 'Choose one of the three grooming options.';
        }

        if ($type === 'custom') {
            if ($url === '') {
                $errors[] = 'Enter the YouTube link you want to use.';
            } elseif (!is_youtube_url($url)) {
                $errors[] = 'That is not a YouTube link. Paste a youtube.com or youtu.be video or playlist URL.';
            } elseif (strlen($url) > 500) {
                $errors[] = 'That link is too long.';
            }
        }

        if ($type === 'suggested' && !match_grooming_role($pdo, $job['title'])) {
            $errors[] = 'No default grooming matches the title "' . $job['title'] . '". '
                      . 'Add a custom link, or choose no grooming.';
        }

        if (!$errors) {
            $pdo->prepare("
                UPDATE jobs SET grooming_type = :type, grooming_url = :url
                WHERE id = :id AND company_id = :co
            ")->execute([
                ':type' => $type,
                ':url'  => $type === 'custom' ? $url : null,
                ':id'   => $jobId, ':co' => $companyId,
            ]);

            flash('Grooming recommendation saved.');
            redirect('company-job-create.php?id=' . $jobId);
        }

        $job['grooming_type'] = $type;
        $job['grooming_url']  = $url;
        $editing = 'grooming';

    // -----------------------------------------------------------
    } elseif ($section === 'rules' && $jobId) {

        $job['cooldown_days']        = (int) ($_POST['cooldown_days'] ?? 3);
        $job['attempt_window_hours'] = (int) ($_POST['attempt_window_hours'] ?? 24);

        if ($job['cooldown_days'] < 0 || $job['cooldown_days'] > 30) {
            $errors[] = 'The wait must be between 0 and 30 days.';
        }
        if ($job['attempt_window_hours'] < 1 || $job['attempt_window_hours'] > 720) {
            $errors[] = 'The second-attempt window must be between 1 and 720 hours.';
        }

        // Same rule as block 1, checked from the other side.
        if (!$errors) {
            $needed = time()
                + ($job['cooldown_days'] * 86400)
                + ($job['attempt_window_hours'] * 3600);

            if ($needed > strtotime($job['deadline'])) {
                $errors[] = 'These rules do not fit before the closing date you saved ('
                          . date('j M Y, g:ia', strtotime($job['deadline'])) . '). '
                          . 'Shorten the wait, or push the closing date back in the job details above.';
            }
        }

        if (!$errors) {
            $pdo->prepare("
                UPDATE jobs SET cooldown_days = :cool, attempt_window_hours = :win
                WHERE id = :id AND company_id = :co
            ")->execute([
                ':cool' => $job['cooldown_days'],
                ':win'  => $job['attempt_window_hours'],
                ':id'   => $jobId, ':co' => $companyId,
            ]);

            flash('Attempt rules saved.');
            redirect('company-job-create.php?id=' . $jobId);
        }

        $editing = 'rules';
    }
}


// ===============================================================
// Display
// ===============================================================
$skills = all_skills($pdo);

$skillNames = [];
foreach ($skills as $s) {
    $skillNames[(int) $s['id']] = $s['name'];
}

$questionCount = 0;
if ($jobId) {
    $questionCount = (int) $pdo->query("
        SELECT COUNT(*) FROM questions q
        JOIN assessments a ON a.id = q.assessment_id
        WHERE a.job_id = " . (int) $jobId
    )->fetchColumn();
}

$hasDetails = $jobId > 0;
$hasSkills  = $jobSkillIds !== [];
$hasRules   = $jobId > 0;

$showDetailsForm = !$isLocked && (!$hasDetails || $editing === 'details');
$showSkillsForm  = !$isLocked && $hasDetails && (!$hasSkills || $editing === 'skills');
$showRulesForm   = !$isLocked && $hasDetails && $editing === 'rules';

$grooming        = $hasDetails ? grooming_for_job($pdo, $jobId) : null;
$suggestedRole   = $hasDetails ? match_grooming_role($pdo, $job['title']) : null;
$showGroomingForm = !$isLocked && $hasDetails && $editing === 'grooming';

// The date input needs its own format.
$deadlineInput = $job['deadline'] !== ''
    ? date('Y-m-d\TH:i', strtotime($job['deadline']))
    : '';

$page_title = $jobId ? ($isLocked ? 'Job: ' . $job['title'] : 'Edit job') : 'Post a job';
$active_nav = 'jobs';
$extra_css  = ['profile.css', 'quiz.css'];
require __DIR__ . '/includes/header-company.php';
?>

<?php if ($isLocked): ?>
<p class="notice" id="locked-notice">
    This job is <?= e($job['status']) ?>, so it can no longer be changed.
    Editing the required skills now would change which grooming videos
    applicants receive. Close it from the job list if you need to stop it.
</p>
<?php endif; ?>

<?php if ($errors): ?>
<div class="errors" id="form-errors">
    <?php foreach ($errors as $error): ?>
    <p class="error-line"><?= e($error) ?></p>
    <?php endforeach; ?>
</div>
<?php endif; ?>


<!-- Block 1: job details -->
<div class="form-card" id="details-card">

    <div class="card-head">
        <h2 class="section-heading">1. Job details</h2>
        <?php if ($hasDetails): ?>
        <span class="saved-tag">Saved</span>
        <?php endif; ?>
    </div>

    <?php if ($showDetailsForm): ?>

    <form method="post" id="details-form">
        <input type="hidden" name="section" value="details">

        <div class="field">
            <label class="field-label" for="title">Job title</label>
            <input class="field-input" type="text" name="title" id="title"
                   value="<?= e($job['title']) ?>" maxlength="255" required>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="field-label" for="job_type">Type</label>
                <select class="field-input" name="job_type" id="job_type">
                    <?php foreach ($JOB_TYPES as $type): ?>
                    <option value="<?= $type ?>"<?= $job['job_type'] === $type ? ' selected' : '' ?>>
                        <?= ucfirst(str_replace('-', ' ', $type)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label class="field-label" for="location">Location</label>
                <input class="field-input" type="text" name="location" id="location"
                       value="<?= e($job['location']) ?>" maxlength="255" required>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="field-label" for="experience_min">Experience from (years)</label>
                <input class="field-input" type="number" name="experience_min" id="experience_min"
                       value="<?= (int) $job['experience_min'] ?>" min="0" max="50">
            </div>

            <div class="field">
                <label class="field-label" for="experience_max">Experience to (years)</label>
                <input class="field-input" type="number" name="experience_max" id="experience_max"
                       value="<?= (int) $job['experience_max'] ?>" min="0" max="50">
            </div>
        </div>

        <div class="field">
            <label class="field-label" for="description">Description</label>
            <textarea class="field-input field-area" name="description" id="description"
                      rows="6"><?= e((string) $job['description']) ?></textarea>
        </div>

        <div class="field">
            <label class="field-label" for="deadline">Applications close</label>
            <input class="field-input" type="datetime-local" name="deadline" id="deadline"
                   value="<?= e($deadlineInput) ?>" required>
        </div>

        <div class="form-actions">
            <button class="btn-primary" type="submit">
                <?= $hasDetails ? 'Save details' : 'Save and continue' ?>
            </button>
            <?php if ($hasDetails): ?>
            <a class="btn-plain" href="company-job-create.php?id=<?= (int) $jobId ?>">Cancel</a>
            <?php else: ?>
            <a class="btn-plain" href="company-jobs.php">Cancel</a>
            <?php endif; ?>
        </div>
    </form>

    <?php else: ?>

    <dl class="detail-list">
        <div class="detail-pair">
            <dt class="detail-key">Title</dt>
            <dd class="detail-value"><?= e($job['title']) ?></dd>
        </div>
        <div class="detail-pair">
            <dt class="detail-key">Type</dt>
            <dd class="detail-value"><?= e(ucfirst(str_replace('-', ' ', $job['job_type']))) ?></dd>
        </div>
        <div class="detail-pair">
            <dt class="detail-key">Location</dt>
            <dd class="detail-value"><?= e($job['location']) ?></dd>
        </div>
        <div class="detail-pair">
            <dt class="detail-key">Experience</dt>
            <dd class="detail-value">
                <?php if ((int) $job['experience_max'] === 0): ?>
                None required
                <?php else: ?>
                <?= (int) $job['experience_min'] ?> to <?= (int) $job['experience_max'] ?> years
                <?php endif; ?>
            </dd>
        </div>
        <div class="detail-pair">
            <dt class="detail-key">Closes</dt>
            <dd class="detail-value"><?= date('j M Y, g:ia', strtotime($job['deadline'])) ?></dd>
        </div>
        <?php if (!empty($job['description'])): ?>
        <div class="detail-pair">
            <dt class="detail-key">Description</dt>
            <dd class="detail-value"><?= nl2br(e($job['description'])) ?></dd>
        </div>
        <?php endif; ?>
    </dl>

    <?php if (!$isLocked): ?>
    <div class="form-actions">
        <a class="btn-plain-border" href="company-job-create.php?id=<?= (int) $jobId ?>&edit=details">Edit</a>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</div>


<!-- Block 2: required skills -->
<div class="form-card<?= $hasDetails ? '' : ' card-locked' ?>" id="skills-card">

    <div class="card-head">
        <h2 class="section-heading">2. Required skills</h2>
        <?php if ($hasSkills): ?>
        <span class="saved-tag">Saved</span>
        <?php endif; ?>
    </div>

    <?php if (!$hasDetails): ?>

    <p class="field-help section-help">Save the job details first, then add the skills.</p>

    <?php else: ?>

    <?php if ($showSkillsForm): ?>

    <p class="field-help section-help">
        Candidates who fail the assessment get grooming videos matched to these skills.
        Add them one at a time.
    </p>

    <form method="post" id="skills-form">
        <input type="hidden" name="section" value="skills">

        <div class="skill-picker" id="skill-picker" hidden
             data-skills='<?= e(json_encode(array_map(fn($s) => ["id" => (int) $s["id"], "name" => $s["name"]], $skills), JSON_UNESCAPED_UNICODE)) ?>'
             data-selected='<?= e(json_encode($jobSkillIds)) ?>'>

            <p class="field-label">Selected skills</p>

            <div class="chip-list" id="selected-skills"></div>
            <p class="field-help" id="skill-empty">Nothing added yet.</p>

            <div class="picker-row">
                <input class="field-input picker-search" type="text" id="skill-search"
                       placeholder="Search skills" autocomplete="off">
                <select class="field-input picker-select" id="skill-select"></select>
                <button class="btn-secondary" type="button" id="skill-add">Add</button>
            </div>

            <div id="skill-hidden"></div>

            <p class="hint-warn" id="skill-dirty" hidden>
                You have unsaved changes. Press Save skills to keep them.
            </p>
        </div>

        <noscript>
            <p class="field-help">JavaScript is off, so this falls back to the plain list. Hold Ctrl or Cmd to pick more than one.</p>
        </noscript>

        <div class="field" id="skills-fallback">
            <label class="field-label" for="skills">Choose the required skills</label>
            <select class="field-input field-multi" name="skills[]" id="skills" multiple size="10">
                <?php foreach ($skills as $skill): ?>
                <option value="<?= (int) $skill['id'] ?>"<?= in_array((int) $skill['id'], $jobSkillIds, true) ? ' selected' : '' ?>>
                    <?= e($skill['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-actions">
            <button class="btn-primary" type="submit">Save skills</button>
            <?php if ($hasSkills): ?>
            <a class="btn-plain" href="company-job-create.php?id=<?= (int) $jobId ?>">Cancel</a>
            <?php endif; ?>
        </div>
    </form>

    <?php else: ?>

    <div class="chip-list">
        <?php foreach ($jobSkillIds as $skillId): ?>
        <span class="chip"><span class="chip-label"><?= e($skillNames[$skillId] ?? '') ?></span></span>
        <?php endforeach; ?>
    </div>

    <?php if (!$isLocked): ?>
    <div class="form-actions">
        <a class="btn-plain-border" href="company-job-create.php?id=<?= (int) $jobId ?>&edit=skills">Edit</a>
    </div>
    <?php endif; ?>

    <?php endif; ?>

    <?php endif; ?>

</div>


<!-- Block 3: attempt rules -->
<div class="form-card<?= $hasDetails ? '' : ' card-locked' ?>" id="rules-card">

    <div class="card-head">
        <h2 class="section-heading">3. Attempt rules</h2>
        <?php if ($hasRules): ?>
        <span class="saved-tag">Saved</span>
        <?php endif; ?>
    </div>

    <?php if (!$hasDetails): ?>

    <p class="field-help section-help">Save the job details first.</p>

    <?php elseif ($showRulesForm): ?>

    <form method="post" id="rules-form">
        <input type="hidden" name="section" value="rules">

        <div class="field-row">
            <div class="field">
                <label class="field-label" for="cooldown_days">Wait before second attempt (days)</label>
                <input class="field-input" type="number" name="cooldown_days" id="cooldown_days"
                       value="<?= (int) $job['cooldown_days'] ?>" min="0" max="30">
                <p class="field-help">Time a failed candidate spends on grooming before retrying. 0 means they can retry as soon as grooming is done.</p>
            </div>

            <div class="field">
                <label class="field-label" for="attempt_window_hours">Second attempt window (hours)</label>
                <input class="field-input" type="number" name="attempt_window_hours" id="attempt_window_hours"
                       value="<?= (int) $job['attempt_window_hours'] ?>" min="1" max="720">
                <p class="field-help">How long they have to sit it once it unlocks.</p>
            </div>
        </div>

        <div class="form-actions">
            <button class="btn-primary" type="submit">Save rules</button>
            <a class="btn-plain" href="company-job-create.php?id=<?= (int) $jobId ?>">Cancel</a>
        </div>
    </form>

    <?php else: ?>

    <dl class="detail-list">
        <div class="detail-pair">
            <dt class="detail-key">Wait to retry</dt>
            <dd class="detail-value">
                <?= (int) $job['cooldown_days'] === 0
                    ? 'None, as soon as grooming is finished'
                    : (int) $job['cooldown_days'] . ' days after the first attempt' ?>
            </dd>
        </div>
        <div class="detail-pair">
            <dt class="detail-key">Window to sit it</dt>
            <dd class="detail-value"><?= (int) $job['attempt_window_hours'] ?> hours</dd>
        </div>
    </dl>

    <?php if (!$isLocked): ?>
    <div class="form-actions">
        <a class="btn-plain-border" href="company-job-create.php?id=<?= (int) $jobId ?>&edit=rules">Edit</a>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</div>


<!-- Block 4: grooming recommendation -->
<div class="form-card<?= $hasDetails ? '' : ' card-locked' ?>" id="grooming-card">

    <div class="card-head">
        <h2 class="section-heading">4. Grooming recommendation</h2>
        <?php if ($hasDetails): ?>
        <span class="saved-tag">Saved</span>
        <?php endif; ?>
    </div>

    <?php if (!$hasDetails): ?>

    <p class="field-help section-help">Save the job details first.</p>

    <?php elseif ($showGroomingForm): ?>

    <p class="field-help section-help">
        Shown to candidates who fail their first attempt, before they retake.
    </p>

    <form method="post" id="grooming-form">
        <input type="hidden" name="section" value="grooming">

        <div class="choice-list">

            <label class="choice<?= $job['grooming_type'] === 'suggested' ? ' chosen' : '' ?>">
                <input class="choice-radio" type="radio" name="grooming_type" value="suggested"
                       <?= $job['grooming_type'] === 'suggested' ? 'checked' : '' ?>>
                <span class="choice-body">
                    <span class="choice-title">Use the suggested content</span>
                    <?php if ($suggestedRole): ?>
                    <span class="choice-sub">
                        Matched to <strong><?= e($suggestedRole['label']) ?></strong> from the title
                        "<?= e($job['title']) ?>" &mdash; <?= e($suggestedRole['title']) ?>
                    </span>
                    <a class="link-action" href="<?= e($suggestedRole['url']) ?>" target="_blank" rel="noopener noreferrer">Preview</a>
                    <?php else: ?>
                    <span class="choice-sub choice-warn">
                        No default matches "<?= e($job['title']) ?>". Pick a custom link or no grooming.
                    </span>
                    <?php endif; ?>
                </span>
            </label>

            <label class="choice<?= $job['grooming_type'] === 'custom' ? ' chosen' : '' ?>">
                <input class="choice-radio" type="radio" name="grooming_type" value="custom"
                       <?= $job['grooming_type'] === 'custom' ? 'checked' : '' ?>>
                <span class="choice-body">
                    <span class="choice-title">Add a custom YouTube link</span>
                    <span class="choice-sub">A video or playlist of your own, used for this job only.</span>
                    <input class="field-input" type="url" name="grooming_url" id="grooming_url"
                           value="<?= e((string) ($job['grooming_url'] ?? '')) ?>" maxlength="500"
                           placeholder="https://youtube.com/playlist?list=...">
                </span>
            </label>

            <label class="choice<?= $job['grooming_type'] === 'none' ? ' chosen' : '' ?>">
                <input class="choice-radio" type="radio" name="grooming_type" value="none"
                       <?= $job['grooming_type'] === 'none' ? 'checked' : '' ?>>
                <span class="choice-body">
                    <span class="choice-title">No grooming recommendation</span>
                    <span class="choice-sub">Candidates who fail get no material before their retake.</span>
                </span>
            </label>

        </div>

        <div class="form-actions">
            <button class="btn-primary" type="submit">Save grooming</button>
            <a class="btn-plain" href="company-job-create.php?id=<?= (int) $jobId ?>">Cancel</a>
        </div>
    </form>

    <?php else: ?>

    <dl class="detail-list">
        <div class="detail-pair">
            <dt class="detail-key">Type</dt>
            <dd class="detail-value">
                <?php if ($job['grooming_type'] === 'custom'): ?>
                Custom, company provided
                <?php elseif ($job['grooming_type'] === 'none'): ?>
                None
                <?php else: ?>
                Suggested default
                <?php endif; ?>
            </dd>
        </div>
        <?php if ($grooming['type'] !== 'none'): ?>
        <div class="detail-pair">
            <dt class="detail-key">Material</dt>
            <dd class="detail-value"><?= e($grooming['title']) ?></dd>
        </div>
        <div class="detail-pair">
            <dt class="detail-key">Source</dt>
            <dd class="detail-value"><?= e($grooming['source']) ?></dd>
        </div>
        <?php elseif ($job['grooming_type'] === 'suggested'): ?>
        <div class="detail-pair">
            <dt class="detail-key">Warning</dt>
            <dd class="detail-value">No default matches this job title, so candidates will see nothing.</dd>
        </div>
        <?php endif; ?>
    </dl>

    <div class="form-actions">
        <?php if ($grooming['type'] !== 'none'): ?>
        <a class="btn-plain-border" href="<?= e($grooming['url']) ?>" target="_blank" rel="noopener noreferrer">Open grooming</a>
        <?php endif; ?>
        <?php if (!$isLocked): ?>
        <a class="btn-plain-border" href="company-job-create.php?id=<?= (int) $jobId ?>&edit=grooming">Edit grooming</a>
        <?php endif; ?>
    </div>

    <?php endif; ?>

</div>


<!-- Assessment, built on its own page -->
<div class="form-card<?= $hasDetails ? '' : ' card-locked' ?>" id="assessment-card">

    <div class="card-head">
        <h2 class="section-heading">5. Assessment</h2>
        <?php if ($questionCount > 0): ?>
        <span class="saved-tag"><?= $questionCount ?> question<?= $questionCount === 1 ? '' : 's' ?></span>
        <?php endif; ?>
    </div>

    <?php if (!$hasDetails): ?>

    <p class="field-help section-help">Save the job details first.</p>

    <?php else: ?>

    <p class="field-help section-help">
        <?php if ($questionCount === 0): ?>
        No questions yet. A job cannot be published without at least one.
        <?php else: ?>
        Candidates answer these to qualify. Questions lock once the first candidate submits.
        <?php endif; ?>
    </p>

    <div class="form-actions">
        <a class="<?= $questionCount === 0 ? 'btn-primary' : 'btn-plain-border' ?>"
           href="company-quiz-builder.php?job=<?= (int) $jobId ?>">
            <?= $questionCount === 0 ? 'Add questions' : 'Edit questions' ?>
        </a>
        <a class="btn-plain" href="company-jobs.php">Back to jobs</a>
    </div>

    <?php endif; ?>

</div>

<script src="../js/skill-picker.js"></script>
<script src="../js/grooming.js"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>