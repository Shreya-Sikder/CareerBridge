<?php
// Author the assessment for one job.
//
//   company-quiz-builder.php?job=12
//
// Posts to itself. Saving replaces every question for this job, which
// is safe because the page refuses to edit once locked_at is stamped
// — by then at least one candidate has been scored against these
// questions and they must not change.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/helpers.php';

require_role('company');

const OPTIONS_PER_QUESTION = 4;
const MAX_QUESTIONS        = 30;

$companyId = current_user_id();
$jobId     = isset($_GET['job']) ? (int) $_GET['job'] : 0;
$errors    = [];

$stmt = $pdo->prepare("SELECT * FROM jobs WHERE id = :id AND company_id = :co");
$stmt->execute([':id' => $jobId, ':co' => $companyId]);
$job = $stmt->fetch();

if (!$job) {
    flash('That job does not exist, or it belongs to another company.');
    redirect('company-jobs.php');
}

// Older jobs may predate the assessment row, so make sure there is one.
$pdo->prepare("
    INSERT INTO assessments (job_id) VALUES (:job)
    ON DUPLICATE KEY UPDATE job_id = job_id
")->execute([':job' => $jobId]);

$stmt = $pdo->prepare("SELECT * FROM assessments WHERE job_id = :job");
$stmt->execute([':job' => $jobId]);
$assessment = $stmt->fetch();

$isLocked = $assessment['locked_at'] !== null;

$settings = [
    'time_limit_minutes' => (int) $assessment['time_limit_minutes'],
    'passing_score'      => (int) $assessment['passing_score'],
];

// Load the saved questions.
$questions = [];
$rows = $pdo->prepare("
    SELECT q.id, q.question_text, q.points
    FROM questions q
    WHERE q.assessment_id = :a
    ORDER BY q.position, q.id
");
$rows->execute([':a' => $assessment['id']]);

$optionStmt = $pdo->prepare("
    SELECT option_text, is_correct
    FROM question_options
    WHERE question_id = :q
    ORDER BY id
");

foreach ($rows->fetchAll() as $row) {
    $optionStmt->execute([':q' => $row['id']]);
    $options = $optionStmt->fetchAll();

    $correct = 0;
    foreach ($options as $i => $option) {
        if ((int) $option['is_correct'] === 1) {
            $correct = $i;
        }
    }

    // Pad to the fixed option count in case older rows have fewer.
    while (count($options) < OPTIONS_PER_QUESTION) {
        $options[] = ['option_text' => '', 'is_correct' => 0];
    }

    $questions[] = [
        'question_text' => $row['question_text'],
        'points'        => (int) $row['points'],
        'options'       => array_slice($options, 0, OPTIONS_PER_QUESTION),
        'correct'       => $correct,
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isLocked) {

    $settings['time_limit_minutes'] = (int) ($_POST['time_limit_minutes'] ?? 30);
    $settings['passing_score']      = (int) ($_POST['passing_score'] ?? 60);

    // Rebuild the question list from the form so a failed save
    // re-renders exactly what was typed.
    $questions = [];
    foreach ($_POST['questions'] ?? [] as $posted) {

        $correct = isset($posted['correct']) ? (int) $posted['correct'] : -1;
        $options = [];

        for ($i = 0; $i < OPTIONS_PER_QUESTION; $i++) {
            $options[] = [
                'option_text' => trim($posted['options'][$i]['option_text'] ?? ''),
                'is_correct'  => $i === $correct ? 1 : 0,
            ];
        }

        $questions[] = [
            'question_text' => trim($posted['question_text'] ?? ''),
            'points'        => (int) ($posted['points'] ?? 5),
            'options'       => $options,
            'correct'       => $correct,
        ];
    }

    // Drop blocks the company left completely empty rather than
    // failing the save on them.
    $questions = array_values(array_filter($questions, function ($q) {
        if ($q['question_text'] !== '') {
            return true;
        }
        foreach ($q['options'] as $option) {
            if ($option['option_text'] !== '') {
                return true;
            }
        }
        return false;
    }));

    // Shared validation: empty text, too few options, zero or several
    // correct answers, points below 1.
    $errors = validate_questions($questions);

    // Checks validate_questions() cannot make.
    foreach ($questions as $i => $q) {
        $n = $i + 1;
        foreach ($q['options'] as $j => $option) {
            if ($option['option_text'] === '') {
                $errors[] = "Question $n: option " . ($j + 1) . ' is empty.';
            }
        }
    }

    if (count($questions) > MAX_QUESTIONS) {
        $errors[] = 'A maximum of ' . MAX_QUESTIONS . ' questions is allowed.';
    }
    if ($settings['passing_score'] < 1 || $settings['passing_score'] > 100) {
        $errors[] = 'The pass mark must be between 1 and 100.';
    }
    if ($settings['time_limit_minutes'] < 1 || $settings['time_limit_minutes'] > 180) {
        $errors[] = 'The time limit must be between 1 and 180 minutes.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            $pdo->prepare("
                UPDATE assessments
                SET time_limit_minutes = :mins, passing_score = :pass
                WHERE id = :id AND locked_at IS NULL
            ")->execute([
                ':mins' => $settings['time_limit_minutes'],
                ':pass' => $settings['passing_score'],
                ':id'   => $assessment['id'],
            ]);

            // Options cascade with their question.
            $pdo->prepare("DELETE FROM questions WHERE assessment_id = :a")
                ->execute([':a' => $assessment['id']]);

            $insertQuestion = $pdo->prepare("
                INSERT INTO questions (assessment_id, question_text, points, position)
                VALUES (:a, :text, :points, :pos)
            ");
            $insertOption = $pdo->prepare("
                INSERT INTO question_options (question_id, option_text, is_correct)
                VALUES (:q, :text, :correct)
            ");

            foreach ($questions as $i => $q) {
                $insertQuestion->execute([
                    ':a'      => $assessment['id'],
                    ':text'   => $q['question_text'],
                    ':points' => $q['points'],
                    ':pos'    => $i + 1,
                ]);
                $questionId = (int) $pdo->lastInsertId();

                foreach ($q['options'] as $option) {
                    $insertOption->execute([
                        ':q'       => $questionId,
                        ':text'    => $option['option_text'],
                        ':correct' => $option['is_correct'],
                    ]);
                }
            }

            $pdo->commit();

            flash('Assessment saved. Publish the job when you are ready.');
            redirect('company-jobs.php');

        } catch (PDOException $ex) {
            $pdo->rollBack();
            $errors[] = 'The assessment could not be saved. Try again.';
        }
    }
}

// A new assessment opens with three empty blocks so the page is
// usable even if JavaScript is off.
if (!$questions && !$isLocked) {
    for ($i = 0; $i < 3; $i++) {
        $questions[] = [
            'question_text' => '',
            'points'        => 5,
            'options'       => array_fill(0, OPTIONS_PER_QUESTION, ['option_text' => '', 'is_correct' => 0]),
            'correct'       => -1,
        ];
    }
}

$page_title = 'Assessment: ' . $job['title'];
$active_nav = 'jobs';
$extra_css  = ['quiz.css'];
require __DIR__ . '/includes/header-company.php';
?>

<?php if ($isLocked): ?>
<p class="notice" id="locked-notice">
    Candidates have already sat this assessment, so the questions are fixed.
    Changing them now would make scores incomparable.
</p>
<?php endif; ?>

<?php if ($errors): ?>
<div class="errors" id="form-errors">
    <?php foreach ($errors as $error): ?>
    <p class="error-line"><?= e($error) ?></p>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="post" class="form-card" id="quiz-form">

    <div class="field-row">
        <div class="field">
            <label class="field-label" for="time_limit_minutes">Time limit (minutes)</label>
            <input class="field-input" type="number" name="time_limit_minutes" id="time_limit_minutes"
                   value="<?= (int) $settings['time_limit_minutes'] ?>" min="1" max="180"
                   <?= $isLocked ? 'disabled' : '' ?>>
        </div>

        <div class="field">
            <label class="field-label" for="passing_score">Pass mark (%)</label>
            <input class="field-input" type="number" name="passing_score" id="passing_score"
                   value="<?= (int) $settings['passing_score'] ?>" min="1" max="100"
                   <?= $isLocked ? 'disabled' : '' ?>>
        </div>
    </div>

    <div class="question-list" id="question-list">
        <?php foreach ($questions as $i => $q): ?>
        <div class="question-block">

            <div class="question-head">
                <span class="question-number">Question <?= $i + 1 ?></span>
                <?php if (!$isLocked): ?>
                <button class="link-button remove-question" type="button">Remove</button>
                <?php endif; ?>
            </div>

            <div class="field">
                <label class="field-label">Question</label>
                <textarea class="field-input field-area" name="questions[<?= $i ?>][question_text]"
                          rows="2" <?= $isLocked ? 'disabled' : '' ?>><?= e($q['question_text']) ?></textarea>
            </div>

            <div class="option-list">
                <?php foreach ($q['options'] as $j => $option): ?>
                <div class="option-row">
                    <input class="option-radio" type="radio"
                           name="questions[<?= $i ?>][correct]" value="<?= $j ?>"
                           <?= (int) $q['correct'] === $j ? 'checked' : '' ?>
                           <?= $isLocked ? 'disabled' : '' ?>>
                    <input class="field-input option-text" type="text"
                           name="questions[<?= $i ?>][options][<?= $j ?>][option_text]"
                           value="<?= e($option['option_text']) ?>" maxlength="500"
                           placeholder="Option <?= $j + 1 ?>" <?= $isLocked ? 'disabled' : '' ?>>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="field question-points">
                <label class="field-label">Points</label>
                <input class="field-input" type="number" name="questions[<?= $i ?>][points]"
                       value="<?= (int) $q['points'] ?>" min="1" max="100"
                       <?= $isLocked ? 'disabled' : '' ?>>
            </div>

        </div>
        <?php endforeach; ?>
    </div>

    <?php if (!$isLocked): ?>
    <div class="form-actions">
        <button class="btn-plain-border" type="button" id="add-question">Add question</button>
        <button class="btn-primary" type="submit" id="save-quiz">Save assessment</button>
        <a class="btn-plain" href="company-jobs.php">Cancel</a>
    </div>
    <?php else: ?>
    <div class="form-actions">
        <a class="btn-plain" href="company-jobs.php">Back to jobs</a>
    </div>
    <?php endif; ?>

</form>

<?php if (!$isLocked): ?>
<template id="question-template">
    <div class="question-block">

        <div class="question-head">
            <span class="question-number">Question</span>
            <button class="link-button remove-question" type="button">Remove</button>
        </div>

        <div class="field">
            <label class="field-label">Question</label>
            <textarea class="field-input field-area" name="questions[0][question_text]" rows="2"></textarea>
        </div>

        <div class="option-list">
            <?php for ($j = 0; $j < OPTIONS_PER_QUESTION; $j++): ?>
            <div class="option-row">
                <input class="option-radio" type="radio" name="questions[0][correct]" value="<?= $j ?>">
                <input class="field-input option-text" type="text"
                       name="questions[0][options][<?= $j ?>][option_text]"
                       maxlength="500" placeholder="Option <?= $j + 1 ?>">
            </div>
            <?php endfor; ?>
        </div>

        <div class="field question-points">
            <label class="field-label">Points</label>
            <input class="field-input" type="number" name="questions[0][points]" value="5" min="1" max="100">
        </div>

    </div>
</template>

<script src="../js/quiz-builder.js"></script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>