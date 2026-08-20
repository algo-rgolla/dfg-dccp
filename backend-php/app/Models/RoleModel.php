<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class RoleModel
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
     * List all roles in the system
     */
    public function listAll(): array
    {
        try {
            $sql = "SELECT RoleID, RoleName, Active, DateCreated, DateUpdated 
                    FROM dbo.tblRoles
                    ORDER BY RoleName ASC";
            $stmt = $this->conn->query($sql);
            $roles = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (empty($roles)) {
                error_log('RoleModel::listAll returned empty result');
            }
            return $roles;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            error_log('RoleModel::listAll error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Find a role by ID
     */
    public function find(int $id): ?array
    {
        try {
            $sql = "SELECT RoleID, RoleName, Active, DateCreated, DateUpdated
                    FROM dbo.tblRoles
                    WHERE RoleID = :id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindValue(':id', $id, PDO::PARAM_INT);
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }
}