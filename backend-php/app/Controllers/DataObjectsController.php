<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Shared\SessionHelper;

final class DataObjectsController extends BaseController
{
    protected bool $requiresContext = true;
    
    /** Require login for all actions */
    protected array $acl = [
        '*' => ['auth' => true],
    ];

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Picker UI (roots).
     *
     * Query params:
     *  - iframe=1            Render without main layout
     *  - showAll=1           Ignore user grants; show true top-level nodes
     *  - fy=YYYY             Override FiscalYearID (falls back to session)
     *  - selected=CODE       Pre-select a node; view will auto-expand to it
     *  - return=<relative>   Back link target (sanitized to relative only)
     */
    public function picker(): void
{
    require __DIR__ . '/../../config/db.php';

    $uid       = (int) SessionHelper::get('auth.user_id', 0);
    $fy        = (int) (SessionHelper::get('FiscalYearID') ?? 0);
    $showAll   = (($_GET['showAll'] ?? '') === '1');
    $selected  = (string) ($_GET['selected'] ?? '');

    if ($selected === '') {
        $selected = (string) (SessionHelper::get('scope.dataobject_code') ?? '');
    }
    $isIframe  = !empty($_GET['iframe']);

    if (isset($_GET['fy']) && is_numeric($_GET['fy'])) {
        $fy = (int) $_GET['fy'];
    }

    $backUrl = (string)($_GET['return'] ?? ($_SERVER['HTTP_REFERER'] ?? 'index.php?route=home/index'));
    if (preg_match('~^https?://~i', $backUrl)) {
        $backUrl = 'index.php?route=home/index';
    }

    $rows       = [];
    $selectedPath = [];
    $debugStats = ['fy' => $fy, 'uid' => $uid, 'showAll' => $showAll];

    try {
        if ($fy > 0) {
            if ($showAll) {
                $sql = "
                    SELECT c.DataObjectCode, c.DataObjectName,
                           CASE WHEN EXISTS (
                             SELECT 1
                               FROM dbo.tblDataObjectCodes ch
                              WHERE ch.FiscalYearID = c.FiscalYearID
                                AND ch.DataObjectCodeParent = c.DataObjectCode
                           ) THEN 1 ELSE 0 END AS HasChildren
                      FROM dbo.tblDataObjectCodes c
                     WHERE c.FiscalYearID = :fy
                       AND (c.DataObjectCodeParent IS NULL OR c.DataObjectCodeParent = '')
                     ORDER BY c.DataObjectCode
                ";
                $st = $conn->prepare($sql);
                $st->execute([':fy' => $fy]);
            } else {
                // CORRECT TABLE: tblDataObjectCodeAccess
                $sql = "
                    WITH Acc AS (
                      SELECT DISTINCT d.DataObjectCode,
                                      d.DataObjectName,
                                      d.DataObjectCodeParent
                        FROM dbo.tblDataObjectCodes d
                        JOIN dbo.tblDataObjectTree tr
                          ON tr.FiscalYearID   = d.FiscalYearID
                         AND tr.DescendantCode = d.DataObjectCode
                        JOIN dbo.tblDataObjectCodeAccess g  -- FIXED TABLE
                          ON g.FiscalYearID = tr.FiscalYearID
                         AND g.DataObjectCode   = tr.AncestorCode  -- FIXED COLUMN
                       WHERE d.FiscalYearID = :fy
                         AND g.UserID       = :uid
                         AND g.Revoked      = 0
                    )
                    SELECT a.DataObjectCode,
                           a.DataObjectName,
                           CASE WHEN EXISTS (
                             SELECT 1 FROM Acc c WHERE c.DataObjectCodeParent = a.DataObjectCode
                           ) THEN 1 ELSE 0 END AS HasChildren
                      FROM Acc a
                      LEFT JOIN Acc p
                        ON p.DataObjectCode = a.DataObjectCodeParent
                     WHERE (a.DataObjectCodeParent IS NULL OR a.DataObjectCodeParent = '')
                        OR p.DataObjectCode IS NULL
                     ORDER BY a.DataObjectCode
                ";
                $st = $conn->prepare($sql);
                $st->execute([':fy' => $fy, ':uid' => $uid]);
            }

            $rowsDb = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            foreach ($rowsDb as $r) {
                $rows[] = [
                    'code'        => (string) $r['DataObjectCode'],
                    'name'        => (string) ($r['DataObjectName'] ?? ''),
                    'hasChildren' => (bool)   ($r['HasChildren'] ?? false),
                ];
            }

            if ($selected !== '') {
                try {
                    $sqlPath = "
                        SELECT AncestorCode, Depth
                          FROM dbo.tblDataObjectTree
                         WHERE FiscalYearID = :fy
                           AND DescendantCode = :code
                           AND Depth > 0
                         ORDER BY Depth DESC
                    ";
                    $sp = $conn->prepare($sqlPath);
                    $sp->execute([':fy' => $fy, ':code' => $selected]);
                    $pathRows = $sp->fetchAll(\PDO::FETCH_ASSOC) ?: [];
                    $selectedPath = array_map(fn($r) => (string)$r['AncestorCode'], $pathRows);
                } catch (\Throwable $e) {
                    if (function_exists('app_log')) {
                        app_log('dataobjects.picker.path.error', ['error' => $e->getMessage()], 'error');
                    }
                    $selectedPath = [];
                }
            }
        }
    } catch (\Throwable $e) {
        if (function_exists('app_log')) {
            app_log('dataobjects.picker query failed', ['error' => $e->getMessage()], 'error');
        }
        $rows = [];
    }

    if (function_exists('app_log')) {
        app_log('dataobjects.picker.context', [
            'uid'         => $uid,
            'fy'          => $fy,
            'showAll'     => $showAll,
            'roots'       => count($rows),
            'selected'    => $selected,
            'hasPath'     => !empty($selectedPath),
        ], 'info');
    }

    $params = [
        'title'        => __t('select_data_scope'),
        'rows'         => $rows,
        'selected'     => $selected,
        'selectedPath' => $selectedPath,
        'fiscalYearID' => $fy,
        'backUrl'      => $backUrl,
        'debug'        => isset($_GET['debug']),
        'debugStats'   => $debugStats,
        'showAll'      => $showAll,
    ];

    if ($isIframe) {
        $this->renderPartial('dataobjects/DataObjectPicker', $params);
    } else {
        $this->render('dataobjects/DataObjectPicker', $params);
    }
}

    /**
     * Set or clear the current DataObject scope in session, then redirect back.
     *
     * GET:
     *  - code=<DataObjectCode> (unless clear=1)
     *  - name=<optional>
     *  - clear=1
     *  - return=<relative url>
     */
    public function select(): void
    {
        $return = (string)($_GET['return'] ?? 'index.php?route=home/index');
        // Only allow relative returns
        if (preg_match('~^https?://~i', $return)) {
            $return = 'index.php?route=home/index';
        }

        if (isset($_GET['clear'])) {
            SessionHelper::forget('scope.dataobject_code');
            SessionHelper::forget('scope.dataobject_name');
            $this->flashInfo(__t('data_scope_cleared'));
            header('Location: ' . $return);
            exit;
        }

        $code = trim((string)($_GET['code'] ?? ''));
        $name = trim((string)($_GET['name'] ?? ''));
        if ($code === '') {
            $this->flashError(__t('invalid_data_scope'));
            header('Location: ' . $return);
            exit;
        }

        SessionHelper::set('scope.dataobject_code', $code);
        SessionHelper::set('scope.dataobject_name', $name);
        $this->flashSuccess(__t('data_scope_set', ['code' => $code]));
        header('Location: ' . $return);
        exit;
    }

    /**
     * JSON children loader (lazy).
     *
     * GET:
     *   parent=<DataObjectCode> (omit/empty for top-level)
     *
     * Returns:
     *   { items: [{code, name, hasChildren}] }
     */
    public function children(): void
    {
        header('Content-Type: application/json; charset=utf-8');

        require __DIR__ . '/../../config/db.php';
        $fy     = (int)(SessionHelper::get('FiscalYearID') ?? 0);
        $parent = isset($_GET['parent']) ? (string)$_GET['parent'] : '';

        if ($fy <= 0) {
            echo json_encode(['items' => []]);
            return;
        }

        try {
            if ($parent === '') {
                $sql = "
                    SELECT c.DataObjectCode, c.DataObjectName,
                           CASE WHEN EXISTS (
                             SELECT 1 FROM dbo.tblDataObjectCodes ch
                              WHERE ch.FiscalYearID = c.FiscalYearID
                                AND ch.DataObjectCodeParent = c.DataObjectCode
                           ) THEN 1 ELSE 0 END AS HasChildren
                      FROM dbo.tblDataObjectCodes c
                     WHERE c.FiscalYearID = :fy
                       AND (c.DataObjectCodeParent IS NULL OR c.DataObjectCodeParent = '')
                     ORDER BY c.DataObjectCode
                ";
                $st = $conn->prepare($sql);
                $st->execute([':fy' => $fy]);
            } else {
                $sql = "
                    SELECT c.DataObjectCode, c.DataObjectName,
                           CASE WHEN EXISTS (
                             SELECT 1 FROM dbo.tblDataObjectCodes ch
                              WHERE ch.FiscalYearID = c.FiscalYearID
                                AND ch.DataObjectCodeParent = c.DataObjectCode
                           ) THEN 1 ELSE 0 END AS HasChildren
                      FROM dbo.tblDataObjectCodes c
                     WHERE c.FiscalYearID = :fy
                       AND c.DataObjectCodeParent = :p
                     ORDER BY c.DataObjectCode
                ";
                $st = $conn->prepare($sql);
                $st->execute([':fy' => $fy, ':p' => $parent]);
            }

            $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $items = array_map(static function ($r) {
                return [
                    'code'        => (string)$r['DataObjectCode'],
                    'name'        => (string)($r['DataObjectName'] ?? ''),
                    'hasChildren' => (bool)  ($r['HasChildren'] ?? false),
                ];
            }, $rows);

            echo json_encode(['items' => $items]);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
}
