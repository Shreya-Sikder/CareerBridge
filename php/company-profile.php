<?php
// The company's own profile. This is what a candidate sees when
// deciding whether to apply, so it needs more than a name.
//
// Two blocks, each posting separately, same pattern as the candidate
// profile and the job form:
//
//   details  name, industry, size, location, founded, website,
//            contact email, about
//   logo     image upload
//
// A block still showing a form is a block that has not been saved.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/helpers.php';

require_role('company');

const LOGO_MAX_BYTES = 1024 * 1024;
const LOGO_DIR       = __DIR__ . '/../uploads/logos/';

$companyId = current_user_id();
$errors    = [];
$editing   = $_GET['edit'] ?? '';

$SIZES = ['1-10', '11-50', '51-200', '201-1000', '1000+'];


// ===============================================================
// POST
// ===============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $section = $_POST['section'] ?? '';
    $action  = $_POST['action'] ?? 'save';

    // -----------------------------------------------------------
    if ($action === 'delete') {

        if ($section === 'details') {
            // company_name is NOT NULL and is what every job listing
            // shows, so it is kept.
            $pdo->prepare("
                UPDATE company_profiles SET
                    industry = NULL, company_size = NULL, location = NULL,
                    founded_year = NULL, website = NULL,
                    contact_email = NULL, about = NULL
                WHERE user_id = :id
            ")->execute([':id' => $companyId]);

            flash('Details cleared. Your company name was kept because it is required.');

        } elseif ($section === 'logo') {
            $stmt = $pdo->prepare("SELECT logo_path FROM company_profiles WHERE user_id = :id");
            $stmt->execute([':id' => $companyId]);
            $old = $stmt->fetchColumn();

            $pdo->prepare("
                UPDATE company_profiles SET logo_path = NULL, logo_name = NULL
                WHERE user_id = :id
            ")->execute([':id' => $companyId]);

            if ($old && is_file(__DIR__ . '/../' . $old)) {
                @unlink(__DIR__ . '/../' . $old);
            }
            flash('Logo removed.');
        }

        redirect('company-profile.php');
    }

    // -----------------------------------------------------------
    if ($section === 'details') {

        $companyName  = trim($_POST['company_name'] ?? '');
        $industry     = trim($_POST['industry'] ?? '');
        $size         = $_POST['company_size'] ?? '';
        $location     = trim($_POST['location'] ?? '');
        $foundedYear  = trim($_POST['founded_year'] ?? '');
        $website      = trim($_POST['website'] ?? '');
        $contactEmail = trim($_POST['contact_email'] ?? '');
        $about        = trim($_POST['about'] ?? '');

        if ($companyName === '') {
            $errors[] = 'Enter your company name.';
        }
        if ($size !== '' && !in_array($size, $SIZES, true)) {
            $errors[] = 'Choose a valid company size.';
        }
        if ($foundedYear !== '') {
            $year = (int) $foundedYear;
            if ($year < 1800 || $year > (int) date('Y')) {
                $errors[] = 'Founded year must be between 1800 and ' . date('Y') . '.';
            }
        }
        if ($website !== '' && !filter_var($website, FILTER_VALIDATE_URL)) {
            $errors[] = 'Website must be a full address, starting with http:// or https://';
        }
        if ($contactEmail !== '' && !filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Contact email is not a valid address.';
        }
        if (text_length($about) > 2000) {
            $errors[] = 'Keep the description under 2000 characters.';
        }

        if (!$errors) {
            $pdo->prepare("
                UPDATE company_profiles SET
                    company_name = :name, industry = :ind, company_size = :size,
                    location = :loc, founded_year = :year, website = :web,
                    contact_email = :email, about = :about
                WHERE user_id = :id
            ")->execute([
                ':name'  => $companyName,
                ':ind'   => $industry ?: null,
                ':size'  => $size ?: null,
                ':loc'   => $location ?: null,
                ':year'  => $foundedYear === '' ? null : (int) $foundedYear,
                ':web'   => $website ?: null,
                ':email' => $contactEmail ?: null,
                ':about' => $about ?: null,
                ':id'    => $companyId,
            ]);

            flash('Company details saved.');
            redirect('company-profile.php');
        }

        $editing = 'details';

    // -----------------------------------------------------------
    } elseif ($section === 'logo') {

        $file = $_FILES['logo'] ?? null;

        if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
            $errors[] = 'Choose an image to upload.';
        } elseif ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            $errors[] = 'That image is too large. The limit is 1 MB.';
        } elseif ($file['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'The upload did not complete. Try again.';
        } elseif (!is_uploaded_file($file['tmp_name'])) {
            $errors[] = 'The upload could not be verified.';
        } elseif ($file['size'] > LOGO_MAX_BYTES) {
            $errors[] = 'That image is too large. The limit is 1 MB.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime  = $finfo->file($file['tmp_name']);

            // SVG is deliberately not accepted: it can carry script and
            // would be served from this site's own origin.
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];

            if (!isset($allowed[$mime])) {
                $errors[] = 'Upload a JPG or PNG. Other image types are not accepted.';
            } else {
                $extension = $allowed[$mime];

                // Confirm it really is an image, not a file with image
                // bytes glued to the front.
                $size = @getimagesize($file['tmp_name']);
                if ($size === false) {
                    $errors[] = 'That file is not a readable image.';
                }
            }
        }

        if (!$errors) {
            if (!is_dir(LOGO_DIR) && !@mkdir(LOGO_DIR, 0775, true)) {
                $errors[] = 'The uploads/logos folder is missing and could not be created. '
                          . 'Create it manually and allow the web server to write to it.';
            } elseif (!is_writable(LOGO_DIR)) {
                $errors[] = 'The uploads/logos folder is not writable by the web server.';
            }
        }

        if (!$errors) {
            $newName  = 'logo_' . $companyId . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
            $original = basename($file['name']);

            if (!move_uploaded_file($file['tmp_name'], LOGO_DIR . $newName)) {
                $errors[] = 'The image could not be saved. Check folder permissions.';
            } else {
                $stmt = $pdo->prepare("SELECT logo_path FROM company_profiles WHERE user_id = :id");
                $stmt->execute([':id' => $companyId]);
                $old = $stmt->fetchColumn();

                $pdo->prepare("
                    UPDATE company_profiles SET logo_path = :path, logo_name = :name
                    WHERE user_id = :id
                ")->execute([
                    ':path' => 'uploads/logos/' . $newName,
                    ':name' => $original,
                    ':id'   => $companyId,
                ]);

                if ($old && basename($old) !== $newName && is_file(__DIR__ . '/../' . $old)) {
                    @unlink(__DIR__ . '/../' . $old);
                }

                flash('Logo uploaded.');
                redirect('company-profile.php');
            }
        }

        $editing = 'logo';
    }
}


// ===============================================================
// Display
// ===============================================================
$stmt = $pdo->prepare("SELECT * FROM company_profiles WHERE user_id = :id");
$stmt->execute([':id' => $companyId]);
$profile = $stmt->fetch() ?: [];

$hasDetails = !empty($profile['industry']) || !empty($profile['location'])
           || !empty($profile['about'])    || !empty($profile['website'])
           || !empty($profile['company_size']) || !empty($profile['contact_email'])
           || !empty($profile['founded_year']);
$hasLogo = !empty($profile['logo_path']);

$showDetailsForm = !$hasDetails || $editing === 'details';
$showLogoForm    = !$hasLogo    || $editing === 'logo';

// What a candidate still cannot see about this company.
$missing = [];
if (empty($profile['industry'])) { $missing[] = 'your industry'; }
if (empty($profile['location'])) { $missing[] = 'your location'; }
if (empty($profile['about']))    { $missing[] = 'a short description'; }

$page_title = 'Company profile';
$active_nav = 'profile';
$extra_css  = ['profile.css'];
require __DIR__ . '/includes/header-company.php';
?>

<?php if ($missing): ?>
<p class="notice" id="completeness">
    Candidates see this profile on every job you post. Still missing: <?= e(implode(', ', $missing)) ?>.
</p>
<?php else: ?>
<p class="notice notice-done" id="completeness">Your profile is complete. Candidates see it on your job listings.</p>
<?php endif; ?>

<?php if ($errors): ?>
<div class="errors" id="form-errors">
    <?php foreach ($errors as $error): ?>
    <p class="error-line"><?= e($error) ?></p>
    <?php endforeach; ?>
</div>
<?php endif; ?>


<!-- Details -->
<div class="form-card" id="details-card">

    <div class="card-head">
        <h2 class="section-heading">Company details</h2>
        <?php if ($hasDetails && !$showDetailsForm): ?>
        <span class="saved-tag">Saved</span>
        <?php endif; ?>
    </div>

    <?php if ($showDetailsForm): ?>

    <form method="post" id="details-form">
        <input type="hidden" name="section" value="details">

        <div class="field">
            <label class="field-label" for="company_name">Company name</label>
            <input class="field-input" type="text" name="company_name" id="company_name"
                   value="<?= e($profile['company_name'] ?? '') ?>" maxlength="255" required>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="field-label" for="industry">Industry</label>
                <input class="field-input" type="text" name="industry" id="industry"
                       value="<?= e($profile['industry'] ?? '') ?>" maxlength="120"
                       placeholder="Software, fintech, logistics">
            </div>

            <div class="field">
                <label class="field-label" for="company_size">Company size</label>
                <select class="field-input" name="company_size" id="company_size">
                    <option value="">Not stated</option>
                    <?php foreach ($SIZES as $size): ?>
                    <option value="<?= $size ?>"<?= ($profile['company_size'] ?? '') === $size ? ' selected' : '' ?>>
                        <?= $size ?> people
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="field-label" for="location">Location</label>
                <input class="field-input" type="text" name="location" id="location"
                       value="<?= e($profile['location'] ?? '') ?>" maxlength="255"
                       placeholder="Dhaka, Bangladesh">
            </div>

            <div class="field">
                <label class="field-label" for="founded_year">Founded</label>
                <input class="field-input" type="number" name="founded_year" id="founded_year"
                       value="<?= e((string) ($profile['founded_year'] ?? '')) ?>"
                       min="1800" max="<?= date('Y') ?>" placeholder="<?= date('Y') ?>">
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label class="field-label" for="website">Website</label>
                <input class="field-input" type="url" name="website" id="website"
                       value="<?= e($profile['website'] ?? '') ?>" maxlength="255"
                       placeholder="https://example.com">
            </div>

            <div class="field">
                <label class="field-label" for="contact_email">Contact email</label>
                <input class="field-input" type="email" name="contact_email" id="contact_email"
                       value="<?= e($profile['contact_email'] ?? '') ?>" maxlength="255"
                       placeholder="careers@example.com">
                <p class="field-help">Shown to candidates. Separate from your login email.</p>
            </div>
        </div>

        <div class="field">
            <label class="field-label" for="about">About the company</label>
            <textarea class="field-input field-area" name="about" id="about"
                      rows="5" maxlength="2000"
                      placeholder="What you do, and what it is like to work there."><?= e($profile['about'] ?? '') ?></textarea>
        </div>

        <div class="form-actions">
            <button class="btn-primary" type="submit">Save details</button>
            <?php if ($hasDetails): ?>
            <a class="btn-plain" href="company-profile.php">Cancel</a>
            <?php endif; ?>
        </div>
    </form>

    <?php else: ?>

    <dl class="detail-list">
        <div class="detail-pair">
            <dt class="detail-key">Name</dt>
            <dd class="detail-value"><?= e($profile['company_name']) ?></dd>
        </div>
        <?php if (!empty($profile['industry'])): ?>
        <div class="detail-pair">
            <dt class="detail-key">Industry</dt>
            <dd class="detail-value"><?= e($profile['industry']) ?></dd>
        </div>
        <?php endif; ?>
        <?php if (!empty($profile['company_size'])): ?>
        <div class="detail-pair">
            <dt class="detail-key">Size</dt>
            <dd class="detail-value"><?= e($profile['company_size']) ?> people</dd>
        </div>
        <?php endif; ?>
        <?php if (!empty($profile['location'])): ?>
        <div class="detail-pair">
            <dt class="detail-key">Location</dt>
            <dd class="detail-value"><?= e($profile['location']) ?></dd>
        </div>
        <?php endif; ?>
        <?php if (!empty($profile['founded_year'])): ?>
        <div class="detail-pair">
            <dt class="detail-key">Founded</dt>
            <dd class="detail-value"><?= (int) $profile['founded_year'] ?></dd>
        </div>
        <?php endif; ?>
        <?php if (!empty($profile['website'])): ?>
        <div class="detail-pair">
            <dt class="detail-key">Website</dt>
            <dd class="detail-value">
                <a class="link-action" href="<?= e($profile['website']) ?>" target="_blank" rel="noopener noreferrer"><?= e($profile['website']) ?></a>
            </dd>
        </div>
        <?php endif; ?>
        <?php if (!empty($profile['contact_email'])): ?>
        <div class="detail-pair">
            <dt class="detail-key">Contact</dt>
            <dd class="detail-value"><?= e($profile['contact_email']) ?></dd>
        </div>
        <?php endif; ?>
        <?php if (!empty($profile['about'])): ?>
        <div class="detail-pair">
            <dt class="detail-key">About</dt>
            <dd class="detail-value"><?= nl2br(e($profile['about'])) ?></dd>
        </div>
        <?php endif; ?>
    </dl>

    <div class="form-actions">
        <a class="btn-plain-border" href="company-profile.php?edit=details">Edit</a>
        <form method="post" class="inline-form">
            <input type="hidden" name="section" value="details">
            <input type="hidden" name="action" value="delete">
            <button class="link-button link-danger" type="submit">Delete</button>
        </form>
    </div>

    <?php endif; ?>

</div>


<!-- Logo -->
<div class="form-card" id="logo-card">

    <div class="card-head">
        <h2 class="section-heading">Logo</h2>
        <?php if ($hasLogo && !$showLogoForm): ?>
        <span class="saved-tag">Uploaded</span>
        <?php endif; ?>
    </div>

    <?php if ($showLogoForm): ?>

    <p class="field-help section-help">Optional. JPG or PNG, up to 1 MB. A square image works best.</p>

    <form method="post" id="logo-form" enctype="multipart/form-data">
        <input type="hidden" name="section" value="logo">

        <div class="field">
            <label class="field-label" for="logo">Choose an image</label>
            <input class="field-input" type="file" name="logo" id="logo" accept="image/jpeg,image/png">
        </div>

        <div class="form-actions">
            <button class="btn-primary" type="submit">Upload logo</button>
            <?php if ($hasLogo): ?>
            <a class="btn-plain" href="company-profile.php">Cancel</a>
            <?php endif; ?>
        </div>
    </form>

    <?php else: ?>

    <div class="logo-row" id="logo-preview">
        <img class="logo-image" src="../<?= e($profile['logo_path']) ?>" alt="<?= e($profile['company_name']) ?> logo">
        <span class="logo-name"><?= e($profile['logo_name'] ?: basename($profile['logo_path'])) ?></span>
    </div>

    <div class="form-actions">
        <a class="btn-plain-border" href="company-profile.php?edit=logo">Replace</a>
        <form method="post" class="inline-form">
            <input type="hidden" name="section" value="logo">
            <input type="hidden" name="action" value="delete">
            <button class="link-button link-danger" type="submit">Delete</button>
        </form>
    </div>

    <?php endif; ?>

</div>

<?php require __DIR__ . '/includes/footer.php'; ?>