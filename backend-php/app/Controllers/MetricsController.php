<?php
declare(strict_types=1);

namespace App\Controllers;

use PDO;

require_once __DIR__ . '/../../config/db.php';   // <-- get $conn
require_once __DIR__ . '/../../shared/logger.php';

class MetricsController extends BaseController
{
    protected array $acl = [
        // Default: lock everything down
        '*'              => ['auth' => true, 'permsAny' => ['SYSADMIN']],

        // Allow specific metrics views for users with METRICS_VIEW or SYSADMIN
        'failedLogins'   => ['auth' => true, 'permsAny' => ['METRICS_VIEW','SYSADMIN']],
        'errorsTrend'    => ['auth' => true, 'permsAny' => ['METRICS_VIEW','SYSADMIN']],
        'health'         => ['auth' => true, 'permsAny' => ['METRICS_VIEW','SYSADMIN']],
    ];

    public function __construct()
    {
        parent::__construct(); // ✅ enforce auth + ACL checks
    }
    
    /**
     * Show failed login attempts per day (last 30 days).
     */
    public function failedLogins(): void
    {
        global $conn;

        $sql = "
            SELECT CONVERT(date, AttemptTime) AS d, COUNT(*) AS c
            FROM dbo.tblLoginAttempts
            WHERE AttemptTime >= DATEADD(day, -30, GETDATE())
              AND Success = 0
            GROUP BY CONVERT(date, AttemptTime)
            ORDER BY d ASC
        ";
        $stmt = $conn->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $chartData = [
            'labels' => array_map(fn($r) => $r['d'], $rows),
            'values' => array_map(fn($r) => (int)$r['c'], $rows),
        ];

        $this->render('metrics/FailedLoginsView', [
            'chartData' => $chartData,
        ]);
    }

    /**
     * Show error counts per day from app logs.
     */
    public function errorsTrend(): void
    {
        $logDir = __DIR__ . '/../../logs';
        $files  = glob($logDir . '/app-*.log');

        $counts = [];
        foreach ($files as $file) {
            if (preg_match('/app-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m)) {
                $date = $m[1];
                $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                $errors = 0;
                foreach ($lines as $line) {
                    if (strpos($line, '[ERROR]') !== false) {
                        $errors++;
                    }
                }
                $counts[$date] = $errors;
            }
        }

        ksort($counts);
        $counts = array_slice($counts, -30, 30, true);

        $chartData = [
            'labels' => array_keys($counts),
            'values' => array_values($counts),
        ];

        $this->render('metrics/ErrorsTrendView', [
            'chartData' => $chartData,
        ]);
    }

    /**
     * Basic system health summary.
     */
    public function health(): void
    {
        $health = [];

        // DB check
        try {
            global $conn;
            $conn->query("SELECT 1");
            $health['db'] = 'OK';
        } catch (\Throwable $e) {
            $health['db'] = 'FAIL: ' . $e->getMessage();
        }

        // SMTP check
        try {
            $host = envStr('SMTP_HOST', 'localhost');
            $port = (int)envStr('SMTP_PORT', 25);
            $connSmtp = @fsockopen($host, $port, $errno, $errstr, 2);
            if ($connSmtp) {
                fclose($connSmtp);
                $health['smtp'] = 'OK';
            } else {
                $health['smtp'] = "FAIL: $errstr ($errno)";
            }
        } catch (\Throwable $e) {
            $health['smtp'] = 'FAIL: ' . $e->getMessage();
        }

        // Disk space
        $free = @disk_free_space(__DIR__);
        $total = @disk_total_space(__DIR__);
        if ($free !== false && $total !== false) {
            $percent = round(($free / $total) * 100, 1);
            $health['disk'] = "Free: $percent%";
        } else {
            $health['disk'] = 'Unknown';
        }

        // Session round-trip
        try {
            $_SESSION['health_check'] = 'ok';
            if ($_SESSION['health_check'] === 'ok') {
                $health['session'] = 'OK';
            } else {
                $health['session'] = 'FAIL';
            }
        } catch (\Throwable $e) {
            $health['session'] = 'FAIL: ' . $e->getMessage();
        }

        $this->render('metrics/HealthSummaryView', [
            'health' => $health,
        ]);
    }
}
