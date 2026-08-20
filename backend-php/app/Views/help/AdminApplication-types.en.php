<?php
declare(strict_types=1);
?>
<div class="p-3">
  <h5 class="mb-3"><i class="bi bi-card-list me-2"></i>Help - Application Types</h5>
  <p class="text-muted">
    This screen manages the application types available in the portal and the configuration tied to each type.
  </p>

  <div class="alert alert-info py-2 mb-3" role="alert">
    <i class="bi bi-info-circle me-2"></i>
    Use this help as a guide to the main purpose of the screen, the important checks to make, and any workflow or data impacts to be aware of.
  </div>

  <hr>

  <h6><i class="bi bi-info-circle me-2"></i>Typical Uses</h6>
  <ul>
    <li>review existing application types</li>
    <li>add or edit application type records</li>
    <li>confirm the correct type key exists for downstream workflow and export logic</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i>Why This Screen Matters</h6>
  <ul>
    <li>Application type configuration affects what users can start and how workflows are routed.</li>
    <li>Related rules such as workflow approval rules often depend on the application type identifier.</li>
  </ul>

  <hr>
  <p class="text-muted small mb-0">
    Tip: If you create or rename an application type, review any related workflow rules and downstream integrations at the same time.
  </p>
</div>
