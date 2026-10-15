<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$fullName = trim((string) ($argv[1] ?? ''));
$username = trim((string) ($argv[2] ?? ''));
$password = getenv('COFFEE_ADMIN_PASSWORD');

if ($fullName === '' || $username === '' || !is_string($password) || $password === '') {
    fwrite(STDERR, "Usage: set COFFEE_ADMIN_PASSWORD securely, then run create_admin.php \"Full Name\" username\n");
    exit(1);
}
if (strlen($fullName) > 150 || strlen($username) > 80 || strlen($password) < 12) {
    fwrite(STDERR, "Name/username is too long, or the password is shorter than 12 characters.\n");
    exit(1);
}

try {
    $pdo = db();
    $lock = $pdo->query(
        "SELECT GET_LOCK('coffee_management_admin_bootstrap', 10)"
    )->fetchColumn();
    if ((int) $lock !== 1) {
        throw new RuntimeException('Could not acquire the administrator setup lock.');
    }

    $existingAdmin = $pdo->query(
        "SELECT id FROM users WHERE role = 'administrator' LIMIT 1"
    )->fetchColumn();

    if ($existingAdmin !== false) {
        fwrite(STDERR, "An administrator account already exists. No account was created.\n");
        exit(1);
    }

    $statement = $pdo->prepare(
        "INSERT INTO users (full_name, username, password_hash, role, status)
         VALUES (:full_name, :username, :password_hash, 'administrator', 'active')"
    );
    $statement->execute([
        'full_name' => $fullName,
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);
    fwrite(STDOUT, "Administrator account created successfully.\n");
} catch (Throwable $exception) {
    error_log('[Coffee Management] Administrator bootstrap failed: ' . $exception->getMessage());
    fwrite(STDERR, "Could not create the administrator. Check the database settings and PHP error log.\n");
    exit(1);
} finally {
    putenv('COFFEE_ADMIN_PASSWORD');
    if (isset($pdo)) {
        $pdo->query("SELECT RELEASE_LOCK('coffee_management_admin_bootstrap')");
    }
}
