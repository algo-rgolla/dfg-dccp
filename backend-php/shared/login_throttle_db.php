<?php
declare(strict_types=1);

/**
 * DB-backed login throttling for SQL Server (PDO sqlsrv)
 *
 * Tables (aligned with your schema):
 *
 * dbo.tblLoginAttempts(
 *   AttemptID     INT IDENTITY PRIMARY KEY,
 *   Username      NVARCHAR(100) NOT NULL,
 *   IP            NVARCHAR(45) NULL,
 *   AttemptTime   DATETIME2(3)  NOT NULL DEFAULT SYSUTCDATETIME(),
 *   Success       BIT           NOT NULL DEFAULT (0)
 * );
 *
 * dbo.tblLoginLocks(
 *   LockID        INT IDENTITY PRIMARY KEY,
 *   Username      NVARCHAR(100) NOT NULL UNIQUE,
 *   IP            NVARCHAR(45) NULL,
 *   NormalizedIP  AS (ISNULL([IP],N'')) PERSISTED NOT NULL,
 *   LockedUntil   DATETIME2(0)  NOT NULL,
 *   Reason        NVARCHAR(50)  NOT NULL DEFAULT ('too_many_attempts'),
 *   CreatedAt     DATETIME2(3)  NOT NULL DEFAULT SYSUTCDATETIME()
 * );
 */

/////////////////////////////
// Internal column resolver
/////////////////////////////
if (!function_exists('lt_detect_user_col')) {
    function lt_detect_user_col(\PDO $conn): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $detect = function(string $table, array $candidates) use ($conn): string {
            foreach ($candidates as $cand) {
                $sql = "
                    SELECT 1
                    FROM INFORMATION_SCHEMA.COLUMNS
                    WHERE TABLE_SCHEMA = 'dbo'
                      AND TABLE_NAME   = :t
                      AND COLUMN_NAME  = :c
                ";
                $st = $conn->prepare($sql);
                $st->execute([':t' => $table, ':c' => $cand]);
                if ($st->fetchColumn()) {
                    return $cand;
                }
            }
            return 'Username';
        };

        $attemptsCol = $detect('tblLoginAttempts', ['UsernameKey', 'Username']);
        $locksCol    = $detect('tblLoginLocks',    ['UsernameKey', 'Username']);

        $cache = ['attempts_col' => $attemptsCol, 'locks_col' => $locksCol];
        return $cache;
    }
}

/////////////////////////////
// Config
/////////////////////////////
if (!function_exists('lt_get_config')) {
    function lt_get_config(\PDO $conn): array
    {
        $maxAttempts   = 5;
        $decayMinutes  = 10;
        $lockMinutes   = 15;
        $permanentFlag = false;

        try {
            require_once __DIR__ . '/../app/Models/SystemSettingsModel.php';
            $ss = new \App\Models\SystemSettingsModel($conn);
            $maxAttempts   = (int)($ss->get('LOGIN_MAX_ATTEMPTS', (string)$maxAttempts));
            $decayMinutes  = (int)($ss->get('LOGIN_DECAY_MIN',    (string)$decayMinutes));
            $lockMinutes   = (int)($ss->get('LOGIN_LOCKOUT_MIN',  (string)$lockMinutes));
            $permanentFlag = filter_var(
                $ss->get('LOGIN_LOCKOUT_PERMANENT', 'false'),
                FILTER_VALIDATE_BOOLEAN
            );
        } catch (\Throwable $e) {
            // fall back to defaults
        }

        return [
            'maxAttempts'      => max(1, $maxAttempts),
            'decaySeconds'     => max(60, $decayMinutes * 60),
            'lockSeconds'      => max(60, $lockMinutes * 60),
            'permanentLockout' => $permanentFlag,
        ];
    }
}

/////////////////////////////
// Precheck (is user locked?)
/////////////////////////////
if (!function_exists('lt_precheck')) {
    function lt_precheck(\PDO $conn, string $usernameKey, string $ip): array
    {
        $cols = lt_detect_user_col($conn);
        $uCol = $cols['locks_col'];

        $sql = "
            SELECT LockedUntil
            FROM dbo.tblLoginLocks
            WHERE $uCol = :u
              AND LockedUntil > SYSUTCDATETIME();
        ";
        $st = $conn->prepare($sql);
        $st->bindValue(':u', $usernameKey, \PDO::PARAM_STR);
        $st->execute();

        $lockedUntil = $st->fetchColumn();
        if ($lockedUntil) {
            // If locked far in future, treat as permanent
            if ($lockedUntil >= '2099-01-01') {
                return ['locked' => true, 'retry_after' => -1];
            }
            $secs = (int)$conn->query("SELECT DATEDIFF(SECOND, SYSUTCDATETIME(), CAST('$lockedUntil' AS DATETIME2))")->fetchColumn();
            if ($secs > 0) {
                return ['locked' => true, 'retry_after' => $secs];
            }
        }
        return ['locked' => false];
    }
}

/////////////////////////////
// Register failure
/////////////////////////////
if (!function_exists('lt_fail')) {
    function lt_fail(
        \PDO $conn,
        string $usernameKey,
        string $ip,
        int $maxAttempts,
        int $decaySeconds,
        int $lockSeconds,
        bool $permanent = false
    ): array {
        $cols = lt_detect_user_col($conn);
        $aCol = $cols['attempts_col'];
        $lCol = $cols['locks_col'];

        // 1) Record attempt
        $ins = $conn->prepare("
            INSERT INTO dbo.tblLoginAttempts ($aCol, IP)
            VALUES (:u, :ip);
        ");
        $ins->bindValue(':u', $usernameKey, \PDO::PARAM_STR);
        $ins->bindValue(':ip', $ip, \PDO::PARAM_STR);
        $ins->execute();

        // 2) Count attempts in decay window
        $countSql = "
            SELECT COUNT(*) AS Cnt
            FROM dbo.tblLoginAttempts
            WHERE $aCol = :u
              AND AttemptTime >= DATEADD(SECOND, -CAST(:decay AS INT), SYSUTCDATETIME());
        ";
        $cnt = $conn->prepare($countSql);
        $cnt->bindValue(':u', $usernameKey, \PDO::PARAM_STR);
        $cnt->bindValue(':decay', $decaySeconds, \PDO::PARAM_INT);
        $cnt->execute();

        $attempts  = (int)$cnt->fetchColumn();
        $remaining = max(0, $maxAttempts - $attempts);

        // 3) Threshold check
        if ($attempts >= $maxAttempts) {
            if ($permanent) {
                $lockedUntil = '9999-12-31T23:59:59';
                // Permanent lock
                $upd = $conn->prepare("
                    UPDATE dbo.tblLoginLocks
                    SET LockedUntil = :lu
                    WHERE $lCol = :u;
                ");
                $upd->bindValue(':u', $usernameKey, \PDO::PARAM_STR);
                $upd->bindValue(':lu', $lockedUntil, \PDO::PARAM_STR);
                $upd->execute();

                if ($upd->rowCount() === 0) {
                    $insLock = $conn->prepare("
                        INSERT INTO dbo.tblLoginLocks ($lCol, LockedUntil)
                        VALUES (:u, :lu);
                    ");
                    $insLock->bindValue(':u', $usernameKey, \PDO::PARAM_STR);
                    $insLock->bindValue(':lu', $lockedUntil, \PDO::PARAM_STR);
                    $insLock->execute();
                }

                return ['locked' => true, 'retry_after' => -1];
            } else {
                // Temporary lock
                $upd = $conn->prepare("
                    UPDATE dbo.tblLoginLocks
                    SET LockedUntil = DATEADD(SECOND, CAST(:lock AS INT), SYSUTCDATETIME())
                    WHERE $lCol = :u;
                ");
                $upd->bindValue(':u', $usernameKey, \PDO::PARAM_STR);
                $upd->bindValue(':lock', $lockSeconds, \PDO::PARAM_INT);
                $upd->execute();

                if ($upd->rowCount() === 0) {
                    $insLock = $conn->prepare("
                        INSERT INTO dbo.tblLoginLocks ($lCol, LockedUntil)
                        VALUES (:u, DATEADD(SECOND, CAST(:lock AS INT), SYSUTCDATETIME()));
                    ");
                    $insLock->bindValue(':u', $usernameKey, \PDO::PARAM_STR);
                    $insLock->bindValue(':lock', $lockSeconds, \PDO::PARAM_INT);
                    $insLock->execute();
                }

                // Compute retry-after
                $ra = $conn->prepare("
                    SELECT DATEDIFF(SECOND, SYSUTCDATETIME(), LockedUntil)
                    FROM dbo.tblLoginLocks
                    WHERE $lCol = :u
                      AND LockedUntil > SYSUTCDATETIME();
                ");
                $ra->bindValue(':u', $usernameKey, \PDO::PARAM_STR);
                $ra->execute();
                $retryAfter = (int)($ra->fetchColumn() ?: 0);

                return ['locked' => true, 'retry_after' => max(0, $retryAfter)];
            }
        }

        return ['remaining' => $remaining];
    }
}

/////////////////////////////
// Success (clear state)
/////////////////////////////
if (!function_exists('lt_success')) {
    function lt_success(\PDO $conn, string $usernameKey, string $ip): void
    {
        $cols = lt_detect_user_col($conn);
        $aCol = $cols['attempts_col'];
        $lCol = $cols['locks_col'];

        $delA = $conn->prepare("DELETE FROM dbo.tblLoginAttempts WHERE $aCol = :u;");
        $delA->bindValue(':u', $usernameKey, \PDO::PARAM_STR);
        $delA->execute();

        $delL = $conn->prepare("DELETE FROM dbo.tblLoginLocks WHERE $lCol = :u;");
        $delL->bindValue(':u', $usernameKey, \PDO::PARAM_STR);
        $delL->execute();
    }
}
