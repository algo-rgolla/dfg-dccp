<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$row = (isset($row) && is_array($row)) ? $row : [];
$id = (int)($row['CardID'] ?? 0);
$csrf = h((string)($_csrf ?? csrf_token()));

$dateValue = static function ($value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    return substr($value, 0, 10);
};
?>

<div class="container mt-4">
  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div class="d-flex align-items-center">
        <i class="bi bi-credit-card-2-front me-2"></i>
        <strong><?= $id > 0 ? 'Edit Portal Card' : 'Create Portal Card' ?></strong>
      </div>
      <a href="index.php?route=admin/portal-cards" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Back
      </a>
    </div>

    <div class="card-body">
      <form method="post" action="index.php?route=admin/portal-cards-save" class="needs-validation" novalidate>
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <?php if ($id > 0): ?>
          <input type="hidden" name="CardID" value="<?= h((string)$id) ?>">
        <?php endif; ?>

        <div class="row g-3 mb-3">
          <div class="col-md-3">
            <label class="form-label" for="tblCardID">tblCardID</label>
            <input type="number" class="form-control" id="tblCardID" name="tblCardID" value="<?= h((string)($row['tblCardID'] ?? '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="EmployeeID">Employee ID</label>
            <input type="text" class="form-control" id="EmployeeID" name="EmployeeID" required value="<?= h((string)($row['EmployeeID'] ?? '')) ?>">
            <div class="invalid-feedback">Employee ID is required.</div>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="ApplicationID">Application ID</label>
            <input type="number" class="form-control" id="ApplicationID" name="ApplicationID" value="<?= h((string)($row['ApplicationID'] ?? '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="CMSUser">CMS User</label>
            <input type="text" class="form-control" id="CMSUser" name="CMSUser" value="<?= h((string)($row['CMSUser'] ?? '')) ?>">
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-3">
            <label class="form-label" for="CardType">Card Type</label>
            <input type="text" class="form-control" id="CardType" name="CardType" required value="<?= h((string)($row['CardType'] ?? '')) ?>">
            <div class="invalid-feedback">Card type is required.</div>
          </div>
          <div class="col-md-3">
            <label class="form-label" for="CardTypeSub">Card Type Sub</label>
            <input type="text" class="form-control" id="CardTypeSub" name="CardTypeSub" value="<?= h((string)($row['CardTypeSub'] ?? '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="Status">Status</label>
            <input type="text" class="form-control" id="Status" name="Status" value="<?= h((string)($row['Status'] ?? '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="ProcessStatus">Process Status</label>
            <input type="text" class="form-control" id="ProcessStatus" name="ProcessStatus" value="<?= h((string)($row['ProcessStatus'] ?? '')) ?>">
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-2">
            <label class="form-label" for="Title">Title</label>
            <input type="text" class="form-control" id="Title" name="Title" value="<?= h((string)($row['Title'] ?? '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="FirstName">First Name</label>
            <input type="text" class="form-control" id="FirstName" name="FirstName" value="<?= h((string)($row['FirstName'] ?? '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="MiddleName">Middle Name</label>
            <input type="text" class="form-control" id="MiddleName" name="MiddleName" value="<?= h((string)($row['MiddleName'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="Surname">Surname</label>
            <input type="text" class="form-control" id="Surname" name="Surname" value="<?= h((string)($row['Surname'] ?? '')) ?>">
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label" for="NameOnCard">Name On Card</label>
            <input type="text" class="form-control" id="NameOnCard" name="NameOnCard" value="<?= h((string)($row['NameOnCard'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="Email">Email</label>
            <input type="email" class="form-control" id="Email" name="Email" value="<?= h((string)($row['Email'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="PortalScreenProgress">Portal Screen Progress</label>
            <input type="text" class="form-control" id="PortalScreenProgress" name="PortalScreenProgress" value="<?= h((string)($row['PortalScreenProgress'] ?? '')) ?>">
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label" for="HomePhone">Home Phone</label>
            <input type="text" class="form-control" id="HomePhone" name="HomePhone" value="<?= h((string)($row['HomePhone'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="WorkPhone">Work Phone</label>
            <input type="text" class="form-control" id="WorkPhone" name="WorkPhone" value="<?= h((string)($row['WorkPhone'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="MobilePhone">Mobile Phone</label>
            <input type="text" class="form-control" id="MobilePhone" name="MobilePhone" value="<?= h((string)($row['MobilePhone'] ?? '')) ?>">
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label" for="Address1">Address 1</label>
            <input type="text" class="form-control" id="Address1" name="Address1" value="<?= h((string)($row['Address1'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="Address2">Address 2</label>
            <input type="text" class="form-control" id="Address2" name="Address2" value="<?= h((string)($row['Address2'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="Address3">Address 3</label>
            <input type="text" class="form-control" id="Address3" name="Address3" value="<?= h((string)($row['Address3'] ?? '')) ?>">
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label" for="Suburb">Suburb</label>
            <input type="text" class="form-control" id="Suburb" name="Suburb" value="<?= h((string)($row['Suburb'] ?? '')) ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label" for="State">State</label>
            <input type="text" class="form-control" id="State" name="State" value="<?= h((string)($row['State'] ?? '')) ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label" for="PostCode">Post Code</label>
            <input type="text" class="form-control" id="PostCode" name="PostCode" value="<?= h((string)($row['PostCode'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="DefaultCompany">Default Company</label>
            <input type="text" class="form-control" id="DefaultCompany" name="DefaultCompany" value="<?= h((string)($row['DefaultCompany'] ?? '')) ?>">
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-4">
            <label class="form-label" for="DefaultCostCentre">Default Cost Centre</label>
            <input type="text" class="form-control" id="DefaultCostCentre" name="DefaultCostCentre" value="<?= h((string)($row['DefaultCostCentre'] ?? '')) ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label" for="CardNumber">Card Number</label>
            <input type="text" class="form-control" id="CardNumber" name="CardNumber" value="<?= h((string)($row['CardNumber'] ?? '')) ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label" for="CardNumberShort">Card Short</label>
            <input type="text" class="form-control" id="CardNumberShort" name="CardNumberShort" value="<?= h((string)($row['CardNumberShort'] ?? '')) ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label" for="AccountNumber">Account Number</label>
            <input type="text" class="form-control" id="AccountNumber" name="AccountNumber" value="<?= h((string)($row['AccountNumber'] ?? '')) ?>">
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-3">
            <label class="form-label" for="CreditLimitAmount">Credit Limit</label>
            <input type="number" step="0.01" class="form-control" id="CreditLimitAmount" name="CreditLimitAmount" value="<?= h((string)($row['CreditLimitAmount'] ?? '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="ActiveCeiling">Active Ceiling</label>
            <input type="number" step="0.01" class="form-control" id="ActiveCeiling" name="ActiveCeiling" value="<?= h((string)($row['ActiveCeiling'] ?? '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="TransactionLimit">Transaction Limit</label>
            <input type="number" step="0.01" class="form-control" id="TransactionLimit" name="TransactionLimit" value="<?= h((string)($row['TransactionLimit'] ?? '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="ATMLimit">ATM Limit</label>
            <input type="number" step="0.01" class="form-control" id="ATMLimit" name="ATMLimit" value="<?= h((string)($row['ATMLimit'] ?? '')) ?>">
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-3">
            <label class="form-label" for="OTCLimit">OTC Limit</label>
            <input type="number" step="0.01" class="form-control" id="OTCLimit" name="OTCLimit" value="<?= h((string)($row['OTCLimit'] ?? '')) ?>">
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-2">
            <label class="form-label" for="Expiry">Expiry</label>
            <input type="date" class="form-control" id="Expiry" name="Expiry" value="<?= h($dateValue($row['Expiry'] ?? '')) ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label" for="PortalInviteSent">Invite Sent</label>
            <input type="date" class="form-control" id="PortalInviteSent" name="PortalInviteSent" value="<?= h($dateValue($row['PortalInviteSent'] ?? '')) ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label" for="LoggedOntoPortal">Logged Onto Portal</label>
            <input type="date" class="form-control" id="LoggedOntoPortal" name="LoggedOntoPortal" value="<?= h($dateValue($row['LoggedOntoPortal'] ?? '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="TermsAndConditions">Terms Accepted</label>
            <input type="date" class="form-control" id="TermsAndConditions" name="TermsAndConditions" value="<?= h($dateValue($row['TermsAndConditions'] ?? '')) ?>">
          </div>
          <div class="col-md-3">
            <label class="form-label" for="AddressConfirmedInPortal">Address Confirmed</label>
            <input type="date" class="form-control" id="AddressConfirmedInPortal" name="AddressConfirmedInPortal" value="<?= h($dateValue($row['AddressConfirmedInPortal'] ?? '')) ?>">
          </div>
        </div>

        <div class="row g-3 mb-3">
          <div class="col-md-3">
            <label class="form-label" for="AddressChangedInPortal">Address Changed</label>
            <input type="date" class="form-control" id="AddressChangedInPortal" name="AddressChangedInPortal" value="<?= h($dateValue($row['AddressChangedInPortal'] ?? '')) ?>">
          </div>
          <div class="col-md-3 d-flex align-items-end">
            <div class="form-check me-3">
              <input class="form-check-input" type="checkbox" id="Active" name="Active" <?= ((string)($row['Active'] ?? 'N') === 'Y') ? 'checked' : '' ?>>
              <label class="form-check-label" for="Active">Active</label>
            </div>
            <div class="form-check me-3">
              <input class="form-check-input" type="checkbox" id="OnHold" name="OnHold" <?= ((string)($row['OnHold'] ?? 'N') === 'Y') ? 'checked' : '' ?>>
              <label class="form-check-label" for="OnHold">On Hold</label>
            </div>
            <div class="form-check">
              <input class="form-check-input" type="checkbox" id="ValidAddress" name="ValidAddress" <?= ((string)($row['ValidAddress'] ?? 'N') === 'Y') ? 'checked' : '' ?>>
              <label class="form-check-label" for="ValidAddress">Valid Address</label>
            </div>
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label" for="Notes">Notes</label>
          <textarea class="form-control" id="Notes" name="Notes" rows="4"><?= h((string)($row['Notes'] ?? '')) ?></textarea>
        </div>

        <hr class="mt-4 mb-2">
        <div class="d-flex justify-content-between align-items-center">
          <p class="text-muted small mb-0">
            <i class="bi bi-info-circle me-1"></i>Changes are not saved until you click Save.
          </p>
          <div class="d-flex gap-2">
            <a href="index.php?route=admin/portal-cards" class="btn btn-sm btn-outline-secondary">
              <i class="bi bi-arrow-left me-1"></i>Back
            </a>
            <button type="submit" class="btn btn-sm btn-primary">
              <i class="bi bi-save me-1"></i>Save
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
(() => {
  'use strict';
  const forms = document.querySelectorAll('.needs-validation');
  Array.from(forms).forEach(form => {
    form.addEventListener('submit', event => {
      if (!form.checkValidity()) {
        event.preventDefault();
        event.stopPropagation();
      }
      form.classList.add('was-validated');
    }, false);
  });
})();
</script>
