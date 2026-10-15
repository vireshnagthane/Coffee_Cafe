<?php
declare(strict_types=1);

require_once __DIR__ . '/config/app.php';

redirect(isset($_SESSION['user']) ? '/dashboard.php' : '/login.php');
