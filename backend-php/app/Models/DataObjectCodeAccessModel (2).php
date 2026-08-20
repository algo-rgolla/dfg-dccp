<?php
declare(strict_types=1);

namespace App\Models;

final class DataObjectCodesModel
{
    private \PDO $pdo;
    private string $lastError = '';

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    /* ------------------------------------------------------------------ */
    /*  LIST / PAGINATION – unchanged (kept exactly as you had it)       */
    /* ------------------------------------------------------------------ */
  public function listPaged(
    int $fiscalYearID,
    int $page,
    int $pageSize,
    ?string $q = null,
    ?string $sortCol = 'DataObjectCode',
    ?string $sortDir = 'ASC',
    ?int $typeId = null,
    ?string $status = null
): array {
    $page     = max(1, $page);
    $pageSize = max(1, min(200, $pageSize));
    $offset   = ($page - 1) * $pageSize;

    $sortMap = [
        'DataObjectCode'       => 'o.DataObjectCode',
        'DataObjectName'       => 'o.DataObjectName',
        'DataObjectCodeParent' => 'o.DataObjectCodeParent',
        'DataObjectTypeID'     => 't.DataObjectTypeName',   // <-- sort by name
        'DataObjectCodeStatus' => 'o.DataObjectCodeStatus',
        'DateUpdated'          => 'o.DateUpdated',
    ];
    $orderBy = $sortMap[$sortCol ?? 'DataObjectCode'] ?? 'o.DataObjectCode';
    $dir     = strtoupper((string)$sortDir) === 'DESC' ? 'DESC' : 'ASC';

    $where  = ['o.FiscalYearID = :fy'];
    $params = [':fy' => $fiscalYearID];

    if ($q !== null && $q !== '') {
        $where[]      = '(o.DataObjectCode LIKE :q OR o.DataObjectName LIKE :q OR o.DataObjectDesc LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }
    if ($typeId !== null) {
        $where[]           = 'o.DataObjectTypeID = :typeId';
        $params[':typeId'] = $typeId;
    }
    if ($status !== null && $status !== '') {
        $where[]           = "COALESCE(o.DataObjectCodeStatus, '') = :status";
        $params[':status'] = $status;
    }

    $whereSql = implode(' AND ', $where);

    // TOTAL COUNT
    try {
        $st = $this->pdo->prepare("
            SELECT COUNT(*) 
            FROM tblDataObjectCodes o
            LEFT JOIN tblDataObjectTypes t ON o.DataObjectTypeID = t.DataObjectTypeID
            WHERE {$whereSql}
        ");
        $st->execute($params);
        $total = (int)$st->fetchColumn();
    } catch (\Throwable $e) {
        $this->lastError = $e->getMessage();
        $total = 0;
    }

    // ROWS – include DataObjectTypeName
    $sql = "
        SELECT
            o.FiscalYearID,
            o.DataObjectCode,
            o.DataObjectName,
            o.DataObjectCodeParent,
            o.DataObjectTypeID,
            t.DataObjectTypeName,
            o.DataObjectDesc,
            o.DataObjectCodeStatus,
            o.UpdatedBy,
            o.DateUpdated
        FROM tblDataObjectCodes o
        LEFT JOIN tblDataObjectTypes t ON o.DataObjectTypeID = t.DataObjectTypeID
        WHERE {$whereSql}
        ORDER BY {$orderBy} {$dir}
        OFFSET :off ROWS FETCH NEXT :lim ROWS ONLY;
    ";

    try {
        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':off', $offset, \PDO::PARAM_INT);
        $st->bindValue(':lim', $pageSize, \PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $this->lastError = $e->getMessage();
        $rows = [];
    }

    return ['rows' => $rows, 'total' => $total];
}

    public function getOne(int $fiscalYearID, string $code): ?array
    {
        $sql = "
            SELECT TOP 1 *
            FROM tblDataObjectCodes
            WHERE FiscalYearID = :fy AND DataObjectCode = :code;
        ";
        try {
            $st = $this->pdo->prepare($sql);
            $st->execute([':fy' => $fiscalYearID, ':code' => $code]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  UPSERT – fully bound, matches your table exactly                 */
    /* ------------------------------------------------------------------ */
    public function upsert(int $fiscalYearID, array $data, int $userId): bool
{
    $code   = trim($data['DataObjectCode'] ?? '');
    $name   = trim($data['DataObjectName'] ?? '');
    $parent = trim($data['DataObjectCodeParent'] ?? '');
    $typeId = (int)($data['DataObjectTypeID'] ?? 0);
    $desc   = $data['DataObjectDesc'] ?? null;
    $status = $data['DataObjectCodeStatus'] ?? 'Active';

    // Validation
    if ($code === '' || $name === '' || $typeId <= 0) {
        $this->lastError = 'Missing required fields.';
        return false;
    }
    if ($status !== 'Active' && $status !== 'Inactive') {
        $this->lastError = 'Invalid DataObjectCodeStatus; must be Active or Inactive.';
        return false;
    }
    if ($userId <= 0) {
        $this->lastError = 'Invalid UpdatedBy; must be a positive integer.';
        return false;
    }
    if ($parent !== '' && $parent === $code) {
        $this->lastError = 'Parent cannot be the same as the code.';
        return false;
    }
    if (!$this->typeExists($typeId)) {
        $this->lastError = 'Invalid DataObjectTypeID.';
        return false;
    }
    if ($parent !== '' && !$this->codeExists($fiscalYearID, $parent)) {
        $this->lastError = 'Parent code not found in the same Fiscal Year.';
        return false;
    }
    if (strlen($code) > 50 || strlen($name) > 100 || ($desc !== null && strlen($desc) > 500)) {
        $this->lastError = 'Input exceeds column length limits.';
        return false;
    }

    // MERGE with POSITIONAL parameters (?)
    $sql = <<<'SQL'
MERGE tblDataObjectCodes AS tgt
USING (VALUES (?, ?)) AS src (FiscalYearID, DataObjectCode)
ON (tgt.FiscalYearID = src.FiscalYearID AND tgt.DataObjectCode = src.DataObjectCode)
WHEN MATCHED THEN
    UPDATE SET
        DataObjectName       = ?,
        DataObjectCodeParent = ?,
        DataObjectTypeID     = ?,
        DataObjectDesc       = ?,
        DataObjectCodeStatus = ?,
        UpdatedBy            = ?,
        DateUpdated          = SYSUTCDATETIME()
WHEN NOT MATCHED THEN
    INSERT (FiscalYearID, DataObjectCode, DataObjectName, DataObjectCodeParent,
            DataObjectTypeID, DataObjectDesc, DataObjectCodeStatus,
            UpdatedBy, DateUpdated)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, SYSUTCDATETIME());
SQL;

    try {
        $st = $this->pdo->prepare($sql);

        $params = [
            $fiscalYearID,
            $code,
            $name,
            $parent !== '' ? $parent : null,
            $typeId,
            $desc,
            $status,
            $userId,
            // INSERT values (same order)
            $fiscalYearID,
            $code,
            $name,
            $parent !== '' ? $parent : null,
            $typeId,
            $desc,
            $status,
            $userId,
        ];

        return $st->execute($params);
    } catch (\Throwable $e) {
        $this->lastError = $e->getMessage();
        return false;
    }
}

    /* ------------------------------------------------------------------ */
    /*  DELETE                                                            */
    /* ------------------------------------------------------------------ */
    public function delete(int $fiscalYearID, string $code): bool
    {
        try {
            $st = $this->pdo->prepare("
                DELETE FROM tblDataObjectCodes
                WHERE FiscalYearID = :fy AND DataObjectCode = :code;
            ");
            return $st->execute([':fy' => $fiscalYearID, ':code' => $code]);
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  HELPER METHODS                                                    */
    /* ------------------------------------------------------------------ */
    private function codeExists(int $fy, string $code): bool
    {
        $st = $this->pdo->prepare("
            SELECT 1 FROM tblDataObjectCodes
            WHERE FiscalYearID = :fy AND DataObjectCode = :c;
        ");
        $st->execute([':fy' => $fy, ':c' => $code]);
        return (bool)$st->fetchColumn();
    }

    private function typeExists(int $typeId): bool
    {
        $st = $this->pdo->prepare("
            SELECT 1 FROM tblDataObjectTypes WHERE DataObjectTypeID = :t;
        ");
        $st->execute([':t' => $typeId]);
        return (bool)$st->fetchColumn();
    }

    public function listTypes(): array
    {
        try {
            $st = $this->pdo->query("
                SELECT DataObjectTypeID, DataObjectTypeName
                FROM tblDataObjectTypes
                ORDER BY DataObjectTypeName;
            ");
            return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function listForFiscalYear(int $fy, ?string $status = null, ?int $currentLevel = null): array
    {
        $sql = "
            SELECT 
                doc.DataObjectCode,
                doc.DataObjectName,
                doc.DataObjectTypeID,
                dot.DataObjectTypeName,
                dot.Level
            FROM tblDataObjectCodes doc
            INNER JOIN tblDataObjectTypes dot ON doc.DataObjectTypeID = dot.DataObjectTypeID
            WHERE doc.FiscalYearID = :fy
        ";
        $params = [':fy' => $fy];

        if ($status !== null && $status !== '') {
            $sql .= " AND COALESCE(doc.DataObjectCodeStatus, '') = :status";
            $params[':status'] = $status;
        }

        // ONLY SHOW PARENTS WITH LOWER hierarchy level
        if ($currentLevel !== null && $currentLevel > 1) {
            $sql .= " AND dot.Level < :currentLevel";
            $params[':currentLevel'] = $currentLevel;
        }

        $sql .= " ORDER BY doc.DataObjectCode ASC";

        try {
            $st = $this->pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }
}