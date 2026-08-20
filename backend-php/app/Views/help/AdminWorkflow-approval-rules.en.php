<?php
declare(strict_types=1);
?>
<div class="p-3">
  <h5 class="mb-3"><i class="bi bi-diagram-3 me-2"></i>Help - Workflow Approval Rules</h5>
  <p class="text-muted">
    This screen manages the approval rules that drive workflow routing for configured portal processes, including staged limit change approvals.
  </p>

  <div class="alert alert-info py-2 mb-3" role="alert">
    <i class="bi bi-info-circle me-2"></i>
    Use this help as a guide to the main purpose of the screen, the important checks to make, and any workflow or data impacts to be aware of.
  </div>

  <hr>

  <h6><i class="bi bi-info-circle me-2"></i>How Rules Work</h6>
  <ul>
    <li><strong>Application Type:</strong> identifies which process the rule applies to.</li>
    <li><strong>Employee Group:</strong> narrows the rule to the relevant employee population.</li>
    <li><strong>Approval Stage:</strong> controls the order for sequential approvals.</li>
    <li><strong>Min / Max Limit:</strong> defines the amount band the rule covers.</li>
    <li><strong>Required Approver Type:</strong> determines how approvers are resolved.</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i>Current Approval Options</h6>
  <ul>
    <li>Use values such as <strong>SUPERVISOR</strong>, <strong>SES</strong>, <strong>ASFIN</strong>, or <strong>CFO</strong> to resolve approvers from the configured approver directory.</li>
    <li>Use <strong>EMAIL</strong> to make the request screen show manual approver email entry for that stage.</li>
    <li>Multiple rows with the same stage and amount band can be used to notify multiple approver types for a shared stage.</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i>Important Notes</h6>
  <ul>
    <li>Some scenarios only need one stage, while others can use multiple sequential stages.</li>
    <li>For shared stages, multiple approvers can be notified and any one of them may approve.</li>
    <li>The list filters stay in place until they are changed or reset.</li>
  </ul>

  <hr>
  <p class="text-muted small mb-0">
    Tip: When testing a new rule, check both the request screen and the approval screen so you can confirm the stage and approver behaviour matches what you intended.
  </p>
</div>
