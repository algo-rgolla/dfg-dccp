<?php
declare(strict_types=1);

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$title = trim((string)($title ?? 'Exit Portal'));
?>
<div class="container-fluid mt-5">
  <div class="row justify-content-center">
    <div class="col-lg-5 col-md-7">
      <div class="card shadow-sm">
        <div class="card-body text-center p-4">
          <h3 class="mb-2"><?= h($title) ?></h3>
          <div class="text-muted mb-3">You have been logged out. This window will now try to close.</div>
          <button type="button" class="btn btn-outline-secondary" onclick="window.open('', '_self'); window.close();">
            Close Window
          </button>
          <div class="small text-muted mt-3">If your browser keeps this tab open, you can close it manually.</div>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
  window.setTimeout(function () {
    window.open('', '_self');
    window.close();
  }, 150);
</script>
