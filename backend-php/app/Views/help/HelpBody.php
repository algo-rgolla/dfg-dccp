<?php
declare(strict_types=1);
/** @var string $screen */
/** @var string $viewFile */
?>

<?php if (is_file($viewFile)): ?>
  <?php require $viewFile; ?>
<?php else: ?>
  <p class="text-muted">
    No help content available for <strong><?= htmlspecialchars($screen, ENT_QUOTES, 'UTF-8') ?></strong>.
  </p>
<?php endif; ?>
