<?php
declare(strict_types=1);

namespace App\Models;

use PDO;

final class PortalCardModel
{
    private PDO $conn;
    private string $lastError = '';

    /** Only these columns can be inserted/updated by create()/update() */
    private array $allowed = [
        // core identifiers / routing
        'tblCardID', 'EmployeeID', 'ApplicationID',

        // card identity
        'CardType', 'CardTypeSub', 'Title', 'FirstName', 'MiddleName', 'Surname',
        'NameOnCard',

        // contact
        'Email', 'HomePhone', 'WorkPhone', 'MobilePhone',

        // address
        'Address1', 'Address2', 'Address3', 'Suburb', 'State', 'PostCode',

        // account details
        'CardNumber', 'CardNumberShort', 'AccountNumber',
        'CreditLimit', 'CreditLimitAmount', 'ActiveCeiling',
        'TransactionLimit', 'ATMLimit', 'OTCLimit',

        // status/workflow
        'Status', 'ProcessStatus', 'PortalScreenProgress',
        'DefaultCompany', 'DefaultCostCentre',
        'CMSUser',

        // flags
        'Active', 'OnHold', 'ValidAddress',

        // dates (nullable)
        'Expiry',
        'PortalInviteSent',
        'LoggedOntoPortal',
        'TermsAndConditions',
        'AddressConfirmedInPortal',
        'AddressChangedInPortal',

        // misc
        'Notes',

        // auditing columns
        'UpdatedBy',
        // DateUpdated set by SQL (SYSUTCDATETIME())
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

    public function find(int $id): ?array
    {
        try {
            $sql = "SELECT *
                    FROM dbo.tblPORTALCards
                    WHERE CardID = :id";
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

    public function countFiltered(
        ?string $q,
        ?string $employeeId,
        ?string $cardType,
        ?string $status,
        ?string $active,
        ?string $cardTypeSub = null
    ): int {
        $sql = "SELECT COUNT(*) AS cnt
                FROM dbo.tblPORTALCards
                WHERE 1=1";
        $params = [];

        if (!empty($q)) {
            [$searchSql, $searchParams] = $this->buildSearchClause($q);
            $sql .= $searchSql;
            $params = array_merge($params, $searchParams);
        }

        if (!empty($employeeId)) {
            $sql .= " AND EmployeeID = ?";
            $params[] = $employeeId;
        }

        if (!empty($cardType)) {
            $sql .= " AND CardType = ?";
            $params[] = $cardType;
        }

        if (!empty($cardTypeSub)) {
            $sql .= " AND CardTypeSub = ?";
            $params[] = $cardTypeSub;
        }

        if (!empty($status)) {
            $sql .= " AND Status = ?";
            $params[] = $status;
        }

        if ($active !== null && $active !== '') {
            // Expect '1'/'0' from UI; table uses char(1) - commonly 'Y'/'N'
            $sql .= " AND Active = ?";
            $params[] = ((string)$active === '1') ? 'Y' : 'N';
        }

        try {
            $st = $this->conn->prepare($sql);
            $st->execute($params);
            return (int)$st->fetchColumn();
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return 0;
        }
    }

    public function listFiltered(
        ?string $q,
        ?string $employeeId,
        ?string $cardType,
        ?string $status,
        ?string $active,
        ?string $cardTypeSub = null,
        int $offset = 0,
        int $limit = 25
    ): array {
        $sql = "SELECT
                    CardID,
                    EmployeeID,
                    ApplicationID,
                    CardType,
                    CardTypeSub,
                    Title,
                    FirstName,
                    Surname,
                    Address1,
                    Address2,
                    Address3,
                    Suburb,
                    State,
                    PostCode,
                    Email,
                    Status,
                    ProcessStatus,
                    PortalScreenProgress,
                    CardNumberShort,
                    AccountNumber,
                    DefaultCompany,
                    DefaultCostCentre,
                    CreditLimitAmount,
                    ActiveCeiling,
                    Expiry,
                    Active,
                    OnHold,
                    ValidAddress,
                    DateUpdated
                FROM dbo.tblPORTALCards
                WHERE 1=1";
        $params = [];

        if (!empty($q)) {
            [$searchSql, $searchParams] = $this->buildSearchClause($q);
            $sql .= $searchSql;
            $params = array_merge($params, $searchParams);
        }

        if (!empty($employeeId)) {
            $sql .= " AND EmployeeID = ?";
            $params[] = $employeeId;
        }

        if (!empty($cardType)) {
            $sql .= " AND CardType = ?";
            $params[] = $cardType;
        }

        if (!empty($cardTypeSub)) {
            $sql .= " AND CardTypeSub = ?";
            $params[] = $cardTypeSub;
        }

        if (!empty($status)) {
            $sql .= " AND Status = ?";
            $params[] = $status;
        }

        if ($active !== null && $active !== '') {
            $sql .= " AND Active = ?";
            $params[] = ((string)$active === '1') ? 'Y' : 'N';
        }

        $offset = max(0, (int)$offset);
        $limit  = max(1, (int)$limit);

        // Order newest updated first, then id
        $sql .= " ORDER BY DateUpdated DESC, CardID DESC
                  OFFSET {$offset} ROWS FETCH NEXT {$limit} ROWS ONLY";

        try {
            $st = $this->conn->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function listDistinctCardTypes(): array
    {
        try {
            $sql = "SELECT DISTINCT CardType
                    FROM dbo.tblPORTALCards
                    WHERE LTRIM(RTRIM(ISNULL(CardType, ''))) <> ''
                    ORDER BY CardType";
            $st = $this->conn->query($sql);
            return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function listDistinctCardTypeSubs(): array
    {
        try {
            $sql = "SELECT DISTINCT CardTypeSub
                    FROM dbo.tblPORTALCards
                    WHERE LTRIM(RTRIM(ISNULL(CardTypeSub, ''))) <> ''
                    ORDER BY CardTypeSub";
            $st = $this->conn->query($sql);
            return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    public function listDistinctStatuses(): array
    {
        try {
            $sql = "SELECT DISTINCT Status
                    FROM dbo.tblPORTALCards
                    WHERE LTRIM(RTRIM(ISNULL(Status, ''))) <> ''
                    ORDER BY Status";
            $st = $this->conn->query($sql);
            return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
    }

    /** Insert new record. Returns new CardID on success, 0 on failure. */
    public function create(array $data): int
    {
        try {
            $payload = $this->filterAllowed($data);

            // Remove CardID if someone passed it
            unset($payload['CardID']);

            // Always set DateUpdated server-side
            $payload['DateUpdated'] = null; // placeholder; set in SQL
            $payload['UpdatedBy'] = $payload['UpdatedBy'] ?? null;

            // Build INSERT list (skip DateUpdated placeholder; we’ll set it as SYSUTCDATETIME())
            $cols = array_keys($payload);
            $cols = array_values(array_filter($cols, fn($c) => $c !== 'DateUpdated'));

            if (count($cols) === 0) {
                $this->lastError = 'No valid fields supplied.';
                return 0;
            }

            $colSql = implode(', ', array_map(fn($c) => '[' . $c . ']', $cols));
            $valSql = implode(', ', array_map(fn($c) => ':' . $c, $cols));

            $sql = "INSERT INTO dbo.tblPORTALCards ({$colSql}, [DateUpdated])
                    VALUES ({$valSql}, SYSUTCDATETIME());
                    SELECT CAST(SCOPE_IDENTITY() AS int) AS NewID;";

            $st = $this->conn->prepare($sql);

            foreach ($cols as $c) {
                $st->bindValue(':' . $c, $payload[$c]);
            }

            $st->execute();
            $newId = (int)$st->fetchColumn();
            return $newId > 0 ? $newId : 0;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return 0;
        }
    }

    /** Update existing record. Returns true on success. */
    public function update(int $id, array $data): bool
    {
        try {
            $payload = $this->filterAllowed($data);

            // never update identity
            unset($payload['CardID']);

            // Always update DateUpdated server-side
            $payload['DateUpdated'] = null; // placeholder; set in SQL

            if (count($payload) === 0) {
                $this->lastError = 'No valid fields supplied.';
                return false;
            }

            $sets = [];
            $params = [':CardID' => $id];

            foreach ($payload as $col => $val) {
                if ($col === 'DateUpdated') {
                    $sets[] = "[DateUpdated] = SYSUTCDATETIME()";
                    continue;
                }
                $sets[] = '[' . $col . '] = :' . $col;
                $params[':' . $col] = $val;
            }

            $setSql = implode(",\n                ", $sets);

            $sql = "UPDATE dbo.tblPORTALCards
                    SET {$setSql}
                    WHERE CardID = :CardID";

            $st = $this->conn->prepare($sql);
            $ok = $st->execute($params);

            if ($ok && $st->rowCount() === 0) {
                $this->lastError = 'No rows updated. CardID may not exist.';
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
            $sql = "DELETE FROM dbo.tblPORTALCards WHERE CardID = :CardID";
            $st = $this->conn->prepare($sql);
            $ok = $st->execute([':CardID' => $id]);
            if ($ok && $st->rowCount() === 0) {
                $this->lastError = 'No rows deleted. CardID may not exist.';
                return false;
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    private function filterAllowed(array $data): array
    {
        $out = [];
        foreach ($this->allowed as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = $data[$key];
            }
        }
        return $out;
    }

    /**
     * Broad portal-card search used by both the count and list queries.
     */
    private function buildSearchClause(string $q): array
    {
        $q = trim($q);
        $like = '%' . $q . '%';

        return [
            " AND (
                CAST(CardID AS NVARCHAR(50)) = ?
                OR CAST(CardID AS NVARCHAR(50)) LIKE ?
                OR ISNULL(CAST(EmployeeID AS NVARCHAR(50)), '') LIKE ?
                OR ISNULL(FirstName, '') LIKE ?
                OR ISNULL(Surname, '') LIKE ?
                OR LTRIM(RTRIM(ISNULL(FirstName, '') + ' ' + ISNULL(Surname, ''))) LIKE ?
                OR ISNULL(NameOnCard, '') LIKE ?
                OR ISNULL(Email, '') LIKE ?
                OR ISNULL(CardType, '') LIKE ?
                OR ISNULL(CardTypeSub, '') LIKE ?
                OR ISNULL(Status, '') LIKE ?
                OR ISNULL(CardNumberShort, '') LIKE ?
                OR ISNULL(AccountNumber, '') LIKE ?
                OR ISNULL(DefaultCompany, '') LIKE ?
                OR ISNULL(DefaultCostCentre, '') LIKE ?
            )",
            [
                $q,
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
                $like,
            ],
        ];
    }
}
