<?php

require __DIR__ . '/db.php';
require_once __DIR__ . '/auth-guard.php';
$errors = [];
$old = ['email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $old['email'] = $email;

    if ($email === '' || $password === '') {
        $errors[] = 'Enter your email and password.';
    } else {
        $stmt = $pdo->prepare('SELECT id, password, role FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // One generic message for both cases, so nobody can test which emails exist.
        if (!$user || !password_verify($password, $user['password'])) {
            $errors[] = 'Invalid email or password.';
        } else {
            session_regenerate_id(true);
          set_login_session((int) $user['id'], $user['role']);

            // The role stored at signup decides the portal.
            if ($user['role'] === 'candidate') {
                header('Location: candidate-dashboard.php');
            } else {
                header('Location: company-dashboard.php');
            }
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — CareerBridge</title>
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

            <h1 class="auth-title">Welcome back</h1>
            <p class="auth-sub">Sign in to your candidate or company account.</p>

            <?php if ($errors): ?>
                <div class="alert">
                    <?php foreach ($errors as $error): ?>
                        <p><?= htmlspecialchars($error) ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="post">
                <div class="field">
                    <label for="email">Email address</label>
                    <input type="email" id="email" name="email" placeholder="you@example.com"
                        value="<?= htmlspecialchars($old['email']) ?>" required>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Your password" required>
                </div>

                <button type="submit" class="btn btn-blue btn-full">Sign in</button>
            </form>

            <p class="auth-alt">
                New here? <a href="signup-candidate.php">Join as Candidate</a>
                or <a href="signup-company.php">Join as Company</a>
            </p>

        </div>
    </main>

    <div class="footer">
        <span>&copy; 2025 CareerBridge. All rights reserved.</span>
    </div>

</body>

</html>
