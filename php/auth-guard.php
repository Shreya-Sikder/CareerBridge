<?php
// Session and role checks.
//
// THE ARCHITECTURE, and why it is not a flat user_id + role:
//
// A PHP session is identified by one cookie, and a cookie is shared
// by every tab of a browser. There is therefore exactly ONE session
// no matter how many tabs are open. A flat $_SESSION['role'] means
// the second portal you log into overwrites the first, and the tab
// still showing the first portal silently becomes the other one.
//
// So the session holds a login PER PORTAL instead:
//
//     $_SESSION['logins'] = ['candidate' => 3, 'company' => 1];
//
// The page decides which portal it is by calling require_role(). The
// active identity is therefore a property of the page being
// requested, never of the shared session. Two tabs in two portals
// stop fighting because neither one has to win.
//
// This file must emit no output: no blank line before the opening
// tag, no PHP closing tag at the end, and never that closing tag
// inside a comment either, because PHP leaves PHP mode at it even
// in a comment. Output here stops the session cookie being set and
// logs everyone out at random.

// ---------------------------------------------------------------
// Start the session once, with a cookie the whole application
// shares. The default cookie path is the script's own directory, so
// a session begun in /php/ would be invisible in /html/.
// ---------------------------------------------------------------
if (session_status() === PHP_SESSION_NONE) {

    $base = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/')));
    if ($base === '' || $base === '.') {
        $base = '/';
    }
    if (substr($base, -1) !== '/') {
        $base .= '/';
    }

    session_set_cookie_params([
        'path'     => $base,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

if (!isset($_SESSION['logins']) || !is_array($_SESSION['logins'])) {
    $_SESSION['logins'] = [];
}


// ---------------------------------------------------------------
// Carry an older flat session into the per-portal map, so anyone
// already signed in stays signed in across this upgrade. Also
// accepts the various key names earlier versions used.
// ---------------------------------------------------------------
(function (): void {

    $id = 0;
    foreach (['user_id', 'id', 'uid', 'userid', 'user_ID'] as $key) {
        if (!empty($_SESSION[$key]) && is_numeric($_SESSION[$key])) {
            $id = (int) $_SESSION[$key];
            break;
        }
    }
    if (!$id && !empty($_SESSION['user']['id'])) {
        $id = (int) $_SESSION['user']['id'];
    }

    $role = '';
    foreach (['role', 'user_role', 'user_type', 'account_type', 'type'] as $key) {
        if (!empty($_SESSION[$key]) && is_string($_SESSION[$key])) {
            $role = $_SESSION[$key];
            break;
        }
    }
    if ($role === '' && !empty($_SESSION['user']['role'])) {
        $role = (string) $_SESSION['user']['role'];
    }

    $role = strtolower(trim($role));

    if ($id > 0 && in_array($role, ['candidate', 'company'], true)
        && empty($_SESSION['logins'][$role])) {
        $_SESSION['logins'][$role] = $id;
    }

    // The flat keys are deliberately dropped. Leaving them would let
    // a page that still reads them see the wrong portal's user.
    unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['user'],
          $_SESSION['id'], $_SESSION['uid'], $_SESSION['userid'],
          $_SESSION['user_ID'], $_SESSION['user_role'],
          $_SESSION['user_type'], $_SESSION['account_type'], $_SESSION['type']);
})();


// ---------------------------------------------------------------
// The active portal for THIS request. Set by require_role(), held
// in a static rather than in the session, so one tab's navigation
// cannot change what another tab is looking at.
// ---------------------------------------------------------------
function active_portal(?string $set = null): string
{
    static $portal = '';

    if ($set !== null) {
        $portal = strtolower(trim($set));
    }
    return $portal;
}

function is_logged_in(?string $role = null): bool
{
    if ($role === null) {
        return $_SESSION['logins'] !== [];
    }
    return !empty($_SESSION['logins'][strtolower($role)]);
}

// The id of the user whose portal this page belongs to.
function current_user_id(): int
{
    $portal = active_portal();
    if ($portal === '') {
        return 0;
    }
    return (int) ($_SESSION['logins'][$portal] ?? 0);
}

function current_role(): string
{
    return active_portal();
}

// Call from login.php after the password check. Signing into one
// portal deliberately leaves the other one alone, which is what
// makes two tabs possible.
function set_login_session(int $userId, string $role): void
{
    $role = strtolower(trim($role));

    if (!in_array($role, ['candidate', 'company'], true)) {
        return;
    }

    // Keep the other portal's login across the id change.
    $keep = $_SESSION['logins'] ?? [];
    session_regenerate_id(true);

    $_SESSION['logins'] = $keep;
    $_SESSION['logins'][$role] = $userId;
}

// Sign out of one portal, leaving the other signed in.
function logout_role(string $role): void
{
    unset($_SESSION['logins'][strtolower(trim($role))]);
}

// Sign out of everything.
function logout_all(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
                  $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// Where to send someone who needs to sign in. portal tells login.php
// which form to show, return_to where to send them afterwards.
function login_url(string $portal = ''): string
{
    $base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if (substr($base, -1) !== '/') {
        $base .= '/';
    }

    $query = [];
    if ($portal !== '') {
        $query['portal'] = $portal;
    }
    if (!empty($_SERVER['REQUEST_URI']) && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        $query['return_to'] = $_SERVER['REQUEST_URI'];
    }

    return $base . 'login.php' . ($query ? '?' . http_build_query($query) : '');
}


// ---------------------------------------------------------------
// The guard
//
// This page belongs to one portal. If that portal is signed in, the
// page runs as that user. If it is not, the user is sent to sign in
// FOR THAT PORTAL.
//
// It never redirects to the other portal's dashboard. That is what
// made a company tab suddenly show candidate pages.
// ---------------------------------------------------------------
function require_role(string $role): void
{
    $role = strtolower(trim($role));

    if (empty($_SESSION['logins'][$role])) {
        header('Location: ' . login_url($role));
        exit;
    }

    active_portal($role);
}

// For a page either portal may open. Prefers the portal named in the
// URL when both are signed in.
function require_login(string $prefer = ''): void
{
    $prefer = strtolower(trim($prefer));

    if ($prefer !== '' && !empty($_SESSION['logins'][$prefer])) {
        active_portal($prefer);
        return;
    }

    foreach (['candidate', 'company'] as $role) {
        if (!empty($_SESSION['logins'][$role])) {
            active_portal($role);
            return;
        }
    }

    header('Location: ' . login_url());
    exit;
}