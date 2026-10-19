<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

if (isset($_SESSION['user'])) {
    redirect('/dashboard.php');
}

$error = null;
$fullName = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullNameInput = $_POST['full_name'] ?? null;
    $usernameInput = $_POST['username'] ?? null;
    $passwordInput = $_POST['password'] ?? null;
    $passwordConfirmationInput = $_POST['password_confirmation'] ?? null;
    $fullName = is_string($fullNameInput) ? trim($fullNameInput) : '';
    $username = is_string($usernameInput) ? trim($usernameInput) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';
    $passwordConfirmation = is_string($passwordConfirmationInput) ? $passwordConfirmationInput : '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your form expired. Refresh the page and try again.';
    } elseif (
        $fullName === ''
        || mb_strlen($fullName) > 150
        || !preg_match('/^[A-Za-z0-9_.-]{3,80}$/', $username)
        || strlen($password) < 12
        || strlen($password) > 4096
    ) {
        $error = 'Enter your name, a username (3–80 letters/numbers/._-), and a password between 12 and 4096 characters.';
    } elseif ($password !== $passwordConfirmation) {
        $error = 'The passwords do not match.';
    } else {
        try {
            $statement = db()->prepare(
                "INSERT INTO users (full_name, username, password_hash, role, status)
                 VALUES (:full_name, :username, :password_hash, 'cashier', 'active')"
            );
            $statement->execute([
                'full_name' => $fullName,
                'username' => $username,
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);

            session_regenerate_id(true);
            $_SESSION['user'] = [
                'id' => (int) db()->lastInsertId(),
                'full_name' => $fullName,
                'username' => $username,
                'role' => 'cashier',
            ];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            set_flash('success', 'Your cashier account is ready. Welcome!');
            redirect('/dashboard.php');
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $error = 'That username is already in use. Choose another username.';
            } else {
                error_log('[Coffee Management] Account registration failed: ' . $exception->getMessage());
                $error = 'Account registration is temporarily unavailable. Check the application error log.';
            }
        } catch (Throwable $exception) {
            error_log('[Coffee Management] Account registration failed: ' . $exception->getMessage());
            $error = 'Account registration is temporarily unavailable. Check the application error log.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Create account · <?= e(APP_NAME) ?></title>
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
                <span class="eyebrow">JOIN YOUR CAFE TEAM</span>
                <h1>Your day, thoughtfully organized.</h1>
                <p>Create a cashier account for the Brew &amp; Bean cafe workspace.</p>
            </div>
            <div class="coffee-art" aria-hidden="true"><span></span><i></i><b></b></div>
            <small class="story-foot">LOCAL-FIRST CAFE OPERATIONS</small>
        </section>
        <section class="login-panel">
            <div class="login-form-wrap">
                <span class="eyebrow">NEW STAFF ACCOUNT</span>
                <h2>Create your account</h2>
                <p class="muted">Create a cashier account and start using your cafe workspace right away.</p>
                <?php if ($error !== null): ?>
                    <div class="alert alert-error" role="alert"><?= e($error) ?></div>
                <?php endif; ?>
                <form method="post" action="<?= e(APP_BASE_URL) ?>/register.php" class="login-form">
                    <?= csrf_field() ?>
                    <label for="full-name">Full name</label>
                    <input id="full-name" name="full_name" type="text" autocomplete="name" maxlength="150" required value="<?= e($fullName) ?>">
                    <label for="username">Username</label>
                    <input id="username" name="username" type="text" autocomplete="username" minlength="3" maxlength="80" pattern="[A-Za-z0-9_.\-]+" required value="<?= e($username) ?>">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="4096" required>
                    <label for="password-confirmation">Confirm password</label>
                    <input id="password-confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="4096" required>
                    <p class="form-hint">Use at least 12 characters. New accounts have cashier access; administrator access is not available through public registration.</p>
                    <button class="button button-primary button-wide" type="submit">Create account <span aria-hidden="true">→</span></button>
                </form>
                <p class="login-register">Already have an account? <a href="<?= e(APP_BASE_URL) ?>/login.php">Sign in</a></p>
                <a class="back-link" href="<?= e(APP_BASE_URL) ?>/">← Back to home</a>
            </div>
        </section>
    </main>
</body>
</html>
