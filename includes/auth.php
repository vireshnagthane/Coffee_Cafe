<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';

function require_auth(): array
{
    $user = $_SESSION['user'] ?? null;
    if (!is_array($user) || !isset($user['id'], $user['role'])) {
        set_flash('error', 'Please sign in to continue.');
        redirect('/login.php');
    }

    return $user;
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
