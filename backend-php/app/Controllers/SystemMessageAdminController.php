<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\SystemMessageModel;
use App\Services\AudienceService;
use App\Shared\SessionHelper;

class SystemMessageAdminController extends BaseController
{
    private SystemMessageModel $model;

    public function __construct()
    {
        parent::__construct();
        require __DIR__ . '/../../config/db.php'; // $conn (PDO)
        $this->model = new SystemMessageModel($conn);
    }

    public function index(): void
    {
        $rows = $this->model->listAll();
        $this->render('systemmessages/index', [
            'title' => 'System Messages',
            'rows' => $rows,
        ]);
    }

    // GET form
    public function createForm(): void
    {
        $this->render('systemmessages/create', [
            'title' => 'Create System Message'
        ]);
    }

    public function editForm(): void
    {
        require __DIR__ . '/../../config/db.php';
        $id = (int)($_GET['MessageID'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo 'Missing MessageID';
            return;
        }

        $row = $this->model->getById($id);
        if (!$row) {
            http_response_code(404);
            echo 'Message not found';
            return;
        }

        $this->render('systemmessages/edit', [
            'title' => 'Edit System Message',
            'msg' => $row,
            'codes' => $this->fetchCodes($conn, $id),
            'roles' => $this->fetchRoles($conn, $id),
            'users' => $this->fetchUsers($conn, $id),
        ]);
    }

    // POST create + (optionally) publish
    public function create(): void
    {
        require __DIR__ . '/../../config/db.php';
        $userId = (int) SessionHelper::get('auth.user_id', 0);
        $startAt = $this->normalizeDateTimeInput($_POST['StartAt'] ?? null) ?? gmdate('Y-m-d H:i:s');
        $endAt = $this->normalizeDateTimeInput($_POST['EndAt'] ?? null);

        $codes = array_filter(array_map('trim', explode(',', $_POST['DataObjectCodes'] ?? '')));
        $roles = array_filter(array_map('trim', explode(',', $_POST['Roles'] ?? '')));
        $users = array_filter(array_map('intval', explode(',', $_POST['UserIDs'] ?? '')));

        $data = [
            'Title'              => $_POST['Title'] ?? '',
            'Body'               => $_POST['Body'] ?? '',
            'Severity'           => $_POST['Severity'] ?? 'info',
            'IsHtml'             => !empty($_POST['IsHtml']) ? 1 : 0,
            'IsDismissible'      => !empty($_POST['IsDismissible']) ? 1 : 0,
            'AudienceGlobal'     => !empty($_POST['AudienceGlobal']) ? 1 : 0,
            'StartAt'            => $startAt,
            'EndAt'              => $endAt,
            'Priority'           => (int)($_POST['Priority'] ?? 10),
            'IncludeDescendants' => !empty($_POST['IncludeDescendants']) ? 1 : 0,
            'ScopeGroupName'     => trim((string)($_POST['ScopeGroupName'] ?? '')),
            'RequiresAck'        => !empty($_POST['RequiresAck']) ? 1 : 0,
            'SendEmail'          => !empty($_POST['SendEmail']) ? 1 : 0,
            'EmailSubject'       => $_POST['EmailSubject'] ?? null,
            'Status'             => (($_POST['Action'] ?? '') === 'publish') ? 'published' : 'draft',
            'CreatedBy'          => $userId,
        ];

        try {
            $id = $this->model->create($data, $codes, $users, $roles);
            header('Location: index.php?route=systemmessages/preview&MessageID=' . $id);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo 'Create failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }
    }

    public function update(): void
    {
        require __DIR__ . '/../../config/db.php';
        $messageId = (int)($_POST['MessageID'] ?? 0);
        if ($messageId <= 0) {
            http_response_code(400);
            echo 'Missing MessageID';
            return;
        }

        $userId = (int) SessionHelper::get('auth.user_id', 0);
        $startAt = $this->normalizeDateTimeInput($_POST['StartAt'] ?? null) ?? gmdate('Y-m-d H:i:s');
        $endAt = $this->normalizeDateTimeInput($_POST['EndAt'] ?? null);

        $codes = array_filter(array_map('trim', explode(',', $_POST['DataObjectCodes'] ?? '')));
        $roles = array_filter(array_map('trim', explode(',', $_POST['Roles'] ?? '')));
        $users = array_filter(array_map('intval', explode(',', $_POST['UserIDs'] ?? '')));

        $data = [
            'Title'              => $_POST['Title'] ?? '',
            'Body'               => $_POST['Body'] ?? '',
            'Severity'           => $_POST['Severity'] ?? 'info',
            'AudienceGlobal'     => !empty($_POST['AudienceGlobal']) ? 1 : 0,
            'StartAt'            => $startAt,
            'EndAt'              => $endAt,
            'IncludeDescendants' => !empty($_POST['IncludeDescendants']) ? 1 : 0,
            'ScopeGroupName'     => trim((string)($_POST['ScopeGroupName'] ?? '')),
            'RequiresAck'        => !empty($_POST['RequiresAck']) ? 1 : 0,
            'SendEmail'          => !empty($_POST['SendEmail']) ? 1 : 0,
            'EmailSubject'       => $_POST['EmailSubject'] ?? null,
            'Status'             => (($_POST['Action'] ?? '') === 'publish') ? 'published' : 'draft',
            'UpdatedBy'          => $userId,
        ];

        try {
            $this->model->updateMessage($messageId, $data, $codes, $users, $roles);
            header('Location: index.php?route=systemmessages/index');
        } catch (\Throwable $e) {
            http_response_code(500);
            echo 'Update failed: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES);
        }
    }

    private function normalizeDateTimeInput(?string $value): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $value = str_replace('T', ' ', $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
            $value .= ':00';
        }

        return $value;
    }

    // GET audience preview
    public function preview(): void
    {
        require __DIR__ . '/../../config/db.php';
        $id = (int)($_GET['MessageID'] ?? 0);
        if (!$id) { http_response_code(400); echo 'Missing MessageID'; return; }

        $row = $this->model->getById($id);
        if (!$row) { http_response_code(404); echo 'Message not found'; return; }

        $aud = new AudienceService($conn);
        $codes = $this->fetchCodes($conn, $id);
        $roles = $this->fetchRoles($conn, $id);
        $users = $this->fetchUsers($conn, $id);
        $ctx = $this->context();

        $uids = $aud->resolveUserIds(
            !empty($row['AudienceGlobal']) || !empty($row['IsGlobal']),
            $codes,
            !empty($row['IncludeDescendants']) || !empty($row['DescendantTarget']),
            $users,
            $roles,
            $ctx['FiscalYearID'] ?: null
        );
        $emails = $aud->resolveEmails($uids);

        $this->render('systemmessages/preview', [
            'title'  => 'Audience Preview',
            'msg'    => $row,
            'counts' => [
                'users'  => count($uids),
                'emails' => count($emails),
            ],
            'sample' => array_slice($emails, 0, 200),
        ]);
    }

    private function fetchCodes($db, int $id): array {
        $q = $db->prepare("SELECT DataObjectCode FROM dbo.tblSystemMessageDataObject WHERE MessageID = :id");
        $q->bindValue(':id', $id, \PDO::PARAM_INT); $q->execute();
        return array_column($q->fetchAll(\PDO::FETCH_ASSOC), 'DataObjectCode');
    }
    private function fetchUsers($db, int $id): array {
        $q = $db->prepare("SELECT UserID FROM dbo.tblSystemMessageUser WHERE MessageID = :id");
        $q->bindValue(':id', $id, \PDO::PARAM_INT); $q->execute();
        return array_map('intval', array_column($q->fetchAll(\PDO::FETCH_ASSOC), 'UserID'));
    }
    private function fetchRoles($db, int $id): array {
        $q = $db->prepare("SELECT RoleName FROM dbo.tblSystemMessageRole WHERE MessageID = :id");
        $q->bindValue(':id', $id, \PDO::PARAM_INT); $q->execute();
        return array_column($q->fetchAll(\PDO::FETCH_ASSOC), 'RoleName');
    }
}
