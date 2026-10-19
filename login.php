<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

if (isset($_SESSION['user'])) {
    redirect('/dashboard.php');
}

$error = null;
$username = '';
$flashMessage = take_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } elseif ($username === '' || mb_strlen($username) > 80 || $password === '' || strlen($password) > 4096) {
        $error = 'Enter both your username and password.';
    } else {
        try {
            $statement = db()->prepare(
                'SELECT id, full_name, username, password_hash, role, status
                 FROM users WHERE username = :username LIMIT 1'
            );
            $statement->execute(['username' => $username]);
            $user = $statement->fetch();

            if (
                is_array($user)
                && $user['status'] === 'active'
                && password_verify($password, $user['password_hash'])
            ) {
                if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                    $rehash = db()->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :id');
                    $rehash->execute([
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'id' => $user['id'],
                    ]);
                }
                session_regenerate_id(true);
                unset($user['password_hash'], $user['status']);
                $_SESSION['user'] = $user;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                redirect('/dashboard.php');
            }

            $error = 'The username or password is incorrect, or the account is inactive.';
        } catch (Throwable $exception) {
            if ($exception instanceof RuntimeException && !$exception instanceof PDOException) {
                $error = $exception->getMessage();
            } else {
                error_log('[Coffee Management] Login failed: ' . $exception->getMessage());
                $error = 'Sign-in is temporarily unavailable. Check the application error log.';
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(APP_BASE_URL) ?>/assets/css/style.css?v=<?= e(APP_ASSET_VERSION) ?>">
</head>
<body class="login-page">
    <main class="login-layout">
        <section class="login-story">
            <a class="brand brand-light" href="<?= e(APP_BASE_URL) ?>/">
                <span class="brand-mark" aria-hidden="true">B</span>
                <span><strong>Brew &amp; Bean</strong><small>COFFEE MANAGEMENT</small></span>
            </a>
            <div class="story-copy">
                <span class="eyebrow">YOUR CAFE, IN GOOD HANDS</span>
                <h1>A calmer way to run your coffee shop.</h1>
                <p>Keep daily operations, orders and stock in one thoughtful workspace.</p>
            </div>
            <div class="coffee-art" aria-hidden="true"><span></span><i></i><b></b></div>
            <small class="story-foot">LOCAL-FIRST CAFE OPERATIONS</small>
        </section>
        <section class="login-panel">
            <div class="login-form-wrap">
                <span class="eyebrow">WELCOME BACK</span>
                <h2>Sign in to your account</h2>
                <p class="muted">Enter your account details to continue.</p>
                <?php if ($flashMessage !== null): ?>
                    <div class="alert alert-<?= e($flashMessage['type'] ?? 'info') ?>" role="status">
                        <?= e($flashMessage['message'] ?? '') ?>
                    </div>
                <?php endif; ?>
                <?php if ($error !== null): ?>
                    <div class="alert alert-error" role="alert"><?= e($error) ?></div>
                <?php endif; ?>
                <form method="post" action="<?= e(APP_BASE_URL) ?>/login.php" class="login-form">
                    <?= csrf_field() ?>
                    <label for="username">Username</label>
                    <input id="username" name="username" type="text" autocomplete="username" maxlength="80" required value="<?= e($username) ?>">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                    <button class="button button-primary button-wide" type="submit">Sign in <span aria-hidden="true">→</span></button>
                </form>
                <p class="login-register">Need a staff account? <a href="<?= e(APP_BASE_URL) ?>/register.php">Create account</a></p>
                <p class="login-note">New accounts are ready to use immediately with cashier access. The first administrator is created with the local command-line setup utility.</p>
                <a class="back-link" href="<?= e(APP_BASE_URL) ?>/">← Back to home</a>
            </div>
        </section>
    </main>
</body>
</html>
