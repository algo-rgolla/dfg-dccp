<?php
declare(strict_types=1);

namespace App\Models;

final class AuditModel
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
     * Insert an audit record into tblAuditLog
     */
    public function insert(array $data): bool
    {
        try {
            $ip   = $_SERVER['REMOTE_ADDR'] ?? null;
            $json = !empty($data['Details'])
                ? json_encode($data['Details'], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
                : null;

            $st = $this->pdo->prepare("
                INSERT INTO dbo.tblAuditLog
                    (EventTime, UserID, Username, Action, Entity, EntityKey, IPAddress, Details, FiscalYearID, VersionID)
                VALUES
                    (SYSDATETIME(), :uid, :uname, :act, :ent, :ek, :ip, :det, :fy, :ver)
            ");
            $st->execute([
                ':uid'   => $data['UserID']       ?? null,
                ':uname' => $data['Username']     ?? null,
                ':act'   => $data['Action'],
                ':ent'   => $data['Entity'],
                ':ek'    => $data['EntityKey']    ?? null,
                ':ip'    => $ip,
                ':det'   => $json,
                ':fy'    => $data['FiscalYearID'] ?? null,
                ':ver'   => $data['VersionID']    ?? null,
            ]);
            return true;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /**
     * List logs with filters + pagination
     */
    public function listLogs(
        string $q,
        string $entity,
        string $userFilter,
        string $actionFilter,
        string $startDate,
        string $endDate,
        int $page,
        int $pageSize,
        ?int $fiscalYearID = null,
        ?int $versionID = null
    ): array {
        $where = [];
        $args  = [];

        if ($q !== '') {
            $where[] = '(Username LIKE :q OR Entity LIKE :q OR EntityKey LIKE :q OR Action LIKE :q)';
            $args[':q'] = "%{$q}%";
        }
        if ($entity !== '') {
            $where[] = 'Entity = :entity';
            $args[':entity'] = $entity;
        }
        if ($userFilter !== '') {
            $where[] = 'Username = :userFilter';
            $args[':userFilter'] = $userFilter;
        }
        if ($actionFilter !== '') {
            $where[] = 'Action = :actionFilter';
            $args[':actionFilter'] = $actionFilter;
        }
        if ($startDate !== '') {
            $where[] = 'EventTime >= :startDate';
            $args[':startDate'] = $startDate . ' 00:00:00';
        }
        if ($endDate !== '') {
            $where[] = 'EventTime <= :endDate';
            $args[':endDate'] = $endDate . ' 23:59:59';
        }
        if ($fiscalYearID !== null && $versionID !== null) {
            $where[] = '((FiscalYearID = :fy AND VersionID = :ver) OR (COALESCE(FiscalYearID, 0) = 0 AND COALESCE(VersionID, 0) = 0))';
            $args[':fy'] = $fiscalYearID;
            $args[':ver'] = $versionID;
        } elseif ($fiscalYearID !== null) {
            $where[] = '(FiscalYearID = :fy OR COALESCE(FiscalYearID, 0) = 0)';
            $args[':fy'] = $fiscalYearID;
        } elseif ($versionID !== null) {
            $where[] = '(VersionID = :ver OR COALESCE(VersionID, 0) = 0)';
            $args[':ver'] = $versionID;
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $offset   = ($page - 1) * $pageSize;

        try {
            // total
            $sqlCount = "SELECT COUNT(*) FROM dbo.tblAuditLog {$whereSql}";
            $stc = $this->pdo->prepare($sqlCount);
            $stc->execute($args);
            $total = (int)$stc->fetchColumn();

            // page
            $sql = "
                SELECT AuditID, EventTime, UserID, Username, Action, Entity, EntityKey,
                       IPAddress, Details, FiscalYearID, VersionID
                FROM dbo.tblAuditLog
                {$whereSql}
                ORDER BY AuditID DESC
                OFFSET :off ROWS FETCH NEXT :lim ROWS ONLY
            ";
            $st = $this->pdo->prepare($sql);
            foreach ($args as $k => $v) {
                $st->bindValue($k, $v);
            }
            $st->bindValue(':off', $offset, \PDO::PARAM_INT);
            $st->bindValue(':lim', $pageSize, \PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            return ['items' => $rows, 'total' => $total];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return ['items' => [], 'total' => 0];
        }
    }

    /**
     * Distinct entity names for filter
     */
    public function distinctEntities(): array
    {
        try {
            $rs = $this->pdo
                ->query("SELECT DISTINCT Entity FROM dbo.tblAuditLog ORDER BY Entity ASC")
                ->fetchAll(\PDO::FETCH_COLUMN);
            return array_values(array_filter($rs, fn($x) => (string)$x !== ''));
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }
}
