<?php
declare(strict_types=1);

define('APP_NAME', 'Brew & Bean');
define('APP_BASE_URL', '/coffee_management');
define('APP_ASSET_VERSION', '2');

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_name('coffee_management_session');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => APP_BASE_URL . '/',
    ]);
    session_start();
}

header('Content-Security-Policy: default-src \'self\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'; object-src \'none\'; script-src \'self\'; style-src \'self\'; img-src \'self\'; font-src \'self\'');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), geolocation=(), microphone=()');
header('Cache-Control: no-store, private');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
