<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('agreementCheckboxStyle')) {
    function agreementCheckboxStyle(): string
    {
        return 'width:1.35rem;height:1.35rem;border:2px solid #495057;accent-color:#0d6efd;';
    }
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
                $rel = str_starts_with($lowerHref, 'mailto:') || str_starts_with($lowerHref, 'objective:') ? '' : ' target="_blank" rel="noopener noreferrer"';
                $token = '%%LOGIN_AGREEMENT_LINK_' . $index++ . '%%';
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

        $escaped = preg_replace(
            '~\*\*(.+?)\*\*~s',
            '<strong>$1</strong>',
            $escaped
        );

        $escaped = preg_replace(
            '~(?<!\*)\*(?![\s*])(.+?)(?<![\s*])\*(?!\*)~s',
            '<em>$1</em>',
            $escaped
        );

        $escaped = preg_replace(
            '~(?<![A-Z0-9])_([^_\r\n]+)_~i',
            '<em>$1</em>',
            $escaped
        );

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

            if (preg_match('/^(?:&bull;|&#8226;|•|\*|-)\s+(.+)$/u', $trimmed, $matches)) {
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

$agreementText = trim((string)($agreementText ?? ''));
$privacyError = trim((string)($privacyError ?? ''));
$csrf = h((string)($_csrf ?? csrf_token()));
$heading = trim((string)($heading ?? 'Privacy Notice'));
$hasPrivacyError = $privacyError !== '';
?>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1"><?= h($heading) ?></h3>
      <div class="text-muted">You must agree before continuing into the portal.</div>
    </div>
  </div>

  <?php if ($hasPrivacyError): ?>
    <div class="alert alert-danger py-2" role="alert" aria-live="assertive"><?= h($privacyError) ?></div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-header">
      <strong>Before You Continue</strong>
    </div>
    <div class="card-body">
      <div class="border rounded p-3 mb-3 bg-light-subtle" role="document" id="loginAgreementText"><?= renderAgreementText($agreementText) ?></div>

      <form method="post" action="index.php?route=auth/login-agreement-accept" aria-describedby="loginAgreementText">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">

        <fieldset class="mb-3" id="loginAgreementConfirmation">
          <legend class="visually-hidden">Login privacy agreement confirmation</legend>
          <div class="form-check">
            <input
              class="form-check-input<?= $hasPrivacyError ? ' is-invalid' : '' ?>"
              type="checkbox"
              value="1"
              id="agreeLoginAgreement"
              name="agree_login_agreement"
              style="<?= h(agreementCheckboxStyle()) ?>"
              aria-describedby="agreeLoginAgreementHelp<?= $hasPrivacyError ? ' agreeLoginAgreementError' : '' ?>"
              <?= $hasPrivacyError ? 'aria-invalid="true"' : '' ?>
            >
            <label class="form-check-label fs-5" for="agreeLoginAgreement">
              I have read and agree to this privacy notice.
            </label>
          </div>
          <?php if ($hasPrivacyError): ?>
            <div class="invalid-feedback d-block fs-5 fw-bold" id="agreeLoginAgreementError">
              Please tick this checkbox to confirm you have read and agree to the privacy notice before entering the portal.
            </div>
          <?php endif; ?>
          <div class="form-text" id="agreeLoginAgreementHelp">You must agree to the Privacy Notice before continuing into the portal.</div>
        </fieldset>

        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-primary">Agree and Continue</button>
          <a href="index.php?route=auth/logout&close=1" class="btn btn-outline-secondary">Exit</a>
        </div>
      </form>
    </div>
  </div>
</div>

<?php if ($hasPrivacyError): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var checkbox = document.getElementById('agreeLoginAgreement');
  var container = document.getElementById('loginAgreementConfirmation');
  if (container && typeof container.scrollIntoView === 'function') {
    container.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  if (checkbox && typeof checkbox.focus === 'function') {
    checkbox.focus({ preventScroll: true });
  }
});
</script>
<?php endif; ?>
