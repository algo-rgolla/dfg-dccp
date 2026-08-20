<?php
declare(strict_types=1);

namespace App\Models;

final class SystemSettingsModel
{
    private \PDO $pdo;
    private string $lastError = '';

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function getLastError(): string { return $this->lastError; }

    /**
     * Fetch a setting value by key.
     */
    public function get(string $key): ?string
    {
        try {
            $st = $this->pdo->prepare("SELECT SettingValue FROM dbo.tblSystemSettings WHERE SettingKey = :k");
            $st->execute([':k' => $key]);
            $val = $st->fetchColumn();
            return $val !== false ? (string)$val : null;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    /**
     * Return all settings (existing method).
     */
    public function listAll(): array
    {
        try {
            $st = $this->pdo->query("SELECT SettingKey, SettingValue, SettingType FROM dbo.tblSystemSettings ORDER BY SettingKey ASC");
            return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    /**
     * Insert/update a setting (existing method).
     */
  public function set(string $key, string $val, string $type, string $updatedBy): bool
{
    try {
        // 1) Try UPDATE first
        $upd = $this->pdo->prepare("
            UPDATE dbo.tblSystemSettings
               SET SettingValue = :v,
                   SettingType  = :t,
                   UpdatedBy    = :u,
                   UpdatedAt    = SYSDATETIME()
             WHERE SettingKey   = :k
        ");
        $upd->execute([
            ':v' => $val,
            ':t' => $type,
            ':u' => $updatedBy,
            ':k' => $key,
        ]);

        if ($upd->rowCount() > 0) {
            return true; // updated existing row
        }

        // 2) If nothing updated, INSERT a new row
        $ins = $this->pdo->prepare("
            INSERT INTO dbo.tblSystemSettings
                (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
            VALUES
                (:k, :v, :t, NULL, :u, SYSDATETIME())
        ");
        $ins->execute([
            ':k' => $key,
            ':v' => $val,
            ':t' => $type,
            ':u' => $updatedBy,
        ]);

        return true;
    } catch (\Throwable $e) {
        $this->lastError = $e->getMessage();
        return false;
    }
}

}
