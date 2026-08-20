<?php
declare(strict_types=1);
?>
<div class="p-3">
  <h5 class="mb-3"><i class="bi bi-tools me-2"></i>Help - Diagnostics</h5>
  <p class="text-muted">
    The Diagnostics screen provides test and troubleshooting tools intended for technical users and administrators.
  </p>

  <div class="alert alert-info py-2 mb-3" role="alert">
    <i class="bi bi-info-circle me-2"></i>
    Use this help as a guide to the main purpose of the screen, the important checks to make, and any workflow or data impacts to be aware of.
  </div>

  <hr>

  <h6><i class="bi bi-info-circle me-2"></i>Typical Uses</h6>
  <ul>
    <li>verify email sending</li>
    <li>test error handling behaviour</li>
    <li>confirm basic environment health during troubleshooting</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i>Important Caution</h6>
  <ul>
    <li>Some actions are intentionally disruptive and should only be used in an appropriate environment.</li>
    <li>Do not run exception or fatal-error tests in production unless that is explicitly part of the support task.</li>
  </ul>

  <hr>
  <p class="text-muted small mb-0">
    Tip: Use diagnostics purposefully and record what you tested so later troubleshooting is easier.
  </p>
</div>
