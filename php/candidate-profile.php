<?php
// The candidate's own profile. This is what gets forwarded to a
// company once they qualify, so it needs to carry more than a name.
//
// Every section has two modes:
//
//   view  - the section has saved data. It shows what is stored,
//           with Edit and Delete.
//   edit  - the form. Shown when the section is empty, when
//           ?edit=<section> is in the URL, or when a save failed.
//
// That split matters: each section posts separately, so a section
// still showing a form is a section that has NOT been saved. Filling
// every box on the page and pressing one Save button only saves that
// one section.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/helpers.php';

require_role('candidate');

const CV_MAX_BYTES = 2 * 1024 * 1024;
const CV_DIR       = __DIR__ . '/../uploads/resumes/';

$candidateId = current_user_id();
$errors      = [];

// Which section the form is open on. A failed save forces its own
// section back open so nothing typed is lost.
$editing = $_GET['edit'] ?? '';


// ===============================================================
// POST
// ===============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $section = $_POST['section'] ?? '';
    $action  = $_POST['action'] ?? 'save';

    // -----------------------------------------------------------
    // Deletes. Each one clears its own section and nothing else.
    // -----------------------------------------------------------
    if ($action === 'delete') {

        if ($section === 'basics') {
            // full_name is required and cannot be cleared, so this
            // only wipes the optional details.
            $pdo->prepare("
                UPDATE candidate_profiles SET
                    headline = NULL, phone = NULL, location = NULL,
                    expected_title = NULL, summary = NULL
                WHERE user_id = :id
            ")->execute([':id' => $candidateId]);

            flash('Details cleared. Your name was kept because it is required.');

        } elseif ($section === 'skills') {
            $pdo->prepare("DELETE FROM candidate_skills WHERE candidate_id = :id")
                ->execute([':id' => $candidateId]);
            flash('Skills cleared.');

        } elseif ($section === 'education') {
            $pdo->prepare("DELETE FROM candidate_education WHERE candidate_id = :id")
                ->execute([':id' => $candidateId]);
            flash('Education cleared.');

        } elseif ($section === 'experience') {
            $pdo->prepare("DELETE FROM candidate_experience WHERE candidate_id = :id")
                ->execute([':id' => $candidateId]);
            flash('Experience cleared.');

        } elseif ($section === 'cv') {
            $stmt = $pdo->prepare("SELECT resume_path FROM candidate_profiles WHERE user_id = :id");
            $stmt->execute([':id' => $candidateId]);
            $old = $stmt->fetchColumn();

            $pdo->prepare("
                UPDATE candidate_profiles
                SET resume_path = NULL, resume_name = NULL
                WHERE user_id = :id
            ")->execute([':id' => $candidateId]);

            if ($old && is_file(__DIR__ . '/../' . $old)) {
                @unlink(__DIR__ . '/../' . $old);
            }
            flash('CV removed.');
        }

        redirect('candidate-profile.php');
    }

    // -----------------------------------------------------------
    // Remove one skill from the saved list.
    // -----------------------------------------------------------
    if ($action === 'remove_skill') {

        $skillId = (int) ($_POST['skill_id'] ?? 0);

        $pdo->prepare("
            DELETE FROM candidate_skills
            WHERE candidate_id = :id AND skill_id = :skill
        ")->execute([':id' => $candidateId, ':skill' => $skillId]);

        flash('Skill removed.');
        redirect('candidate-profile.php');
    }

    // -----------------------------------------------------------
    if ($section === 'basics') {

        $fullName      = trim($_POST['full_name'] ?? '');
        $headline      = trim($_POST['headline'] ?? '');
        $phone         = trim($_POST['phone'] ?? '');
        $location      = trim($_POST['location'] ?? '');
        $expectedTitle = trim($_POST['expected_title'] ?? '');
        $summary       = trim($_POST['summary'] ?? '');

        if ($fullName === '') {
            $errors[] = 'Enter your full name.';
        }
        if (text_length($summary) > 2000) {
            $errors[] = 'Keep the summary under 2000 characters.';
        }

        if (!$errors) {
            $pdo->prepare("
                UPDATE candidate_profiles SET
                    full_name = :name, headline = :head, phone = :phone,
                    location = :loc, expected_title = :title, summary = :summary
                WHERE user_id = :id
            ")->execute([
                ':name'  => $fullName,
                ':head'  => $headline ?: null,
                ':phone' => $phone ?: null,
                ':loc'   => $location ?: null,
                ':title' => $expectedTitle ?: null,
                ':summary' => $summary ?: null,
                ':id'    => $candidateId,
            ]);

            flash('Details saved.');
            redirect('candidate-profile.php');
        }

        $editing = 'basics';

    // -----------------------------------------------------------
    } elseif ($section === 'skills') {

        $skillIds = array_map('intval', $_POST['skills'] ?? []);

        if (!$skillIds) {
            $errors[] = 'Select at least one skill.';
        }

        if (!$errors) {
            $pdo->beginTransaction();

            $pdo->prepare("DELETE FROM candidate_skills WHERE candidate_id = :id")
                ->execute([':id' => $candidateId]);

            $insert = $pdo->prepare("
                INSERT INTO candidate_skills (candidate_id, skill_id)
                VALUES (:id, :skill)
            ");
            foreach (array_unique($skillIds) as $skillId) {
                $insert->execute([':id' => $candidateId, ':skill' => $skillId]);
            }

            $pdo->commit();

            flash('Skills saved.');
            redirect('candidate-profile.php');
        }

        $editing = 'skills';

    // -----------------------------------------------------------
    } elseif ($section === 'education') {

        $rows = [];
        foreach ($_POST['education'] ?? [] as $row) {
            $institution = trim($row['institution'] ?? '');
            $degree      = trim($row['degree'] ?? '');
            $startYear   = trim($row['start_year'] ?? '');
            $endYear     = trim($row['end_year'] ?? '');

            if ($institution === '' && $degree === '' && $startYear === '' && $endYear === '') {
                continue;
            }

            $rows[] = [
                'institution' => $institution,
                'degree'      => $degree,
                'start_year'  => $startYear === '' ? null : (int) $startYear,
                'end_year'    => $endYear === '' ? null : (int) $endYear,
            ];
        }

        if (!$rows) {
            $errors[] = 'Add at least one education entry, or press Delete to clear the section.';
        }

        foreach ($rows as $i => $row) {
            $n = $i + 1;
            if ($row['institution'] === '') {
                $errors[] = "Education $n: enter the institution.";
            }
            if ($row['degree'] === '') {
                $errors[] = "Education $n: enter the degree.";
            }
            if ($row['start_year'] !== null && ($row['start_year'] < 1950 || $row['start_year'] > 2100)) {
                $errors[] = "Education $n: start year looks wrong.";
            }
            if ($row['end_year'] !== null && ($row['end_year'] < 1950 || $row['end_year'] > 2100)) {
                $errors[] = "Education $n: end year looks wrong.";
            }
            if ($row['start_year'] !== null && $row['end_year'] !== null
                && $row['end_year'] < $row['start_year']) {
                $errors[] = "Education $n: end year is before the start year.";
            }
        }

        if (!$errors) {
            $pdo->beginTransaction();

            $pdo->prepare("DELETE FROM candidate_education WHERE candidate_id = :id")
                ->execute([':id' => $candidateId]);

            $insert = $pdo->prepare("
                INSERT INTO candidate_education
                    (candidate_id, institution, degree, start_year, end_year)
                VALUES (:id, :inst, :deg, :start, :end)
            ");
            foreach ($rows as $row) {
                $insert->execute([
                    ':id'    => $candidateId,
                    ':inst'  => $row['institution'],
                    ':deg'   => $row['degree'],
                    ':start' => $row['start_year'],
                    ':end'   => $row['end_year'],
                ]);
            }

            $pdo->commit();

            flash('Education saved.');
            redirect('candidate-profile.php');
        }

        $editing = 'education';

    // -----------------------------------------------------------
    } elseif ($section === 'experience') {

        $rows = [];
        foreach ($_POST['experience'] ?? [] as $row) {
            $company     = trim($row['company'] ?? '');
            $jobTitle    = trim($row['job_title'] ?? '');
            $startDate   = trim($row['start_date'] ?? '');
            $endDate     = trim($row['end_date'] ?? '');
            $description = trim($row['description'] ?? '');

            if ($company === '' && $jobTitle === '' && $startDate === ''
                && $endDate === '' && $description === '') {
                continue;
            }

            $rows[] = [
                'company'     => $company,
                'job_title'   => $jobTitle,
                'start_date'  => $startDate === '' ? null : $startDate,
                'end_date'    => $endDate === '' ? null : $endDate,
                'description' => $description,
            ];
        }

        if (!$rows) {
            $errors[] = 'Fill in an experience entry, or press Delete to clear the section.';
        }

        foreach ($rows as $i => $row) {
            $n = $i + 1;
            if ($row['company'] === '') {
                $errors[] = "Experience $n: enter the company.";
            }
            if ($row['job_title'] === '') {
                $errors[] = "Experience $n: enter the job title.";
            }
            if ($row['start_date'] !== null && !strtotime($row['start_date'])) {
                $errors[] = "Experience $n: start date is not a valid date.";
            }
            if ($row['end_date'] !== null && !strtotime($row['end_date'])) {
                $errors[] = "Experience $n: end date is not a valid date.";
            }
            if ($row['start_date'] !== null && $row['end_date'] !== null
                && strtotime($row['end_date']) < strtotime($row['start_date'])) {
                $errors[] = "Experience $n: end date is before the start date.";
            }
        }

        if (!$errors) {
            $pdo->beginTransaction();

            $pdo->prepare("DELETE FROM candidate_experience WHERE candidate_id = :id")
                ->execute([':id' => $candidateId]);

            $insert = $pdo->prepare("
                INSERT INTO candidate_experience
                    (candidate_id, company, job_title, start_date, end_date, description)
                VALUES (:id, :co, :title, :start, :end, :desc)
            ");
            foreach ($rows as $row) {
                $insert->execute([
                    ':id'    => $candidateId,
                    ':co'    => $row['company'],
                    ':title' => $row['job_title'],
                    ':start' => $row['start_date'],
                    ':end'   => $row['end_date'],
                    ':desc'  => $row['description'] ?: null,
                ]);
            }

            $pdo->commit();

            flash('Experience saved.');
            redirect('candidate-profile.php');
        }

        $editing = 'experience';

    // -----------------------------------------------------------
    } elseif ($section === 'photo') {

        $saved = save_image_upload($_FILES['photo'] ?? [], 'photos', 'photo', $candidateId);

        if (isset($saved['error'])) {
            $errors[] = $saved['error'];
        } else {
            $stmt = $pdo->prepare("SELECT photo_path FROM candidate_profiles WHERE user_id = :id");
            $stmt->execute([':id' => $candidateId]);
            $old = $stmt->fetchColumn();

            $pdo->prepare("
                UPDATE candidate_profiles SET photo_path = :path, photo_name = :name
                WHERE user_id = :id
            ")->execute([':path' => $saved['path'], ':name' => $saved['name'], ':id' => $candidateId]);

            if ($old !== $saved['path']) {
                delete_upload($old);
            }

            flash('Photo updated.');
            redirect('candidate-profile.php');
        }

        $editing = 'photo';

    // -----------------------------------------------------------
    } elseif ($section === 'project') {

        $projectId   = (int) ($_POST['project_id'] ?? 0);
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $tech        = trim($_POST['technologies'] ?? '');
        $link        = trim($_POST['project_url'] ?? '');
        $video       = trim($_POST['video_url'] ?? '');

        if ($title === '') {
            $errors[] = 'Enter a project title.';
        }
        if ($link !== '' && !filter_var($link, FILTER_VALIDATE_URL)) {
            $errors[] = 'The project link must be a full address, starting with http:// or https://';
        }
        if ($video !== '' && !is_youtube_url($video)) {
            $errors[] = 'The demo video must be a YouTube link.';
        }
        if (text_length($description) > 2000) {
            $errors[] = 'Keep the project description under 2000 characters.';
        }

        $imagePath = null;
        if (!empty($_FILES['image']['name'])) {
            $saved = save_image_upload($_FILES['image'], 'projects', 'project', $candidateId);
            if (isset($saved['error'])) {
                $errors[] = $saved['error'];
            } else {
                $imagePath = $saved['path'];
            }
        }

        if (!$errors) {
            if ($projectId) {
                // candidate_id in the WHERE is the authorisation.
                $stmt = $pdo->prepare("SELECT image_path FROM candidate_projects WHERE id = :id AND candidate_id = :c");
                $stmt->execute([':id' => $projectId, ':c' => $candidateId]);
                $existing = $stmt->fetch();

                if ($existing) {
                    if ($imagePath) {
                        $pdo->prepare("
                            UPDATE candidate_projects SET
                                title = :t, description = :d, technologies = :tech,
                                project_url = :url, video_url = :vid, image_path = :img
                            WHERE id = :id AND candidate_id = :c
                        ")->execute([
                            ':t' => $title, ':d' => $description ?: null,
                            ':tech' => $tech ?: null, ':url' => $link ?: null,
                            ':vid' => $video ?: null, ':img' => $imagePath,
                            ':id' => $projectId, ':c' => $candidateId,
                        ]);
                        delete_upload($existing['image_path']);
                    } else {
                        $pdo->prepare("
                            UPDATE candidate_projects SET
                                title = :t, description = :d, technologies = :tech,
                                project_url = :url, video_url = :vid
                            WHERE id = :id AND candidate_id = :c
                        ")->execute([
                            ':t' => $title, ':d' => $description ?: null,
                            ':tech' => $tech ?: null, ':url' => $link ?: null,
                            ':vid' => $video ?: null,
                            ':id' => $projectId, ':c' => $candidateId,
                        ]);
                    }
                }
                flash('Project saved.');
            } else {
                $pdo->prepare("
                    INSERT INTO candidate_projects
                        (candidate_id, title, description, technologies, project_url, video_url, image_path, position)
                    VALUES (:c, :t, :d, :tech, :url, :vid, :img,
                        (SELECT COALESCE(MAX(p.position), 0) + 1 FROM candidate_projects p WHERE p.candidate_id = :c2))
                ")->execute([
                    ':c' => $candidateId, ':t' => $title, ':d' => $description ?: null,
                    ':tech' => $tech ?: null, ':url' => $link ?: null,
                    ':vid' => $video ?: null, ':img' => $imagePath, ':c2' => $candidateId,
                ]);
                flash('Project added.');
            }

            redirect('candidate-profile.php');
        }

        $editing = 'project';

    // -----------------------------------------------------------
    } elseif ($section === 'project-delete') {

        $projectId = (int) ($_POST['project_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT image_path FROM candidate_projects WHERE id = :id AND candidate_id = :c");
        $stmt->execute([':id' => $projectId, ':c' => $candidateId]);
        $row = $stmt->fetch();

        if ($row) {
            $pdo->prepare("DELETE FROM candidate_projects WHERE id = :id AND candidate_id = :c")
                ->execute([':id' => $projectId, ':c' => $candidateId]);
            delete_upload($row['image_path']);
            flash('Project deleted.');
        }

        redirect('candidate-profile.php');

    // -----------------------------------------------------------
    } elseif ($section === 'achievement') {

        $achievementId = (int) ($_POST['achievement_id'] ?? 0);
        $title         = trim($_POST['title'] ?? '');
        $organization  = trim($_POST['organization'] ?? '');
        $description   = trim($_POST['description'] ?? '');
        $achievedOn    = trim($_POST['achieved_on'] ?? '');

        if ($title === '') {
            $errors[] = 'Enter an achievement title.';
        }
        if ($achievedOn !== '' && !strtotime($achievedOn)) {
            $errors[] = 'That date is not valid.';
        }
        if (text_length($description) > 2000) {
            $errors[] = 'Keep the achievement description under 2000 characters.';
        }

        $imagePath = null;
        if (!empty($_FILES['image']['name'])) {
            $saved = save_image_upload($_FILES['image'], 'achievements', 'cert', $candidateId);
            if (isset($saved['error'])) {
                $errors[] = $saved['error'];
            } else {
                $imagePath = $saved['path'];
            }
        }

        if (!$errors) {
            if ($achievementId) {
                $stmt = $pdo->prepare("SELECT image_path FROM candidate_achievements WHERE id = :id AND candidate_id = :c");
                $stmt->execute([':id' => $achievementId, ':c' => $candidateId]);
                $existing = $stmt->fetch();

                if ($existing) {
                    if ($imagePath) {
                        $pdo->prepare("
                            UPDATE candidate_achievements
                            SET title = :t, organization = :o, description = :d,
                                achieved_on = :on, image_path = :img
                            WHERE id = :id AND candidate_id = :c
                        ")->execute([
                            ':t' => $title, ':o' => $organization ?: null,
                            ':d' => $description ?: null,
                            ':on' => $achievedOn ?: null, ':img' => $imagePath,
                            ':id' => $achievementId, ':c' => $candidateId,
                        ]);
                        delete_upload($existing['image_path']);
                    } else {
                        $pdo->prepare("
                            UPDATE candidate_achievements
                            SET title = :t, organization = :o, description = :d, achieved_on = :on
                            WHERE id = :id AND candidate_id = :c
                        ")->execute([
                            ':t' => $title, ':o' => $organization ?: null,
                            ':d' => $description ?: null, ':on' => $achievedOn ?: null,
                            ':id' => $achievementId, ':c' => $candidateId,
                        ]);
                    }
                }
                flash('Achievement saved.');
            } else {
                $pdo->prepare("
                    INSERT INTO candidate_achievements
                        (candidate_id, title, organization, description, achieved_on, image_path, position)
                    VALUES (:c, :t, :o, :d, :on, :img,
                        (SELECT COALESCE(MAX(a.position), 0) + 1 FROM candidate_achievements a WHERE a.candidate_id = :c2))
                ")->execute([
                    ':c' => $candidateId, ':t' => $title, ':o' => $organization ?: null,
                    ':d' => $description ?: null, ':on' => $achievedOn ?: null,
                    ':img' => $imagePath, ':c2' => $candidateId,
                ]);
                flash('Achievement added.');
            }

            redirect('candidate-profile.php');
        }

        $editing = 'achievement';

    // -----------------------------------------------------------
    } elseif ($section === 'achievement-delete') {

        $achievementId = (int) ($_POST['achievement_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT image_path FROM candidate_achievements WHERE id = :id AND candidate_id = :c");
        $stmt->execute([':id' => $achievementId, ':c' => $candidateId]);
        $row = $stmt->fetch();

        if ($row) {
            $pdo->prepare("DELETE FROM candidate_achievements WHERE id = :id AND candidate_id = :c")
                ->execute([':id' => $achievementId, ':c' => $candidateId]);
            delete_upload($row['image_path']);
            flash('Achievement deleted.');
        }

        redirect('candidate-profile.php');

    // -----------------------------------------------------------
    } elseif ($section === 'cv') {

        $file = $_FILES['cv'] ?? null;

        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Choose a PDF to upload.';
        } elseif ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            $errors[] = 'That file is too large. The limit is 2 MB.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'The upload did not complete. Try again.';
        } elseif (!is_uploaded_file($file['tmp_name'])) {
            $errors[] = 'The upload could not be verified.';
        } elseif ($file['size'] > CV_MAX_BYTES) {
            $errors[] = 'That file is too large. The limit is 2 MB.';
        } else {
            $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $finfo     = new finfo(FILEINFO_MIME_TYPE);
            $mime      = $finfo->file($file['tmp_name']);

            if ($extension !== 'pdf' || $mime !== 'application/pdf') {
                $errors[] = 'Upload a PDF. Other file types are not accepted.';
            }
        }

        if (!$errors) {
            if (!is_dir(CV_DIR) && !@mkdir(CV_DIR, 0775, true)) {
                $errors[] = 'The uploads/resumes folder is missing and could not be created. '
                          . 'Create it manually and allow the web server to write to it.';
            } elseif (!is_writable(CV_DIR)) {
                $errors[] = 'The uploads/resumes folder is not writable by the web server.';
            }
        }

        if (!$errors) {
            // Never reuse the uploaded name: it is user-controlled.
            // The candidate's own name for the file is kept separately
            // so the page can show something recognisable.
            $newName  = 'cv_' . $candidateId . '_' . bin2hex(random_bytes(8)) . '.pdf';
            $original = basename($file['name']);

            if (!move_uploaded_file($file['tmp_name'], CV_DIR . $newName)) {
                $errors[] = 'The file could not be saved. Check folder permissions.';
            } else {
                $stmt = $pdo->prepare("SELECT resume_path FROM candidate_profiles WHERE user_id = :id");
                $stmt->execute([':id' => $candidateId]);
                $old = $stmt->fetchColumn();

                $pdo->prepare("
                    UPDATE candidate_profiles
                    SET resume_path = :path, resume_name = :name
                    WHERE user_id = :id
                ")->execute([
                    ':path' => 'uploads/resumes/' . $newName,
                    ':name' => $original,
                    ':id'   => $candidateId,
                ]);

                if ($old && basename($old) !== $newName && is_file(__DIR__ . '/../' . $old)) {
                    @unlink(__DIR__ . '/../' . $old);
                }

                flash('CV uploaded: ' . $original);
                redirect('candidate-profile.php');
            }
        }

        $editing = 'cv';
    }
}


// ===============================================================
// Load everything for display
// ===============================================================
$stmt = $pdo->prepare("SELECT * FROM candidate_profiles WHERE user_id = :id");
$stmt->execute([':id' => $candidateId]);
$profile = $stmt->fetch() ?: [];

$stmt = $pdo->prepare("
    SELECT s.id, s.name
    FROM candidate_skills cs
    JOIN skills s ON s.id = cs.skill_id
    WHERE cs.candidate_id = :id
    ORDER BY s.name
");
$stmt->execute([':id' => $candidateId]);
$mySkills = $stmt->fetchAll();
$mySkillIds = array_map('intval', array_column($mySkills, 'id'));

$stmt = $pdo->prepare("
    SELECT * FROM candidate_education
    WHERE candidate_id = :id ORDER BY end_year DESC, id
");
$stmt->execute([':id' => $candidateId]);
$education = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT * FROM candidate_experience
    WHERE candidate_id = :id ORDER BY start_date DESC, id
");
$stmt->execute([':id' => $candidateId]);
$experience = $stmt->fetchAll();

// A section is "filled" when it has something stored. That decides
// view mode versus the form.
$hasBasics     = !empty($profile['location']) || !empty($profile['headline'])
              || !empty($profile['phone'])    || !empty($profile['expected_title'])
              || !empty($profile['summary']);
$hasSkills     = $mySkills !== [];
$hasEducation  = $education !== [];
$hasExperience = $experience !== [];
$hasCv         = !empty($profile['resume_path']);

$showBasicsForm     = !$hasBasics     || $editing === 'basics';
$showSkillsForm     = !$hasSkills     || $editing === 'skills';
$showEducationForm  = !$hasEducation  || $editing === 'education';
$showExperienceForm = !$hasExperience || $editing === 'experience';
$showCvForm         = !$hasCv         || $editing === 'cv';

// Forms need at least one row to type into.
$educationRows = $education ?: [['institution' => '', 'degree' => '', 'start_year' => '', 'end_year' => '']];
$experienceRows = $experience ?: [['company' => '', 'job_title' => '', 'start_date' => '', 'end_date' => '', 'description' => '']];

$stmt = $pdo->prepare("SELECT * FROM candidate_projects WHERE candidate_id = :id ORDER BY position, id");
$stmt->execute([':id' => $candidateId]);
$projects = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM candidate_achievements WHERE candidate_id = :id ORDER BY achieved_on DESC, id");
$stmt->execute([':id' => $candidateId]);
$achievements = $stmt->fetchAll();

// Which single item is open for editing, if any.
$editProject     = isset($_GET['project'])     ? (int) $_GET['project']     : 0;
$editAchievement = isset($_GET['achievement']) ? (int) $_GET['achievement'] : 0;

$skills  = all_skills($pdo);
$missing = profile_missing_parts($pdo, $candidateId);

$page_title = 'My profile';
$active_nav = 'profile';
$extra_css  = ['profile.css'];
require __DIR__ . '/includes/header-candidate.php';
?>

<?php if ($missing): ?>
<p class="notice" id="completeness">
    Not ready to send to employers yet. Still needed: <?= e(implode(', ', $missing)) ?>.
</p>
<?php else: ?>
<p class="notice notice-done" id="completeness">Your profile is complete. You can apply for jobs.</p>
<?php endif; ?>

<?php if ($errors): ?>
<div class="errors" id="form-errors">
    <?php foreach ($errors as $error): ?>
    <p class="error-line"><?= e($error) ?></p>
    <?php endforeach; ?>
</div>
<?php endif; ?>


<!-- Photo -->
<div class="form-card" id="photo-card">

    <div class="card-head">
        <h2 class="section-heading">Profile photo</h2>
        <?php if (!empty($profile['photo_path'])): ?>
        <span class="saved-tag">Uploaded</span>
        <?php endif; ?>
    </div>

    <div class="photo-row">
        <?php if (!empty($profile['photo_path'])): ?>
        <img class="photo-preview" src="../<?= e($profile['photo_path']) ?>" alt="Your profile photo">
        <?php else: ?>
        <span class="photo-preview photo-blank"><?= e(strtoupper(substr($profile['full_name'] ?? '?', 0, 1))) ?></span>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="photo-form">
            <input type="hidden" name="section" value="photo">
            <label class="field-label" for="photo">JPG, PNG or WEBP, up to 2 MB</label>
            <input class="field-input" type="file" name="photo" id="photo" accept="image/jpeg,image/png,image/webp">
            <div class="form-actions">
                <button class="btn-primary" type="submit"><?= !empty($profile['photo_path']) ? 'Replace photo' : 'Upload photo' ?></button>
            </div>
        </form>
    </div>

</div>


<!-- Details -->
<div class="form-card" id="basics-card">

    <div class="card-head">
        <h2 class="section-heading">Your details</h2>
        <?php if ($hasBasics && !$showBasicsForm): ?>
        <span class="saved-tag">Saved</span>
        <?php endif; ?>
    </div>

    <?php if ($showBasicsForm): ?>

    <form method="post" id="basics-form">
        <input type="hidden" name="section" value="basics">

        <div class="field">
            <label class="field-label" for="full_name">Full name</label>
            <input class="field-input" type="text" name="full_name" id="full_name"
                   value="<?= e($profile['full_name'] ?? '') ?>" maxlength="255" required>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="field-label" for="headline">Headline</label>
                <input class="field-input" type="text" name="headline" id="headline"
                       value="<?= e($profile['headline'] ?? '') ?>" maxlength="255"
                       placeholder="Final-year CSE student">
            </div>

            <div class="field">
                <label class="field-label" for="expected_title">Role you want</label>
                <input class="field-input" type="text" name="expected_title" id="expected_title"
                       value="<?= e($profile['expected_title'] ?? '') ?>" maxlength="255"
                       placeholder="Junior backend developer">
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="field-label" for="phone">Phone</label>
                <input class="field-input" type="text" name="phone" id="phone"
                       value="<?= e($profile['phone'] ?? '') ?>" maxlength="30">
            </div>

            <div class="field">
                <label class="field-label" for="location">Location</label>
                <input class="field-input" type="text" name="location" id="location"
                       value="<?= e($profile['location'] ?? '') ?>" maxlength="255"
                       placeholder="Dhaka, Bangladesh">
            </div>
        </div>

        <div class="field">
            <label class="field-label" for="summary">About you</label>
            <textarea class="field-input field-area" name="summary" id="summary"
                      rows="4" maxlength="2000"><?= e($profile['summary'] ?? '') ?></textarea>
        </div>

        <div class="form-actions">
            <button class="btn-primary" type="submit">Save details</button>
            <?php if ($hasBasics): ?>
            <a class="btn-plain" href="candidate-profile.php">Cancel</a>
            <?php endif; ?>
        </div>
    </form>

    <?php else: ?>

    <dl class="detail-list">
        <div class="detail-pair">
            <dt class="detail-key">Name</dt>
            <dd class="detail-value"><?= e($profile['full_name']) ?></dd>
        </div>
        <?php if (!empty($profile['headline'])): ?>
        <div class="detail-pair">
            <dt class="detail-key">Headline</dt>
            <dd class="detail-value"><?= e($profile['headline']) ?></dd>
        </div>
        <?php endif; ?>
        <?php if (!empty($profile['expected_title'])): ?>
        <div class="detail-pair">
            <dt class="detail-key">Role wanted</dt>
            <dd class="detail-value"><?= e($profile['expected_title']) ?></dd>
        </div>
        <?php endif; ?>
        <?php if (!empty($profile['phone'])): ?>
        <div class="detail-pair">
            <dt class="detail-key">Phone</dt>
            <dd class="detail-value"><?= e($profile['phone']) ?></dd>
        </div>
        <?php endif; ?>
        <?php if (!empty($profile['location'])): ?>
        <div class="detail-pair">
            <dt class="detail-key">Location</dt>
            <dd class="detail-value"><?= e($profile['location']) ?></dd>
        </div>
        <?php endif; ?>
        <?php if (!empty($profile['summary'])): ?>
        <div class="detail-pair">
            <dt class="detail-key">About</dt>
            <dd class="detail-value"><?= nl2br(e($profile['summary'])) ?></dd>
        </div>
        <?php endif; ?>
    </dl>

    <div class="form-actions">
        <a class="btn-plain-border" href="candidate-profile.php?edit=basics">Edit</a>
        <form method="post" class="inline-form">
            <input type="hidden" name="section" value="basics">
            <input type="hidden" name="action" value="delete">
            <button class="link-button link-danger" type="submit">Delete</button>
        </form>
    </div>

    <?php endif; ?>

</div>


<!-- Skills -->
<div class="form-card" id="skills-card">

    <div class="card-head">
        <h2 class="section-heading">Skills</h2>
        <?php if ($hasSkills): ?>
        <span class="saved-tag">Saved</span>
        <?php endif; ?>
    </div>

    <p class="field-help section-help">
        Jobs list the skills they need, and grooming videos are matched from the same list.
        Add them one at a time.
    </p>

    <form method="post" id="skills-form">
        <input type="hidden" name="section" value="skills">

        <div class="skill-picker" id="skill-picker" hidden
             data-skills='<?= e(json_encode(array_map(fn($s) => ["id" => (int) $s["id"], "name" => $s["name"]], $skills), JSON_UNESCAPED_UNICODE)) ?>'
             data-selected='<?= e(json_encode($mySkillIds)) ?>'>

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
            <label class="field-label" for="skills">Choose your skills</label>
            <select class="field-input field-multi" name="skills[]" id="skills" multiple size="10">
                <?php foreach ($skills as $skill): ?>
                <option value="<?= (int) $skill['id'] ?>"<?= in_array((int) $skill['id'], $mySkillIds, true) ? ' selected' : '' ?>>
                    <?= e($skill['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-actions">
            <button class="btn-primary" type="submit">Save skills</button>
            <?php if ($hasSkills): ?>
            <button class="link-button link-danger" type="submit" name="action" value="delete">Delete all</button>
            <?php endif; ?>
        </div>
    </form>

</div>


<!-- Education -->
<div class="form-card" id="education-card">

    <div class="card-head">
        <h2 class="section-heading">Education</h2>
        <?php if ($hasEducation && !$showEducationForm): ?>
        <span class="saved-tag">Saved</span>
        <?php endif; ?>
    </div>

    <?php if ($showEducationForm): ?>

    <form method="post" id="education-form">
        <input type="hidden" name="section" value="education">

        <div class="row-list" id="education-list" data-prefix="education">
            <?php foreach ($educationRows as $i => $row): ?>
            <div class="repeat-row">
                <div class="repeat-head">
                    <span class="repeat-number">Entry <?= $i + 1 ?></span>
                    <button class="link-button remove-row" type="button">Remove</button>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label class="field-label">Institution</label>
                        <input class="field-input" type="text" name="education[<?= $i ?>][institution]"
                               value="<?= e($row['institution']) ?>" maxlength="255">
                    </div>

                    <div class="field">
                        <label class="field-label">Degree</label>
                        <input class="field-input" type="text" name="education[<?= $i ?>][degree]"
                               value="<?= e($row['degree']) ?>" maxlength="255">
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label class="field-label">From (year)</label>
                        <input class="field-input" type="number" name="education[<?= $i ?>][start_year]"
                               value="<?= e((string) $row['start_year']) ?>" min="1950" max="2100">
                    </div>

                    <div class="field">
                        <label class="field-label">To (year)</label>
                        <input class="field-input" type="number" name="education[<?= $i ?>][end_year]"
                               value="<?= e((string) $row['end_year']) ?>" min="1950" max="2100">
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="form-actions">
            <button class="btn-plain-border add-row" type="button" data-target="education-list">Add entry</button>
            <button class="btn-primary" type="submit">Save education</button>
            <?php if ($hasEducation): ?>
            <a class="btn-plain" href="candidate-profile.php">Cancel</a>
            <?php endif; ?>
        </div>
    </form>

    <?php else: ?>

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

    <div class="form-actions">
        <a class="btn-plain-border" href="candidate-profile.php?edit=education">Edit</a>
        <form method="post" class="inline-form">
            <input type="hidden" name="section" value="education">
            <input type="hidden" name="action" value="delete">
            <button class="link-button link-danger" type="submit">Delete all</button>
        </form>
    </div>

    <?php endif; ?>

</div>


<!-- Experience -->
<div class="form-card" id="experience-card">

    <div class="card-head">
        <h2 class="section-heading">Experience</h2>
        <?php if ($hasExperience && !$showExperienceForm): ?>
        <span class="saved-tag">Saved</span>
        <?php endif; ?>
    </div>

    <?php if ($showExperienceForm): ?>

    <p class="field-help section-help">Optional. Leave it empty if you have none yet.</p>

    <form method="post" id="experience-form">
        <input type="hidden" name="section" value="experience">

        <div class="row-list" id="experience-list" data-prefix="experience">
            <?php foreach ($experienceRows as $i => $row): ?>
            <div class="repeat-row">
                <div class="repeat-head">
                    <span class="repeat-number">Entry <?= $i + 1 ?></span>
                    <button class="link-button remove-row" type="button">Remove</button>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label class="field-label">Company</label>
                        <input class="field-input" type="text" name="experience[<?= $i ?>][company]"
                               value="<?= e($row['company']) ?>" maxlength="255">
                    </div>

                    <div class="field">
                        <label class="field-label">Job title</label>
                        <input class="field-input" type="text" name="experience[<?= $i ?>][job_title]"
                               value="<?= e($row['job_title']) ?>" maxlength="255">
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label class="field-label">From</label>
                        <input class="field-input" type="date" name="experience[<?= $i ?>][start_date]"
                               value="<?= e((string) $row['start_date']) ?>">
                    </div>

                    <div class="field">
                        <label class="field-label">To</label>
                        <input class="field-input" type="date" name="experience[<?= $i ?>][end_date]"
                               value="<?= e((string) $row['end_date']) ?>">
                    </div>
                </div>

                <div class="field">
                    <label class="field-label">What you did</label>
                    <textarea class="field-input field-area" name="experience[<?= $i ?>][description]"
                              rows="3"><?= e((string) $row['description']) ?></textarea>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="form-actions">
            <button class="btn-plain-border add-row" type="button" data-target="experience-list">Add entry</button>
            <button class="btn-primary" type="submit">Save experience</button>
            <?php if ($hasExperience): ?>
            <a class="btn-plain" href="candidate-profile.php">Cancel</a>
            <?php endif; ?>
        </div>
    </form>

    <?php else: ?>

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

    <div class="form-actions">
        <a class="btn-plain-border" href="candidate-profile.php?edit=experience">Edit</a>
        <form method="post" class="inline-form">
            <input type="hidden" name="section" value="experience">
            <input type="hidden" name="action" value="delete">
            <button class="link-button link-danger" type="submit">Delete all</button>
        </form>
    </div>

    <?php endif; ?>

</div>


<!-- Projects -->
<div class="form-card" id="projects-card">

    <div class="card-head">
        <h2 class="section-heading">Projects</h2>
        <?php if ($projects): ?>
        <span class="saved-tag"><?= count($projects) ?> saved</span>
        <?php endif; ?>
    </div>

    <?php foreach ($projects as $project): ?>
        <?php if ($editProject === (int) $project['id']): ?>

        <form method="post" enctype="multipart/form-data" class="repeat-row">
            <input type="hidden" name="section" value="project">
            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">

            <div class="field">
                <label class="field-label">Project title</label>
                <input class="field-input" type="text" name="title" value="<?= e($project['title']) ?>" maxlength="255" required>
            </div>

            <div class="field">
                <label class="field-label">What it does</label>
                <textarea class="field-input field-area" name="description" rows="3" maxlength="2000"><?= e((string) $project['description']) ?></textarea>
            </div>

            <div class="field">
                <label class="field-label">Technologies used</label>
                <input class="field-input" type="text" name="technologies" value="<?= e((string) $project['technologies']) ?>" maxlength="500" placeholder="PHP, MySQL, JavaScript">
            </div>

            <div class="field-row">
                <div class="field">
                    <label class="field-label">Project or GitHub link</label>
                    <input class="field-input" type="url" name="project_url" value="<?= e((string) $project['project_url']) ?>" maxlength="500">
                </div>
                <div class="field">
                    <label class="field-label">Demo video (YouTube)</label>
                    <input class="field-input" type="url" name="video_url" value="<?= e((string) $project['video_url']) ?>" maxlength="500">
                </div>
            </div>

            <div class="field">
                <label class="field-label">Screenshot</label>
                <input class="field-input" type="file" name="image" accept="image/jpeg,image/png,image/webp">
                <p class="field-help">Leave empty to keep the current image.</p>
            </div>

            <div class="form-actions">
                <button class="btn-primary" type="submit">Save project</button>
                <a class="btn-plain" href="candidate-profile.php">Cancel</a>
            </div>
        </form>

        <?php else: ?>

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

            <div class="item-actions">
                <a class="link-action" href="candidate-profile.php?project=<?= (int) $project['id'] ?>#projects-card">Edit</a>
                <form method="post" class="inline-form">
                    <input type="hidden" name="section" value="project-delete">
                    <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">
                    <button class="link-button link-danger" type="submit">Delete</button>
                </form>
            </div>
        </div>

        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($editProject === 0): ?>
    <details class="add-block"<?= $projects ? '' : ' open' ?>>
        <summary class="add-summary">Add a project</summary>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="section" value="project">

            <div class="field">
                <label class="field-label" for="new-project-title">Project title</label>
                <input class="field-input" type="text" name="title" id="new-project-title" maxlength="255" required>
            </div>

            <div class="field">
                <label class="field-label">What it does</label>
                <textarea class="field-input field-area" name="description" rows="3" maxlength="2000"></textarea>
            </div>

            <div class="field">
                <label class="field-label">Technologies used</label>
                <input class="field-input" type="text" name="technologies" maxlength="500" placeholder="PHP, MySQL, JavaScript">
            </div>

            <div class="field-row">
                <div class="field">
                    <label class="field-label">Project or GitHub link</label>
                    <input class="field-input" type="url" name="project_url" maxlength="500" placeholder="https://github.com/...">
                </div>
                <div class="field">
                    <label class="field-label">Demo video (YouTube)</label>
                    <input class="field-input" type="url" name="video_url" maxlength="500" placeholder="https://youtu.be/...">
                </div>
            </div>

            <div class="field">
                <label class="field-label">Screenshot</label>
                <input class="field-input" type="file" name="image" accept="image/jpeg,image/png,image/webp">
            </div>

            <div class="form-actions">
                <button class="btn-primary" type="submit">Add project</button>
            </div>
        </form>
    </details>
    <?php endif; ?>

</div>


<!-- Achievements -->
<div class="form-card" id="achievements-card">

    <div class="card-head">
        <h2 class="section-heading">Achievements</h2>
        <?php if ($achievements): ?>
        <span class="saved-tag"><?= count($achievements) ?> saved</span>
        <?php endif; ?>
    </div>

    <p class="field-help section-help">Awards, competitions, certifications, scholarships, publications.</p>

    <?php foreach ($achievements as $achievement): ?>
        <?php if ($editAchievement === (int) $achievement['id']): ?>

        <form method="post" enctype="multipart/form-data" class="repeat-row">
            <input type="hidden" name="section" value="achievement">
            <input type="hidden" name="achievement_id" value="<?= (int) $achievement['id'] ?>">

            <div class="field">
                <label class="field-label">Title</label>
                <input class="field-input" type="text" name="title" value="<?= e($achievement['title']) ?>" maxlength="255" required>
            </div>

            <div class="field-row">
                <div class="field">
                    <label class="field-label">Organisation or event</label>
                    <input class="field-input" type="text" name="organization" value="<?= e((string) $achievement['organization']) ?>" maxlength="255">
                </div>
                <div class="field">
                    <label class="field-label">Date</label>
                    <input class="field-input" type="date" name="achieved_on" value="<?= e((string) $achievement['achieved_on']) ?>">
                </div>
            </div>

            <div class="field">
                <label class="field-label">Details</label>
                <textarea class="field-input field-area" name="description" rows="3" maxlength="2000"><?= e((string) $achievement['description']) ?></textarea>
            </div>

            <div class="field">
                <label class="field-label">Certificate image</label>
                <input class="field-input" type="file" name="image" accept="image/jpeg,image/png,image/webp">
                <p class="field-help">Leave empty to keep the current image.</p>
            </div>

            <div class="form-actions">
                <button class="btn-primary" type="submit">Save achievement</button>
                <a class="btn-plain" href="candidate-profile.php">Cancel</a>
            </div>
        </form>

        <?php else: ?>

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

            <div class="item-actions">
                <a class="link-action" href="candidate-profile.php?achievement=<?= (int) $achievement['id'] ?>#achievements-card">Edit</a>
                <form method="post" class="inline-form">
                    <input type="hidden" name="section" value="achievement-delete">
                    <input type="hidden" name="achievement_id" value="<?= (int) $achievement['id'] ?>">
                    <button class="link-button link-danger" type="submit">Delete</button>
                </form>
            </div>
        </div>

        <?php endif; ?>
    <?php endforeach; ?>

    <?php if ($editAchievement === 0): ?>
    <details class="add-block"<?= $achievements ? '' : ' open' ?>>
        <summary class="add-summary">Add an achievement</summary>

        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="section" value="achievement">

            <div class="field">
                <label class="field-label" for="new-achievement-title">Title</label>
                <input class="field-input" type="text" name="title" id="new-achievement-title" maxlength="255" required>
            </div>

            <div class="field-row">
                <div class="field">
                    <label class="field-label">Organisation or event</label>
                    <input class="field-input" type="text" name="organization" maxlength="255">
                </div>
                <div class="field">
                    <label class="field-label">Date</label>
                    <input class="field-input" type="date" name="achieved_on">
                </div>
            </div>

            <div class="field">
                <label class="field-label">Details</label>
                <textarea class="field-input field-area" name="description" rows="3" maxlength="2000"></textarea>
            </div>

            <div class="field">
                <label class="field-label">Certificate image</label>
                <input class="field-input" type="file" name="image" accept="image/jpeg,image/png,image/webp">
            </div>

            <div class="form-actions">
                <button class="btn-primary" type="submit">Add achievement</button>
            </div>
        </form>
    </details>
    <?php endif; ?>

</div>


<!-- CV -->
<div class="form-card" id="cv-card">

    <div class="card-head">
        <h2 class="section-heading">CV</h2>
        <?php if ($hasCv && !$showCvForm): ?>
        <span class="saved-tag">Uploaded</span>
        <?php endif; ?>
    </div>

    <?php if ($showCvForm): ?>

    <p class="field-help section-help">Optional. PDF only, up to 2 MB.</p>

    <form method="post" id="cv-form" enctype="multipart/form-data">
        <input type="hidden" name="section" value="cv">

        <div class="field">
            <label class="field-label" for="cv">Choose a PDF</label>
            <input class="field-input" type="file" name="cv" id="cv" accept="application/pdf">
        </div>

        <div class="form-actions">
            <button class="btn-primary" type="submit">Upload CV</button>
            <?php if ($hasCv): ?>
            <a class="btn-plain" href="candidate-profile.php">Cancel</a>
            <?php endif; ?>
        </div>
    </form>

    <?php else: ?>

    <p class="file-line" id="cv-name">
        <a class="link-action" href="../<?= e($profile['resume_path']) ?>" target="_blank"><?= e($profile['resume_name'] ?: basename($profile['resume_path'])) ?></a>
    </p>

    <div class="form-actions">
        <a class="btn-plain-border" href="candidate-profile.php?edit=cv">Replace</a>
        <form method="post" class="inline-form">
            <input type="hidden" name="section" value="cv">
            <input type="hidden" name="action" value="delete">
            <button class="link-button link-danger" type="submit">Delete</button>
        </form>
    </div>

    <?php endif; ?>

</div>

<script src="../js/profile-rows.js"></script>
<script src="../js/skill-picker.js"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>