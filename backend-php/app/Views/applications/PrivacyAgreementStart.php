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
                $token = '%%AGREEMENT_LINK_' . $index++ . '%%';
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

$type = is_array($applicationType ?? null) ? $applicationType : [];
$typeKey = trim((string)($type['ApplicationTypeKey'] ?? ''));
$typeName = trim((string)($type['ApplicationTypeName'] ?? $typeKey));
$agreementText = trim((string)($type['PrivacyAgreementText'] ?? ''));
$privacyError = trim((string)($privacyError ?? ''));
$csrf = h((string)($_csrf ?? csrf_token()));
$heading = trim((string)($heading ?? 'Terms and Conditions'));
$backHref = trim((string)($backHref ?? 'index.php?route=portalcards/list'));
$formAction = trim((string)($formAction ?? 'index.php?route=applications/start-agree'));
$agreeLabel = trim((string)($agreeLabel ?? 'I have read and agree to the terms and conditions.'));
$submitLabel = trim((string)($submitLabel ?? 'Agree and Continue'));
$cancelLabel = trim((string)($cancelLabel ?? 'Cancel'));
$hiddenFields = is_array($hiddenFields ?? null) ? $hiddenFields : [];
$hasPrivacyError = $privacyError !== '';
?>

<section class="container-fluid mt-4" aria-labelledby="privacyAgreementHeading">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h1 class="h3 mb-1" id="privacyAgreementHeading"><?= h($heading !== '' ? $heading : 'Privacy Notice') ?></h1>
      <div class="text-muted"><?= h($typeName !== '' ? $typeName : 'Application') ?></div>
    </div>
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.location.href='<?= h($backHref) ?>';">
      <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Back
    </button>
  </div>

  <?php if ($hasPrivacyError): ?>
    <div class="alert alert-danger py-2" role="alert" aria-live="assertive"><?= h($privacyError) ?></div>
  <?php endif; ?>

  <section class="card shadow-sm" aria-labelledby="privacyContinueHeading">
    <div class="card-header">
      <h2 class="h5 mb-0" id="privacyContinueHeading">Before You Continue</h2>
    </div>
    <div class="card-body">
      <div class="border rounded p-3 mb-3 bg-light-subtle" role="document" id="privacyAgreementText"><?= renderAgreementText($agreementText) ?></div>

      <form method="post" action="<?= h($formAction) ?>" aria-describedby="privacyAgreementText">
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="type" value="<?= h($typeKey) ?>">
        <?php foreach ($hiddenFields as $fieldName => $fieldValue): ?>
          <input type="hidden" name="<?= h((string)$fieldName) ?>" value="<?= h((string)$fieldValue) ?>">
        <?php endforeach; ?>

        <fieldset class="mb-3" id="privacyAgreementConfirmation">
          <legend class="visually-hidden">Privacy agreement confirmation</legend>
          <div class="form-check">
            <input
              class="form-check-input<?= $hasPrivacyError ? ' is-invalid' : '' ?>"
              type="checkbox"
              value="1"
              id="agreePrivacy"
              name="agree_privacy"
              style="<?= h(agreementCheckboxStyle()) ?>"
              aria-describedby="agreePrivacyHelp<?= $hasPrivacyError ? ' agreePrivacyError' : '' ?>"
              <?= $hasPrivacyError ? 'aria-invalid="true"' : '' ?>
            >
            <label class="form-check-label" for="agreePrivacy">
              <?= h($agreeLabel) ?>
            </label>
          </div>
          <?php if ($hasPrivacyError): ?>
            <div class="invalid-feedback d-block fw-bold" id="agreePrivacyError">
              Please tick this checkbox to confirm you have read and agree to the privacy notice before continuing.
            </div>
          <?php endif; ?>
          <div class="form-text" id="agreePrivacyHelp">You must read the Privacy Notice before continuing.</div>
        </fieldset>

        <div class="d-flex gap-2">
          <button type="submit" class="btn btn-primary"><?= h($submitLabel) ?></button>
          <button type="button" class="btn btn-outline-secondary" onclick="window.location.href='<?= h($backHref) ?>';"><?= h($cancelLabel) ?></button>
        </div>
      </form>
    </div>
  </section>
</section>

<?php if ($hasPrivacyError): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var checkbox = document.getElementById('agreePrivacy');
  var container = document.getElementById('privacyAgreementConfirmation');
  if (container && typeof container.scrollIntoView === 'function') {
    container.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }
  if (checkbox && typeof checkbox.focus === 'function') {
    checkbox.focus({ preventScroll: true });
  }
});
</script>
<?php endif; ?>
