<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class CAPSEmployeeTypeModel
{
    private PDO $conn;
    private string $lastError = '';

    private const TABLE = 'dbo.tblCAPSEmployeeType';

    private const COLUMNS = [
        'EmployeeType',
        'DPCEntitled',
        'DTCEntitled',
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
                EmployeeTypeID,
                EmployeeType,
                DPCEntitled,
                DTCEntitled
            FROM " . self::TABLE . "
            WHERE 1 = 1
        ";
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $sql .= "
                AND (
                    CAST(EmployeeTypeID AS NVARCHAR(50)) = :q_exact
                    OR ISNULL(EmployeeType, '') LIKE :q_like
                )
            ";
            $params['q_exact'] = $q;
            $params['q_like'] = '%' . $q . '%';
        }

        $employeeType = trim((string)($filters['employee_type'] ?? ''));
        if ($employeeType !== '') {
            $sql .= " AND ISNULL(EmployeeType, '') LIKE :employee_type";
            $params['employee_type'] = '%' . $employeeType . '%';
        }

        $dpcEntitled = strtoupper(trim((string)($filters['dpc_entitled'] ?? '')));
        if ($dpcEntitled !== '') {
            $sql .= " AND UPPER(ISNULL(DPCEntitled, '')) = :dpc_entitled";
            $params['dpc_entitled'] = substr($dpcEntitled, 0, 1);
        }

        $dtcEntitled = strtoupper(trim((string)($filters['dtc_entitled'] ?? '')));
        if ($dtcEntitled !== '') {
            $sql .= " AND UPPER(ISNULL(DTCEntitled, '')) = :dtc_entitled";
            $params['dtc_entitled'] = substr($dtcEntitled, 0, 1);
        }

        $sql .= " ORDER BY EmployeeTypeID DESC
                  OFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            if (function_exists('app_log')) {
                app_log('CAPSEmployeeTypeModel listAll failed', ['error' => $this->lastError], 'error');
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
                    CAST(EmployeeTypeID AS NVARCHAR(50)) = :q_exact
                    OR ISNULL(EmployeeType, '') LIKE :q_like
                )
            ";
            $params['q_exact'] = $q;
            $params['q_like'] = '%' . $q . '%';
        }

        $employeeType = trim((string)($filters['employee_type'] ?? ''));
        if ($employeeType !== '') {
            $sql .= " AND ISNULL(EmployeeType, '') LIKE :employee_type";
            $params['employee_type'] = '%' . $employeeType . '%';
        }

        $dpcEntitled = strtoupper(trim((string)($filters['dpc_entitled'] ?? '')));
        if ($dpcEntitled !== '') {
            $sql .= " AND UPPER(ISNULL(DPCEntitled, '')) = :dpc_entitled";
            $params['dpc_entitled'] = substr($dpcEntitled, 0, 1);
        }

        $dtcEntitled = strtoupper(trim((string)($filters['dtc_entitled'] ?? '')));
        if ($dtcEntitled !== '') {
            $sql .= " AND UPPER(ISNULL(DTCEntitled, '')) = :dtc_entitled";
            $params['dtc_entitled'] = substr($dtcEntitled, 0, 1);
        }

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            if (function_exists('app_log')) {
                app_log('CAPSEmployeeTypeModel countFiltered failed', ['error' => $this->lastError], 'error');
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
                WHERE EmployeeTypeID = :id
            ");
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            if (function_exists('app_log')) {
                app_log('CAPSEmployeeTypeModel find failed', ['id' => $id, 'error' => $this->lastError], 'error');
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
                OUTPUT INSERTED.EmployeeTypeID
                VALUES ({$valueSql})
            ");
            $stmt->execute($this->buildParams($data));
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            if (function_exists('app_log')) {
                app_log('CAPSEmployeeTypeModel create failed', ['error' => $this->lastError], 'error');
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
                WHERE EmployeeTypeID = :id
            ");

            $params = $this->buildParams($data);
            $params['id'] = $id;
            $ok = $stmt->execute($params);
            if ($ok && $stmt->rowCount() === 0) {
                $this->lastError = 'No rows updated. EmployeeTypeID may not exist.';
                return false;
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            if (function_exists('app_log')) {
                app_log('CAPSEmployeeTypeModel update failed', ['id' => $id, 'error' => $this->lastError], 'error');
            }
            return false;
        }
    }

    public function delete(int $id): bool
    {
        try {
            $stmt = $this->conn->prepare("
                DELETE FROM " . self::TABLE . "
                WHERE EmployeeTypeID = :id
            ");
            $ok = $stmt->execute(['id' => $id]);
            if ($ok && $stmt->rowCount() === 0) {
                $this->lastError = 'No rows deleted. EmployeeTypeID may not exist.';
                return false;
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            if (function_exists('app_log')) {
                app_log('CAPSEmployeeTypeModel delete failed', ['id' => $id, 'error' => $this->lastError], 'error');
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
