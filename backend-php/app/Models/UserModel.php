<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class UserModel
{
    private PDO $conn;
    private string $lastError = '';

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
        $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    /**
     * Generic finder kept for controller compatibility.
     * Internally calls findById().
     */
    public function find(int $id): ?array
    {
        return $this->findById($id);
    }

    /**
     * Find user by ID
     */
    public function findById(int $id): ?array
    {
        try {
            $sql = "SELECT 
                        UserID,
                        EmployeeID,
                        Username,
                        LTRIM(RTRIM(DisplayName)) AS DisplayName,
                        FirstName,
                        LastName,
                        (FirstName + ' ' + LastName) AS FullName,
                        Email,
                        Department,
                        JobTitle,
                        Phone,
                        Notes,
                        IsActive,
                        IsActivated,
                        LastLoginAt,
                        LastLoginIP,
                        LoginCount,
                        CreatedAt,
                        UpdatedAt,
                        FailedLoginCount,
                        LastFailedLoginAt,
                        ForcePasswordReset,
                        MustChangePassword
                    FROM dbo.tblUsers
                    WHERE UserID = :id";
            $st = $this->conn->prepare($sql);
            $st->bindValue(':id', $id, PDO::PARAM_INT);
            $st->execute();
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    /**
     * Paginated list of users with optional search
     */
    public function all(int $page = 1, int $pageSize = 20, string $q = ''): array
    {
        $offset = ($page - 1) * $pageSize;
        $params = [];
        $where  = '';

        if ($q !== '') {
            $where        = "WHERE (Username LIKE :q 
                              OR Email LIKE :q 
                              OR DisplayName LIKE :q 
                              OR FirstName LIKE :q 
                              OR LastName LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }

        try {
            $sql = "
                SELECT UserID, Username, Email,
                       LTRIM(RTRIM(DisplayName)) AS DisplayName,
                       FirstName, LastName,
                       Department, JobTitle, IsActive,
                       LastLoginAt, LastLoginIP, LoginCount,
                       CreatedAt, UpdatedAt,
                       (FirstName + ' ' + LastName) AS FullName
                FROM dbo.tblUsers
                $where
                ORDER BY DisplayName ASC
                OFFSET :off ROWS FETCH NEXT :lim ROWS ONLY
            ";
            $st = $this->conn->prepare($sql);
            foreach ($params as $k => $v) {
                $st->bindValue($k, $v);
            }
            $st->bindValue(':off', $offset, PDO::PARAM_INT);
            $st->bindValue(':lim', $pageSize, PDO::PARAM_INT);
            $st->execute();
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function count(string $q = ''): int
    {
        $params = [];
        $where  = '';

        if ($q !== '') {
            $where        = "WHERE (Username LIKE :q 
                              OR Email LIKE :q 
                              OR DisplayName LIKE :q 
                              OR FirstName LIKE :q 
                              OR LastName LIKE :q)";
            $params[':q'] = '%' . $q . '%';
        }

        try {
            $st = $this->conn->prepare("SELECT COUNT(*) FROM dbo.tblUsers $where");
            $st->execute($params);
            return (int) $st->fetchColumn();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return 0;
        }
    }

    /**
     * Lightweight list (active users only) – for dropdowns etc.
     */
    public function listAll(): array
    {
        try {
            $sql = "SELECT UserID, Username, LTRIM(RTRIM(DisplayName)) AS DisplayName, Email
                    FROM dbo.tblUsers
                    WHERE IsActive = 1
                    ORDER BY DisplayName ASC, Username ASC";
            $st = $this->conn->query($sql);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function listDepartments(): array
    {
        try {
            $stmt = $this->conn->query("
                SELECT DISTINCT LTRIM(RTRIM(Department)) AS Department
                FROM dbo.tblUsers
                WHERE Department IS NOT NULL
                  AND LTRIM(RTRIM(Department)) <> ''
                ORDER BY LTRIM(RTRIM(Department)) ASC
            ");
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

            return array_values(array_filter(array_map(
                static fn($value): string => trim((string)$value),
                $rows
            ), static fn(string $value): bool => $value !== ''));
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    /**
     * Count users with filters (search, status, department)
     */
    public function countFiltered(?string $search, ?string $status, ?string $department): int
    {
        $sql = "SELECT COUNT(*) AS cnt
                FROM dbo.tblUsers
                WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (Username LIKE ?
                        OR DisplayName LIKE ?
                        OR FirstName LIKE ?
                        OR LastName LIKE ?
                        OR Email LIKE ?)";
            for ($i = 0; $i < 5; $i++) {
                $params[] = "%{$search}%";
            }
        }

        if ($status !== null && $status !== '') {
            $sql     .= " AND IsActive = ?";
            $params[] = (int) $status;
        }

        if (!empty($department)) {
            $sql     .= " AND Department = ?";
            $params[] = $department;
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Filtered, paginated list
     */
    public function listFiltered(
        ?string $search,
        ?string $status,
        ?string $department,
        int $offset = 0,
        int $limit = 25
    ): array {
        $sql = "SELECT 
                    UserID,
                    Username,
                    LTRIM(RTRIM(DisplayName)) AS DisplayName,
                    FirstName,
                    LastName,
                    (FirstName + ' ' + LastName) AS FullName,
                    Email,
                    Department,
                    JobTitle,
                    IsActive,
                    LastLoginAt,
                    FailedLoginCount
                FROM dbo.tblUsers
                WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (Username LIKE ?
                        OR DisplayName LIKE ?
                        OR FirstName LIKE ?
                        OR LastName LIKE ?
                        OR Email LIKE ?)";
            for ($i = 0; $i < 5; $i++) {
                $params[] = "%{$search}%";
            }
        }

        if ($status !== null && $status !== '') {
            $sql     .= " AND IsActive = ?";
            $params[] = (int) $status;
        }

        if (!empty($department)) {
            $sql     .= " AND Department = ?";
            $params[] = $department;
        }

        // SQL Server requires integer literals for OFFSET/FETCH
        $offset = max(0, (int) $offset);
        $limit  = max(1, (int) $limit);

        $sql .= " ORDER BY DisplayName ASC
                  OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Filtered list without pagination (for PDF export)
     */
    public function listAllFiltered(?string $search, ?string $status, ?string $department): array
    {
        $sql = "SELECT 
                    UserID,
                    Username,
                    LTRIM(RTRIM(DisplayName)) AS DisplayName,
                    FirstName,
                    LastName,
                    (FirstName + ' ' + LastName) AS FullName,
                    Email,
                    Department,
                    JobTitle,
                    IsActive,
                    LastLoginAt,
                    FailedLoginCount
                FROM dbo.tblUsers
                WHERE 1=1";
        $params = [];

        if (!empty($search)) {
            $sql .= " AND (Username LIKE ?
                        OR DisplayName LIKE ?
                        OR FirstName LIKE ?
                        OR LastName LIKE ?
                        OR Email LIKE ?)";
            for ($i = 0; $i < 5; $i++) {
                $params[] = "%{$search}%";
            }
        }

        if ($status !== null && $status !== '') {
            $sql     .= " AND IsActive = ?";
            $params[] = (int) $status;
        }

        if (!empty($department)) {
            $sql     .= " AND Department = ?";
            $params[] = $department;
        }

        $sql .= " ORDER BY DisplayName ASC";

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Create a new user
     */
    public function create(array $data): bool
    {
        $passwordHash = isset($data['Password'])
            ? password_hash($data['Password'], PASSWORD_BCRYPT)
            : ($data['PasswordHash'] ?? password_hash('ChangeMe123!', PASSWORD_BCRYPT));

        $sql = "
            INSERT INTO dbo.tblUsers
                (EmployeeID, Username, Email, FirstName, LastName, DisplayName,
                 Phone, Department, JobTitle, Notes, IsActive,
                 IsActivated, ForcePasswordReset, MustChangePassword,
                 PasswordHash, CreatedAt, UpdatedAt, CreatedBy, UpdatedBy)
            VALUES
                (:EmployeeID, :Username, :Email, :FirstName, :LastName, :DisplayName,
                 :Phone, :Department, :JobTitle, :Notes, :IsActive,
                 :IsActivated, :ForcePasswordReset, :MustChangePassword,
                 :PasswordHash, SYSUTCDATETIME(), SYSUTCDATETIME(), :CreatedBy, :UpdatedBy)
        ";

        try {
            $st = $this->conn->prepare($sql);
            return $st->execute([
                ':EmployeeID'         => $data['EmployeeID'] ?? null,
                ':Username'           => $data['Username'] ?? '',
                ':Email'              => $data['Email'] ?? null,
                ':FirstName'          => $data['FirstName'] ?? '',
                ':LastName'           => $data['LastName'] ?? '',
                ':DisplayName'        => $data['DisplayName'] ?? null,
                ':Phone'              => $data['Phone'] ?? null,
                ':Department'         => $data['Department'] ?? null,
                ':JobTitle'           => $data['JobTitle'] ?? null,
                ':Notes'              => $data['Notes'] ?? null,
                ':IsActive'           => $data['IsActive'] ?? 1,
                ':IsActivated'        => $data['IsActivated'] ?? 0,
                ':ForcePasswordReset' => $data['ForcePasswordReset'] ?? 1,
                ':MustChangePassword' => $data['MustChangePassword'] ?? 0,
                ':PasswordHash'       => $passwordHash,
                ':CreatedBy'          => $data['CreatedBy'] ?? null,
                ':UpdatedBy'          => $data['UpdatedBy'] ?? null,
            ]);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Update user
     */
    public function update(int $id, array $data): bool
    {
        $sql = "
            UPDATE dbo.tblUsers
            SET EmployeeID = :EmployeeID,
                Username = :Username,
                Email = :Email,
                FirstName = :FirstName,
                LastName = :LastName,
                DisplayName = :DisplayName,
                Phone = :Phone,
                Department = :Department,
                JobTitle = :JobTitle,
                Notes = :Notes,
                IsActive = :IsActive,
                IsActivated = :IsActivated,
                ForcePasswordReset = :ForcePasswordReset,
                MustChangePassword = :MustChangePassword,
                UpdatedAt = SYSUTCDATETIME(),
                UpdatedBy = :UpdatedBy
            WHERE UserID = :UserID
        ";

        try {
            $st = $this->conn->prepare($sql);
            $executed = $st->execute([
                ':UserID'             => $id,
                ':EmployeeID'         => $data['EmployeeID'] ?? null,
                ':Username'           => $data['Username'] ?? '',
                ':Email'              => $data['Email'] ?? null,
                ':FirstName'          => $data['FirstName'] ?? '',
                ':LastName'           => $data['LastName'] ?? '',
                ':DisplayName'        => $data['DisplayName'] ?? null,
                ':Phone'              => $data['Phone'] ?? null,
                ':Department'         => $data['Department'] ?? null,
                ':JobTitle'           => $data['JobTitle'] ?? null,
                ':Notes'              => $data['Notes'] ?? null,
                ':IsActive'           => $data['IsActive'] ?? 1,
                ':IsActivated'        => $data['IsActivated'] ?? 0,
                ':ForcePasswordReset' => $data['ForcePasswordReset'] ?? 0,
                ':MustChangePassword' => $data['MustChangePassword'] ?? 0,
                ':UpdatedBy'          => $data['UpdatedBy'] ?? null,
            ]);

            if ($executed && $st->rowCount() === 0) {
                $this->lastError = 'No rows updated. User ID may not exist.';
                return false;
            }

            return $executed;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * Unlock user (reset login attempts + locks)
     */
    public function unlock(int $id): int
    {
        try {
            $stUser = $this->conn->prepare("SELECT Username FROM dbo.tblUsers WHERE UserID = :id");
            $stUser->bindValue(':id', $id, PDO::PARAM_INT);
            $stUser->execute();
            $username = $stUser->fetchColumn();
            if (!$username) {
                return 0;
            }

            $stUpdate = $this->conn->prepare("
                UPDATE dbo.tblUsers
                SET FailedLoginCount = 0, LastFailedLoginAt = NULL
                WHERE UserID = :id
            ");
            $stUpdate->bindValue(':id', $id, PDO::PARAM_INT);
            $stUpdate->execute();

            $this->conn->prepare("DELETE FROM dbo.tblLoginLocks WHERE Username = :u")
                ->execute([':u' => $username]);
            $this->conn->prepare("DELETE FROM dbo.tblLoginAttempts WHERE Username = :u")
                ->execute([':u' => $username]);

            return $stUpdate->rowCount();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return 0;
        }
    }

    /**
     * Replace roles for a user
     */
    public function setUserRoles(int $userId, array $roleIds): void
    {
        $this->conn->beginTransaction();
        try {
            // clear existing
            $stmt = $this->conn->prepare("DELETE FROM dbo.tblUserRoles WHERE UserID = :uid");
            $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
            $stmt->execute();

            // insert new
            if (!empty($roleIds)) {
                $stmt = $this->conn->prepare("
                    INSERT INTO dbo.tblUserRoles (UserID, RoleID)
                    VALUES (:uid, :rid)
                ");
                foreach ($roleIds as $rid) {
                    $stmt->execute([
                        ':uid' => $userId,
                        ':rid' => (int) $rid,
                    ]);
                }
            }

            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            $this->lastError = $e->getMessage();
            throw $e;
        }
    }
}
