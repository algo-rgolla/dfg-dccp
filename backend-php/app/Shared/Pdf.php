<?php
declare(strict_types=1);

namespace App\Shared;

final class Pdf
{
    public static function fromHtml(string $html, array $flags = [], int $timeoutSeconds = 120): string
    {
        if (stripos($html, '<meta charset=') === false) {
            $html = "<!doctype html>\n<meta charset=\"utf-8\">\n" . $html;
        }

        $tmpDir   = sys_get_temp_dir();
        $stamp    = date('Ymd_His') . '_' . substr(sha1(random_bytes(8)), 0, 8);
        $htmlPath = $tmpDir . DIRECTORY_SEPARATOR . "cbms_pdf_$stamp.html";
        $pdfPath  = $tmpDir . DIRECTORY_SEPARATOR . "cbms_pdf_$stamp.pdf";
        $logPath  = $tmpDir . DIRECTORY_SEPARATOR . "cbms_pdf_$stamp.log";

        file_put_contents($htmlPath, $html);

        try {
            $exe = self::detectBinary();

            $defaultFlags = [
                '--enable-local-file-access',
                '--load-error-handling', 'ignore',
                '--load-media-error-handling', 'ignore',
                '--print-media-type',
                '--javascript-delay', '750',
                '--disable-smart-shrinking',
                '--quiet',
            ];

            $all = array_values(array_merge($defaultFlags, $flags));
            self::run($exe, $htmlPath, $pdfPath, $all, $timeoutSeconds, $logPath);

            if (!is_file($pdfPath) || filesize($pdfPath) === 0) {
                throw new \RuntimeException("wkhtmltopdf generated an empty file. See log: $logPath");
            }
            return $pdfPath;
        } finally {
            @unlink($htmlPath); // keep log on failure for debugging
        }
    }

    public static function fromUrl(string $url, array $flags = [], int $timeoutSeconds = 120): string
    {
        $tmpDir   = sys_get_temp_dir();
        $stamp    = date('Ymd_His') . '_' . substr(sha1(random_bytes(8)), 0, 8);
        $pdfPath  = $tmpDir . DIRECTORY_SEPARATOR . "cbms_pdf_$stamp.pdf";
        $logPath  = $tmpDir . DIRECTORY_SEPARATOR . "cbms_pdf_$stamp.log";

        $exe = self::detectBinary();

        $defaultFlags = [
            '--enable-local-file-access',
            '--load-error-handling', 'ignore',
            '--load-media-error-handling', 'ignore',
            '--print-media-type',
            '--javascript-delay', '750',
            '--disable-smart-shrinking',
            '--quiet',
        ];

        $all = array_values(array_merge($defaultFlags, $flags));
        self::run($exe, $url, $pdfPath, $all, $timeoutSeconds, $logPath);

        if (!is_file($pdfPath) || filesize($pdfPath) === 0) {
            throw new \RuntimeException("wkhtmltopdf generated an empty file. See log: $logPath");
        }
        return $pdfPath;
    }

    // ---------- internals ----------

    private static function detectBinary(): string
    {
        $candidates = [
            getenv('WKHTMLTOPDF_BIN') ?: '',
            'C:\Program Files\wkhtmltopdf\bin\wkhtmltopdf.exe',
            'C:\Program Files (x86)\wkhtmltopdf\bin\wkhtmltopdf.exe',
            '/usr/local/bin/wkhtmltopdf',
            '/usr/bin/wkhtmltopdf',
        ];
        foreach ($candidates as $p) {
            if ($p && is_file($p)) return $p;
        }
        $which = [];
        $code  = 1;
        if (DIRECTORY_SEPARATOR === '\\') {
            @exec('where wkhtmltopdf 2> NUL', $which, $code);
        } else {
            @exec('which wkhtmltopdf 2> /dev/null', $which, $code);
        }
        if ($code === 0 && !empty($which[0]) && is_file($which[0])) return $which[0];

        throw new \RuntimeException('wkhtmltopdf not found. Install it or set WKHTMLTOPDF_BIN.');
    }

    private static function run(string $exe, string $in, string $out, array $flags, int $timeout, string $logPath): void
    {
        $parts = [self::qq($exe)];
        foreach ($flags as $f) $parts[] = self::qq((string)$f);
        $parts[] = self::qq($in);
        $parts[] = self::qq($out);

        $cmd = implode(' ', $parts) . ' 2>&1';

        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new \RuntimeException('Failed to start wkhtmltopdf process.');
        }

        stream_set_blocking($pipes[1], false);
        if (isset($pipes[2])) stream_set_blocking($pipes[2], false);

        $start = time();
        $stdout = '';
        $stderr = '';

        try {
            while (true) {
                $status = proc_get_status($proc);
                $stdout .= (string)stream_get_contents($pipes[1]);
                if (isset($pipes[2])) $stderr .= (string)stream_get_contents($pipes[2]);

                if (!$status['running']) break;
                if ((time() - $start) > $timeout) {
                    proc_terminate($proc);
                    $stderr .= "\n[TIMEOUT after {$timeout}s]\n";
                    break;
                }
                usleep(100_000);
            }
        } finally {
            @fclose($pipes[1]);
            if (isset($pipes[2])) @fclose($pipes[2]);
        }

        $exit = proc_close($proc);
        @file_put_contents($logPath, $stdout . "\n--- STDERR ---\n" . $stderr);

        if ($exit !== 0) {
            throw new \RuntimeException("wkhtmltopdf failed (exit $exit). See log: $logPath");
        }
    }

    private static function qq(string $s): string
    {
        if ($s === '') return '""';
        if (preg_match('/[^\w\-\.\/:\\\@%=]/', $s)) {
            return '"' . str_replace('"', '\"', $s) . '"';
        }
        return $s;
    }
}
