<?php
// Candidate registration.
//
// auth-guard.php starts the session with the application-wide cookie
// path, so this file must NOT call session_start() itself. Calling it
// first would create the cookie with the default path (the script's
// own directory) and the session would be invisible from other
// folders.
require __DIR__ . '/db.php';
require_once __DIR__ . '/auth-guard.php';

$errors = [];
$old = ['name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    $old['name']  = $name;
    $old['email'] = $email;

    if ($name === '') {
        $errors[] = 'Full name is required.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    }
    if (strlen($password) < 8) {
        $errors[] = 'Password must be at least 8 characters.';
    }
    if ($password !== $confirm) {
        $errors[] = 'Passwords do not match.';
    }
    if (!isset($_POST['terms'])) {
        $errors[] = 'You must accept the Terms.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);

        if ($stmt->fetch()) {
            $errors[] = 'That email is already registered.';
        } else {
            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    'INSERT INTO users (email, password, role) VALUES (?, ?, ?)'
                );
                $stmt->execute([
                    $email,
                    password_hash($password, PASSWORD_DEFAULT),
                    'candidate',
                ]);

                $userId = (int) $pdo->lastInsertId();

                $stmt = $pdo->prepare(
                    'INSERT INTO candidate_profiles (user_id, full_name) VALUES (?, ?)'
                );
                $stmt->execute([$userId, $name]);

                $pdo->commit();

                // Signs into the candidate portal only, leaving any
                // company login in this browser alone. It regenerates
                // the session id itself, so do not call
                // session_regenerate_id() here as well.
                set_login_session($userId, 'candidate');

                header('Location: candidate-dashboard.php');
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $errors[] = 'Could not create the account. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up as Candidate — CareerBridge</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/landingpage.css">
    <link rel="stylesheet" href="../css/auth.css">
</head>

<body>

    <header class="nav">
        <div class="container nav-inner">
            <div class="logo">
                <a href="../html/landingpage.html">Career<span class="blue">Bridge</span></a>
            </div>

            <nav class="nav-links">
                <a href="../html/landingpage.html">Home</a>
                <a href="../html/how-it-works.html">How It Works</a>
            </nav>
        </div>
    </header>

    <main class="auth">
        <div class="auth-card">

            <h1 class="auth-title">Create your account</h1>
            <p class="auth-sub">Build your profile, apply for jobs, and take assessments.</p>

            <div class="auth-tabs">
                <a href="signup-candidate.php" class="tab on">Candidate</a>
                <a href="signup-company.php" class="tab">Company</a>
            </div>

            <?php if ($errors): ?>
                <div class="alert">
                    <?php foreach ($errors as $error): ?>
                        <p><?= htmlspecialchars($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form action="signup-candidate.php" method="post">
                <div class="field">
                    <label for="name">Full name</label>
                    <input type="text" id="name" name="name" placeholder="Your full name"
                        value="<?= htmlspecialchars($old['name']) ?>" required>
                </div>

                <div class="field">
                    <label for="email">Email address</label>
                    <input type="email" id="email" name="email" placeholder="you@example.com"
                        value="<?= htmlspecialchars($old['email']) ?>" required>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="At least 8 characters" required>
                </div>

                <div class="field">
                    <label for="confirm">Confirm password</label>
                    <input type="password" id="confirm" name="confirm" placeholder="Re-enter your password" required>
                </div>

                <div class="check">
                    <input type="checkbox" id="terms" name="terms" required>
                    <label for="terms">I agree to the <a href="#">Terms</a> and <a href="#">Privacy Policy</a>.</label>
                </div>

                <button type="submit" class="btn btn-blue btn-full">Create candidate account</button>
            </form>

            <p class="auth-alt">Already have an account? <a href="login.php">Sign in</a></p>

        </div>
    </main>

    <div class="footer">
        <span>&copy; 2025 CareerBridge. All rights reserved.</span>
    </div>

</body>

</html>