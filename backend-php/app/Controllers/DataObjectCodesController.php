<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Shared\SessionHelper;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use App\Models\DataObjectCodesModel;
use App\Shared\Pdf;

final class DataObjectCodesController extends BaseController
{
    protected array $acl = [
        '*'        => ['auth' => true, 'permsAny' => ['DATAOBJECTCODES_ADMIN']],
        'index'    => ['auth' => true, 'permsAny' => ['DATAOBJECTCODES_ADMIN']],
        'create'   => ['auth' => true, 'permsAny' => ['DATAOBJECTCODES_ADMIN']],
        'edit'     => ['auth' => true, 'permsAny' => ['DATAOBJECTCODES_ADMIN']],
        'save'     => ['auth' => true, 'permsAny' => ['DATAOBJECTCODES_ADMIN']],
        'delete'   => ['auth' => true, 'permsAny' => ['DATAOBJECTCODES_ADMIN']],
    ];

    protected bool $requiresContext = true;

    /* ------------------------------------------------------------------ */
    /*  EXPORT PDF                                                        */
    /* ------------------------------------------------------------------ */
    public function exportPdf(): void
    {
        @set_time_limit(120);

        if (!isset($conn) || !($conn instanceof \PDO)) {
            require __DIR__ . '/../../config/db.php';
        }
        require_once __DIR__ . '/../Models/DataObjectCodesModel.php';

        $fy     = (int)SessionHelper::get('FiscalYearID', 0);
        $q      = trim((string)($_GET['q'] ?? ''));
        $typeId = $_GET['typeId'] !== '' ? (int)$_GET['typeId'] : null;
        $status = (string)($_GET['status'] ?? '');
        $sort   = (string)($_GET['sort'] ?? 'DataObjectCode');
        $dir    = strtoupper((string)($_GET['dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        $model = new \App\Models\DataObjectCodesModel($conn);

        $first = $model->listPaged($fy, 1, 1, $q !== '' ? $q : null, $sort, $dir, $typeId, $status !== '' ? $status : null);
        $total = max(0, (int)($first['total'] ?? 0));

        $pageSz = max(1, $total ?: 1);
        $all    = $model->listPaged($fy, 1, $pageSz, $q !== '' ? $q : null, $sort, $dir, $typeId, $status !== '' ? $status : null);
        $rows   = $all['rows'] ?? [];

        $cssPath = realpath(__DIR__ . '/../../public/assets/pdf/pdf.css');

        $columns = [
            ['label' => 'Code',    'key' => 'DataObjectCode',       'class' => 'code nowrap col-code'],
            ['label' => 'Name',    'key' => 'DataObjectName',       'class' => 'col-name'],
            ['label' => 'Parent',  'key' => 'DataObjectCodeParent', 'class' => 'code nowrap col-parent'],
            ['label' => 'Type',    'key' => 'DataObjectTypeID',     'class' => 'num nowrap col-type'],
            ['label' => 'Status',  'key' => 'DataObjectCodeStatus', 'class' => 'nowrap col-status'],
            ['label' => 'Updated', 'key' => 'DateUpdated',          'class' => 'nowrap col-updated'],
        ];

        $esc = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

        ob_start();
        echo "<!doctype html><meta charset='utf-8'>";
        echo "<h1>Data Object Codes</h1>";
        echo "<p class='meta'>Fiscal Year: " . $esc($fy);
        if ($q !== '')        echo " &middot; Search: " . $esc($q);
        if ($typeId !== null) echo " &middot; Type: " . $esc($typeId);
        if ($status !== '')   echo " &middot; Status: " . $esc($status);
        echo " &middot; Total: " . number_format($total);
        echo " &middot; Generated: " . date('Y-m-d H:i') . "</p>";

        echo "<table><thead><tr>";
        foreach ($columns as $c) {
            echo '<th class="' . $esc($c['class']) . '">' . $esc($c['label']) . '</th>';
        }
        echo "</tr></thead><tbody>";

        foreach ($rows as $r) {
            echo "<tr>";
            foreach ($columns as $c) {
                $val = $r[$c['key']] ?? '';
                echo '<td class="' . $esc($c['class']) . '">' . $esc($val) . '</td>';
            }
            echo "</tr>";
        }
        if (!$rows) {
            echo "<tr><td colspan='" . count($columns) . "' class='nowrap'>No records.</td></tr>";
        }
        echo "</tbody></table>";
        $html = ob_get_clean();

        $tmpDir  = sys_get_temp_dir();
        $stamp   = date('Ymd_His') . '_' . substr(sha1(random_bytes(8)), 0, 6);
        $hdrPath = $tmpDir . DIRECTORY_SEPARATOR . "cbms_hdr_$stamp.html";
        $ftrPath = $tmpDir . DIRECTORY_SEPARATOR . "cbms_ftr_$stamp.html";

        $headerHtml = '<!doctype html><meta charset="utf-8">
        <style>
            body{margin:0}
            .wrap{border-bottom:1px solid #d1d5db}
            table.bar{width:100%;border-collapse:collapse;font-family:Segoe UI,Arial,sans-serif;font-size:11px;color:#1f2937;table-layout:fixed;white-space:nowrap;}
            td{padding:2.8mm 8mm}
            td.left{overflow:hidden;text-overflow:ellipsis;}
            td.right{width:40mm;text-align:right;}
        </style>
        <div class="wrap"><table class="bar"><tr>
            <td class="left">Data Object Codes — Fiscal Year: ' . $esc($fy) . '</td>
            <td class="right">CBMSv2.1</td>
        </tr></table></div>';

        $footerHtml = '<!doctype html><meta charset="utf-8">
        <style>
            body{margin:0}
            .wrap{border-top:1px solid #d1d5db}
            table.bar{width:100%;border-collapse:collapse;font-family:Segoe UI,Arial,sans-serif;font-size:9px;color:#6b7280;table-layout:fixed;white-space:nowrap;}
            td{padding:2.4mm 8mm}
            td.left{overflow:hidden;text-overflow:ellipsis}
            td.right{width:40mm;text-align:right;color:#4b5563}
        </style>
        <script>
            function subst(){
                var m={}, a=window.location.search.substring(1).split("&");
                for(var i=0;i<a.length;i++){var p=a[i].split("="); m[decodeURIComponent(p[0])] = decodeURIComponent(p[1]||"");}
                var set=function(cls,val){var els=document.getElementsByClassName(cls); for(var j=0;j<els.length;j++) els[j].textContent=val;}
                set("page", m.page||""); set("topage", m.topage||"");
            }
        </script>
        <body onload="subst()">
            <div class="wrap"><table class="bar"><tr>
                <td class="left">Generated ' . date('Y-m-d H:i') . '</td>
                <td class="right">Page <span class="page"></span> of <span class="topage"></span></td>
            </tr></table></div>
        </body>';

        file_put_contents($hdrPath, $headerHtml);
        file_put_contents($ftrPath, $footerHtml);

        try {
            $flags = [
                '--header-html', $hdrPath,
                '--footer-html', $ftrPath,
                '--margin-top','15mm',
                '--margin-bottom','10mm',
                '--print-media-type',
                '--dpi','96',
            ];
            if ($cssPath && is_file($cssPath)) {
                $flags[] = '--user-style-sheet';
                $flags[] = $cssPath;
            }

            $pdfPath = \App\Shared\Pdf::fromHtml($html, $flags, 120);

            if (ob_get_length()) { @ob_end_clean(); }
            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="DataObjectCodes.pdf"');
            header('Content-Length: ' . filesize($pdfPath));
            readfile($pdfPath);
        } catch (\Throwable $e) {
            http_response_code(500);
            echo "<div style='margin:1rem;padding:1rem;border:1px solid #ddd;background:#fff3cd'>
                    <strong>PDF export failed.</strong>
                    <div style='color:#555;margin-top:.5rem'>" . $esc($e->getMessage()) . "</div>
                  </div>";
        } finally {
            if (!empty($pdfPath) && is_file($pdfPath)) { @unlink($pdfPath); }
            if (is_file($hdrPath)) @unlink($hdrPath);
            if (is_file($ftrPath)) @unlink($ftrPath);
        }
        exit;
    }

    /* ------------------------------------------------------------------ */
    /*  EXPORT XLSX                                                       */
    /* ------------------------------------------------------------------ */
    public function exportXlsx(): void
    {
        if (!isset($conn) || !($conn instanceof \PDO)) {
            require __DIR__ . '/../../config/db.php';
        }
        require_once __DIR__ . '/../Models/DataObjectCodesModel.php';

        $fy = (int)SessionHelper::get('FiscalYearID', 0);
        if ($fy <= 0) {
            $this->flashError('Fiscal year required.');
            header('Location: index.php?route=dataobjectcodes/index');
            return;
        }

        $q      = trim((string)($_GET['q'] ?? ''));
        $typeId = $_GET['typeId'] !== '' ? (int)$_GET['typeId'] : null;
        $status = (string)($_GET['status'] ?? '');
        $sort   = (string)($_GET['sort'] ?? 'DataObjectCode');
        $dir    = strtoupper((string)($_GET['dir'] ?? 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        $model = new \App\Models\DataObjectCodesModel($conn);
        $result = $model->listPaged($fy, 1, 100000, $q !== '' ? $q : null, $sort, $dir, $typeId, $status !== '' ? $status : null);

        if ($model->getLastError()) {
            $this->flashError('Failed to load data for export: ' . $model->getLastError());
            header('Location: index.php?route=dataobjectcodes/index');
            return;
        }

        $rows = $result['rows'] ?? [];

        $headers = [
            'FiscalYearID','DataObjectCode','DataObjectName','DataObjectCodeParent',
            'DataObjectTypeID','DataObjectDesc','DataObjectCodeStatus','UpdatedBy','DateUpdated'
        ];

        $data = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($headers as $h) {
                $line[] = $row[$h] ?? '';
            }
            $data[] = $line;
        }

        try {
            $ss = new Spreadsheet();
            $ws = $ss->getActiveSheet();
            $ws->setTitle('DataObjectCodes');
            $ws->fromArray($headers, null, 'A1');
            if (!empty($data)) {
                $ws->fromArray($data, null, 'A2');
            }
            for ($col = 1; $col <= count($headers); $col++) {
                $ws->getColumnDimensionByColumn($col)->setAutoSize(true);
            }

            $filename = 'DataObjectCodes_' . date('Ymd_His') . '.xlsx';
            if (ob_get_length()) { @ob_end_clean(); }
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="'.$filename.'"');
            header('Cache-Control: max-age=0');
            $writer = new Xlsx($ss);
            $writer->save('php://output');
            exit;
        } catch (\Throwable $e) {
            $this->flashError('Export failed: ' . $e->getMessage());
            header('Location: index.php?route=dataobjectcodes/index');
        }
    }

    /* ------------------------------------------------------------------ */
    /*  INDEX                                                             */
    /* ------------------------------------------------------------------ */
    public function index(): void
    {
        if (!isset($conn) || !($conn instanceof \PDO)) {
            require __DIR__ . '/../../config/db.php';
        }
        require_once __DIR__ . '/../Models/DataObjectCodesModel.php';
        require_once __DIR__ . '/../../shared/csrf.php';

        $fy = (int)SessionHelper::get('FiscalYearID', 0);
        $model = new \App\Models\DataObjectCodesModel($conn);

        $result = $model->listPaged($fy, 1, 25, null, 'DataObjectCode', 'ASC', null, null);
        if ($model->getLastError()) {
            $this->flashError('Failed to load data: ' . $model->getLastError());
            $result = ['rows' => [], 'total' => 0];
        }

        $types = $model->listTypes();
        if ($model->getLastError()) {
            $this->flashError('Failed to load types: ' . $model->getLastError());
            $types = [];
        }

        $this->render('dataobjectcodes/DataObjectCodesList', [
            'title'    => 'docodes_title',
            'rows'     => $result['rows'] ?? [],
            'total'    => (int)($result['total'] ?? 0),
            'page'     => 1,
            'pageSize' => 25,
            'q'        => '',
            'typeId'   => null,
            'status'   => '',
            'sort'     => 'DataObjectCode',
            'dir'      => 'ASC',
            'types'    => $types,
            '_csrf'    => csrf_token(),
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  CREATE                                                            */
    /* ------------------------------------------------------------------ */
    public function create(): void
    {
        require_once __DIR__ . '/../../shared/csrf.php';
        if (!isset($conn) || !($conn instanceof \PDO)) {
            require __DIR__ . '/../../config/db.php';
        }
        require_once __DIR__ . '/../Models/DataObjectCodesModel.php';

        $fy = (int)SessionHelper::get('FiscalYearID', 0);
        $model = new \App\Models\DataObjectCodesModel($conn);

        $types = $model->listTypes();
        if ($model->getLastError()) {
            $this->flashError('Failed to load types: ' . $model->getLastError());
            $types = [];
        }

        $parents = $model->listForFiscalYear($fy);
        if ($model->getLastError()) {
            $this->flashError('Failed to load parent codes: ' . $model->getLastError());
            $parents = [];
        }

        $typeId = (int)($_POST['DataObjectTypeID'] ?? 0); // or from form
        $attributes = $model->getAllAttributes($typeId);

        if ($model->getLastError()) {
            $this->flashError('Failed to load attributes: ' . $model->getLastError());
            $attributes = [];
        }

        $this->render('dataobjectcodes/DataObjectCodesForm', [
            'title'   => 'docodes_add_title',
            'row'     => [
                'DataObjectCode'        => '',
                'DataObjectName'        => '',
                'DataObjectCodeParent'  => '',
                'DataObjectTypeID'      => '',
                'DataObjectDesc'        => '',
                'DataObjectCodeStatus'  => '',
                'attributes'            => [],
            ],
            'types'      => $types,
            'parents'    => $parents,
            'attributes' => $attributes,
            '_csrf'      => csrf_token(),
            'mode'       => 'create',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  EDIT                                                              */
    /* ------------------------------------------------------------------ */
    public function edit(): void
    {
        require_once __DIR__ . '/../../shared/csrf.php';
        if (!isset($conn) || !($conn instanceof \PDO)) {
            require __DIR__ . '/../../config/db.php';
        }
        require_once __DIR__ . '/../Models/DataObjectCodesModel.php';

        $fy = (int)SessionHelper::get('FiscalYearID', 0);
        $code = (string)($_GET['DataObjectCode'] ?? $_GET['code'] ?? '');
        if ($code === '') {
            $this->flashError('Missing code');
            header('Location: index.php?route=dataobjectcodes/index');
            return;
        }

        $model = new \App\Models\DataObjectCodesModel($conn);
        $row = $model->getOne($fy, $code);

        if (!$row) {
            $error = $model->getLastError();
            $this->flashError('Code not found.' . ($error ? " Error: $error" : ''));
            header('Location: index.php?route=dataobjectcodes/index');
            return;
        }

        $currentLevel = (int)($row['Level'] ?? 99);

        $parents = $model->listForFiscalYear($fy, null, $currentLevel);
        if ($model->getLastError()) {
            $this->flashError('Failed to load parent codes: ' . $model->getLastError());
            header('Location: index.php?route=dataobjectcodes/index');
            return;
        }

        $types = $model->listTypes();
        if ($model->getLastError()) {
            $this->flashError('Failed to load types: ' . $model->getLastError());
            header('Location: index.php?route=dataobjectcodes/index');
            return;
        }

        $typeId = (int)$row['DataObjectTypeID'];
        $attributes = $model->getAllAttributes($typeId);
        if ($model->getLastError()) {
            $this->flashError('Failed to load attributes: ' . $model->getLastError());
            header('Location: index.php?route=dataobjectcodes/index');
            return;
        }

        $this->render('dataobjectcodes/DataObjectCodesForm', [
            'title'      => 'docodes_edit_title',
            'row'        => $row,
            'types'      => $types,
            'parents'    => $parents,
            'attributes' => $attributes,
            '_csrf'      => csrf_token(),
            'mode'       => 'edit',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  SAVE                                                              */
    /* ------------------------------------------------------------------ */
    public function save(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405); echo 'Method Not Allowed'; return;
    }

    require_once __DIR__ . '/../../shared/csrf.php';
    if (!csrf_check($_POST['_csrf'] ?? '')) {
        $this->flashError('Security check failed.');
        header('Location: index.php?route=dataobjectcodes/index');
        return;
    }

    if (!isset($conn) || !($conn instanceof \PDO)) {
        require __DIR__ . '/../../config/db.php';
    }
    require_once __DIR__ . '/../Models/DataObjectCodesModel.php';

    $fy     = (int)SessionHelper::get('FiscalYearID', 0);
    $userId = (int)SessionHelper::get('auth.user_id', 0);

    $data = [
        'DataObjectCode'        => trim((string)($_POST['DataObjectCode'] ?? '')),
        'DataObjectName'        => trim((string)($_POST['DataObjectName'] ?? '')),
        'DataObjectCodeParent'  => trim((string)($_POST['DataObjectCodeParent'] ?? '')),
        'DataObjectTypeID'      => (int)($_POST['DataObjectTypeID'] ?? 0),
        'DataObjectDesc'        => (string)($_POST['DataObjectDesc'] ?? null),
        'DataObjectCodeStatus'  => (string)($_POST['DataObjectCodeStatus'] ?? 'Active'),
        'attributes'            => $_POST['attributes'] ?? [], // ← THIS WAS MISSING
    ];

    $model = new DataObjectCodesModel($conn);

    if ($model->upsert($fy, $data, $userId)) {
        $this->flashSuccess('Saved successfully.');
    } else {
        $this->flashError('Save failed: ' . $model->getLastError());
    }

    header('Location: index.php?route=dataobjectcodes/index');
    exit;
}
    /* ------------------------------------------------------------------ */
    /*  DELETE                                                            */
    /* ------------------------------------------------------------------ */
    public function delete(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            http_response_code(405); echo 'Method Not Allowed'; return;
        }

        require_once __DIR__ . '/../../shared/csrf.php';
        if (!csrf_check($_POST['_csrf'] ?? '')) {
            $this->flashError('Security check failed.');
            header('Location: index.php?route=dataobjectcodes/index');
            return;
        }

        if (!isset($conn) || !($conn instanceof \PDO)) {
            require __DIR__ . '/../../config/db.php';
        }
        require_once __DIR__ . '/../Models/DataObjectCodesModel.php';

        $fy   = (int)SessionHelper::get('FiscalYearID', 0);
        $code = (string)($_POST['DataObjectCode'] ?? '');

        if ($code === '') {
            $this->flashError('Missing code.');
            header('Location: index.php?route=dataobjectcodes/index');
            return;
        }

        $model = new DataObjectCodesModel($conn);

        if ($model->delete($fy, $code)) {
            $this->flashSuccess('Deleted.');
        } else {
            $this->flashError('Delete failed: ' . $model->getLastError());
        }

        header('Location: index.php?route=dataobjectcodes/index');
        exit;
    }
}