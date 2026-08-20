<?php
declare(strict_types=1);

namespace App\Models;

use PDO;
use App\Services\MailService;

class EmailQueueModel
{
    public function __construct(private PDO $db, private MailService $mailer)
    {
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function enqueueBatch(array $rows): void
    {
        $sql = "INSERT INTO dbo.tblEmailQueue (ToEmail, Subject, BodyHtml, BodyText, SendAtUTC, MessageID)
                VALUES (:to, :subj, :html, :text, :when, :mid)";
        $st = $this->db->prepare($sql);
        foreach ($rows as $r) {
            $st->bindValue(':to',   $r['to']);
            $st->bindValue(':subj', $r['subject']);
            $st->bindValue(':html', $r['html'] ?? null);
            $st->bindValue(':text', $r['text'] ?? null);
            $st->bindValue(':when', is_string($r['when']) ? $r['when'] : ($r['when']->format('Y-m-d H:i:s')));
            $st->bindValue(':mid',  $r['MessageID'] ?? null, PDO::PARAM_INT);
            $st->execute();
        }
    }

    public function listQueue(int $limit = 500): array
    {
        $limit = max(1, min(1000, $limit));
        $sql = "
            SELECT TOP {$limit}
                EmailID,
                ToEmail,
                Subject,
                BodyHtml,
                BodyText,
                SendAtUTC,
                SentAtUTC,
                Attempts,
                LastError,
                MessageID,
                CASE
                    WHEN SentAtUTC IS NOT NULL THEN 'sent'
                    WHEN ISNULL(Attempts, 0) > 0 AND ISNULL(LastError, '') <> '' THEN 'failed'
                    ELSE 'pending'
                END AS QueueStatus
            FROM dbo.tblEmailQueue
            ORDER BY
                CASE WHEN SentAtUTC IS NULL THEN 0 ELSE 1 END,
                SendAtUTC ASC,
                EmailID DESC
        ";

        $st = $this->db->query($sql);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function listPendingIds(int $limit = 500): array
    {
        $limit = max(1, min(1000, $limit));
        $sql = "
            SELECT TOP {$limit} EmailID
            FROM dbo.tblEmailQueue
            WHERE SentAtUTC IS NULL
            ORDER BY SendAtUTC ASC, EmailID ASC
        ";

        $st = $this->db->query($sql);
        return array_map('intval', array_column($st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'EmailID'));
    }

    public function listRecipients(int $limit = 1000, ?int $messageId = null): array
    {
        $limit = max(1, min(2000, $limit));
        $where = '';
        if ($messageId !== null && $messageId > 0) {
            $where = ' WHERE MessageID = :mid';
        }
        $sql = "
            SELECT TOP {$limit}
                ToEmail,
                MessageID,
                COUNT(*) AS QueueCount,
                SUM(CASE WHEN SentAtUTC IS NULL THEN 1 ELSE 0 END) AS PendingCount,
                SUM(CASE WHEN SentAtUTC IS NOT NULL THEN 1 ELSE 0 END) AS SentCount,
                SUM(CASE WHEN SentAtUTC IS NULL AND ISNULL(Attempts, 0) > 0 AND ISNULL(LastError, '') <> '' THEN 1 ELSE 0 END) AS FailedCount,
                MIN(SendAtUTC) AS NextSendAtUTC,
                MAX(SentAtUTC) AS LastSentAtUTC
            FROM dbo.tblEmailQueue
            {$where}
            GROUP BY ToEmail, MessageID
            ORDER BY ToEmail ASC
        ";

        $st = $this->db->prepare($sql);
        if ($where !== '') {
            $st->bindValue(':mid', $messageId, PDO::PARAM_INT);
        }
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function processDue(int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        $sel = $this->db->prepare("
            SELECT TOP {$limit} EmailID
            FROM dbo.tblEmailQueue WITH (ROWLOCK, READPAST)
            WHERE SentAtUTC IS NULL AND SendAtUTC <= SYSUTCDATETIME()
            ORDER BY SendAtUTC ASC, EmailID ASC
        ");
        $sel->execute();
        $ids = array_map('intval', array_column($sel->fetchAll(PDO::FETCH_ASSOC) ?: [], 'EmailID'));
        return $this->processSelected($ids);
    }

    public function processSelected(array $emailIds): array
    {
        $emailIds = array_values(array_unique(array_filter(array_map('intval', $emailIds))));
        if (!$emailIds) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($emailIds), '?'));

        $this->db->beginTransaction();
        try {
            $sel = $this->db->prepare("
                SELECT EmailID, ToEmail, Subject, BodyHtml, BodyText
                FROM dbo.tblEmailQueue WITH (ROWLOCK, READPAST)
                WHERE SentAtUTC IS NULL
                  AND EmailID IN ({$placeholders})
                ORDER BY SendAtUTC ASC, EmailID ASC
            ");
            foreach ($emailIds as $index => $emailId) {
                $sel->bindValue($index + 1, $emailId, PDO::PARAM_INT);
            }
            $sel->execute();
            $batch = $sel->fetchAll(PDO::FETCH_ASSOC) ?: [];

            if ($batch) {
                $ids = implode(',', array_map('intval', array_column($batch, 'EmailID')));
                $this->db->exec("UPDATE dbo.tblEmailQueue SET Attempts = ISNULL(Attempts, 0) + 1 WHERE EmailID IN ({$ids})");
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        foreach ($batch as $row) {
            $ok = false;
            try {
                $ok = $this->mailer->sendEmail($row['ToEmail'], $row['Subject'], $row['BodyHtml'] ?? ($row['BodyText'] ?? ''));
            } catch (\Throwable $e) {
                $ok = false;
            }

            $upd = $this->db->prepare("UPDATE dbo.tblEmailQueue SET SentAtUTC = :sentAt, LastError = :e WHERE EmailID = :id");
            $upd->bindValue(':sentAt', $ok ? gmdate('Y-m-d H:i:s') : null, $ok ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $upd->bindValue(':e', $ok ? null : 'MailService->sendEmail returned false');
            $upd->bindValue(':id', (int)$row['EmailID'], PDO::PARAM_INT);
            $upd->execute();
        }

        return $batch;
    }
}
