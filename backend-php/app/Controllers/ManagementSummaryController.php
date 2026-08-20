<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Models\FeedbackModel;
use App\Shared\SessionHelper;

final class ManagementSummaryController extends BaseController
{
    protected array $acl = [
        '*' => ['auth' => true],
        'index' => ['auth' => true],
    ];

    public function index(): void
    {
        $this->ensureAdminAccess();

        if (!($this->db instanceof \PDO)) {
            $this->flashError('Management summary data is unavailable right now.');
            header('Location: index.php?route=home/index');
            exit;
        }

        $feedbackModel = new FeedbackModel($this->db);

        $this->render('admin/ManagementSummary', [
            'title' => 'Defence Credit Card Portal Management Dashboard',
            'summary' => $this->loadSummaryCards(),
            'timingSummary' => $this->loadTimingSummary(),
            'applicationStatusRows' => $this->loadApplicationStatusBreakdown(),
            'applicationTypeRows' => $this->loadApplicationTypeBreakdown(),
            'cardTypeRows' => $this->loadCardTypeBreakdown(),
            'loginTrendRows' => $this->loadLoginTrend(),
            'feedbackSummary' => $feedbackModel->getSummary(),
            'feedbackBreakdown' => $feedbackModel->getStarBreakdown(),
        ]);
    }

    private function ensureAdminAccess(): void
    {
        $roles = SessionHelper::get('auth.roles', []);
        $isAdminRole = is_array($roles) && in_array('admin', $roles, true);
        $isPrivileged = \App\Core\Rbac::canAny(['ADMIN_ALL', 'SYSADMIN']);
        if (!$isAdminRole && !$isPrivileged) {
            $this->flashError('Access denied.');
            header('Location: index.php?route=home/index');
            exit;
        }
    }

    private function loadSummaryCards(): array
    {
        $cards = [
            'totalUsers' => 0,
            'activeUsers' => 0,
            'activatedUsers' => 0,
            'activeSessions' => 0,
            'totalApplications' => 0,
            'applications30d' => 0,
            'pendingApproval' => 0,
            'issuedCards' => 0,
            'successfulUniqueLogins30d' => 0,
            'failedLogins30d' => 0,
        ];

        $cards['totalUsers'] = $this->scalarInt("SELECT COUNT(*) FROM dbo.tblUsers");
        $cards['activeUsers'] = $this->scalarInt("SELECT COUNT(*) FROM dbo.tblUsers WHERE ISNULL(IsActive, 0) = 1");
        $cards['activatedUsers'] = $this->scalarInt("SELECT COUNT(*) FROM dbo.tblUsers WHERE ISNULL(IsActivated, 0) = 1");
        $cards['activeSessions'] = $this->scalarInt("
            SELECT COUNT(*)
            FROM dbo.tblUserSessions
            WHERE ISNULL(IsActive, 0) = 1
              AND (ExpiresAt IS NULL OR ExpiresAt >= SYSUTCDATETIME())
        ");
        $cards['totalApplications'] = $this->scalarInt("SELECT COUNT(*) FROM dbo.tblApplications");
        $cards['applications30d'] = $this->scalarInt("
            SELECT COUNT(*)
            FROM dbo.tblApplications
            WHERE ISNULL(StartedAt, DATEADD(year, -100, SYSUTCDATETIME())) >= DATEADD(day, -30, SYSUTCDATETIME())
        ");
        $cards['pendingApproval'] = $this->scalarInt("
            SELECT COUNT(*)
            FROM dbo.tblApplications
            WHERE Status = 'ToBeApproved'
        ");
        $cards['issuedCards'] = $this->scalarInt("
            SELECT COUNT(*)
            FROM dbo.tblPORTALCards
            WHERE ISNULL(Status, '') = ''
               OR UPPER(LTRIM(RTRIM(ISNULL(Status, '')))) = 'ACTIVE'
               OR UPPER(LTRIM(RTRIM(ISNULL(Status, '')))) LIKE 'XS%'
        ");
        $cards['successfulUniqueLogins30d'] = $this->scalarInt("
            SELECT COUNT(DISTINCT COALESCE(NULLIF(CAST(UserID AS nvarchar(50)), ''), NULLIF(EntityKey, '')))
            FROM dbo.tblAuditLog
            WHERE Action = 'LOGIN'
              AND Entity = 'Auth'
              AND EventTime >= DATEADD(day, -30, SYSDATETIME())
              AND COALESCE(NULLIF(CAST(UserID AS nvarchar(50)), ''), NULLIF(EntityKey, '')) IS NOT NULL
        ");
        $cards['failedLogins30d'] = $this->scalarInt("
            SELECT COUNT(*)
            FROM dbo.tblAuditLog
            WHERE Action = 'DENIED'
              AND Entity = 'Auth'
              AND EventTime >= DATEADD(day, -30, SYSDATETIME())
              AND JSON_VALUE(Details, '$.operation') = 'login'
        ");

        return $cards;
    }

    private function loadTimingSummary(): array
    {
        return [
            'creationToSubmission' => $this->queryOne("
                SELECT
                    COUNT(*) AS SampleCount,
                    AVG(CAST(DATEDIFF(second, StartedAt, SubmittedAt) AS FLOAT)) AS AverageSeconds
                FROM dbo.tblApplications
                WHERE StartedAt IS NOT NULL
                  AND SubmittedAt IS NOT NULL
                  AND SubmittedAt >= StartedAt
            "),
            'submissionToApproval' => $this->queryOne("
                SELECT
                    COUNT(*) AS SampleCount,
                    AVG(CAST(DATEDIFF(second, a.SubmittedAt, approval.ApprovedAt) AS FLOAT)) AS AverageSeconds
                FROM dbo.tblApplications a
                OUTER APPLY (
                    SELECT TOP 1 audit.EventTime AS ApprovedAt
                    FROM dbo.tblAuditLog audit
                    WHERE audit.EntityKey = CAST(a.ApplicationID AS nvarchar(50))
                      AND audit.Action = 'APPROVE'
                      AND audit.Entity IN ('DpcApplicationApproval', 'LimitChangeApproval')
                    ORDER BY audit.EventTime DESC
                ) audit
                OUTER APPLY (
                    SELECT CASE
                        WHEN audit.ApprovedAt IS NOT NULL THEN audit.ApprovedAt
                        WHEN a.Status IN ('Approved', 'SentToBank', 'CardIssued', 'Sent_To_Bank', 'Card_Issued') THEN a.LastSavedAt
                        ELSE NULL
                    END AS ApprovedAt
                ) approval
                WHERE a.SubmittedAt IS NOT NULL
                  AND approval.ApprovedAt IS NOT NULL
                  AND approval.ApprovedAt >= a.SubmittedAt
            "),
        ];
    }

    private function loadApplicationStatusBreakdown(): array
    {
        return $this->queryAll("
            SELECT
                ISNULL(NULLIF(LTRIM(RTRIM(Status)), ''), 'Unknown') AS StatusLabel,
                COUNT(*) AS ItemCount
            FROM dbo.tblApplications
            GROUP BY ISNULL(NULLIF(LTRIM(RTRIM(Status)), ''), 'Unknown')
            ORDER BY COUNT(*) DESC, StatusLabel ASC
        ");
    }

    private function loadApplicationTypeBreakdown(): array
    {
        return $this->queryAll("
            SELECT
                at.ApplicationTypeName,
                at.ApplicationTypeKey,
                COUNT(*) AS ItemCount,
                SUM(CASE WHEN a.StartedAt >= DATEADD(day, -30, SYSUTCDATETIME()) THEN 1 ELSE 0 END) AS Last30Days
            FROM dbo.tblApplications a
            LEFT JOIN dbo.tblApplicationTypes at
              ON at.ApplicationTypeID = a.ApplicationTypeID
            GROUP BY at.ApplicationTypeName, at.ApplicationTypeKey
            ORDER BY COUNT(*) DESC, at.ApplicationTypeName ASC
        ");
    }

    private function loadCardTypeBreakdown(): array
    {
        return $this->queryAll("
            SELECT
                ISNULL(NULLIF(LTRIM(RTRIM(CardType)), ''), 'Unknown') AS CardType,
                ISNULL(NULLIF(LTRIM(RTRIM(CardTypeSub)), ''), '-') AS CardTypeSub,
                COUNT(*) AS TotalCards,
                SUM(CASE
                    WHEN ISNULL(Status, '') = ''
                      OR UPPER(LTRIM(RTRIM(ISNULL(Status, '')))) = 'ACTIVE'
                      OR UPPER(LTRIM(RTRIM(ISNULL(Status, '')))) LIKE 'XS%'
                    THEN 1 ELSE 0 END) AS HeldCards
            FROM dbo.tblPORTALCards
            GROUP BY
                ISNULL(NULLIF(LTRIM(RTRIM(CardType)), ''), 'Unknown'),
                ISNULL(NULLIF(LTRIM(RTRIM(CardTypeSub)), ''), '-')
            ORDER BY COUNT(*) DESC, CardType ASC, CardTypeSub ASC
        ");
    }

    private function loadLoginTrend(): array
    {
        return $this->queryAll("
            WITH DateSeries AS (
                SELECT CAST(CAST(SYSDATETIME() AS date) AS datetime2(0)) AS LoginDate, 1 AS StepNo
                UNION ALL
                SELECT DATEADD(day, -1, LoginDate), StepNo + 1
                FROM DateSeries
                WHERE StepNo < 14
            ),
            Successes AS (
                SELECT
                    CAST(EventTime AS date) AS LoginDate,
                    COUNT(*) AS SuccessfulLogins
                FROM dbo.tblAuditLog
                WHERE Action = 'LOGIN'
                  AND Entity = 'Auth'
                  AND EventTime >= DATEADD(day, -13, CAST(CAST(SYSDATETIME() AS date) AS datetime2(0)))
                GROUP BY CAST(EventTime AS date)
            ),
            Failures AS (
                SELECT
                    CAST(EventTime AS date) AS LoginDate,
                    COUNT(*) AS FailedLogins
                FROM dbo.tblAuditLog
                WHERE Action = 'DENIED'
                  AND Entity = 'Auth'
                  AND JSON_VALUE(Details, '$.operation') = 'login'
                  AND EventTime >= DATEADD(day, -13, CAST(CAST(SYSDATETIME() AS date) AS datetime2(0)))
                GROUP BY CAST(EventTime AS date)
            )
            SELECT
                ds.LoginDate,
                ISNULL(s.SuccessfulLogins, 0) AS SuccessfulLogins,
                ISNULL(f.FailedLogins, 0) AS FailedLogins
            FROM DateSeries ds
            LEFT JOIN Successes s
              ON s.LoginDate = CAST(ds.LoginDate AS date)
            LEFT JOIN Failures f
              ON f.LoginDate = CAST(ds.LoginDate AS date)
            ORDER BY ds.LoginDate DESC
            OPTION (MAXRECURSION 14)
        ");
    }

    private function scalarInt(string $sql): int
    {
        try {
            $stmt = $this->db->query($sql);
            return (int)($stmt ? $stmt->fetchColumn() : 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function queryAll(string $sql): array
    {
        try {
            $stmt = $this->db->query($sql);
            $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
            return is_array($rows) ? $rows : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function queryOne(string $sql): array
    {
        try {
            $stmt = $this->db->query($sql);
            $row = $stmt ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
            return is_array($row) ? $row : [];
        } catch (\Throwable $e) {
            return [];
        }
    }
}
