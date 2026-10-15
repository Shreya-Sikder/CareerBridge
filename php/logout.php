<?php
// Sign out.
//
//   logout.php                   sign out of both portals
//   logout.php?portal=candidate  sign out of the candidate portal only
//   logout.php?portal=company    sign out of the company portal only
//
// Per-portal sign out matters now that both can be signed in at
// once: closing the candidate tab should not throw the company tab
// out as well.

require_once __DIR__ . '/auth-guard.php';

$portal = strtolower(trim($_GET['portal'] ?? ''));

if (in_array($portal, ['candidate', 'company'], true)) {

    logout_role($portal);

    // If the other portal is still signed in, send them there rather
    // than to a sign-in page they do not need.
    if (is_logged_in()) {
        $other = is_logged_in('company') ? 'company' : 'candidate';
        header('Location: ' . $other . '-dashboard.php');
        exit;
    }
}

logout_all();

header('Location: ../html/landingpage.html');
exit;