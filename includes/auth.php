<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';

function require_auth(): array
{
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user) || !isset($user['id'], $user['role'])) {
        set_flash('error', 'Please sign in to continue.');
        redirect('/login.php');
    }

    try {
        $statement = db()->prepare(
            "SELECT id, full_name, username, role, status
             FROM users WHERE id = :id LIMIT 1"
        );
        $statement->execute(['id' => $user['id']]);
        $activeUser = $statement->fetch();
    } catch (Throwable $exception) {
        error_log('[Coffee Management] Session authorization check failed: ' . $exception->getMessage());
        http_response_code(503);
        exit('Sign-in verification is temporarily unavailable. Please try again.');
    }

    if (!is_array($activeUser) || $activeUser['status'] !== 'active') {
        $_SESSION = [];
        session_regenerate_id(true);
        set_flash('error', 'Your account is no longer active. Please sign in again.');
        redirect('/login.php');
    }

    unset($activeUser['status']);
    $_SESSION['user'] = $activeUser;

    return $activeUser;
}

function require_role(string ...$roles): array
{
    $user = require_auth();
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('You do not have permission to access this page.');
    }

    return $user;
}
