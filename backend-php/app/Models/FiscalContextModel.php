<?php
declare(strict_types=1);

namespace App\Models;

final class FiscalContextModel
{
    private \PDO $pdo;
    private string $lastError = '';

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function getLastError(): string { return $this->lastError; }

    public function listFiscalYears(): array
    {
        try {
            $sql = "
                SELECT FiscalYearID, YearLabel
                FROM dbo.tblFiscalYears
                WHERE IsActive = 1
                ORDER BY FiscalYearID DESC;
            ";
            return $this->pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

   public function listVersions(int $fiscalYearID): array
    {
        try {
            $sql = "
                SELECT VersionID, VersionLabel, IsDefault
                FROM dbo.tblVersions
                WHERE FiscalYearID = :fy AND IsActive = 1
                ORDER BY IsDefault DESC, VersionID ASC;  -- default first
            ";
            $st = $this->pdo->prepare($sql);
            $st->execute([':fy' => $fiscalYearID]);
            return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

}
