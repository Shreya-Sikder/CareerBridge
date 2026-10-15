<?php
// Notifications for the signed-in portal.
//
// Every query is filtered by user_id taken from the session, never
// from the URL. Candidates and companies are different users rows, so
// a candidate's notifications cannot appear in a company account even
// when both are signed in on the same browser.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth-guard.php';
require_once __DIR__ . '/helpers.php';

require_login($_GET['as'] ?? '');

$userId = current_user_id();
$role   = current_role();
$portal = '?as=' . urlencode($role);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'read_all') {
        $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = :u AND read_at IS NULL")
            ->execute([':u' => $userId]);
        flash('All notifications marked as read.');

    } elseif ($action === 'read_one') {
        // user_id in the WHERE is the authorisation: you can only
        // touch your own rows.
        $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE id = :id AND user_id = :u")
            ->execute([':id' => (int) ($_POST['id'] ?? 0), ':u' => $userId]);

    } elseif ($action === 'clear') {
        $pdo->prepare("DELETE FROM notifications WHERE user_id = :u AND read_at IS NOT NULL")
            ->execute([':u' => $userId]);
        flash('Read notifications cleared.');
    }

    redirect('notifications.php' . $portal);
}

// Clicking a notification marks it read and goes where it points.
if (isset($_GET['open'])) {

    $stmt = $pdo->prepare("SELECT url FROM notifications WHERE id = :id AND user_id = :u");
    $stmt->execute([':id' => (int) $_GET['open'], ':u' => $userId]);
    $target = $stmt->fetchColumn();

    if ($target !== false) {
        $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE id = :id AND user_id = :u")
            ->execute([':id' => (int) $_GET['open'], ':u' => $userId]);

        // Only ever redirect to a page of this application. A stored
        // url is data, and treating it as a destination without this
        // check would be an open redirect.
        if ($target && strpos($target, '://') === false && strpos($target, '//') !== 0) {
            redirect($target);
        }
    }

    redirect('notifications.php' . $portal);
}

$filter = $_GET['show'] ?? 'all';
$where  = $filter === 'unread' ? ' AND n.read_at IS NULL' : '';

$stmt = $pdo->prepare("
    SELECT n.*,
           COALESCE(cp.full_name, co.company_name) AS actor_name,
           cp.photo_path, co.logo_path
    FROM notifications n
    LEFT JOIN users u               ON u.id = n.actor_id
    LEFT JOIN candidate_profiles cp ON cp.user_id = u.id
    LEFT JOIN company_profiles   co ON co.user_id = u.id
    WHERE n.user_id = :u" . $where . "
    ORDER BY n.created_at DESC, n.id DESC
    LIMIT 100
");
$stmt->execute([':u' => $userId]);
$notifications = $stmt->fetchAll();

$unread = unread_notifications($pdo, $userId);

function notification_age(string $stamp): string
{
    $diff = time() - strtotime($stamp);

    if ($diff < 60)     { return 'just now'; }
    if ($diff < 3600)   { return floor($diff / 60) . 'm ago'; }
    if ($diff < 86400)  { return floor($diff / 3600) . 'h ago'; }
    if ($diff < 604800) { return floor($diff / 86400) . 'd ago'; }

    return date('j M Y', strtotime($stamp));
}

$page_title = 'Notifications';
$active_nav = 'notifications';
$extra_css  = ['community.css'];
require __DIR__ . '/includes/header-' . ($role === 'company' ? 'company' : 'candidate') . '.php';
?>

<div class="filter-row">
    <a class="filter-tab<?= $filter === 'all' ? ' on' : '' ?>" href="notifications.php<?= $portal ?>&show=all">All</a>
    <a class="filter-tab<?= $filter === 'unread' ? ' on' : '' ?>" href="notifications.php<?= $portal ?>&show=unread">
        Unread<?= $unread > 0 ? ' (' . $unread . ')' : '' ?>
    </a>

    <?php if ($unread > 0): ?>
    <form method="post" class="inline-form">
        <input type="hidden" name="action" value="read_all">
        <button class="link-button" type="submit">Mark all as read</button>
    </form>
    <?php endif; ?>

    <form method="post" class="inline-form">
        <input type="hidden" name="action" value="clear">
        <button class="link-button link-danger" type="submit">Clear read</button>
    </form>
</div>

<?php if (!$notifications): ?>

<div class="empty" id="no-notifications">
    <p class="empty-title"><?= $filter === 'unread' ? 'Nothing unread' : 'No notifications yet' ?></p>
    <p class="empty-text">
        You will hear here when your application moves, your assessment is marked,
        or someone reacts to your posts.
    </p>
</div>

<?php else: ?>

<ul class="notice-list" id="notification-list">
<?php foreach ($notifications as $item): ?>
    <li class="notice-item<?= $item['read_at'] === null ? ' unread' : '' ?>">

        <?php $avatar = $item['photo_path'] ?: $item['logo_path']; ?>
        <?php if ($avatar): ?>
        <img class="avatar avatar-small" src="../<?= e($avatar) ?>" alt="">
        <?php else: ?>
        <span class="avatar avatar-small avatar-blank"><?= e(strtoupper(substr($item['actor_name'] ?: 'C', 0, 1))) ?></span>
        <?php endif; ?>

        <div class="notice-body">
            <?php if (!empty($item['url'])): ?>
            <a class="notice-title" href="notifications.php<?= $portal ?>&open=<?= (int) $item['id'] ?>">
                <?= e($item['title']) ?>
            </a>
            <?php else: ?>
            <span class="notice-title"><?= e($item['title']) ?></span>
            <?php endif; ?>

            <?php if (!empty($item['body'])): ?>
            <p class="notice-text"><?= e($item['body']) ?></p>
            <?php endif; ?>

            <span class="notice-when">
                <?= notification_age($item['created_at']) ?>
                &middot; <?= date('j M Y, g:ia', strtotime($item['created_at'])) ?>
            </span>
        </div>

        <?php if ($item['read_at'] === null): ?>
        <form method="post" class="inline-form notice-action">
            <input type="hidden" name="action" value="read_one">
            <input type="hidden" name="id" value="<?= (int) $item['id'] ?>">
            <button class="link-button" type="submit">Mark read</button>
        </form>
        <?php endif; ?>

    </li>
<?php endforeach; ?>
</ul>

<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>