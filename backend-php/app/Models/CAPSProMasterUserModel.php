<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class CAPSProMasterUserModel
{
    private PDO $conn;
    private string $lastError = '';

    private const TABLE = 'dbo.tblCAPSProMasterUser';

    private const COLUMNS = [
        'extract_date',
        'employee_id',
        'contractor_ind',
        'user_name',
        'first_name',
        'surname',
        'location_name',
        'admin_ctr',
        'admin_ctr_name',
        'active_indicator',
        'locked',
        'inactive_reason',
        'admin_centre_controller',
        'enterprise_controller',
        'email_address',
        'Work_Phone',
        'Mobile',
        'review_date',
        'create_date',
        'created_by',
        'last_logon',
        'unprocessed_transactions',
        'active_cards',
        'Supervisor',
    ];

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
        $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    public function listAll(array $filters = [], int $page = 1, int $perPage = 100): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(500, $perPage));
        $offset = ($page - 1) * $perPage;

        $sql = "
            SELECT
                ProMasterUserID,
                employee_id,
                user_name,
                first_name,
                surname,
                location_name,
                admin_ctr,
                admin_ctr_name,
                active_indicator,
                locked,
                contractor_ind,
                email_address,
                Work_Phone,
                Mobile,
                review_date,
                create_date,
                last_logon,
                unprocessed_transactions,
                active_cards,
                Supervisor
            FROM " . self::TABLE . "
            WHERE 1 = 1
        ";
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $sql .= "
                AND (
                    CAST(ProMasterUserID AS NVARCHAR(50)) = :q_exact
                    OR ISNULL(employee_id, '') LIKE :q_like_employee_id
                    OR ISNULL(user_name, '') LIKE :q_like_user_name
                    OR ISNULL(first_name, '') LIKE :q_like_first_name
                    OR ISNULL(surname, '') LIKE :q_like_surname
                    OR ISNULL(email_address, '') LIKE :q_like_email_address
                    OR ISNULL(location_name, '') LIKE :q_like_location_name
                )
            ";
            $params['q_exact'] = $q;
            $qLike = '%' . $q . '%';
            $params['q_like_employee_id'] = $qLike;
            $params['q_like_user_name'] = $qLike;
            $params['q_like_first_name'] = $qLike;
            $params['q_like_surname'] = $qLike;
            $params['q_like_email_address'] = $qLike;
            $params['q_like_location_name'] = $qLike;
        }

        foreach (['active_indicator', 'locked', 'contractor_ind'] as $flagField) {
            $value = strtoupper(trim((string)($filters[$flagField] ?? '')));
            if ($value !== '') {
                $sql .= " AND UPPER(ISNULL({$flagField}, '')) = :{$flagField}";
                $params[$flagField] = $value;
            }
        }

        $sql .= " ORDER BY ProMasterUserID DESC
                  OFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            if (function_exists('app_log')) {
                app_log('CAPSProMasterUserModel listAll failed', ['error' => $this->lastError], 'error');
            }
            return [];
        }
    }

    public function countFiltered(array $filters = []): int
    {
        $sql = "
            SELECT COUNT(1)
            FROM " . self::TABLE . "
            WHERE 1 = 1
        ";
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $sql .= "
                AND (
                    CAST(ProMasterUserID AS NVARCHAR(50)) = :q_exact
                    OR ISNULL(employee_id, '') LIKE :q_like_employee_id
                    OR ISNULL(user_name, '') LIKE :q_like_user_name
                    OR ISNULL(first_name, '') LIKE :q_like_first_name
                    OR ISNULL(surname, '') LIKE :q_like_surname
                    OR ISNULL(email_address, '') LIKE :q_like_email_address
                    OR ISNULL(location_name, '') LIKE :q_like_location_name
                )
            ";
            $params['q_exact'] = $q;
            $qLike = '%' . $q . '%';
            $params['q_like_employee_id'] = $qLike;
            $params['q_like_user_name'] = $qLike;
            $params['q_like_first_name'] = $qLike;
            $params['q_like_surname'] = $qLike;
            $params['q_like_email_address'] = $qLike;
            $params['q_like_location_name'] = $qLike;
        }

        foreach (['active_indicator', 'locked', 'contractor_ind'] as $flagField) {
            $value = strtoupper(trim((string)($filters[$flagField] ?? '')));
            if ($value !== '') {
                $sql .= " AND UPPER(ISNULL({$flagField}, '')) = :{$flagField}";
                $params[$flagField] = $value;
            }
        }

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            if (function_exists('app_log')) {
                app_log('CAPSProMasterUserModel countFiltered failed', ['error' => $this->lastError], 'error');
            }
            return 0;
        }
    }

    public function find(int $id): ?array
    {
        try {
            $stmt = $this->conn->prepare("
                SELECT *
                FROM " . self::TABLE . "
                WHERE ProMasterUserID = :id
            ");
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            if (function_exists('app_log')) {
                app_log('CAPSProMasterUserModel find failed', ['id' => $id, 'error' => $this->lastError], 'error');
            }
            return null;
        }
    }

    public function create(array $data): int
    {
        try {
            $columnSql = implode(', ', self::COLUMNS);
            $valueSql = implode(', ', array_map(static fn(string $column): string => ':' . $column, self::COLUMNS));

            $stmt = $this->conn->prepare("
                INSERT INTO " . self::TABLE . " ({$columnSql})
                VALUES ({$valueSql});
                SELECT CAST(SCOPE_IDENTITY() AS int);
            ");
            $stmt->execute($this->buildParams($data));
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            if (function_exists('app_log')) {
                app_log('CAPSProMasterUserModel create failed', ['error' => $this->lastError], 'error');
            }
            return 0;
        }
    }

    public function update(int $id, array $data): bool
    {
        try {
            $assignments = implode(",\n                    ", array_map(
                static fn(string $column): string => "{$column} = :{$column}",
                self::COLUMNS
            ));

            $stmt = $this->conn->prepare("
                UPDATE " . self::TABLE . "
                SET {$assignments}
                WHERE ProMasterUserID = :id
            ");

            $params = $this->buildParams($data);
            $params['id'] = $id;
            $ok = $stmt->execute($params);
            if ($ok && $stmt->rowCount() === 0) {
                $this->lastError = 'No rows updated. ProMasterUserID may not exist.';
                return false;
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            if (function_exists('app_log')) {
                app_log('CAPSProMasterUserModel update failed', ['id' => $id, 'error' => $this->lastError], 'error');
            }
            return false;
        }
    }

    public function delete(int $id): bool
    {
        try {
            $stmt = $this->conn->prepare("
                DELETE FROM " . self::TABLE . "
                WHERE ProMasterUserID = :id
            ");
            $ok = $stmt->execute(['id' => $id]);
            if ($ok && $stmt->rowCount() === 0) {
                $this->lastError = 'No rows deleted. ProMasterUserID may not exist.';
                return false;
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            if (function_exists('app_log')) {
                app_log('CAPSProMasterUserModel delete failed', ['id' => $id, 'error' => $this->lastError], 'error');
            }
            return false;
        }
    }

    private function buildParams(array $data): array
    {
        $params = [];
        foreach (self::COLUMNS as $column) {
            $params[$column] = $data[$column] ?? null;
        }
        return $params;
    }
}
