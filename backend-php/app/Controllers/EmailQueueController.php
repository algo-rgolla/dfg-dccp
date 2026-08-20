<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\EmailQueueModel;
use App\Services\MailService;

class EmailQueueController extends BaseController
{
    public function index(): void
    {
        require __DIR__ . '/../../config/db.php';
        $queue = new EmailQueueModel($conn, new MailService($conn));
        $rows = $queue->listQueue(500);

        $this->render('emailqueue/index', [
            'title' => 'Email Queue',
            'rows' => $rows,
        ]);
    }

    public function recipients(): void
    {
        require __DIR__ . '/../../config/db.php';
        $messageId = (int)($_GET['MessageID'] ?? 0);
        $queue = new EmailQueueModel($conn, new MailService($conn));
        $rows = $queue->listRecipients(1000, $messageId > 0 ? $messageId : null);

        $this->render('emailqueue/recipients', [
            'title' => 'Email Queue Recipients',
            'rows' => $rows,
            'messageId' => $messageId > 0 ? $messageId : null,
        ]);
    }

    public function send(): void
    {
        require_once __DIR__ . '/../../shared/csrf.php';
        if (!csrf_check($_POST['_csrf'] ?? null)) {
            $this->flashError('Security check failed.');
            header('Location: index.php?route=emailqueue/index');
            return;
        }

        require __DIR__ . '/../../config/db.php';
        $queue = new EmailQueueModel($conn, new MailService($conn));

        try {
            $action = (string)($_POST['action'] ?? 'selected');
            if ($action === 'all') {
                $ids = $queue->listPendingIds(500);
            } else {
                $ids = $_POST['email_ids'] ?? [];
                $ids = is_array($ids) ? $ids : [];
            }

            $processed = $queue->processSelected($ids);

            if (!$processed) {
                $this->flashError('No pending emails were selected to send.');
            } else {
                $this->flashSuccess('Processed ' . count($processed) . ' queued email(s).');
            }
        } catch (\Throwable $e) {
            $this->flashError('Queue send failed: ' . $e->getMessage());
        }

        header('Location: index.php?route=emailqueue/index');
    }

    public function process(): void
    {
        require __DIR__ . '/../../config/db.php'; // $conn
        $mailer = new MailService($conn);
        $queue  = new EmailQueueModel($conn, $mailer);
        $sent   = $queue->processDue(200);

        header('Content-Type: application/json');
        echo json_encode(['processed' => count($sent)]);
    }
}
