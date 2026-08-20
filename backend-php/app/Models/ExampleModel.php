<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class ExampleModel
{
    private PDO $conn;
    private string $lastError = '';

    public function __construct(PDO $conn)
    {
        $this->conn = $conn;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    /** List records with optional filters + pagination */
    public function listFiltered(string $q = '', int $page = 1, int $perPage = 10): array
    {
        $offset = ($page - 1) * $perPage;
        $params = [];
        $where  = [];

        if ($q !== '') {
            $where[] = "Name LIKE :q";
            $params[':q'] = "%$q%";
        }

        $sql = "SELECT ExampleID, Name, Description, CreatedAt, UpdatedAt
                FROM dbo.tblExample";
        if ($where) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY ExampleID ASC
                  OFFSET :off ROWS FETCH NEXT :lim ROWS ONLY";

        $stmt = $this->conn->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);

        if (!$stmt->execute()) {
            $this->lastError = implode(' | ', $stmt->errorInfo());
            return [];
        }

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /** Count total records (for pagination) */
    public function countFiltered(string $q = ''): int
    {
        $params = [];
        $where  = [];

        if ($q !== '') {
            $where[] = "Name LIKE :q";
            $params[':q'] = "%$q%";
        }

        $sql = "SELECT COUNT(*) FROM dbo.tblExample";
        if ($where) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);

        return (int)$stmt->fetchColumn();
    }

    /** Find a record */
    public function find(int $id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM dbo.tblExample WHERE ExampleID = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Create */
    public function create(array $data): bool
    {
        $sql = "INSERT INTO dbo.tblExample (Name, Description, CreatedAt)
                VALUES (:Name, :Description, SYSUTCDATETIME())";

        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([
            ':Name'        => $data['Name'] ?? '',
            ':Description' => $data['Description'] ?? '',
        ]);
    }

    /** Update */
    public function update(int $id, array $data): bool
    {
        $sql = "UPDATE dbo.tblExample
                SET Name = :Name, Description = :Description, UpdatedAt = SYSUTCDATETIME()
                WHERE ExampleID = :id";

        $stmt = $this->conn->prepare($sql);
        return $stmt->execute([
            ':id'          => $id,
            ':Name'        => $data['Name'] ?? '',
            ':Description' => $data['Description'] ?? '',
        ]);
    }

    /** Delete */
    public function delete(int $id): bool
    {
        $stmt = $this->conn->prepare("DELETE FROM dbo.tblExample WHERE ExampleID = :id");
        return $stmt->execute([':id' => $id]);
    }
}
