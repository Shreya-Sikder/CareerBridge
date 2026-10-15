<?php
// The community feed. Candidates and companies share it.
//
// Either portal may open this page, so it uses require_login()
// rather than require_role(). The portal is taken from ?as= when
// both are signed in, which keeps a company tab and a candidate tab
// independent exactly as every other page does.
//
// Everything posts back to this file:
//   post     publish a new post
//   like     toggle a reaction
//   comment  add a comment
//   delete   remove your own post

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/helpers.php';

require_login($_GET['as'] ?? '');

$userId = current_user_id();
$role   = current_role();
$errors = [];

// Keep the portal on every link so navigating the feed never flips
// a tab into the other portal.
$portalQuery = '?as=' . urlencode($role);


if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    // -----------------------------------------------------------
    if ($action === 'post') {

        $kind  = $_POST['kind'] ?? 'text';
        $body  = trim($_POST['body'] ?? '');
        $video = trim($_POST['video_url'] ?? '');
        $jobId = (int) ($_POST['job_id'] ?? 0);

        if (!in_array($kind, ['text', 'image', 'video', 'job'], true)) {
            $errors[] = 'Choose what kind of post this is.';
        }
        if ($body === '' && $kind === 'text') {
            $errors[] = 'Write something to post.';
        }
        if (text_length($body) > 3000) {
            $errors[] = 'Keep a post under 3000 characters.';
        }

        $imagePath = null;

        if ($kind === 'image') {
            $saved = save_image_upload($_FILES['image'] ?? [], 'posts', 'post', $userId);
            if (isset($saved['error'])) {
                $errors[] = $saved['error'];
            } else {
                $imagePath = $saved['path'];
            }
        }

        if ($kind === 'video') {
            if ($video === '') {
                $errors[] = 'Paste the YouTube link for your video.';
            } elseif (!is_youtube_url($video)) {
                $errors[] = 'That is not a YouTube link. Videos are shared as youtube.com or youtu.be URLs.';
            }
        }

        // Only a company can share one of its own jobs, and only its
        // own. The id is checked against the session, never trusted.
        if ($kind === 'job') {
            if ($role !== 'company') {
                $errors[] = 'Only companies can share a job post.';
            } else {
                $check = $pdo->prepare("SELECT id FROM jobs WHERE id = :id AND company_id = :co");
                $check->execute([':id' => $jobId, ':co' => $userId]);
                if (!$check->fetch()) {
                    $errors[] = 'Choose one of your own jobs to share.';
                }
            }
        }

        if (!$errors) {
            $pdo->prepare("
                INSERT INTO community_posts (author_id, kind, body, image_path, video_url, job_id)
                VALUES (:a, :k, :b, :img, :vid, :job)
            ")->execute([
                ':a'   => $userId,
                ':k'   => $kind,
                ':b'   => $body ?: null,
                ':img' => $imagePath,
                ':vid' => $kind === 'video' ? $video : null,
                ':job' => $kind === 'job' ? $jobId : null,
            ]);

            flash('Posted.');
            redirect('community.php' . $portalQuery);
        }

    // -----------------------------------------------------------
    } elseif ($action === 'like') {

        $postId = (int) ($_POST['post_id'] ?? 0);

        $exists = $pdo->prepare("SELECT id FROM community_posts WHERE id = :id");
        $exists->execute([':id' => $postId]);

        if ($exists->fetch()) {
            $mine = $pdo->prepare("SELECT 1 FROM post_reactions WHERE post_id = :p AND user_id = :u");
            $mine->execute([':p' => $postId, ':u' => $userId]);

            if ($mine->fetch()) {
                $pdo->prepare("DELETE FROM post_reactions WHERE post_id = :p AND user_id = :u")
                    ->execute([':p' => $postId, ':u' => $userId]);
            } else {
                // The composite key makes a duplicate impossible even
                // if two clicks race.
                $pdo->prepare("
                    INSERT INTO post_reactions (post_id, user_id) VALUES (:p, :u)
                    ON DUPLICATE KEY UPDATE post_id = post_id
                ")->execute([':p' => $postId, ':u' => $userId]);
            }
        }

        redirect('community.php' . $portalQuery . '#post-' . $postId);

    // -----------------------------------------------------------
    } elseif ($action === 'comment') {

        $postId = (int) ($_POST['post_id'] ?? 0);
        $body   = trim($_POST['body'] ?? '');

        if ($body === '') {
            $errors[] = 'Write a comment before posting it.';
        } elseif (text_length($body) > 1000) {
            $errors[] = 'Keep a comment under 1000 characters.';
        } else {
            $exists = $pdo->prepare("SELECT id FROM community_posts WHERE id = :id");
            $exists->execute([':id' => $postId]);

            if ($exists->fetch()) {
                $pdo->prepare("
                    INSERT INTO post_comments (post_id, author_id, body)
                    VALUES (:p, :a, :b)
                ")->execute([':p' => $postId, ':a' => $userId, ':b' => $body]);
            }
            redirect('community.php' . $portalQuery . '#post-' . $postId);
        }

    // -----------------------------------------------------------
    } elseif ($action === 'delete') {

        $postId = (int) ($_POST['post_id'] ?? 0);

        // author_id in the WHERE is the authorisation: you can only
        // delete your own.
        $stmt = $pdo->prepare("SELECT image_path FROM community_posts WHERE id = :id AND author_id = :a");
        $stmt->execute([':id' => $postId, ':a' => $userId]);
        $post = $stmt->fetch();

        if ($post) {
            $pdo->prepare("DELETE FROM community_posts WHERE id = :id AND author_id = :a")
                ->execute([':id' => $postId, ':a' => $userId]);
            delete_upload($post['image_path']);
            flash('Post deleted.');
        }

        redirect('community.php' . $portalQuery);
    }
}


// ---------------------------------------------------------------
// The feed
// ---------------------------------------------------------------
$stmt = $pdo->prepare("
    SELECT p.*,
           u.role AS author_role,
           COALESCE(cp.full_name, co.company_name, u.email) AS author_name,
           COALESCE(cp.headline, co.industry) AS author_sub,
           cp.photo_path, co.logo_path,
           j.title AS job_title, j.location AS job_location, j.job_type,
           j.description AS job_description, j.status AS job_status,
           j.deadline AS job_deadline,
           jc.company_name AS job_company,
           (SELECT COUNT(*) FROM post_reactions r WHERE r.post_id = p.id) AS likes,
           (SELECT COUNT(*) FROM post_comments  c WHERE c.post_id = p.id) AS comment_count,
           (SELECT COUNT(*) FROM post_reactions r WHERE r.post_id = p.id AND r.user_id = :me) AS liked_by_me
    FROM community_posts p
    JOIN users u                    ON u.id = p.author_id
    LEFT JOIN candidate_profiles cp ON cp.user_id = u.id
    LEFT JOIN company_profiles   co ON co.user_id = u.id
    LEFT JOIN jobs j                ON j.id = p.job_id
    LEFT JOIN company_profiles   jc ON jc.user_id = j.company_id
    ORDER BY p.created_at DESC, p.id DESC
    LIMIT 50
");
$stmt->execute([':me' => $userId]);
$posts = $stmt->fetchAll();

// Comments for everything on screen, in one query rather than one
// per post.
$comments = [];
if ($posts) {
    $ids = array_column($posts, 'id');
    $in  = implode(',', array_map('intval', $ids));

    foreach ($pdo->query("
        SELECT c.*, COALESCE(cp.full_name, co.company_name, u.email) AS author_name,
               cp.photo_path, co.logo_path
        FROM post_comments c
        JOIN users u                    ON u.id = c.author_id
        LEFT JOIN candidate_profiles cp ON cp.user_id = u.id
        LEFT JOIN company_profiles   co ON co.user_id = u.id
        WHERE c.post_id IN ($in)
        ORDER BY c.created_at
    ")->fetchAll() as $row) {
        $comments[(int) $row['post_id']][] = $row;
    }
}

// A company can share one of its own live jobs.
$myJobs = [];
if ($role === 'company') {
    $stmt = $pdo->prepare("
        SELECT id, title FROM jobs
        WHERE company_id = :co AND status = 'active'
        ORDER BY created_at DESC
    ");
    $stmt->execute([':co' => $userId]);
    $myJobs = $stmt->fetchAll();
}

$me = community_author($pdo, $userId);

// Turn a YouTube link into its embed form.
function youtube_embed(string $url): ?string
{
    $parts = parse_url($url);
    if (!$parts) {
        return null;
    }

    $host = strtolower(preg_replace('/^www\./', '', $parts['host'] ?? ''));
    parse_str($parts['query'] ?? '', $query);

    if ($host === 'youtu.be') {
        return 'https://www.youtube.com/embed/' . ltrim($parts['path'] ?? '', '/');
    }
    if (!empty($query['list'])) {
        return 'https://www.youtube.com/embed/videoseries?list=' . $query['list'];
    }
    if (!empty($query['v'])) {
        return 'https://www.youtube.com/embed/' . $query['v'];
    }
    return null;
}

function when(string $stamp): string
{
    $diff = time() - strtotime($stamp);

    if ($diff < 60)    { return 'just now'; }
    if ($diff < 3600)  { return floor($diff / 60) . 'm ago'; }
    if ($diff < 86400) { return floor($diff / 3600) . 'h ago'; }
    if ($diff < 604800){ return floor($diff / 86400) . 'd ago'; }

    return date('j M Y', strtotime($stamp));
}

$page_title = 'Community';
$active_nav = 'community';
$extra_css  = ['community.css'];
require __DIR__ . '/includes/header-' . ($role === 'company' ? 'company' : 'candidate') . '.php';
?>

<?php if ($errors): ?>
<div class="errors" id="form-errors">
    <?php foreach ($errors as $error): ?>
    <p class="error-line"><?= e($error) ?></p>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Composer -->
<div class="post-card" id="composer">

    <div class="post-head">
        <?php if (!empty($me['photo_path']) || !empty($me['logo_path'])): ?>
        <img class="avatar" src="../<?= e($me['photo_path'] ?: $me['logo_path']) ?>" alt="">
        <?php else: ?>
        <span class="avatar avatar-blank"><?= e(strtoupper(substr($me['name'] ?? '?', 0, 1))) ?></span>
        <?php endif; ?>
        <div class="post-meta">
            <span class="post-author"><?= e($me['name'] ?? '') ?></span>
            <span class="post-sub">Share something with the community</span>
        </div>
    </div>

    <form method="post" enctype="multipart/form-data" id="composer-form">
        <input type="hidden" name="action" value="post">

        <div class="kind-row">
            <label class="kind chosen">
                <input class="kind-radio" type="radio" name="kind" value="text" checked>
                <span>Text</span>
            </label>
            <label class="kind">
                <input class="kind-radio" type="radio" name="kind" value="image">
                <span>Image</span>
            </label>
            <label class="kind">
                <input class="kind-radio" type="radio" name="kind" value="video">
                <span>Video</span>
            </label>
            <?php if ($role === 'company' && $myJobs): ?>
            <label class="kind">
                <input class="kind-radio" type="radio" name="kind" value="job">
                <span>Job</span>
            </label>
            <?php endif; ?>
        </div>

        <textarea class="field-input field-area" name="body" rows="3" maxlength="3000"
                  placeholder="What are you working on?"></textarea>

        <div class="kind-panel" data-for="image" hidden>
            <label class="field-label" for="post-image">Image</label>
            <input class="field-input" type="file" name="image" id="post-image" accept="image/jpeg,image/png,image/webp">
            <p class="field-help">JPG, PNG or WEBP, up to 2 MB.</p>
        </div>

        <div class="kind-panel" data-for="video" hidden>
            <label class="field-label" for="post-video">YouTube link</label>
            <input class="field-input" type="url" name="video_url" id="post-video"
                   placeholder="https://youtu.be/...">
            <p class="field-help">Videos are shared as YouTube links rather than uploaded files.</p>
        </div>

        <?php if ($role === 'company' && $myJobs): ?>
        <div class="kind-panel" data-for="job" hidden>
            <label class="field-label" for="post-job">Which job</label>
            <select class="field-input" name="job_id" id="post-job">
                <?php foreach ($myJobs as $job): ?>
                <option value="<?= (int) $job['id'] ?>"><?= e($job['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="form-actions">
            <button class="btn-primary" type="submit">Post</button>
        </div>
    </form>

</div>


<?php if (!$posts): ?>

<div class="empty" id="no-posts">
    <p class="empty-title">Nothing here yet</p>
    <p class="empty-text">Be the first to post. Share a project, an update, or a job opening.</p>
</div>

<?php endif; ?>

<?php foreach ($posts as $post): ?>
<div class="post-card" id="post-<?= (int) $post['id'] ?>">

    <div class="post-head">
        <?php $avatar = $post['photo_path'] ?: $post['logo_path']; ?>
        <?php if ($avatar): ?>
        <img class="avatar" src="../<?= e($avatar) ?>" alt="">
        <?php else: ?>
        <span class="avatar avatar-blank"><?= e(strtoupper(substr($post['author_name'], 0, 1))) ?></span>
        <?php endif; ?>

        <div class="post-meta">
            <span class="post-author">
                <?= e($post['author_name']) ?>
                <span class="role-tag"><?= $post['author_role'] === 'company' ? 'Company' : 'Candidate' ?></span>
            </span>
            <span class="post-sub">
                <?= $post['author_sub'] ? e($post['author_sub']) . ' &middot; ' : '' ?><?= when($post['created_at']) ?>
            </span>
        </div>

        <?php if ((int) $post['author_id'] === $userId): ?>
        <form method="post" class="inline-form post-delete">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
            <button class="link-button link-danger" type="submit">Delete</button>
        </form>
        <?php endif; ?>
    </div>

    <?php if (!empty($post['body'])): ?>
    <p class="post-body"><?= nl2br(e($post['body'])) ?></p>
    <?php endif; ?>

    <?php if ($post['kind'] === 'image' && $post['image_path']): ?>
    <img class="post-image" src="../<?= e($post['image_path']) ?>" alt="">
    <?php endif; ?>

    <?php if ($post['kind'] === 'video' && $post['video_url']): ?>
        <?php $embed = youtube_embed($post['video_url']); ?>
        <?php if ($embed): ?>
        <div class="post-video">
            <iframe src="<?= e($embed) ?>" title="Video" allowfullscreen loading="lazy"></iframe>
        </div>
        <?php else: ?>
        <p class="post-body"><a class="link-action" href="<?= e($post['video_url']) ?>" target="_blank" rel="noopener noreferrer">Watch the video</a></p>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($post['kind'] === 'job' && $post['job_title']): ?>
    <div class="job-card">
        <p class="job-card-title"><?= e($post['job_title']) ?></p>
        <p class="job-card-sub">
            <?= e($post['job_company']) ?> &middot;
            <?= e(ucfirst(str_replace('-', ' ', (string) $post['job_type']))) ?> &middot;
            <?= e($post['job_location']) ?>
        </p>
        <?php if (!empty($post['job_description'])): ?>
        <p class="job-card-text"><?= e(mb_substr_safe($post['job_description'], 220)) ?></p>
        <?php endif; ?>
        <?php if ($role === 'candidate' && $post['job_status'] === 'active' && strtotime($post['job_deadline']) > time()): ?>
        <a class="btn-primary" href="candidate-jobs.php?id=<?= (int) $post['job_id'] ?>">View job</a>
        <?php elseif ($role === 'company'): ?>
        <a class="btn-plain-border" href="company-applicants.php?job=<?= (int) $post['job_id'] ?>">View applicants</a>
        <?php else: ?>
        <span class="pill pill-grey">Closed</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="post-actions">
        <form method="post" class="inline-form">
            <input type="hidden" name="action" value="like">
            <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
            <button class="react<?= (int) $post['liked_by_me'] ? ' reacted' : '' ?>" type="submit">
                <?= (int) $post['liked_by_me'] ? 'Liked' : 'Like' ?>
                <?php if ((int) $post['likes'] > 0): ?>
                <span class="react-count"><?= (int) $post['likes'] ?></span>
                <?php endif; ?>
            </button>
        </form>

        <span class="post-count"><?= (int) $post['comment_count'] ?> comment<?= (int) $post['comment_count'] === 1 ? '' : 's' ?></span>
    </div>

    <?php if (!empty($comments[(int) $post['id']])): ?>
    <ul class="comment-list">
        <?php foreach ($comments[(int) $post['id']] as $comment): ?>
        <li class="comment">
            <?php $cav = $comment['photo_path'] ?: $comment['logo_path']; ?>
            <?php if ($cav): ?>
            <img class="avatar avatar-small" src="../<?= e($cav) ?>" alt="">
            <?php else: ?>
            <span class="avatar avatar-small avatar-blank"><?= e(strtoupper(substr($comment['author_name'], 0, 1))) ?></span>
            <?php endif; ?>
            <div class="comment-body">
                <span class="comment-author"><?= e($comment['author_name']) ?></span>
                <span class="comment-when"><?= when($comment['created_at']) ?></span>
                <p class="comment-text"><?= nl2br(e($comment['body'])) ?></p>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>

    <form method="post" class="comment-form">
        <input type="hidden" name="action" value="comment">
        <input type="hidden" name="post_id" value="<?= (int) $post['id'] ?>">
        <input class="field-input" type="text" name="body" maxlength="1000" placeholder="Add a comment">
        <button class="btn-plain-border" type="submit">Comment</button>
    </form>

</div>
<?php endforeach; ?>

<script src="../js/community.js"></script>

<?php require __DIR__ . '/includes/footer.php'; ?>