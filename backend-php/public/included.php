<?php
declare(strict_types=1);
require_once __DIR__ . '/../shared/env.php';
require_once __DIR__ . '/../shared/SessionHelper.php';
require_once __DIR__ . '/../shared/csrf.php';
loadEnv(__DIR__ . '/../.env');
header('Content-Type: text/plain; charset=UTF-8');
echo implode(PHP_EOL, get_included_files());
