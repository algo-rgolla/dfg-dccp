<?php
declare(strict_types=1);
?>
<div class="p-3">
  <h5 class="mb-3"><i class="bi bi-card-checklist me-2"></i>Help - Application</h5>
  <p class="text-muted">
    Use this screen to create, review, save, and submit a card application. The form brings together applicant details, card request details, supervisor approval, declarations, and final submission checks.
  </p>

  <div class="alert alert-info py-2 mb-3" role="alert">
    <i class="bi bi-info-circle me-2"></i>
    Fields marked as required on the form must be completed before you can submit the application.
  </div>

  <hr>

  <h6><i class="bi bi-info-circle me-2"></i><i class="bi bi-signpost-2 me-2"></i>How To Work Through The Form</h6>
  <ul>
    <li>Move through the screen from top to bottom and complete each section before submitting.</li>
    <li>Use <strong>Save Draft</strong> if you are not ready to submit or are still waiting on information.</li>
    <li>Use <strong>Submit</strong> only after the required fields, declarations, and approval details are complete.</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i><i class="bi bi-asterisk text-danger me-2"></i>Mandatory Fields At A Glance</h6>
  <ul>
    <li><strong>Employee details:</strong> mandatory identity and contact fields shown as required on the form.</li>
    <li><strong>Card request details:</strong> required card type, limit, delivery, and other application-specific fields.</li>
    <li><strong>Supervisor:</strong> required where the workflow needs a supervisor approval.</li>
    <li><strong>Declarations:</strong> all mandatory declarations and acknowledgements must be completed.</li>
    <li><strong>Privacy Notice:</strong> the required Privacy Notice acknowledgement must be completed before continuing.</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i><i class="bi bi-person-badge me-2"></i>Employee Section</h6>
  <ul>
    <li>This section shows the applicant details used by the portal and downstream processing.</li>
    <li>Some fields are prefilled from portal or CAPS data and may be read only.</li>
    <li>Review the employee information carefully before moving to the card-specific sections.</li>
    <li>If employee details look incorrect, that can affect eligibility, defaults, and approval routing later in the form.</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i><i class="bi bi-credit-card-2-front me-2"></i>Card Request Details</h6>
  <ul>
    <li>Complete the card-specific fields required for the application type you are submitting.</li>
    <li>Some options and defaults are driven by the selected application type and your employee profile.</li>
    <li>Where limits are shown, make sure they match the card type and business requirement for the request.</li>
    <li>Check postal and work address details carefully if the application includes delivery or address-based fields.</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i><i class="bi bi-person-check me-2"></i>Supervisor Section</h6>
  <ul>
    <li>Use the supervisor search to locate and select the correct approving supervisor where the workflow requires it.</li>
    <li>The selected supervisor cannot be the applicant.</li>
    <li>The search results are driven by the available directory data, so spelling, employee ID, or email details can affect what is returned.</li>
    <li>If you cannot find the expected supervisor, confirm the employee data first before forcing a different choice.</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i><i class="bi bi-diagram-3 me-2"></i>Approvals And Workflow</h6>
  <ul>
    <li>Once submitted, the application moves into the configured workflow for that application type.</li>
    <li>The approval path can vary depending on the application type, applicant data, employee group, and configured workflow rules.</li>
    <li>Applications remain in the approval process until all required approval steps are completed.</li>
    <li>If the request is rejected, the application status and any rejection reason should explain why it did not proceed.</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i><i class="bi bi-shield-check me-2"></i>Privacy Notice And Declarations</h6>
  <ul>
    <li>Read the <strong>Privacy Notice</strong> and any declaration wording shown on the application.</li>
    <li>You must accept the required statements before the application can continue.</li>
    <li>If you see a flash message or validation error about the Privacy Notice, return to that section and confirm it has been acknowledged correctly.</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i><i class="bi bi-exclamation-triangle me-2"></i>Validation Rules</h6>
  <ul>
    <li>Required fields must be completed before the application can be submitted.</li>
    <li>Some fields are validated against configured lists or business rules rather than free text.</li>
    <li>Supervisor and approver-related fields must resolve to a valid person where required.</li>
    <li>Inline field errors and top-of-page flash messages both help explain what is blocking submission.</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i><i class="bi bi-save me-2"></i>Save Draft Versus Submit</h6>
  <ul>
    <li><strong>Save Draft:</strong> keeps your work without sending it for approval.</li>
    <li><strong>Submit:</strong> performs full validation and then sends the application into workflow.</li>
    <li>If submit fails, the application usually remains saved so you can correct the highlighted fields and try again.</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i><i class="bi bi-x-circle me-2"></i>Common Reasons Submission Fails</h6>
  <ul>
    <li>a required field is empty</li>
    <li>the selected supervisor is invalid or is the applicant</li>
    <li>a declaration or Privacy Notice acknowledgement is incomplete</li>
    <li>the application contains values that do not match configured rules</li>
  </ul>

  <h6><i class="bi bi-info-circle me-2"></i><i class="bi bi-check2-circle me-2"></i>Practical Checks Before You Submit</h6>
  <ul>
    <li>Confirm the applicant details are correct.</li>
    <li>Review all required card request fields.</li>
    <li>Check that the correct supervisor has been selected.</li>
    <li>Read the Privacy Notice and required declarations.</li>
    <li>Scan the page for any inline warnings before pressing Submit.</li>
  </ul>

  <hr>
  <p class="text-muted small mb-0">
    <i class="bi bi-lightbulb me-1"></i>
    Tip: If the application will not submit, work back through the form section by section. Most submission issues come from a required field, supervisor selection, or declaration that still needs attention.
  </p>
</div>
