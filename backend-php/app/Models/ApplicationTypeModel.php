<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class ApplicationTypeModel
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

    public function listAll(?string $q = null, ?string $active = null): array
    {
        $sql = "
            SELECT
                ApplicationTypeID,
                ApplicationTypeKey,
                ApplicationTypeName,
                Description,
                PrivacyAgreementRequired,
                PrivacyAgreementText,
                IsActive,
                CreatedAt,
                UpdatedAt
            FROM dbo.tblApplicationTypes
            WHERE 1 = 1
        ";
        $params = [];

        $q = trim((string)$q);
        if ($q !== '') {
            $sql .= "
                AND (
                    CAST(ApplicationTypeID AS NVARCHAR(50)) = :q_exact
                    OR ApplicationTypeKey LIKE :q_like
                    OR ApplicationTypeName LIKE :q_like
                    OR ISNULL(Description, '') LIKE :q_like
                )
            ";
            $params['q_exact'] = $q;
            $params['q_like'] = '%' . $q . '%';
        }

        if ($active !== null && $active !== '') {
            $sql .= " AND IsActive = :is_active";
            $params['is_active'] = $active === '1' ? 1 : 0;
        }

        $sql .= " ORDER BY ApplicationTypeID ASC";

        try {
            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function find(int $id): ?array
    {
        try {
            $stmt = $this->conn->prepare("
                SELECT *
                FROM dbo.tblApplicationTypes
                WHERE ApplicationTypeID = :id
            ");
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    public function create(array $data): int
    {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO dbo.tblApplicationTypes
                    (ApplicationTypeKey, ApplicationTypeName, Description, PrivacyAgreementRequired, PrivacyAgreementText, IsActive, UpdatedAt)
                VALUES
                    (:type_key, :type_name, :description, :privacy_required, :privacy_text, :is_active, SYSUTCDATETIME());
                SELECT CAST(SCOPE_IDENTITY() AS int);
            ");
            $stmt->execute([
                'type_key' => $data['ApplicationTypeKey'],
                'type_name' => $data['ApplicationTypeName'],
                'description' => $data['Description'] !== '' ? $data['Description'] : null,
                'privacy_required' => $data['PrivacyAgreementRequired'],
                'privacy_text' => $data['PrivacyAgreementText'] !== '' ? $data['PrivacyAgreementText'] : null,
                'is_active' => $data['IsActive'],
            ]);
            return (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return 0;
        }
    }

    public function update(int $id, array $data): bool
    {
        try {
            $stmt = $this->conn->prepare("
                UPDATE dbo.tblApplicationTypes
                SET ApplicationTypeKey = :type_key,
                    ApplicationTypeName = :type_name,
                    Description = :description,
                    PrivacyAgreementRequired = :privacy_required,
                    PrivacyAgreementText = :privacy_text,
                    IsActive = :is_active,
                    UpdatedAt = SYSUTCDATETIME()
                WHERE ApplicationTypeID = :id
            ");
            $ok = $stmt->execute([
                'type_key' => $data['ApplicationTypeKey'],
                'type_name' => $data['ApplicationTypeName'],
                'description' => $data['Description'] !== '' ? $data['Description'] : null,
                'privacy_required' => $data['PrivacyAgreementRequired'],
                'privacy_text' => $data['PrivacyAgreementText'] !== '' ? $data['PrivacyAgreementText'] : null,
                'is_active' => $data['IsActive'],
                'id' => $id,
            ]);
            if ($ok && $stmt->rowCount() === 0) {
                $this->lastError = 'No rows updated. ApplicationTypeID may not exist.';
                return false;
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function delete(int $id): bool
    {
        try {
            $stmt = $this->conn->prepare("
                DELETE FROM dbo.tblApplicationTypes
                WHERE ApplicationTypeID = :id
            ");
            $ok = $stmt->execute(['id' => $id]);
            if ($ok && $stmt->rowCount() === 0) {
                $this->lastError = 'No rows deleted. ApplicationTypeID may not exist.';
                return false;
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }
}
