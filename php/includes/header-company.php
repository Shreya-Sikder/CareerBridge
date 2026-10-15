<?php
// Shared shell for every company page.
//
// Set these before requiring this file:
//   $page_title  string  shown in <title> and the topbar
//   $active_nav  string  one of: dashboard, jobs, profile
//   $extra_css   array   optional extra stylesheet names in css/,
//                        e.g. ['quiz.css']
//
// Usage:
//   $page_title = 'Post a job';
//   $active_nav = 'jobs';
//   require __DIR__ . '/includes/header-company.php';

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../auth-guard.php';
require_once __DIR__ . '/../helpers.php';

require_role('company');

$company = $pdo->prepare("
    SELECT c.company_name, u.email
    FROM company_profiles c
    JOIN users u ON u.id = c.user_id
    WHERE c.user_id = :id
");
$company->execute([':id' => current_user_id()]);
$company = $company->fetch();

$page_title = $page_title ?? 'Dashboard';
$active_nav = $active_nav ?? '';
$extra_css  = $extra_css ?? [];
$message    = flash();
?>
<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($page_title) ?> — CareerBridge</title>
<link rel="stylesheet" href="../css/dashboard.css">
<link rel="stylesheet" href="../css/jobs.css">
<?php foreach ($extra_css as $sheet): ?>
<link rel="stylesheet" href="../css/<?= e($sheet) ?>">
<?php endforeach; ?>

<style>
/* Sidebar layout, inlined on purpose.
   This sits after every <link> above, so it wins no matter what the
   external stylesheets say, and it still works if one of them fails
   to load or is served from cache. */

html, body { height: 100% !important; }
body { overflow: hidden !important; margin: 0 !important; }

.shell { display: block !important; }

.shell-side {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    bottom: 0 !important;
    width: 210px !important;
    height: 100vh !important;
    display: flex !important;
    flex-direction: column !important;
    overflow: hidden !important;
    background: #0f1c33 !important;
    padding: 20px 0 0 0 !important;
    box-sizing: border-box !important;
    z-index: 100 !important;
}

.shell-brand { flex-shrink: 0 !important; }

.shell-nav {
    display: flex !important;
    flex-direction: column !important;
    flex: 1 1 auto !important;
    min-height: 0 !important;
    overflow-y: auto !important;
}

.shell-logout {
    flex-shrink: 0 !important;
    margin-top: auto !important;
    padding: 14px 20px !important;
    border-top: 1px solid rgba(255, 255, 255, 0.12) !important;
}

.shell-main {
    margin-left: 210px !important;
    height: 100vh !important;
    overflow-y: auto !important;
    overflow-x: hidden !important;
    box-sizing: border-box !important;
}

@media (max-width: 720px) {
    body { overflow: auto !important; }

    .shell-side {
        position: sticky !important;
        bottom: auto !important;
        width: auto !important;
        height: auto !important;
        flex-direction: row !important;
        align-items: center !important;
        flex-wrap: wrap !important;
        padding: 10px 14px !important;
        overflow: visible !important;
    }

    .shell-nav {
        flex-direction: row !important;
        flex-wrap: wrap !important;
        overflow: visible !important;
    }

    .shell-logout {
        margin-top: 0 !important;
        margin-left: auto !important;
        padding: 7px 10px !important;
        border-top: none !important;
    }

    .shell-main {
        margin-left: 0 !important;
        height: auto !important;
        overflow: visible !important;
    }
}
</style>
</head>

<body>

<div class="shell">

    <aside class="shell-side">
        <div class="shell-brand">CareerBridge</div>

        <nav class="shell-nav">
            <a href="company-dashboard.php" class="shell-link<?= $active_nav === 'dashboard' ? ' on' : '' ?>">Dashboard</a>
            <a href="company-jobs.php" class="shell-link<?= $active_nav === 'jobs' ? ' on' : '' ?>">Jobs</a>
            <a href="company-profile.php" class="shell-link<?= $active_nav === 'profile' ? ' on' : '' ?>">Company profile</a>
            <a href="community.php?as=company" class="shell-link<?= $active_nav === 'community' ? ' on' : '' ?>">Community</a>
        </nav>

        <a href="logout.php?portal=company" class="shell-logout">Log out</a>
    </aside>

    <main class="shell-main">

        <div class="shell-top">
            <h1 class="shell-title"><?= e($page_title) ?></h1>
            <span class="shell-who"><?= e($company['company_name'] ?? '') ?></span>
        </div>

        <?php if ($message): ?>
        <p class="notice" id="flash-message"><?= e($message) ?></p>
        <?php endif; ?>