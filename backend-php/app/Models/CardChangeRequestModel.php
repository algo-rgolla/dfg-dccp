<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class CardChangeRequestModel
{
    private PDO $conn;
    private string $lastError = '';
    private ?array $columnCache = null;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
        $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function listAll(array $filters = []): array
    {
        $selectParts = [
            'cr.RequestID',
            'cr.CardID',
            'cr.EmployeeID',
            'cr.RequestType',
            'cr.Status',
            'cr.PayloadJson',
            'cr.SubmittedAt',
            'cr.CompletedAt',
            'cr.CreatedAt',
            'cr.UpdatedAt',
            'cr.UpdatedBy',
            'pc.CardType',
            'pc.CardTypeSub',
            'pc.CardNumber',
            'pc.FirstName',
            'pc.Surname',
        ];
        if ($this->hasColumn('CancelDate')) {
            $selectParts[] = 'cr.CancelDate';
        }
        if ($this->hasColumn('ProcessedAt')) {
            $selectParts[] = 'cr.ProcessedAt';
        }

        $sql = "
            SELECT
                " . implode(",\n                ", $selectParts) . "
            FROM dbo.tblCardChangeRequests cr
            LEFT JOIN dbo.tblPORTALCards pc
              ON pc.CardID = cr.CardID
            WHERE 1 = 1
        ";
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $sql .= "
                AND (
                    CAST(cr.RequestID AS NVARCHAR(50)) = :q_exact
                    OR CAST(cr.CardID AS NVARCHAR(50)) = :q_exact
                    OR cr.EmployeeID LIKE :q_like
                    OR cr.RequestType LIKE :q_like
                    OR cr.Status LIKE :q_like
                    OR ISNULL(pc.CardType, '') LIKE :q_like
                    OR ISNULL(pc.CardTypeSub, '') LIKE :q_like
                    OR ISNULL(pc.FirstName, '') LIKE :q_like
                    OR ISNULL(pc.Surname, '') LIKE :q_like
                )
            ";
            $params['q_exact'] = $q;
            $params['q_like'] = '%' . $q . '%';
        }

        $employeeId = trim((string)($filters['employee_id'] ?? ''));
        if ($employeeId !== '') {
            $sql .= " AND cr.EmployeeID = :employee_id";
            $params['employee_id'] = $employeeId;
        }

        $requestType = trim((string)($filters['request_type'] ?? ''));
        if ($requestType !== '') {
            $sql .= " AND cr.RequestType = :request_type";
            $params['request_type'] = $requestType;
        }

        $status = trim((string)($filters['status'] ?? ''));
        if ($status !== '') {
            $sql .= " AND cr.Status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY cr.CreatedAt DESC, cr.RequestID DESC";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function listDistinctRequestTypes(): array
    {
        try {
            $stmt = $this->conn->query("
                SELECT DISTINCT RequestType
                FROM dbo.tblCardChangeRequests
                WHERE LTRIM(RTRIM(ISNULL(RequestType, ''))) <> ''
                ORDER BY RequestType
            ");
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function listDistinctStatuses(): array
    {
        try {
            $stmt = $this->conn->query("
                SELECT DISTINCT Status
                FROM dbo.tblCardChangeRequests
                WHERE LTRIM(RTRIM(ISNULL(Status, ''))) <> ''
                ORDER BY Status
            ");
            return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function find(int $requestId): ?array
    {
        try {
            $selectParts = ['*'];
            $stmt = $this->conn->prepare("
                SELECT *
                FROM dbo.tblCardChangeRequests
                WHERE RequestID = :request_id
            ");
            $stmt->execute(['request_id' => $requestId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    public function listPendingCancellations(): array
    {
        try {
            $selectParts = [
                'cr.RequestID',
                'cr.CardID',
                'cr.EmployeeID',
                'cr.RequestType',
                'cr.Status',
                'cr.PayloadJson',
                'cr.SubmittedAt',
                'cr.CompletedAt',
                'cr.CreatedAt',
                'cr.UpdatedAt',
                'cr.UpdatedBy',
                'pc.CardType',
                'pc.CardTypeSub',
                'pc.CardNumber',
                'pc.FirstName',
                'pc.Surname',
            ];
            if ($this->hasColumn('CancelDate')) {
                $selectParts[] = 'cr.CancelDate';
            }
            if ($this->hasColumn('ProcessedAt')) {
                $selectParts[] = 'cr.ProcessedAt';
            }

            $sql = "
                SELECT
                    " . implode(",\n                    ", $selectParts) . "
                FROM dbo.tblCardChangeRequests cr
                LEFT JOIN dbo.tblPORTALCards pc
                  ON pc.CardID = cr.CardID
                WHERE cr.RequestType = :request_type
                  AND cr.Status = :status
            ";

            if ($this->hasColumn('ProcessedAt')) {
                $sql .= " AND cr.ProcessedAt IS NULL";
            }

            $sql .= " ORDER BY
                " . ($this->hasColumn('CancelDate') ? "cr.CancelDate ASC," : '') . "
                cr.SubmittedAt ASC,
                cr.RequestID ASC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                'request_type' => 'CANCEL_CARD',
                'status' => 'Cancel Subm',
            ]);

            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $today = date('Y-m-d');

            foreach ($rows as &$row) {
                $payload = json_decode((string)($row['PayloadJson'] ?? ''), true);
                $payload = is_array($payload) ? $payload : [];
                $cancelDate = trim((string)($row['CancelDate'] ?? ''));
                if ($cancelDate === '') {
                    $cancelDate = trim((string)($payload['cancel_date'] ?? ''));
                }
                $row['CancelDateDisplay'] = $cancelDate !== '' ? $cancelDate : '-';
                $row['IsDueNow'] = ($cancelDate === '' || $cancelDate <= $today) ? 1 : 0;
                $row['IsScheduledFuture'] = ($cancelDate !== '' && $cancelDate > $today) ? 1 : 0;
                $row['ReasonDisplay'] = trim((string)($payload['reason'] ?? ''));
                $row['ReasonOtherDisplay'] = trim((string)($payload['reason_other'] ?? ''));
            }
            unset($row);

            return $rows;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function delete(int $requestId): bool
    {
        try {
            $stmt = $this->conn->prepare("
                DELETE FROM dbo.tblCardChangeRequests
                WHERE RequestID = :request_id
            ");
            $ok = $stmt->execute(['request_id' => $requestId]);
            if ($ok && $stmt->rowCount() === 0) {
                $this->lastError = 'No rows deleted. RequestID may not exist.';
                return false;
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    private function hasColumn(string $columnName): bool
    {
        if (trim($columnName) === '') {
            return false;
        }

        if ($this->columnCache === null) {
            $stmt = $this->conn->query("
                SELECT COLUMN_NAME
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = 'dbo'
                  AND TABLE_NAME = 'tblCardChangeRequests'
            ");
            $this->columnCache = [];
            foreach (($stmt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $name) {
                $this->columnCache[strtolower((string)$name)] = true;
            }
        }

        return !empty($this->columnCache[strtolower($columnName)]);
    }
}
