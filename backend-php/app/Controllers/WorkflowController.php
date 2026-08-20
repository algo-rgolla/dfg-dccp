<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Shared\SessionHelper;
use App\Models\WorkflowTaskModel;
use App\Models\WorkflowTaskTypeModel;
use App\Models\WorkflowTaskStatusModel;
use App\Models\UserModel;
use App\Models\AuditModel;
use App\Models\SystemSettingsModel;
use App\Services\MailService;

final class WorkflowController extends BaseController
{
    protected array $acl = [
        '*'      => ['auth' => true, 'permsAny' => ['WORKFLOW_ADMIN']],
        'list'   => ['auth' => true, 'permsAny' => ['WORKFLOW_VIEW','WORKFLOW_ADMIN']],
        'edit'   => ['auth' => true, 'permsAny' => ['WORKFLOW_EDIT','WORKFLOW_ADMIN']],
        'save'   => ['auth' => true, 'permsAny' => ['WORKFLOW_EDIT','WORKFLOW_ADMIN']],
        'delete' => ['auth' => true, 'permsAny' => ['WORKFLOW_ADMIN']],
    ];

    public function __construct()
    {
        parent::__construct();
    }

    /** List tasks (supports iframe=1 + status=open) */
    public function list(): void
    {
        require __DIR__ . '/../../config/db.php';

        $tasksModel    = new WorkflowTaskModel($conn);
        $typesModel    = new WorkflowTaskTypeModel($conn);
        $statusesModel = new WorkflowTaskStatusModel($conn);

        $userID   = (int)SessionHelper::get('auth.user_id', 0);
        $q        = trim((string)($_GET['q'] ?? ''));
        $typeID   = ($_GET['typeID'] ?? '') !== '' ? (int)$_GET['typeID'] : null;
        $statusID = ($_GET['statusID'] ?? '') !== '' ? (int)$_GET['statusID'] : null;

        // special “open” filter for home page widgets
        $statusFlag = (string)($_GET['status'] ?? ''); // 'open' or ''
        $onlyOpen   = ($statusFlag === 'open');

        $page     = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = max(1, min(100, (int)($_GET['pageSize'] ?? 10)));

        $res    = $tasksModel->listByUser($userID, $page, $pageSize, $q, $typeID, $statusID);
        $tasks  = $res['items'] ?? [];
        $total  = (int)($res['total'] ?? 0);

        // If the model doesn’t have “open” semantics, apply a conservative open filter:
        if ($onlyOpen) {
            $closedNames = ['closed','complete','completed','cancelled','done','resolved'];
            $tasks = array_values(array_filter($tasks, static function ($t) use ($closedNames) {
                $statusName   = strtolower((string)($t['StatusName'] ?? ''));
                $isClosedName = in_array($statusName, $closedNames, true);
                $isClosedFlag = isset($t['StatusIsClosed']) ? (bool)$t['StatusIsClosed'] : false;
                $isCompleted  = !empty($t['CompletedAt']);
                return !$isClosedName && !$isClosedFlag && !$isCompleted;
            }));
            // We filtered the current page; keep pagination simple
            $total = count($tasks);
        }

        $totalPages = (int)max(1, ceil(($total ?: 0) / ($pageSize ?: 1)));

        $params = [
            'title'      => __t('workflow_tasks'),
            'tasks'      => $tasks,
            'total'      => $total,
            'totalPages' => $totalPages,
            'page'       => $page,
            'pageSize'   => $pageSize,
            'q'          => $q,
            'typeID'     => $typeID,
            'statusID'   => $statusID,
            'statuses'   => $statusesModel->listActive(),
            'types'      => $typesModel->listActive(),
            'flash'      => SessionHelper::get('flash.message', null),
        ];

        $isIframe = !empty($_GET['iframe']);
        if ($isIframe) {
            // render WITHOUT main layout so the menu header doesn’t appear
            $this->renderPartial('workflow/WorkflowList', $params);
        } else {
            $this->render('workflow/WorkflowList', $params);
        }

        // clear flash if present
        if (SessionHelper::has('flash.message')) {
            SessionHelper::forget('flash.message');
        }
    }

    /** Edit task (form) */
    public function edit(): void
    {
        require __DIR__ . '/../../config/db.php';

        $id = (int)($_GET['id'] ?? 0);

        $tasksModel    = new WorkflowTaskModel($conn);
        $typesModel    = new WorkflowTaskTypeModel($conn);
        $statusesModel = new WorkflowTaskStatusModel($conn);
        $userModel     = new UserModel($conn);

        $task  = $id > 0 ? $tasksModel->find($id) : null;

        $users = method_exists($userModel, 'listAll')
            ? $userModel->listAll()
            : $userModel->all(1, 500, '');

        $params = [
            'title'    => $id > 0 ? __t('edit_task') : __t('create_task'),
            'task'     => $task,
            'types'    => $typesModel->listActive(),
            'statuses' => $statusesModel->listActive(),
            'users'    => $users,
            'flash'    => SessionHelper::get('flash.message', null),
        ];

        $isIframe = !empty($_GET['iframe']);
        if ($isIframe) {
            // important: no main layout in iframe
            $this->renderPartial('workflow/WorkflowForm', $params);
        } else {
            $this->render('workflow/WorkflowForm', $params);
        }

        if (SessionHelper::has('flash.message')) {
            SessionHelper::forget('flash.message');
        }
    }

    /** Save task */
    public function save(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }

        require __DIR__ . '/../../config/db.php';
        $tasksModel    = new WorkflowTaskModel($conn);
        $audit         = new AuditModel($conn);
        $userModel     = new UserModel($conn);
        $settingsModel = new SystemSettingsModel($conn);

        $id   = (int)($_POST['WorkflowTaskID'] ?? 0);
        $data = [
            'TaskTypeID'       => (int)($_POST['TaskTypeID'] ?? 0),
            'StatusID'         => (int)($_POST['StatusID'] ?? 0),
            'Title'            => trim((string)($_POST['Title'] ?? '')),
            'Description'      => trim((string)($_POST['Description'] ?? '')),
            'CreatedByUserID'  => (int)SessionHelper::get('auth.user_id', 0),
            'AssignedToUserID' => ($_POST['AssignedToUserID'] ?? '') !== '' ? (int)$_POST['AssignedToUserID'] : null,
            'RelatedEntity'    => trim((string)($_POST['RelatedEntity'] ?? '')),
            'RelatedKey'       => trim((string)($_POST['RelatedKey'] ?? '')),
            'DueDate'          => ($_POST['DueDate'] ?? '') !== '' ? $_POST['DueDate'] : null,
            'UpdatedBy'        => (int)SessionHelper::get('auth.user_id', 0),
        ];

        // validation
        $errors = [];
        if ($data['Title'] === '')                $errors[] = __t('title_required');
        if ($data['Description'] === '')          $errors[] = __t('description_required');
        if ($data['TaskTypeID'] <= 0)             $errors[] = __t('task_type_required');
        if ($data['StatusID'] <= 0)               $errors[] = __t('status_required');
        if ($data['AssignedToUserID'] === null)   $errors[] = __t('assigned_to_required');
        if ($data['DueDate'] === null)            $errors[] = __t('due_date_required');

        // navigation context (preserve filters and iframe on redirect)
        $q        = (string)($_POST['q'] ?? '');
        $page     = (int)($_POST['page'] ?? 1);
        $pageSize = (int)($_POST['pageSize'] ?? 10);
        $typeID   = ($_POST['typeID']   ?? '') !== '' ? (int)$_POST['typeID']   : null;
        $statusID = ($_POST['statusID'] ?? '') !== '' ? (int)$_POST['statusID'] : null;
        $status   = (string)($_POST['status'] ?? '');
        $isIframe = !empty($_POST['iframe']);

        if ($errors) {
            $this->flashError(implode('<br>', $errors));

            $qs = [
                'route'    => 'workflow/edit',
                'q'        => $q,
                'page'     => $page,
                'pageSize' => $pageSize,
            ];
            if ($id > 0)         { $qs['id']      = (string)$id; }
            if ($typeID !== null){ $qs['typeID']  = (string)$typeID; }
            if ($statusID!==null){ $qs['statusID']= (string)$statusID; }
            if ($status !== '')  { $qs['status']  = $status; }
            if ($isIframe)       { $qs['iframe']  = '1'; }

            header('Location: index.php?' . http_build_query($qs));
            exit;
        }

        try {
            $action = $id > 0 ? 'UPDATE' : 'CREATE';

            if ($id > 0) {
                $tasksModel->update($id, $data);
                $this->flashSuccess(__t('task_updated', ['task' => $data['Title']]));
            } else {
                $tasksModel->create($data);
                $this->flashSuccess(__t('task_created', ['task' => $data['Title']]));
            }

            // Audit log
            $audit->insert([
                'UserID'       => SessionHelper::get('auth.user_id'),
                'Username'     => SessionHelper::get('auth.username', 'guest'),
                'Action'       => $action,
                'Entity'       => 'WorkflowTask',
                'EntityKey'    => $id > 0 ? (string)$id : $data['Title'],
                'IPAddress'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'Details'      => $data,
                'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                'VersionID'    => SessionHelper::get('VersionID'),
            ]);

            // Email notify assignee
            if (!empty($data['AssignedToUserID'])) {
                $assigned   = $userModel->findById((int)$data['AssignedToUserID']);
                $createdBy  = $userModel->findById((int)$data['CreatedByUserID']);
                $taskType   = (new WorkflowTaskTypeModel($conn))->findNameById($data['TaskTypeID']);
                $statusName = (new WorkflowTaskStatusModel($conn))->findNameById($data['StatusID']);

                if ($assigned && !empty($assigned['Email'])) {
                    $subject = $action === 'CREATE'
                        ? 'You have been assigned a new CBMS Task'
                        : 'A CBMS Task assigned to you has been edited';

                    $appUrl  = rtrim($settingsModel->get('APP_URL', 'http://localhost/CBMSv21'), '/');
                    $appUrl  = (string)preg_replace('#/backend-php/public$#i', '', $appUrl);
                    $taskUrl = $appUrl . "/index.php?route=workflow/edit&id=" . ($id > 0 ? $id : '');

                    $body = "
                        <p>Dear " . htmlspecialchars($assigned['DisplayName'] ?? $assigned['Username']) . ",</p>
                        <p>The following task has been " . strtolower($action === 'CREATE' ? 'created and assigned to you' : 'updated') . ":</p>
                        <ul>
                          <li><strong>Title:</strong> " . htmlspecialchars($data['Title']) . "</li>
                          <li><strong>Description:</strong> " . nl2br(htmlspecialchars($data['Description'])) . "</li>
                          <li><strong>Task Type:</strong> " . htmlspecialchars($taskType ?? '-') . "</li>
                          <li><strong>Status:</strong> " . htmlspecialchars($statusName ?? '-') . "</li>
                          <li><strong>Due Date:</strong> " . htmlspecialchars($data['DueDate'] ?? '-') . "</li>
                          <li><strong>Created By:</strong> " . htmlspecialchars($createdBy['DisplayName'] ?? $createdBy['Username'] ?? '-') . "</li>
                        </ul>
                        <p><a href=\"$taskUrl\">Click here to view this task in CBMS</a></p>
                    ";

                    try {
                        $mailer = new MailService($conn);
                        $mailer->sendEmail(
                            $assigned['Email'],
                            $subject,
                            $body,
                            $settingsModel->get('ERROR_EMAIL_FROM', 'noreply@cbmsv2.local')
                        );
                        app_log("[WorkflowController@save] Email sent", [
                            'AssignedToUserID' => $data['AssignedToUserID'],
                            'Email'            => $assigned['Email'],
                            'Action'           => $action,
                        ], 'info');
                    } catch (\Throwable $mailErr) {
                        $this->flashError(__t('task_email_failed') . ': ' . $mailErr->getMessage());
                        app_log("[WorkflowController@save] Mail failed: " . $mailErr->getMessage(), [
                            'AssignedToUserID' => $data['AssignedToUserID'],
                            'Email'            => $assigned['Email'] ?? null,
                        ], 'error');
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->flashError(__t('task_save_failed') . ': ' . $e->getMessage());
            app_log("[WorkflowController@save] Exception", ['error' => $e->getMessage()], 'error');
        }

        // Redirect back to list, preserving filters & iframe flag
        $qs = [
            'route'    => 'workflow/list',
            'q'        => $q,
            'page'     => $page,
            'pageSize' => $pageSize,
        ];
        if ($typeID !== null) { $qs['typeID']   = (string)$typeID; }
        if ($statusID!==null) { $qs['statusID'] = (string)$statusID; }
        if ($status !== '')   { $qs['status']   = $status; }
        if ($isIframe)        { $qs['iframe']   = '1'; }

        header('Location: index.php?' . http_build_query($qs));
        exit;
    }

    /** Delete task */
    public function delete(): void
    {
        require __DIR__ . '/../../config/db.php';
        $tasksModel = new WorkflowTaskModel($conn);
        $audit      = new AuditModel($conn);

        $id = (int)($_GET['id'] ?? 0);
        if ($id <= 0) {
            $this->flashError(__t('invalid_task'));
            header('Location: index.php?route=workflow/list');
            exit;
        }

        try {
            $tasksModel->delete($id);
            $this->flashSuccess(__t('task_deleted', ['id' => $id]));

            $audit->insert([
                'UserID'       => SessionHelper::get('auth.user_id'),
                'Username'     => SessionHelper::get('auth.username', 'guest'),
                'Action'       => 'DELETE',
                'Entity'       => 'WorkflowTask',
                'EntityKey'    => (string)$id,
                'IPAddress'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'Details'      => ['Message' => "Task $id deleted"],
                'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                'VersionID'    => SessionHelper::get('VersionID'),
            ]);
        } catch (\Throwable $e) {
            $this->flashError(__t('task_delete_failed') . ': ' . $e->getMessage());
        }

        header('Location: index.php?route=workflow/list');
        exit;
    }
}
