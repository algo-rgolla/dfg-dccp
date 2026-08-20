<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\AuditModel;
use App\Models\WorkflowApprovalRuleModel;
use App\Shared\SessionHelper;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require_once __DIR__ . '/../../shared/csrf.php';

final class WorkflowApprovalRulesController extends BaseController
{
    protected array $acl = [
        '*' => ['auth' => true, 'permsAny' => ['WORKFLOW_ADMIN', 'ADMIN_ALL']],
        'list' => ['auth' => true, 'permsAny' => ['WORKFLOW_VIEW', 'WORKFLOW_ADMIN', 'ADMIN_ALL']],
        'edit' => ['auth' => true, 'permsAny' => ['WORKFLOW_ADMIN', 'ADMIN_ALL']],
        'save' => ['auth' => true, 'permsAny' => ['WORKFLOW_ADMIN', 'ADMIN_ALL']],
        'delete' => ['auth' => true, 'permsAny' => ['WORKFLOW_ADMIN', 'ADMIN_ALL']],
        'upload' => ['auth' => true, 'permsAny' => ['WORKFLOW_ADMIN', 'ADMIN_ALL']],
        'uploadProcess' => ['auth' => true, 'permsAny' => ['WORKFLOW_ADMIN', 'ADMIN_ALL']],
        'downloadTemplate' => ['auth' => true, 'permsAny' => ['WORKFLOW_ADMIN', 'ADMIN_ALL']],
        'downloadUploadReport' => ['auth' => true, 'permsAny' => ['WORKFLOW_ADMIN', 'ADMIN_ALL']],
    ];

    public function __construct()
    {
        parent::__construct();
    }

    public function list(): void
    {
        require __DIR__ . '/../../config/db.php';
        $model = new WorkflowApprovalRuleModel($conn);
        $sessionKey = 'admin.workflow_approval_rules.filters';
        $filterKeys = ['applicationTypeId', 'employeeGroup', 'approverType', 'active'];
        $savedFilters = SessionHelper::get($sessionKey);
        $savedFilters = is_array($savedFilters) ? $savedFilters : [];

        if ((string)($_GET['reset'] ?? '') === '1') {
            SessionHelper::forget($sessionKey);
            $savedFilters = [];
        }

        $hasExplicitFilterInput = false;
        foreach ($filterKeys as $key) {
            if (array_key_exists($key, $_GET)) {
                $hasExplicitFilterInput = true;
                break;
            }
        }

        if ($hasExplicitFilterInput) {
            $filters = [
                'applicationTypeId' => (string)((int)($_GET['applicationTypeId'] ?? 0)),
                'employeeGroup' => trim((string)($_GET['employeeGroup'] ?? '')),
                'approverType' => trim((string)($_GET['approverType'] ?? '')),
                'active' => ($_GET['active'] ?? '') !== '' ? (string)$_GET['active'] : '',
            ];
            if ($filters['applicationTypeId'] === '0') {
                $filters['applicationTypeId'] = '';
            }
            SessionHelper::set($sessionKey, $filters);
        } else {
            $filters = [
                'applicationTypeId' => (string)($savedFilters['applicationTypeId'] ?? ''),
                'employeeGroup' => trim((string)($savedFilters['employeeGroup'] ?? '')),
                'approverType' => trim((string)($savedFilters['approverType'] ?? '')),
                'active' => (string)($savedFilters['active'] ?? ''),
            ];
        }

        $applicationTypeId = (int)($filters['applicationTypeId'] ?? 0);
        $employeeGroup = trim((string)($filters['employeeGroup'] ?? ''));
        $approverType = trim((string)($filters['approverType'] ?? ''));
        $active = (string)($filters['active'] ?? '');

        $rows = $model->listAll(
            $applicationTypeId > 0 ? $applicationTypeId : null,
            $employeeGroup !== '' ? $employeeGroup : null,
            $approverType !== '' ? $approverType : null,
            $active
        );

        $this->render('admin/WorkflowApprovalRuleList', [
            'title' => 'Workflow Approval Rules',
            'rows' => $rows,
            'filters' => $filters,
            'applicationTypes' => $model->listApplicationTypes(),
            'employeeGroups' => $model->listDistinctEmployeeGroups(),
            'approverTypes' => $model->listDistinctApproverTypes(),
            '_csrf' => csrf_token(),
        ]);
    }

    public function edit(): void
    {
        require __DIR__ . '/../../config/db.php';
        $model = new WorkflowApprovalRuleModel($conn);

        $id = (int)($_GET['id'] ?? 0);
        $row = $id > 0 ? $model->find($id) : null;
        if ($id > 0 && $row === null) {
            $this->flashError('Workflow approval rule not found.');
            header('Location: index.php?route=admin/workflow-approval-rules');
            exit;
        }

        $this->render('admin/WorkflowApprovalRuleForm', [
            'title' => $id > 0 ? 'Edit Workflow Approval Rule' : 'Add Workflow Approval Rule',
            'row' => $row,
            'applicationTypes' => $model->listApplicationTypes(),
            '_csrf' => csrf_token(),
        ]);
    }

    public function save(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }
        if (!csrf_check($_POST['_csrf'] ?? '')) {
            $this->flashError('Security check failed.');
            header('Location: index.php?route=admin/workflow-approval-rules');
            exit;
        }

        require __DIR__ . '/../../config/db.php';
        $model = new WorkflowApprovalRuleModel($conn);
        $audit = new AuditModel($conn);

        $id = (int)($_POST['RuleID'] ?? 0);
        $maxLimitRaw = trim((string)($_POST['MaxLimit'] ?? ''));
        $data = [
            'ApplicationTypeID' => (int)($_POST['ApplicationTypeID'] ?? 0),
            'EmployeeGroup' => trim((string)($_POST['EmployeeGroup'] ?? '')),
            'ApprovalStage' => max(1, (int)($_POST['ApprovalStage'] ?? 1)),
            'MinLimit' => (float)($_POST['MinLimit'] ?? 0),
            'MaxLimit' => $maxLimitRaw !== '' ? (float)$maxLimitRaw : null,
            'RequiredApproverType' => strtoupper(trim((string)($_POST['RequiredApproverType'] ?? ''))),
            'RequiredRank' => trim((string)($_POST['RequiredRank'] ?? '')),
            'IsActive' => ((string)($_POST['IsActive'] ?? '1') === '1') ? 1 : 0,
        ];

        if ($data['ApplicationTypeID'] <= 0 || $data['RequiredApproverType'] === '') {
            $this->flashError('Application Type and Required Approver Type are required.');
            $target = 'index.php?route=admin/workflow-approval-rules-edit';
            if ($id > 0) {
                $target .= '&id=' . urlencode((string)$id);
            }
            header('Location: ' . $target);
            exit;
        }
        if ($data['ApprovalStage'] <= 0) {
            $this->flashError('Approval Stage must be 1 or greater.');
            $target = 'index.php?route=admin/workflow-approval-rules-edit';
            if ($id > 0) {
                $target .= '&id=' . urlencode((string)$id);
            }
            header('Location: ' . $target);
            exit;
        }
        if ($data['MinLimit'] < 0) {
            $this->flashError('Min Limit must be zero or greater.');
            $target = 'index.php?route=admin/workflow-approval-rules-edit';
            if ($id > 0) {
                $target .= '&id=' . urlencode((string)$id);
            }
            header('Location: ' . $target);
            exit;
        }
        if ($data['MaxLimit'] !== null && $data['MaxLimit'] < $data['MinLimit']) {
            $this->flashError('Max Limit must be greater than or equal to Min Limit.');
            $target = 'index.php?route=admin/workflow-approval-rules-edit';
            if ($id > 0) {
                $target .= '&id=' . urlencode((string)$id);
            }
            header('Location: ' . $target);
            exit;
        }

        try {
            if ($id > 0) {
                $ok = $model->update($id, $data);
                if ($ok) {
                    $this->flashSuccess('Workflow approval rule updated.');
                    $audit->insert([
                        'UserID' => SessionHelper::get('auth.user_id'),
                        'Username' => SessionHelper::get('auth.username', 'guest'),
                        'Action' => 'UPDATE',
                        'Entity' => 'WorkflowApprovalRule',
                        'EntityKey' => (string)$id,
                        'IPAddress' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'Details' => $data,
                        'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                        'VersionID' => SessionHelper::get('VersionID'),
                    ]);
                } else {
                    $this->flashError('Save failed: ' . $model->getLastError());
                }
            } else {
                $newId = $model->create($data);
                if ($newId > 0) {
                    $this->flashSuccess('Workflow approval rule created.');
                    $audit->insert([
                        'UserID' => SessionHelper::get('auth.user_id'),
                        'Username' => SessionHelper::get('auth.username', 'guest'),
                        'Action' => 'CREATE',
                        'Entity' => 'WorkflowApprovalRule',
                        'EntityKey' => (string)$newId,
                        'IPAddress' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'Details' => $data,
                        'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                        'VersionID' => SessionHelper::get('VersionID'),
                    ]);
                } else {
                    $this->flashError('Create failed: ' . $model->getLastError());
                }
            }
        } catch (\Throwable $e) {
            $this->flashError('Save failed: ' . $e->getMessage());
        }

        header('Location: index.php?route=admin/workflow-approval-rules');
        exit;
    }

    public function delete(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }
        if (!csrf_check($_POST['_csrf'] ?? '')) {
            $this->flashError('Security check failed.');
            header('Location: index.php?route=admin/workflow-approval-rules');
            exit;
        }

        require __DIR__ . '/../../config/db.php';
        $model = new WorkflowApprovalRuleModel($conn);
        $audit = new AuditModel($conn);

        $id = (int)($_POST['id'] ?? 0);
        $existing = $id > 0 ? $model->find($id) : null;
        if ($id <= 0 || $existing === null) {
            $this->flashError('Workflow approval rule not found.');
            header('Location: index.php?route=admin/workflow-approval-rules');
            exit;
        }

        try {
            if ($model->delete($id)) {
                $this->flashSuccess('Workflow approval rule deleted.');
                $audit->insert([
                    'UserID' => SessionHelper::get('auth.user_id'),
                    'Username' => SessionHelper::get('auth.username', 'guest'),
                    'Action' => 'DELETE',
                    'Entity' => 'WorkflowApprovalRule',
                    'EntityKey' => (string)$id,
                    'IPAddress' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'Details' => $existing,
                    'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                    'VersionID' => SessionHelper::get('VersionID'),
                ]);
            } else {
                $this->flashError('Delete failed: ' . $model->getLastError());
            }
        } catch (\Throwable $e) {
            $this->flashError('Delete failed: ' . $e->getMessage());
        }

        header('Location: index.php?route=admin/workflow-approval-rules');
        exit;
    }

    public function upload(): void
    {
        $this->render('admin/WorkflowApprovalRuleUpload', [
            'title' => 'Upload Workflow Approval Rules',
        ]);
    }

    public function uploadProcess(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            return;
        }
        if (!csrf_check($_POST['_csrf'] ?? '')) {
            $this->flashError('Security check failed.');
            header('Location: index.php?route=admin/workflow-approval-rules-upload');
            exit;
        }

        require __DIR__ . '/../../config/db.php';
        $validator = new \App\Services\WorkflowApprovalRuleUploadValidator($conn);
        $model = new WorkflowApprovalRuleModel($conn);
        $audit = new AuditModel($conn);

        if (!isset($_FILES['uploadFile']) || $_FILES['uploadFile']['error'] !== UPLOAD_ERR_OK) {
            $this->flashError('File upload failed.');
            header('Location: index.php?route=admin/workflow-approval-rules-upload');
            exit;
        }

        $filePath = $_FILES['uploadFile']['tmp_name'];
        $errors = [];
        $warnings = [];
        $reportRows = [];

        try {
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
            $sheet = $spreadsheet->getSheetByName('WorkflowApprovalRules') ?? $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);

            if (empty($rows)) {
                throw new \RuntimeException('Empty worksheet.');
            }

            $headerRow = array_map(static fn($v) => trim((string)$v), $rows[0] ?? []);
            $expected = $validator->expectedHeaders();
            foreach ($expected as $i => $col) {
                $given = $headerRow[$i] ?? '';
                if (strcasecmp($given, $col) !== 0) {
                    throw new \RuntimeException(
                        "Header mismatch at column " . ($i + 1) . ": expected '{$col}', found '{$given}'"
                    );
                }
            }

            $imported = 0;
            $updated = 0;

            for ($r = 1; $r < count($rows); $r++) {
                $row = $rows[$r];
                if (!array_filter($row, static fn($v) => trim((string)$v) !== '')) {
                    continue;
                }

                $assoc = [];
                foreach ($expected as $i => $label) {
                    $assoc[$label] = isset($row[$i]) ? (string)$row[$i] : '';
                }

                $valid = $validator->validateRow($assoc, $r + 1);
                if ($valid === null) {
                    $rowErrors = $validator->getRowErrors($r + 1);
                    $reportRows[] = [
                        'RowNumber' => $r + 1,
                        'Action' => 'Skipped',
                        'Status' => 'Error',
                        'Message' => $rowErrors ? implode(' | ', $rowErrors) : 'Validation failed.',
                        'ApplicationTypeKey' => (string)($assoc['ApplicationTypeKey'] ?? ''),
                        'EmployeeGroup' => (string)($assoc['EmployeeGroup'] ?? ''),
                        'ApprovalStage' => (string)($assoc['ApprovalStage'] ?? ''),
                        'MinLimit' => (string)($assoc['MinLimit'] ?? ''),
                        'MaxLimit' => (string)($assoc['MaxLimit'] ?? ''),
                        'RequiredApproverType' => (string)($assoc['RequiredApproverType'] ?? ''),
                        'RequiredRank' => (string)($assoc['RequiredRank'] ?? ''),
                        'IsActive' => (string)($assoc['IsActive'] ?? ''),
                    ];
                    continue;
                }

                $existing = $model->findImportMatch(
                    (int)$valid['ApplicationTypeID'],
                    (string)$valid['EmployeeGroup'],
                    (int)$valid['ApprovalStage'],
                    (float)$valid['MinLimit'],
                    $valid['MaxLimit'] !== null ? (float)$valid['MaxLimit'] : null
                );
                $overlaps = $model->findOverlaps(
                    (int)$valid['ApplicationTypeID'],
                    (string)$valid['EmployeeGroup'],
                    (int)$valid['ApprovalStage'],
                    (float)$valid['MinLimit'],
                    $valid['MaxLimit'] !== null ? (float)$valid['MaxLimit'] : null,
                    $existing ? (int)($existing['RuleID'] ?? 0) : null
                );
                $overlapMessage = '';
                if ($overlaps) {
                    $overlapIds = array_map(static fn(array $o): string => (string)($o['RuleID'] ?? ''), $overlaps);
                    $overlapMessage = 'Overlaps existing rule(s): ' . implode(', ', array_filter($overlapIds));
                    $warnings[] = 'Row ' . ($r + 1) . ': ' . $overlapMessage;
                }

                if ($existing) {
                    $ruleId = (int)($existing['RuleID'] ?? 0);
                    $ok = $ruleId > 0 ? $model->update($ruleId, $valid) : false;
                    if ($ok) {
                        $updated++;
                        $audit->insert([
                            'UserID' => SessionHelper::get('auth.user_id'),
                            'Username' => SessionHelper::get('auth.username', 'guest'),
                            'Action' => 'UPDATE',
                            'Entity' => 'WorkflowApprovalRule',
                            'EntityKey' => (string)$ruleId,
                            'IPAddress' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                            'Details' => $valid,
                            'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                            'VersionID' => SessionHelper::get('VersionID'),
                        ]);
                        $reportRows[] = [
                            'RowNumber' => $r + 1,
                            'Action' => 'Update',
                            'Status' => $overlapMessage !== '' ? 'Warning' : 'Success',
                            'Message' => $overlapMessage !== '' ? $overlapMessage : 'Existing rule updated.',
                            'ApplicationTypeKey' => (string)($assoc['ApplicationTypeKey'] ?? ''),
                            'EmployeeGroup' => (string)$valid['EmployeeGroup'],
                            'ApprovalStage' => (string)$valid['ApprovalStage'],
                            'MinLimit' => (string)$valid['MinLimit'],
                            'MaxLimit' => $valid['MaxLimit'] === null ? '' : (string)$valid['MaxLimit'],
                            'RequiredApproverType' => (string)$valid['RequiredApproverType'],
                            'RequiredRank' => (string)$valid['RequiredRank'],
                            'IsActive' => (string)$valid['IsActive'],
                        ];
                    } else {
                        $message = 'DB update failed - ' . ($model->getLastError() ?: 'Unknown error');
                        $errors[] = "Row " . ($r + 1) . ': ' . $message;
                        $reportRows[] = [
                            'RowNumber' => $r + 1,
                            'Action' => 'Update',
                            'Status' => 'Error',
                            'Message' => $message,
                            'ApplicationTypeKey' => (string)($assoc['ApplicationTypeKey'] ?? ''),
                            'EmployeeGroup' => (string)$valid['EmployeeGroup'],
                            'ApprovalStage' => (string)$valid['ApprovalStage'],
                            'MinLimit' => (string)$valid['MinLimit'],
                            'MaxLimit' => $valid['MaxLimit'] === null ? '' : (string)$valid['MaxLimit'],
                            'RequiredApproverType' => (string)$valid['RequiredApproverType'],
                            'RequiredRank' => (string)$valid['RequiredRank'],
                            'IsActive' => (string)$valid['IsActive'],
                        ];
                    }
                    continue;
                }

                $newId = $model->create($valid);
                if ($newId > 0) {
                    $imported++;
                    $audit->insert([
                        'UserID' => SessionHelper::get('auth.user_id'),
                        'Username' => SessionHelper::get('auth.username', 'guest'),
                        'Action' => 'CREATE',
                        'Entity' => 'WorkflowApprovalRule',
                        'EntityKey' => (string)$newId,
                        'IPAddress' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                        'Details' => $valid,
                        'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                        'VersionID' => SessionHelper::get('VersionID'),
                    ]);
                    $reportRows[] = [
                        'RowNumber' => $r + 1,
                        'Action' => 'Insert',
                        'Status' => $overlapMessage !== '' ? 'Warning' : 'Success',
                        'Message' => $overlapMessage !== '' ? $overlapMessage : 'New rule inserted.',
                        'ApplicationTypeKey' => (string)($assoc['ApplicationTypeKey'] ?? ''),
                        'EmployeeGroup' => (string)$valid['EmployeeGroup'],
                        'ApprovalStage' => (string)$valid['ApprovalStage'],
                        'MinLimit' => (string)$valid['MinLimit'],
                        'MaxLimit' => $valid['MaxLimit'] === null ? '' : (string)$valid['MaxLimit'],
                        'RequiredApproverType' => (string)$valid['RequiredApproverType'],
                        'RequiredRank' => (string)$valid['RequiredRank'],
                        'IsActive' => (string)$valid['IsActive'],
                    ];
                } else {
                    $message = 'DB insert failed - ' . ($model->getLastError() ?: 'Unknown error');
                    $errors[] = "Row " . ($r + 1) . ': ' . $message;
                    $reportRows[] = [
                        'RowNumber' => $r + 1,
                        'Action' => 'Insert',
                        'Status' => 'Error',
                        'Message' => $message,
                        'ApplicationTypeKey' => (string)($assoc['ApplicationTypeKey'] ?? ''),
                        'EmployeeGroup' => (string)$valid['EmployeeGroup'],
                        'ApprovalStage' => (string)$valid['ApprovalStage'],
                        'MinLimit' => (string)$valid['MinLimit'],
                        'MaxLimit' => $valid['MaxLimit'] === null ? '' : (string)$valid['MaxLimit'],
                        'RequiredApproverType' => (string)$valid['RequiredApproverType'],
                        'RequiredRank' => (string)$valid['RequiredRank'],
                        'IsActive' => (string)$valid['IsActive'],
                    ];
                }
            }

            $allErrors = $validator->getErrors();
            if ($errors) {
                $allErrors = array_merge($allErrors, $errors);
            }
            $report = [
                'generated_at' => gmdate('Y-m-d H:i:s'),
                'rows' => $reportRows,
            ];
            SessionHelper::set('workflowApprovalRules.upload_report', $report);

            $reportLink = '<a href="index.php?route=admin/workflow-approval-rules-upload-report" class="alert-link">Download import report</a>';

            if ($allErrors || $warnings) {
                $parts = [
                    "Inserted {$imported} rule(s).",
                    "Updated {$updated} rule(s).",
                ];
                if ($warnings) {
                    $parts[] = 'Warnings:<br>' . implode('<br>', $warnings);
                }
                if ($allErrors) {
                    $parts[] = 'Errors:<br>' . implode('<br>', $allErrors);
                }
                $parts[] = $reportLink;
                SessionHelper::set('flash.message', [
                    'type' => ($allErrors ? 'danger' : 'warning'),
                    'text' => 'Upload completed.<br>' . implode('<br>', $parts),
                ]);
            } else {
                SessionHelper::set('flash.message', [
                    'type' => 'success',
                    'text' => "Successfully inserted {$imported} and updated {$updated} workflow approval rule(s).<br>{$reportLink}",
                ]);
            }
        } catch (\Throwable $e) {
            $this->flashError('Upload failed: ' . $e->getMessage());
        }

        header('Location: index.php?route=admin/workflow-approval-rules');
        exit;
    }

    public function downloadTemplate(): void
    {
        require __DIR__ . '/../../config/db.php';
        $model = new WorkflowApprovalRuleModel($conn);

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('WorkflowApprovalRules');

        $headers = [
            'ApplicationTypeKey',
            'EmployeeGroup',
            'ApprovalStage',
            'MinLimit',
            'MaxLimit',
            'RequiredApproverType',
            'RequiredRank',
            'IsActive',
        ];

        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
            $col++;
        }

        $applicationTypes = $model->listApplicationTypes();
        $sampleTypeKey = strtolower(trim((string)($applicationTypes[0]['ApplicationTypeKey'] ?? 'dtc_limit_change')));

        $sampleRows = [
            [$sampleTypeKey, 'Defence', '1', '0', '499999.99', 'ASFIN', '', '1'],
            [$sampleTypeKey, 'Defence', '2', '0', '499999.99', 'CFO', '', '1'],
        ];

        $rowNum = 2;
        foreach ($sampleRows as $sampleRow) {
            $col = 'A';
            foreach ($sampleRow as $value) {
                $sheet->setCellValue($col . $rowNum, $value);
                $col++;
            }
            $rowNum++;
        }

        foreach (range('A', 'H') as $colLetter) {
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="WorkflowApprovalRulesTemplate.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    public function downloadUploadReport(): void
    {
        $report = SessionHelper::get('workflowApprovalRules.upload_report');
        if (!is_array($report) || !is_array($report['rows'] ?? null)) {
            $this->flashError('No upload report is available to download.');
            header('Location: index.php?route=admin/workflow-approval-rules');
            exit;
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('ImportResults');

        $headers = [
            'RowNumber',
            'Action',
            'Status',
            'Message',
            'ApplicationTypeKey',
            'EmployeeGroup',
            'ApprovalStage',
            'MinLimit',
            'MaxLimit',
            'RequiredApproverType',
            'RequiredRank',
            'IsActive',
        ];
        $col = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($col . '1', $header);
            $sheet->getStyle($col . '1')->getFont()->setBold(true);
            $col++;
        }

        $rowNum = 2;
        foreach (($report['rows'] ?? []) as $row) {
            $col = 'A';
            foreach ($headers as $header) {
                $sheet->setCellValue($col . $rowNum, (string)($row[$header] ?? ''));
                $col++;
            }
            $rowNum++;
        }

        foreach (range('A', 'L') as $colLetter) {
            $sheet->getColumnDimension($colLetter)->setAutoSize(true);
        }
        $sheet->freezePane('A2');

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="WorkflowApprovalRulesImportReport.xlsx"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }
}
