<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class UserRoleModel
{
    private PDO $conn;

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

   public function listByUser(int $userId): array
{
    $sql = "
        SELECT ur.RoleID, r.RoleName
        FROM dbo.tblUserRoles ur
        JOIN dbo.tblRoles r ON r.RoleID = ur.RoleID
        WHERE ur.UserID = :uid
    ";
    $st = $this->conn->prepare($sql);
    $st->execute([':uid' => $userId]);
    return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}


    public function setRoles(int $userId, array $roleIds): bool
    {
        try {
            $this->conn->beginTransaction();

            // clear existing
            $stmt = $this->conn->prepare("DELETE FROM dbo.tblUserRoles WHERE UserID = :uid");
            $stmt->execute([':uid' => $userId]);

            // add new
            if (!empty($roleIds)) {
                $stmt = $this->conn->prepare(
                    "INSERT INTO dbo.tblUserRoles (UserID, RoleID, DateAssigned)
                     VALUES (:uid, :rid, SYSUTCDATETIME())"
                );
                foreach ($roleIds as $rid) {
                    $stmt->execute([':uid' => $userId, ':rid' => $rid]);
                }
            }

            $this->conn->commit();
            return true;
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            return false;
        }
    }
}
