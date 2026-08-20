<?php
declare(strict_types=1);

require __DIR__ . '/../config/db.php';
require __DIR__ . '/Models/RoleModel.php';

$roleModel = new App\Models\RoleModel($conn);
$roles = $roleModel->listAll();
var_dump($roles);
echo 'Last error: ' . $roleModel->getLastError();