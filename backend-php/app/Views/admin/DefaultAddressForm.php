<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../shared/csrf.php';

if (!function_exists('h')) {
    function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$row = is_array($row ?? null) ? $row : [];
$validationErrors = is_array($validationErrors ?? null) ? $validationErrors : [];
$employeeSearchSeed = is_array($employeeSearchSeed ?? null) ? $employeeSearchSeed : [];
$selectedEmployee = is_array($selectedEmployee ?? null) ? $selectedEmployee : [];
$csrf = h(csrf_token());
$isEdit = !empty($row['DefaultAddressID']);

function field_error(array $errs, string $key): ?string
{
    return $errs[$key] ?? null;
}
?>

<style>
  .readonly-field { background-color:#f8f9fa; color:#495057; cursor:not-allowed; }
  .employee-search-results {
    display: none;
    max-height: 220px;
    overflow-y: auto;
    border: 1px solid #ced4da;
    border-top: 0;
    border-radius: 0 0 .375rem .375rem;
    background: #fff;
  }
  .employee-search-results .list-group-item {
    cursor: pointer;
  }
  .employee-search-results .list-group-item:hover,
  .employee-search-results .list-group-item.active {
    background-color: #f8f9fa;
  }
</style>

<div class="container-fluid mt-4">
  <div class="d-flex justify-content-between align-items-start mb-3">
    <div>
      <h3 class="mb-1"><?= !empty($row['DefaultAddressID']) ? 'Edit Portal Default Address' : 'Add Portal Default Address' ?></h3>
      <div class="text-muted">This address will prefill future applications for the employee.</div>
    </div>
    <a class="btn btn-outline-secondary btn-sm leave-page-link" href="index.php?route=admin/default-addresses">
      <i class="bi bi-arrow-left me-1"></i>Back
    </a>
  </div>

  <div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
      <strong>Default Address Details</strong>
      <span class="text-muted small">
        <?= !empty($row['DefaultAddressID']) ? 'Entry #' . h((string)$row['DefaultAddressID']) : 'New Entry' ?>
      </span>
    </div>
    <div class="card-body">
      <form method="post" action="index.php?route=admin/default-addresses-save" id="defaultAddressForm" novalidate>
        <input type="hidden" name="_csrf" value="<?= $csrf ?>">
        <input type="hidden" name="default_address_id" value="<?= h((string)($row['DefaultAddressID'] ?? 0)) ?>">

        <div class="row g-3">
          <div class="col-md-4">
            <label class="form-label">EmployeeID</label>
            <?php $e = field_error($validationErrors, 'employee_id'); ?>
            <?php if ($isEdit): ?>
              <input type="hidden" name="employee_id" value="<?= h((string)($row['EmployeeID'] ?? '')) ?>">
            <?php endif; ?>
            <input
              class="form-control <?= $isEdit ? 'readonly-field ' : '' ?><?= $e ? 'is-invalid' : '' ?>"
              id="employeeIdInput"
              <?= $isEdit ? '' : 'name="employee_id"' ?>
              value="<?= h((string)($row['EmployeeID'] ?? '')) ?>"
              <?= $isEdit ? 'disabled' : 'autocomplete="off" data-lookup-url="index.php?route=admin/default-addresses-lookup"' ?>
              required
            >
            <?php if ($e): ?><div class="invalid-feedback d-block"><?= h($e) ?></div><?php endif; ?>
            <?php if (!$isEdit): ?>
              <div id="employeeSearchResults" class="employee-search-results list-group">
              </div>
              <div class="form-text">Start typing to search and select an EmployeeID.</div>
            <?php endif; ?>
          </div>
          <div class="col-md-2">
            <label class="form-label">Title</label>
            <input class="form-control readonly-field" id="selectedEmployeeTitle" value="<?= h((string)($selectedEmployee['title'] ?? '')) ?>" readonly tabindex="-1">
          </div>
          <div class="col-md-3">
            <label class="form-label">First Name</label>
            <input class="form-control readonly-field" id="selectedEmployeeFirstName" value="<?= h((string)($selectedEmployee['first_name'] ?? '')) ?>" readonly tabindex="-1">
          </div>
          <div class="col-md-3">
            <label class="form-label">Last Name</label>
            <input class="form-control readonly-field" id="selectedEmployeeLastName" value="<?= h((string)($selectedEmployee['last_name'] ?? '')) ?>" readonly tabindex="-1">
          </div>
          <div class="col-md-4">
            <label class="form-label">Source Application ID</label>
            <input class="form-control readonly-field" type="number" id="sourceApplicationIdInput" name="source_application_id" value="<?= h((string)($row['SourceApplicationID'] ?? '')) ?>" readonly>
          </div>
          <div class="col-md-4">
            <label class="form-label">Confirmed At</label>
            <input class="form-control" value="<?= h((string)($row['ConfirmedAt'] ?? 'Auto on save')) ?>" disabled>
          </div>

          <div class="col-md-12">
            <label class="form-label">Address 1</label>
            <?php $e = field_error($validationErrors, 'address1'); ?>
            <input class="form-control <?= $e ? 'is-invalid' : '' ?>" id="address1Input" name="address1" maxlength="30" value="<?= h((string)($row['Address1'] ?? '')) ?>" required>
            <?php if ($e): ?><div class="invalid-feedback d-block"><?= h($e) ?></div><?php endif; ?>
            <div class="invalid-feedback d-none" id="address1ReqError">Address 1 is required.</div>
            <div class="invalid-feedback d-none" id="address1LenError">Address 1 must be 30 characters or less.</div>
          </div>
          <div class="col-md-12">
            <label class="form-label">Address 2</label>
            <?php $e = field_error($validationErrors, 'address2'); ?>
            <input class="form-control <?= $e ? 'is-invalid' : '' ?>" id="address2Input" name="address2" maxlength="30" value="<?= h((string)($row['Address2'] ?? '')) ?>">
            <?php if ($e): ?><div class="invalid-feedback d-block"><?= h($e) ?></div><?php endif; ?>
            <div class="invalid-feedback d-none" id="address2LenError">Address 2 must be 30 characters or less.</div>
          </div>
          <div class="col-md-12">
            <label class="form-label">Address 3</label>
            <?php $e = field_error($validationErrors, 'address3'); ?>
            <input class="form-control <?= $e ? 'is-invalid' : '' ?>" id="address3Input" name="address3" maxlength="30" value="<?= h((string)($row['Address3'] ?? '')) ?>">
            <?php if ($e): ?><div class="invalid-feedback d-block"><?= h($e) ?></div><?php endif; ?>
            <div class="invalid-feedback d-none" id="address3LenError">Address 3 must be 30 characters or less.</div>
          </div>

          <div class="col-md-4">
            <label class="form-label">Suburb</label>
            <?php $e = field_error($validationErrors, 'suburb'); ?>
            <input class="form-control <?= $e ? 'is-invalid' : '' ?>" id="suburbInput" name="suburb" maxlength="22" value="<?= h((string)($row['Suburb'] ?? '')) ?>" required>
            <?php if ($e): ?><div class="invalid-feedback d-block"><?= h($e) ?></div><?php endif; ?>
            <div class="invalid-feedback d-none" id="suburbReqError">Suburb is required.</div>
            <div class="invalid-feedback d-none" id="suburbLenError">Suburb must be 22 characters or less.</div>
          </div>
          <div class="col-md-4">
            <label class="form-label">State</label>
            <?php $e = field_error($validationErrors, 'state'); ?>
            <input class="form-control <?= $e ? 'is-invalid' : '' ?>" id="stateInput" name="state" value="<?= h((string)($row['State'] ?? '')) ?>" required>
            <?php if ($e): ?><div class="invalid-feedback d-block"><?= h($e) ?></div><?php endif; ?>
          </div>
          <div class="col-md-4">
            <label class="form-label">PostCode</label>
            <?php $e = field_error($validationErrors, 'postcode'); ?>
            <input class="form-control <?= $e ? 'is-invalid' : '' ?>" id="postcodeInput" name="postcode" inputmode="numeric" maxlength="4" value="<?= h((string)($row['PostCode'] ?? '')) ?>" required>
            <?php if ($e): ?><div class="invalid-feedback d-block"><?= h($e) ?></div><?php endif; ?>
            <div class="invalid-feedback d-none" id="postcodeReqError">PostCode is required.</div>
            <div class="invalid-feedback d-none" id="postcodeFmtError">PostCode must be numeric and 4 digits or less.</div>
          </div>

          <div class="col-md-6">
            <label class="form-label">Phone</label>
            <?php $e = field_error($validationErrors, 'phone'); ?>
            <input class="form-control <?= $e ? 'is-invalid' : '' ?>" id="phoneInput" name="phone" maxlength="30" value="<?= h((string)($row['Phone'] ?? '')) ?>">
            <?php if ($e): ?><div class="invalid-feedback d-block"><?= h($e) ?></div><?php endif; ?>
            <div class="invalid-feedback d-none" id="phoneLenError">Phone must be 30 characters or less.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label">Mobile</label>
            <?php $e = field_error($validationErrors, 'mobile'); ?>
            <input class="form-control <?= $e ? 'is-invalid' : '' ?>" id="mobileInput" name="mobile" maxlength="30" value="<?= h((string)($row['Mobile'] ?? '')) ?>">
            <?php if ($e): ?><div class="invalid-feedback d-block"><?= h($e) ?></div><?php endif; ?>
            <div class="invalid-feedback d-none" id="mobileLenError">Mobile must be 30 characters or less.</div>
          </div>
        </div>

        <div class="mt-3 d-flex gap-2">
          <button type="submit" class="btn btn-primary">Save</button>
          <a class="btn btn-outline-secondary leave-page-link" href="index.php?route=admin/default-addresses">Cancel</a>
        </div>
      </form>
    </div>
  </div>
</div>

<div class="modal fade" id="leaveWithoutSavingModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Leave Without Saving?</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        You have unsaved changes. If you leave this page now, your changes will be lost.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Stay on Page</button>
        <a href="#" class="btn btn-danger" id="confirmLeaveWithoutSaving">Leave Without Saving</a>
      </div>
    </div>
  </div>
</div>

<script>
  (function () {
    const form = document.getElementById('defaultAddressForm');
    if (!form) return;

    const employeeId = document.getElementById('employeeIdInput');
    const a1 = document.getElementById('address1Input');
    const a2 = document.getElementById('address2Input');
    const a3 = document.getElementById('address3Input');
    const suburb = document.getElementById('suburbInput');
    const state = document.getElementById('stateInput');
    const postcode = document.getElementById('postcodeInput');
    const sourceApplicationId = document.getElementById('sourceApplicationIdInput');
    const phone = document.getElementById('phoneInput');
    const mobile = document.getElementById('mobileInput');

    const a1ReqError = document.getElementById('address1ReqError');
    const a1LenError = document.getElementById('address1LenError');
    const a2LenError = document.getElementById('address2LenError');
    const a3LenError = document.getElementById('address3LenError');
    const suburbReqError = document.getElementById('suburbReqError');
    const suburbLenError = document.getElementById('suburbLenError');
    const postcodeReqError = document.getElementById('postcodeReqError');
    const postcodeFmtError = document.getElementById('postcodeFmtError');
    const phoneLenError = document.getElementById('phoneLenError');
    const mobileLenError = document.getElementById('mobileLenError');
    const employeeSearchResults = document.getElementById('employeeSearchResults');
    const employeeLookupUrl = employeeId ? (employeeId.dataset.lookupUrl || '') : '';
    const employeeTitle = document.getElementById('selectedEmployeeTitle');
    const employeeFirstName = document.getElementById('selectedEmployeeFirstName');
    const employeeLastName = document.getElementById('selectedEmployeeLastName');
    const leaveLinks = Array.prototype.slice.call(document.querySelectorAll('.leave-page-link'));
    const leaveModalEl = document.getElementById('leaveWithoutSavingModal');
    const leaveConfirmLink = document.getElementById('confirmLeaveWithoutSaving');
    let hasSubmitted = false;
    const touched = new WeakSet();
    let lookupTimer = null;
    let lookupCounter = 0;
    let currentResults = [];
    let detailRequestCounter = 0;
    let leaveTargetHref = '';
    let isFormDirty = false;

    function text(v) { return (v || '').trim(); }
    function show(el, visible) {
      if (!el) return;
      el.classList.toggle('d-none', !visible);
      el.classList.toggle('d-block', visible);
    }

    function formSnapshot() {
      return JSON.stringify({
        employee_id: employeeId ? employeeId.value : '',
        address1: a1 ? a1.value : '',
        address2: a2 ? a2.value : '',
        address3: a3 ? a3.value : '',
        suburb: suburb ? suburb.value : '',
        state: state ? state.value : '',
        postcode: postcode ? postcode.value : '',
        source_application_id: sourceApplicationId ? sourceApplicationId.value : '',
        phone: phone ? phone.value : '',
        mobile: mobile ? mobile.value : ''
      });
    }

    function markDirty() {
      if (!hasSubmitted) {
        isFormDirty = true;
      }
    }

    function getLeaveModal() {
      if (!leaveModalEl || !window.bootstrap || !window.bootstrap.Modal) {
        return null;
      }
      if (typeof window.bootstrap.Modal.getOrCreateInstance === 'function') {
        return window.bootstrap.Modal.getOrCreateInstance(leaveModalEl);
      }
      return new window.bootstrap.Modal(leaveModalEl);
    }

    function shouldShow(el) {
      return hasSubmitted || (el ? touched.has(el) : false);
    }

    function setInvalidState(el, invalid) {
      if (!el) return;
      el.classList.toggle('is-invalid', shouldShow(el) && invalid);
    }

    function setSelectedEmployee(item) {
      const data = item || {};
      if (employeeTitle) employeeTitle.value = data.title || '';
      if (employeeFirstName) employeeFirstName.value = data.first_name || '';
      if (employeeLastName) employeeLastName.value = data.last_name || '';
    }

    function applyDefaultAddress(item) {
      if (<?= $isEdit ? 'true' : 'false' ?>) return;

      const address = item && item.default_address ? item.default_address : (item && item.caps_address ? item.caps_address : null);
      a1.value = address ? (address.address1 || '') : '';
      a2.value = address ? (address.address2 || '') : '';
      a3.value = address ? (address.address3 || '') : '';
      suburb.value = address ? (address.suburb || '') : '';
      state.value = address ? (address.state || '') : '';
      postcode.value = address ? (address.postcode || '') : '';
      if (sourceApplicationId) {
        const sourceValue = item && item.default_address ? (item.default_address.source_application_id || '') : '';
        sourceApplicationId.value = sourceValue === 0 ? '' : sourceValue;
      }
      if (phone) {
        phone.value = item && item.phone ? item.phone : '';
      }
      if (mobile) {
        mobile.value = item && item.mobile ? item.mobile : '';
      }
    }

    function fetchEmployeeDetails(employeeIdValue) {
      const selectedId = text(employeeIdValue);
      if (!employeeLookupUrl || selectedId === '') {
        if (selectedId === '') {
          setSelectedEmployee(null);
        }
        return;
      }

      const requestId = ++detailRequestCounter;
      const url = employeeLookupUrl + '&q=' + encodeURIComponent(selectedId);

      fetch(url, {
        headers: {
          'X-Requested-With': 'XMLHttpRequest'
        }
      })
        .then(function (response) {
          if (!response.ok) {
            throw new Error('Lookup failed');
          }
          return response.json();
        })
        .then(function (data) {
          if (requestId !== detailRequestCounter) return;
          const items = data && Array.isArray(data.items) ? data.items : [];
          const exactMatch = items.find(function (item) {
            return (item.employee_id || '') === selectedId;
          });
          if (exactMatch) {
            setSelectedEmployee(exactMatch);
            applyDefaultAddress(exactMatch);
          }
        })
        .catch(function () {
          if (requestId !== detailRequestCounter) return;
        });
    }

    function setEmployeeOptions(items) {
      if (!employeeSearchResults) return;
      currentResults = Array.isArray(items) ? items : [];
      employeeSearchResults.innerHTML = '';
      currentResults.forEach(function (item) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'list-group-item list-group-item-action';
        button.dataset.employeeId = item.employee_id || '';
        button.textContent = item.label || item.employee_id || '';
        employeeSearchResults.appendChild(button);
      });
      const hasItems = employeeSearchResults.children.length > 0 && text(employeeId.value) !== '';
      employeeSearchResults.style.display = hasItems ? 'block' : 'none';

      const exactMatch = currentResults.find(function (item) {
        return (item.employee_id || '') === text(employeeId.value);
      });
      if (exactMatch) {
        setSelectedEmployee(exactMatch);
        applyDefaultAddress(exactMatch);
        if (!(exactMatch.title || exactMatch.first_name || exactMatch.last_name)) {
          fetchEmployeeDetails(exactMatch.employee_id || '');
        }
      } else if (text(employeeId.value) === '') {
        setSelectedEmployee(null);
        applyDefaultAddress(null);
      }
    }

    function queueEmployeeLookup() {
      if (!employeeLookupUrl || !employeeSearchResults) return;

      const query = text(employeeId.value);
      if (lookupTimer) {
        clearTimeout(lookupTimer);
      }

      if (query === '') {
        setEmployeeOptions([]);
        return;
      }

      setEmployeeOptions([]);

      lookupTimer = setTimeout(function () {
        const requestId = ++lookupCounter;
        const url = employeeLookupUrl + '&q=' + encodeURIComponent(query);

        fetch(url, {
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        })
          .then(function (response) {
            if (!response.ok) {
              throw new Error('Lookup failed');
            }
            return response.json();
          })
          .then(function (data) {
            if (requestId !== lookupCounter) return;
            const items = data && Array.isArray(data.items) ? data.items : [];
            setEmployeeOptions(items);
          })
          .catch(function () {
            if (requestId !== lookupCounter) return;
          });
      }, 180);
    }

    function validate() {
      const employeeOk = text(employeeId.value) !== '';
      const a1Val = a1.value || '';
      const a2Val = a2.value || '';
      const a3Val = a3.value || '';
      const suburbVal = suburb.value || '';
      const stateOk = text(state.value) !== '';
      const postVal = text(postcode.value);
      const phoneVal = phone ? (phone.value || '') : '';
      const mobileVal = mobile ? (mobile.value || '') : '';

      const a1Ok = text(a1Val) !== '' && a1Val.length <= 30;
      const a2Ok = a2Val.length <= 30;
      const a3Ok = a3Val.length <= 30;
      const suburbOk = text(suburbVal) !== '' && suburbVal.length <= 22;
      const postcodeOk = postVal !== '' && /^\d+$/.test(postVal) && postVal.length <= 4;
      const phoneOk = phoneVal.length <= 30;
      const mobileOk = mobileVal.length <= 30;

      setInvalidState(employeeId, !employeeOk);

      setInvalidState(a1, !a1Ok);
      show(a1ReqError, shouldShow(a1) && text(a1Val) === '');
      show(a1LenError, shouldShow(a1) && a1Val.length > 30);

      setInvalidState(a2, !a2Ok);
      show(a2LenError, shouldShow(a2) && a2Val.length > 30);

      setInvalidState(a3, !a3Ok);
      show(a3LenError, shouldShow(a3) && a3Val.length > 30);

      setInvalidState(suburb, !suburbOk);
      show(suburbReqError, shouldShow(suburb) && text(suburbVal) === '');
      show(suburbLenError, shouldShow(suburb) && suburbVal.length > 22);

      setInvalidState(state, !stateOk);

      setInvalidState(postcode, !postcodeOk);
      show(postcodeReqError, shouldShow(postcode) && postVal === '');
      show(postcodeFmtError, shouldShow(postcode) && postVal !== '' && !(/^\d+$/.test(postVal) && postVal.length <= 4));

      if (phone) {
        setInvalidState(phone, !phoneOk);
        show(phoneLenError, shouldShow(phone) && phoneVal.length > 30);
      }
      if (mobile) {
        setInvalidState(mobile, !mobileOk);
        show(mobileLenError, shouldShow(mobile) && mobileVal.length > 30);
      }

      return employeeOk && a1Ok && a2Ok && a3Ok && suburbOk && stateOk && postcodeOk && phoneOk && mobileOk;
    }

    function isDirty() {
      return !hasSubmitted && (isFormDirty || formSnapshot() !== initialSnapshot);
    }

    const initialSnapshot = formSnapshot();

    ['input', 'change', 'blur'].forEach(function (evt) {
      [employeeId, a1, a2, a3, suburb, state, postcode, phone, mobile].forEach(function (el) {
        if (!el) return;
        el.addEventListener(evt, function () {
          touched.add(el);
          if (evt !== 'blur') {
            markDirty();
          }
          if (el === employeeId && evt !== 'blur') {
            const exactMatch = currentResults.find(function (item) {
              return (item.employee_id || '') === text(employeeId.value);
            });
            if (exactMatch) {
              setSelectedEmployee(exactMatch);
              applyDefaultAddress(exactMatch);
              if (!(exactMatch.title || exactMatch.first_name || exactMatch.last_name)) {
                fetchEmployeeDetails(exactMatch.employee_id || '');
              }
            } else if (text(employeeId.value) === '') {
              setSelectedEmployee(null);
              applyDefaultAddress(null);
            }
            queueEmployeeLookup();
          }
          if (el === employeeId && evt === 'blur') {
            setTimeout(function () {
              if (employeeSearchResults) {
                employeeSearchResults.style.display = 'none';
              }
            }, 150);
          }
          validate();
        });
      });
    });

    if (employeeSearchResults) {
      employeeSearchResults.addEventListener('mousedown', function (e) {
        const target = e.target.closest('[data-employee-id]');
        if (!target) return;
        e.preventDefault();
        employeeId.value = target.dataset.employeeId || '';
        const match = currentResults.find(function (item) {
          return (item.employee_id || '') === employeeId.value;
        });
        setSelectedEmployee(match || null);
        applyDefaultAddress(match || null);
        if (!match || !(match.title || match.first_name || match.last_name)) {
          fetchEmployeeDetails(employeeId.value);
        }
        touched.add(employeeId);
        employeeSearchResults.style.display = 'none';
        validate();
      });
    }

    form.addEventListener('submit', function (e) {
      hasSubmitted = true;
      if (!validate()) {
        e.preventDefault();
        e.stopPropagation();
      }
    });

    leaveLinks.forEach(function (link) {
      link.addEventListener('click', function (e) {
        if (!isDirty()) {
          return;
        }
        const leaveModal = getLeaveModal();
        if (!leaveModal || !leaveConfirmLink) {
          return;
        }
        e.preventDefault();
        e.stopPropagation();
        leaveTargetHref = link.getAttribute('href') || 'index.php?route=admin/default-addresses';
        leaveConfirmLink.setAttribute('href', leaveTargetHref);
        leaveModal.show();
      });
    });

    validate();
    if (text(employeeId.value) === '') {
      setSelectedEmployee(null);
      applyDefaultAddress(null);
    } else {
      setEmployeeOptions([]);
      fetchEmployeeDetails(employeeId.value);
    }
  })();
</script>
