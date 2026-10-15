<?php
declare(strict_types=1);

/**
 * Returns the shared PDO connection to the application database.
 *
 * Override the local defaults with COFFEE_DB_HOST, COFFEE_DB_NAME,
 * COFFEE_DB_USER, and COFFEE_DB_PASSWORD environment variables.
 */
function db(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $host = getenv('COFFEE_DB_HOST') ?: 'localhost';
    $database = getenv('COFFEE_DB_NAME') ?: 'coffee_management';
    $username = getenv('COFFEE_DB_USER') ?: 'root';
    $password = getenv('COFFEE_DB_PASSWORD');
    $password = $password === false ? '' : $password;

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=utf8mb4',
        $host,
        $database
    );

    try {
        $connection = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $exception) {
        error_log('[Coffee Management] Database connection failed: ' . $exception->getMessage());
        throw new RuntimeException(
            'Unable to connect to the application database. Check the database settings and confirm that MySQL is running.'
        );
    }

    return $connection;
}
