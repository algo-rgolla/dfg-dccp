<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class CAPSTrainingModel
{
    private PDO $conn;
    private string $lastError = '';

    private const TABLE = 'dbo.tblCAPSTraining';

    private const COLUMNS = [
        'CourseID',
        'OfferingID',
        'CourseTitle',
        'EmployeeID',
        'FirstName',
        'LastName',
        'Email',
        'CompletionDate',
        'Loaded',
        'FileID',
        'DateUpdated',
        'UpdatedBy',
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
                TrainingID,
                CourseID,
                OfferingID,
                CourseTitle,
                EmployeeID,
                FirstName,
                LastName,
                Email,
                CompletionDate,
                Loaded,
                FileID,
                DateUpdated,
                UpdatedBy
            FROM " . self::TABLE . "
            WHERE 1 = 1
        ";
        $params = [];

        $q = trim((string)($filters['q'] ?? ''));
        if ($q !== '') {
            $sql .= "
                AND (
                    CAST(TrainingID AS NVARCHAR(50)) = :q_exact
                    OR ISNULL(CourseID, '') LIKE :q_like
                    OR ISNULL(OfferingID, '') LIKE :q_like
                    OR ISNULL(CourseTitle, '') LIKE :q_like
                    OR ISNULL(EmployeeID, '') LIKE :q_like
                    OR ISNULL(FirstName, '') LIKE :q_like
                    OR ISNULL(LastName, '') LIKE :q_like
                    OR ISNULL(Email, '') LIKE :q_like
                )
            ";
            $params['q_exact'] = $q;
            $params['q_like'] = '%' . $q . '%';
        }

        $courseId = trim((string)($filters['course_id'] ?? ''));
        if ($courseId !== '') {
            $sql .= " AND ISNULL(CourseID, '') LIKE :course_id";
            $params['course_id'] = '%' . $courseId . '%';
        }

        $employeeId = trim((string)($filters['employee_id'] ?? ''));
        if ($employeeId !== '') {
            $sql .= " AND ISNULL(EmployeeID, '') LIKE :employee_id";
            $params['employee_id'] = '%' . $employeeId . '%';
        }

        $loaded = strtoupper(trim((string)($filters['loaded'] ?? '')));
        if ($loaded !== '') {
            $sql .= " AND UPPER(ISNULL(Loaded, '')) = :loaded";
            $params['loaded'] = substr($loaded, 0, 1);
        }

        $sql .= " ORDER BY TrainingID DESC
                  OFFSET {$offset} ROWS FETCH NEXT {$perPage} ROWS ONLY";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            if (function_exists('app_log')) {
                app_log('CAPSTrainingModel listAll failed', ['error' => $this->lastError], 'error');
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
                    CAST(TrainingID AS NVARCHAR(50)) = :q_exact
                    OR ISNULL(CourseID, '') LIKE :q_like
                    OR ISNULL(OfferingID, '') LIKE :q_like
                    OR ISNULL(CourseTitle, '') LIKE :q_like
                    OR ISNULL(EmployeeID, '') LIKE :q_like
                    OR ISNULL(FirstName, '') LIKE :q_like
                    OR ISNULL(LastName, '') LIKE :q_like
                    OR ISNULL(Email, '') LIKE :q_like
                )
            ";
            $params['q_exact'] = $q;
            $params['q_like'] = '%' . $q . '%';
        }

        $courseId = trim((string)($filters['course_id'] ?? ''));
        if ($courseId !== '') {
            $sql .= " AND ISNULL(CourseID, '') LIKE :course_id";
            $params['course_id'] = '%' . $courseId . '%';
        }

        $employeeId = trim((string)($filters['employee_id'] ?? ''));
        if ($employeeId !== '') {
            $sql .= " AND ISNULL(EmployeeID, '') LIKE :employee_id";
            $params['employee_id'] = '%' . $employeeId . '%';
        }

        $loaded = strtoupper(trim((string)($filters['loaded'] ?? '')));
        if ($loaded !== '') {
            $sql .= " AND UPPER(ISNULL(Loaded, '')) = :loaded";
            $params['loaded'] = substr($loaded, 0, 1);
        }

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            if (function_exists('app_log')) {
                app_log('CAPSTrainingModel countFiltered failed', ['error' => $this->lastError], 'error');
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
                WHERE TrainingID = :id
            ");
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            if (function_exists('app_log')) {
                app_log('CAPSTrainingModel find failed', ['id' => $id, 'error' => $this->lastError], 'error');
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
                app_log('CAPSTrainingModel create failed', ['error' => $this->lastError], 'error');
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
                WHERE TrainingID = :id
            ");

            $params = $this->buildParams($data);
            $params['id'] = $id;
            $ok = $stmt->execute($params);
            if ($ok && $stmt->rowCount() === 0) {
                $this->lastError = 'No rows updated. TrainingID may not exist.';
                return false;
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            if (function_exists('app_log')) {
                app_log('CAPSTrainingModel update failed', ['id' => $id, 'error' => $this->lastError], 'error');
            }
            return false;
        }
    }

    public function delete(int $id): bool
    {
        try {
            $stmt = $this->conn->prepare("
                DELETE FROM " . self::TABLE . "
                WHERE TrainingID = :id
            ");
            $ok = $stmt->execute(['id' => $id]);
            if ($ok && $stmt->rowCount() === 0) {
                $this->lastError = 'No rows deleted. TrainingID may not exist.';
                return false;
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            if (function_exists('app_log')) {
                app_log('CAPSTrainingModel delete failed', ['id' => $id, 'error' => $this->lastError], 'error');
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
