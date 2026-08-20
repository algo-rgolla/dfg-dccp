<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('renderAgreementText')) {
    function renderAgreementText(string $text): string
    {
        $escaped = h($text);
        $placeholders = [];
        $index = 0;

        $escaped = preg_replace_callback(
            '~\[(.*?)\]\(((?:https?://)[^\s)]+|mailto:[^\s)]+|objective:[^\s)]+)\)~i',
            static function (array $matches) use (&$placeholders, &$index): string {
                $label = trim((string)($matches[1] ?? ''));
                $href = trim((string)($matches[2] ?? ''));
                if ($label === '' || $href === '') {
                    return $matches[0];
                }

                $safeLabel = h($label);
                $safeHref = h($href);
                $lowerHref = strtolower($href);
                $rel = str_starts_with($lowerHref, 'mailto:') || str_starts_with($lowerHref, 'objective:')
                    ? ''
                    : ' target="_blank" rel="noopener noreferrer"';
                $token = '%%RICH_TEXT_LINK_' . $index++ . '%%';
                $placeholders[$token] = '<a href="' . $safeHref . '"' . $rel . '>' . $safeLabel . '</a>';
                return $token;
            },
            $escaped
        );

        $escaped = preg_replace_callback(
            '~(?:(https?://[^\s<\]]+)|(objective:[^\s<\]]+)|([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}))~i',
            static function (array $matches): string {
                if (!empty($matches[1])) {
                    $url = $matches[1];
                    $href = h($url);
                    return '<a href="' . $href . '" target="_blank" rel="noopener noreferrer">' . $href . '</a>';
                }

                if (!empty($matches[2])) {
                    $url = $matches[2];
                    $href = h($url);
                    return '<a href="' . $href . '">' . $href . '</a>';
                }

                $email = $matches[3] ?? '';
                if ($email !== '') {
                    $safeEmail = h($email);
                    return '<a href="mailto:' . $safeEmail . '">' . $safeEmail . '</a>';
                }

                return $matches[0];
            },
            $escaped
        );

        if ($placeholders !== []) {
            $escaped = strtr($escaped, $placeholders);
        }

        $escaped = preg_replace('~\*\*(.+?)\*\*~s', '<strong>$1</strong>', $escaped);
        $escaped = preg_replace('~(?<!\*)\*(?![\s*])(.+?)(?<![\s*])\*(?!\*)~s', '<em>$1</em>', $escaped);
        $escaped = preg_replace('~(?<![A-Z0-9])_([^_\r\n]+)_~i', '<em>$1</em>', $escaped);

        $lines = preg_split("/\r\n|\n|\r/", $escaped) ?: [];
        $output = [];
        $inList = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                if ($inList) {
                    $output[] = '</ul>';
                    $inList = false;
                }
                $output[] = '<br>';
                continue;
            }

            if (preg_match('/^(?:&bull;|&#8226;|â€¢|\*|-)\s+(.+)$/u', $trimmed, $matches)) {
                if (!$inList) {
                    $output[] = '<ul>';
                    $inList = true;
                }
                $output[] = '<li>' . $matches[1] . '</li>';
                continue;
            }

            if ($inList) {
                $output[] = '</ul>';
                $inList = false;
            }

            $output[] = $trimmed . '<br>';
        }

        if ($inList) {
            $output[] = '</ul>';
        }

        return implode('', $output);
    }
}
