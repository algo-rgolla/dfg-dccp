<?php declare(strict_types=1); ?>
<?php
if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
$rows = is_array($rows ?? null) ? $rows : [];
?>
<div class="card shadow-sm">
  <div class="card-header d-flex justify-content-between align-items-center">
    <strong><?= h((string)($title ?? 'System Messages')) ?></strong>
    <a href="index.php?route=systemmessages/createForm" class="btn btn-sm btn-primary">
      <i class="bi bi-plus-circle me-1"></i>Create Message
    </a>
  </div>
  <div class="card-body">
    <?php if (!$rows): ?>
      <div class="text-muted">No system messages found.</div>
    <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm align-middle">
          <thead>
            <tr>
              <th>ID</th>
              <th>Title</th>
              <th>Status</th>
              <th>Severity</th>
              <th>Scope Group</th>
              <th>Start</th>
              <th>End</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <tr>
                <td><?= (int)($row['MessageID'] ?? 0) ?></td>
                <td><?= h((string)($row['Title'] ?? '')) ?></td>
                <td><?= h((string)($row['Status'] ?? '')) ?></td>
                <td><?= h((string)($row['Severity'] ?? '')) ?></td>
                <td><?= h((string)($row['ScopeGroupName'] ?? 'All Groups')) ?></td>
                <td><?= h((string)($row['DeliveryStartUTC'] ?? '')) ?></td>
                <td><?= h((string)($row['DeliveryEndUTC'] ?? '')) ?></td>
                <td class="text-end">
                  <a href="index.php?route=systemmessages/editForm&MessageID=<?= (int)($row['MessageID'] ?? 0) ?>" class="btn btn-sm btn-outline-primary">Edit</a>
                  <a href="index.php?route=systemmessages/preview&MessageID=<?= (int)($row['MessageID'] ?? 0) ?>" class="btn btn-sm btn-outline-secondary">Preview</a>
                  <a href="index.php?route=emailqueue/recipients&MessageID=<?= (int)($row['MessageID'] ?? 0) ?>" class="btn btn-sm btn-outline-secondary">Recipients</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</div>
