<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\DataObjectWorkflowStatusModel;
use App\Shared\SessionHelper;

class DataObjectWorkflowController extends BaseController
{
    private DataObjectWorkflowStatusModel $model;

    public function __construct()
    {
        parent::__construct();

        // Explicitly load DB
        require __DIR__ . '/../../config/db.php'; // defines $conn (PDO)
        $this->model = new DataObjectWorkflowStatusModel($conn);
    }

    // GET current status
    public function getStatus(): void
    {
        $fy   = (int)($_GET['FiscalYearID'] ?? 0);
        $ver  = (int)($_GET['VersionID'] ?? 0);
        $code = $_GET['DataObjectCode'] ?? '';

        if (!$fy || !$ver || !$code) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing parameters']);
            return;
        }

        $status = $this->model->getStatus($fy, $ver, $code);
        if ($status === null) {
            http_response_code(404);
            echo json_encode(['warning' => 'No workflow status found', 'status' => 'Not Set']);
            return;
        }

        echo json_encode(['status' => $status]);
    }

    // POST update status
public function setStatus(): void
{
    $fy     = (int)($_POST['FiscalYearID'] ?? 0);
    $ver    = (int)($_POST['VersionID'] ?? 0);
    $code   = $_POST['DataObjectCode'] ?? '';
    $status = $_POST['Status'] ?? '';
    $userId = (int) SessionHelper::get('auth.user_id', 0); // ✅ Use numeric UserID

    if (!$fy || !$ver || !$code || !$status || !$userId) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing parameters']);
        return;
    }

    try {
        $this->model->setStatus($fy, $ver, $code, $status, $userId);
        echo json_encode(['success' => true, 'status' => $status]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'error'   => 'Failed to update status',
            'details' => $e->getMessage(),
        ]);
    }
}

}
