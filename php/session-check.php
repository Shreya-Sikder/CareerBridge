<?php
// Diagnostic page. Open it in a browser while logged in:
//
//   http://localhost/careerbridge/php/session-check.php
//
// It reports what the session actually contains, which is the fastest
// way to find why a logged-in user is being bounced to sign-in.
//
// DELETE THIS FILE before you hand the project in. It exposes session
// internals.

require_once __DIR__ . '/auth-guard.php';

header('Content-Type: text/plain; charset=utf-8');

echo "CareerBridge session check\n";
echo str_repeat('=', 46) . "\n\n";

echo "session status      : " . (session_status() === PHP_SESSION_ACTIVE ? 'active' : 'NOT ACTIVE') . "\n";
echo "session id          : " . (session_id() ?: '(none)') . "\n";
echo "cookie path         : " . (session_get_cookie_params()['path'] ?? '?') . "\n";
echo "cookie received     : " . (isset($_COOKIE[session_name()]) ? 'yes' : 'NO - the browser sent no session cookie') . "\n";
echo "headers already sent: ";

$file = '';
$line = 0;
if (headers_sent($file, $line)) {
    echo "YES, from $file line $line\n";
    echo "                      ^ this is the bug. Output before the session\n";
    echo "                        cookie is sent stops the cookie being set.\n";
} else {
    echo "no\n";
}

echo "\n";
echo "logged in           : " . (is_logged_in() ? 'yes' : 'NO') . "\n";
echo "user_id             : " . (current_user_id() ?: '(empty)') . "\n";
echo "role                : " . (current_role() ?: '(empty)') . "\n";

echo "\nEverything in \$_SESSION:\n";
if (!$_SESSION) {
    echo "  (empty)\n";
} else {
    foreach ($_SESSION as $key => $value) {
        printf("  %-20s %s\n", $key, is_scalar($value) ? $value : gettype($value));
    }
}

echo "\n";
echo str_repeat('-', 46) . "\n";

if (!is_logged_in()) {
    echo "Not logged in. If you ARE logged in in another tab, then your\n";
    echo "login.php is storing the id under a name this file does not\n";
    echo "recognise. Look at the \$_SESSION list above and add that name\n";
    echo "to the normalisation list in auth-guard.php, or change\n";
    echo "login.php to call set_login_session(\$id, \$role).\n";
} elseif (current_role() === '') {
    echo "Logged in but no role, so require_role() will always fail.\n";
    echo "login.php must store the role as well as the id.\n";
} else {
    echo "Session looks correct. Navigation should not bounce you to\n";
    echo "sign-in. If it still does, the page you landed on is either\n";
    echo "missing (a 404 that your server rewrites) or calls\n";
    echo "require_role() for the other role.\n";
}