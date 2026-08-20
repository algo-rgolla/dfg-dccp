<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Rbac;
use App\Shared\SessionHelper;
use App\Models\AuditModel;
use App\Models\SystemSettingsModel;

require_once __DIR__ . '/../../shared/csrf.php';

final class EligibilityAdminController extends BaseController
{
    protected array $acl = [
        '*' => ['auth' => true],
    ];

    public function blacklistList(): void
    {
        $this->ensureAdminAccess();

        $stmt = $this->db->query("
            SELECT
                b.*,
                at.ApplicationTypeName
            FROM dbo.tblCardApplicationBlacklist b
            LEFT JOIN dbo.tblApplicationTypes at
              ON at.ApplicationTypeID = b.AppliesToApplicationTypeID
            ORDER BY b.IsActive DESC, b.EmployeeID ASC, b.BlacklistID DESC
        ");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $this->render('admin/BlacklistList', [
            'title' => 'Application Restricted List',
            'rows' => $rows,
        ]);
    }

    public function blacklistEdit(): void
    {
        $this->ensureAdminAccess();

        $id = (int)($_GET['id'] ?? 0);
        $row = null;
        if ($id > 0) {
            $stmt = $this->db->prepare("
                SELECT *
                FROM dbo.tblCardApplicationBlacklist
                WHERE BlacklistID = :id
            ");
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }

        $this->render('admin/BlacklistForm', [
            'title' => $id > 0 ? 'Edit Restricted List Entry' : 'Add Restricted List Entry',
            'row' => $row,
            'applicationTypes' => $this->loadApplicationTypes(),
            'defaultReason' => $this->getRestrictedListDefaultReason(),
        ]);
    }

    private function getRestrictedListDefaultReason(): string
    {
        try {
            $settings = new SystemSettingsModel($this->db);
            $value = trim((string)($settings->get('RESTRICTED_LIST_DEFAULT_REASON') ?? ''));
            return $value !== '' ? $value : 'restricted by credit card team';
        } catch (\Throwable $e) {
            return 'restricted by credit card team';
        }
    }

    public function blacklistSave(): void
    {
        $this->ensureAdminAccess();
        $this->requirePostAndCsrf('eligibility-admin/blacklist-list');

        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        $id = (int)($_POST['blacklist_id'] ?? 0);
        $employeeId = trim((string)($_POST['employee_id'] ?? ''));
        $applicationTypeId = (int)($_POST['application_type_id'] ?? 0);
        $isActive = ((string)($_POST['is_active'] ?? '1') === '1') ? 1 : 0;
        $reason = trim((string)($_POST['reason'] ?? ''));
        $effectiveFrom = $this->normalizeDateTimeInput((string)($_POST['effective_from'] ?? ''));
        $effectiveTo = $this->normalizeDateTimeInput((string)($_POST['effective_to'] ?? ''));

        if ($employeeId === '') {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'EmployeeID is required.']);
            header('Location: index.php?route=eligibility-admin/blacklist-edit' . ($id > 0 ? '&id=' . urlencode((string)$id) : ''));
            exit;
        }

        if ($id > 0) {
            $stmt = $this->db->prepare("
                UPDATE dbo.tblCardApplicationBlacklist
                SET EmployeeID = :eid,
                    AppliesToApplicationTypeID = :atid,
                    IsActive = :active,
                    Reason = :reason,
                    EffectiveFrom = :efrom,
                    EffectiveTo = :eto,
                    UpdatedAt = SYSUTCDATETIME(),
                    UpdatedBy = :uid
                WHERE BlacklistID = :id
            ");
            $stmt->execute([
                'eid' => $employeeId,
                'atid' => $applicationTypeId > 0 ? $applicationTypeId : null,
                'active' => $isActive,
                'reason' => $reason !== '' ? $reason : null,
                'efrom' => $effectiveFrom,
                'eto' => $effectiveTo,
                'uid' => $userId > 0 ? $userId : null,
                'id' => $id,
            ]);
            $this->audit('UPDATE', 'CardApplicationBlacklist', (string)$id, ['employee_id' => $employeeId]);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO dbo.tblCardApplicationBlacklist
                    (EmployeeID, AppliesToApplicationTypeID, IsActive, Reason, EffectiveFrom, EffectiveTo, CreatedBy, UpdatedBy)
                VALUES
                    (:eid, :atid, :active, :reason, :efrom, :eto, :created_by, :updated_by)
            ");
            $stmt->execute([
                'eid' => $employeeId,
                'atid' => $applicationTypeId > 0 ? $applicationTypeId : null,
                'active' => $isActive,
                'reason' => $reason !== '' ? $reason : null,
                'efrom' => $effectiveFrom,
                'eto' => $effectiveTo,
                'created_by' => $userId > 0 ? $userId : null,
                'updated_by' => $userId > 0 ? $userId : null,
            ]);
            $newId = (int)$this->db->lastInsertId();
            $this->audit('CREATE', 'CardApplicationBlacklist', (string)$newId, ['employee_id' => $employeeId]);
        }

        SessionHelper::set('flash.message', ['type' => 'success', 'text' => 'Restricted list entry saved.']);
        header('Location: index.php?route=eligibility-admin/blacklist-list');
        exit;
    }

    public function blacklistDelete(): void
    {
        $this->ensureAdminAccess();
        $this->requirePostAndCsrf('eligibility-admin/blacklist-list');

        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $existingStmt = $this->db->prepare("
                SELECT EmployeeID
                FROM dbo.tblCardApplicationBlacklist
                WHERE BlacklistID = :id
            ");
            $existingStmt->execute(['id' => $id]);
            $employeeId = trim((string)($existingStmt->fetchColumn() ?? ''));

            $stmt = $this->db->prepare("
                DELETE FROM dbo.tblCardApplicationBlacklist
                WHERE BlacklistID = :id
            ");
            $stmt->execute(['id' => $id]);
            $this->audit('DELETE', 'CardApplicationBlacklist', (string)$id, ['employee_id' => $employeeId]);
        }
        SessionHelper::set('flash.message', ['type' => 'success', 'text' => 'Restricted list entry deleted.']);
        header('Location: index.php?route=eligibility-admin/blacklist-list');
        exit;
    }

    public function overrideList(): void
    {
        $this->ensureAdminAccess();

        $stmt = $this->db->query("
            SELECT
                o.*,
                at.ApplicationTypeName
            FROM dbo.tblApplicationEligibilityOverride o
            LEFT JOIN dbo.tblApplicationTypes at
              ON at.ApplicationTypeID = o.AppliesToApplicationTypeID
            ORDER BY o.IsActive DESC, o.EmployeeID ASC, o.OverrideID DESC
        ");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $this->render('admin/OverrideList', [
            'title' => 'Eligibility Overrides',
            'rows' => $rows,
        ]);
    }

    public function overrideEdit(): void
    {
        $this->ensureAdminAccess();

        $id = (int)($_GET['id'] ?? 0);
        $row = null;
        if ($id > 0) {
            $stmt = $this->db->prepare("
                SELECT *
                FROM dbo.tblApplicationEligibilityOverride
                WHERE OverrideID = :id
            ");
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }

        $this->render('admin/OverrideForm', [
            'title' => $id > 0 ? 'Edit Eligibility Override' : 'Add Eligibility Override',
            'row' => $row,
            'applicationTypes' => $this->loadApplicationTypes(),
            'overrideTypes' => ['POSITION_TYPE_CHECK'],
        ]);
    }

    public function overrideSave(): void
    {
        $this->ensureAdminAccess();
        $this->requirePostAndCsrf('eligibility-admin/override-list');

        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        $id = (int)($_POST['override_id'] ?? 0);
        $employeeId = trim((string)($_POST['employee_id'] ?? ''));
        $overrideType = strtoupper(trim((string)($_POST['override_type'] ?? '')));
        $applicationTypeId = (int)($_POST['application_type_id'] ?? 0);
        $isActive = ((string)($_POST['is_active'] ?? '1') === '1') ? 1 : 0;
        $reason = trim((string)($_POST['reason'] ?? ''));
        $effectiveFrom = $this->normalizeDateTimeInput((string)($_POST['effective_from'] ?? ''));
        $effectiveTo = $this->normalizeDateTimeInput((string)($_POST['effective_to'] ?? ''));

        if ($employeeId === '' || $overrideType === '') {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'EmployeeID and Override Type are required.']);
            header('Location: index.php?route=eligibility-admin/override-edit' . ($id > 0 ? '&id=' . urlencode((string)$id) : ''));
            exit;
        }

        if ($id > 0) {
            $stmt = $this->db->prepare("
                UPDATE dbo.tblApplicationEligibilityOverride
                SET EmployeeID = :eid,
                    OverrideType = :otype,
                    AppliesToApplicationTypeID = :atid,
                    IsActive = :active,
                    Reason = :reason,
                    EffectiveFrom = :efrom,
                    EffectiveTo = :eto,
                    UpdatedAt = SYSUTCDATETIME(),
                    UpdatedBy = :uid
                WHERE OverrideID = :id
            ");
            $stmt->execute([
                'eid' => $employeeId,
                'otype' => $overrideType,
                'atid' => $applicationTypeId > 0 ? $applicationTypeId : null,
                'active' => $isActive,
                'reason' => $reason !== '' ? $reason : null,
                'efrom' => $effectiveFrom,
                'eto' => $effectiveTo,
                'uid' => $userId > 0 ? $userId : null,
                'id' => $id,
            ]);
            $this->audit('UPDATE', 'ApplicationEligibilityOverride', (string)$id, ['employee_id' => $employeeId, 'override_type' => $overrideType]);
        } else {
            $stmt = $this->db->prepare("
                INSERT INTO dbo.tblApplicationEligibilityOverride
                    (EmployeeID, OverrideType, AppliesToApplicationTypeID, IsActive, Reason, EffectiveFrom, EffectiveTo, CreatedBy, UpdatedBy)
                VALUES
                    (:eid, :otype, :atid, :active, :reason, :efrom, :eto, :created_by, :updated_by)
            ");
            $stmt->execute([
                'eid' => $employeeId,
                'otype' => $overrideType,
                'atid' => $applicationTypeId > 0 ? $applicationTypeId : null,
                'active' => $isActive,
                'reason' => $reason !== '' ? $reason : null,
                'efrom' => $effectiveFrom,
                'eto' => $effectiveTo,
                'created_by' => $userId > 0 ? $userId : null,
                'updated_by' => $userId > 0 ? $userId : null,
            ]);
            $newId = (int)$this->db->lastInsertId();
            $this->audit('CREATE', 'ApplicationEligibilityOverride', (string)$newId, ['employee_id' => $employeeId, 'override_type' => $overrideType]);
        }

        SessionHelper::set('flash.message', ['type' => 'success', 'text' => 'Eligibility override saved.']);
        header('Location: index.php?route=eligibility-admin/override-list');
        exit;
    }

    public function overrideDelete(): void
    {
        $this->ensureAdminAccess();
        $this->requirePostAndCsrf('eligibility-admin/override-list');

        $id = (int)($_POST['id'] ?? 0);
        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        if ($id > 0) {
            $stmt = $this->db->prepare("
                UPDATE dbo.tblApplicationEligibilityOverride
                SET IsActive = 0,
                    UpdatedAt = SYSUTCDATETIME(),
                    UpdatedBy = :uid
                WHERE OverrideID = :id
            ");
            $stmt->execute([
                'uid' => $userId > 0 ? $userId : null,
                'id' => $id,
            ]);
            $this->audit('DEACTIVATE', 'ApplicationEligibilityOverride', (string)$id);
        }
        SessionHelper::set('flash.message', ['type' => 'success', 'text' => 'Eligibility override deactivated.']);
        header('Location: index.php?route=eligibility-admin/override-list');
        exit;
    }

    public function defaultAddressList(): void
    {
        $this->ensureAdminAccess();

        $stmt = $this->db->query("
            SELECT *
            FROM dbo.tblPortalDefaultAddresses
            ORDER BY UpdatedAt DESC, DefaultAddressID DESC
        ");
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $this->render('admin/DefaultAddressList', [
            'title' => 'Portal Default Addresses',
            'rows' => $rows,
        ]);
    }

    public function defaultAddressEdit(): void
    {
        $this->ensureAdminAccess();

        $id = (int)($_GET['id'] ?? 0);
        $row = null;
        if ($id > 0) {
            $stmt = $this->db->prepare("
                SELECT *
                FROM dbo.tblPortalDefaultAddresses
                WHERE DefaultAddressID = :id
            ");
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        }

        $errorsKey = 'admin.default_address.errors.' . $id;
        $oldKey = 'admin.default_address.old.' . $id;
        $validationErrors = SessionHelper::get($errorsKey, []);
        $oldInput = SessionHelper::get($oldKey, []);
        SessionHelper::forget($errorsKey);
        SessionHelper::forget($oldKey);

        if (!is_array($validationErrors)) {
            $validationErrors = [];
        }
        if (!is_array($oldInput)) {
            $oldInput = [];
        }
        if (!empty($oldInput)) {
            $row = array_merge(is_array($row) ? $row : [], $oldInput);
        }
        if (is_array($row)) {
            $row = $this->hydrateDefaultAddressContactFields($row);
        }
        $selectedEmployee = $this->loadEmployeeSummary(trim((string)($row['EmployeeID'] ?? '')));
        if (is_array($row)) {
            if (trim((string)($row['Phone'] ?? '')) === '' && !empty($selectedEmployee['phone'])) {
                $row['Phone'] = (string)$selectedEmployee['phone'];
            }
            if (trim((string)($row['Mobile'] ?? '')) === '' && !empty($selectedEmployee['mobile'])) {
                $row['Mobile'] = (string)$selectedEmployee['mobile'];
            }
        }
        $employeeSearchSeed = $this->loadEmployeeSearchResults('', 500);

        $this->render('admin/DefaultAddressForm', [
            'title' => $id > 0 ? 'Edit Portal Default Address' : 'Add Portal Default Address',
            'row' => $row,
            'validationErrors' => $validationErrors,
            'employeeOptions' => $this->loadEmployeeOptions(),
            'employeeSearchSeed' => $employeeSearchSeed,
            'selectedEmployee' => $selectedEmployee,
        ]);
    }

    public function defaultAddressLookup(): void
    {
        $this->ensureAdminAccess();

        header('Content-Type: application/json; charset=utf-8');

        $term = trim((string)($_GET['q'] ?? ''));

        echo json_encode([
            'items' => $this->loadEmployeeSearchResults($term, 25),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public function suburbList(): void
    {
        $this->ensureAdminAccess();

        $rows = $this->loadCustomSuburbEntries();
        usort($rows, static function (array $a, array $b): int {
            return [(string)($a['n'] ?? ''), (string)($a['s'] ?? ''), (string)($a['p'] ?? '')]
                <=>
                [(string)($b['n'] ?? ''), (string)($b['s'] ?? ''), (string)($b['p'] ?? '')];
        });

        $this->render('admin/SuburbList', [
            'title' => 'Custom Suburbs',
            'rows' => $rows,
        ]);
    }

    public function suburbEdit(): void
    {
        $this->ensureAdminAccess();

        $id = trim((string)($_GET['id'] ?? ''));
        $row = null;
        foreach ($this->loadCustomSuburbEntries() as $entry) {
            if ((string)($entry['id'] ?? '') === $id) {
                $row = $entry;
                break;
            }
        }

        $errorsKey = 'admin.suburb.errors.' . $id;
        $oldKey = 'admin.suburb.old.' . $id;
        $validationErrors = SessionHelper::get($errorsKey, []);
        $oldInput = SessionHelper::get($oldKey, []);
        SessionHelper::forget($errorsKey);
        SessionHelper::forget($oldKey);

        if (!is_array($validationErrors)) {
            $validationErrors = [];
        }
        if (!is_array($oldInput)) {
            $oldInput = [];
        }
        if (!empty($oldInput)) {
            $row = array_merge(is_array($row) ? $row : [], $oldInput);
        }

        $this->render('admin/SuburbForm', [
            'title' => $id !== '' ? 'Edit Custom Suburb' : 'Add Custom Suburb',
            'row' => $row,
            'validationErrors' => $validationErrors,
        ]);
    }

    public function suburbSave(): void
    {
        $this->ensureAdminAccess();
        $this->requirePostAndCsrf('admin/suburbs');

        $id = trim((string)($_POST['id'] ?? ''));
        $suburb = $this->normalizeManagedSuburbName((string)($_POST['suburb'] ?? ''));
        $state = strtoupper(trim((string)($_POST['state'] ?? '')));
        $postcode = substr(preg_replace('/\D+/', '', (string)($_POST['postcode'] ?? '')) ?? '', 0, 4);

        $entry = [
            'id' => $id,
            'n' => $suburb,
            's' => $state,
            'p' => $postcode,
        ];

        $errors = $this->validateManagedSuburbEntry($entry, $id);
        if (!empty($errors)) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Please correct the suburb details before saving.']);
            SessionHelper::set('admin.suburb.errors.' . $id, $errors);
            SessionHelper::set('admin.suburb.old.' . $id, $entry);
            header('Location: index.php?route=admin/suburbs-edit' . ($id !== '' ? '&id=' . urlencode($id) : ''));
            exit;
        }

        $entries = $this->loadCustomSuburbEntries();
        $updated = false;
        $now = gmdate('Y-m-d H:i:s');
        foreach ($entries as &$existing) {
            if ($id === '' || (string)($existing['id'] ?? '') !== $id) {
                continue;
            }
            $existing['n'] = $suburb;
            $existing['s'] = $state;
            $existing['p'] = $postcode;
            $existing['updated_at'] = $now;
            $updated = true;
            break;
        }
        unset($existing);

        if (!$updated) {
            $id = $id !== '' ? $id : bin2hex(random_bytes(8));
            $entries[] = [
                'id' => $id,
                'n' => $suburb,
                's' => $state,
                'p' => $postcode,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->saveCustomSuburbEntries($entries);
        $this->rebuildManagedSuburbDataset();
        $this->audit($updated ? 'UPDATE' : 'CREATE', 'CustomSuburb', $id, ['suburb' => $suburb, 'state' => $state, 'postcode' => $postcode]);

        SessionHelper::set('flash.message', ['type' => 'success', 'text' => 'Custom suburb saved.']);
        header('Location: index.php?route=admin/suburbs');
        exit;
    }

    public function suburbDelete(): void
    {
        $this->ensureAdminAccess();
        $this->requirePostAndCsrf('admin/suburbs');

        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Missing suburb id.']);
            header('Location: index.php?route=admin/suburbs');
            exit;
        }

        $entries = array_values(array_filter(
            $this->loadCustomSuburbEntries(),
            static fn(array $entry): bool => (string)($entry['id'] ?? '') !== $id
        ));
        $this->saveCustomSuburbEntries($entries);
        $this->rebuildManagedSuburbDataset();
        $this->audit('DELETE', 'CustomSuburb', $id);

        SessionHelper::set('flash.message', ['type' => 'success', 'text' => 'Custom suburb removed.']);
        header('Location: index.php?route=admin/suburbs');
        exit;
    }

    public function defaultAddressSave(): void
    {
        $this->ensureAdminAccess();
        $this->requirePostAndCsrf('admin/default-addresses');

        $userId = (int)(SessionHelper::get('auth.user_id') ?? 0);
        $id = (int)($_POST['default_address_id'] ?? 0);
        $employeeId = trim((string)($_POST['employee_id'] ?? ''));
        $address1 = trim((string)($_POST['address1'] ?? ''));
        $address2 = trim((string)($_POST['address2'] ?? ''));
        $address3 = trim((string)($_POST['address3'] ?? ''));
        $suburb = trim((string)($_POST['suburb'] ?? ''));
        $state = trim((string)($_POST['state'] ?? ''));
        $postcode = trim((string)($_POST['postcode'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $mobile = trim((string)($_POST['mobile'] ?? ''));
        $sourceApplicationId = (int)($_POST['source_application_id'] ?? 0);

        $errors = $this->validateDefaultAddressInput([
            'employee_id' => $employeeId,
            'address1' => $address1,
            'address2' => $address2,
            'address3' => $address3,
            'suburb' => $suburb,
            'state' => $state,
            'postcode' => $postcode,
            'phone' => $phone,
            'mobile' => $mobile,
        ]);

        if (!empty($errors)) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Please correct the address fields before saving.']);
            SessionHelper::set('admin.default_address.errors.' . $id, $errors);
            SessionHelper::set('admin.default_address.old.' . $id, [
                'DefaultAddressID' => $id,
                'EmployeeID' => $employeeId,
                'Address1' => $address1,
                'Address2' => $address2,
                'Address3' => $address3,
                'Suburb' => $suburb,
                'State' => $state,
                'PostCode' => $postcode,
                'Phone' => $phone,
                'Mobile' => $mobile,
                'SourceApplicationID' => $sourceApplicationId > 0 ? $sourceApplicationId : '',
            ]);
            header('Location: index.php?route=admin/default-addresses-edit' . ($id > 0 ? '&id=' . urlencode((string)$id) : ''));
            exit;
        }

        if (!$this->hasActiveCardsForEmployee($employeeId)) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Address changes cannot be saved because this employee has no active cards.']);
            SessionHelper::set('admin.default_address.old.' . $id, [
                'DefaultAddressID' => $id,
                'EmployeeID' => $employeeId,
                'Address1' => $address1,
                'Address2' => $address2,
                'Address3' => $address3,
                'Suburb' => $suburb,
                'State' => $state,
                'PostCode' => $postcode,
                'Phone' => $phone,
                'Mobile' => $mobile,
                'SourceApplicationID' => $sourceApplicationId > 0 ? $sourceApplicationId : '',
            ]);
            header('Location: index.php?route=admin/default-addresses-edit' . ($id > 0 ? '&id=' . urlencode((string)$id) : ''));
            exit;
        }

        if ($this->hasPendingAddressChangeRequest($employeeId)) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'A submitted address change already exists for this employee. You cannot add or update the portal default address until that request is completed.']);
            SessionHelper::set('admin.default_address.old.' . $id, [
                'DefaultAddressID' => $id,
                'EmployeeID' => $employeeId,
                'Address1' => $address1,
                'Address2' => $address2,
                'Address3' => $address3,
                'Suburb' => $suburb,
                'State' => $state,
                'PostCode' => $postcode,
                'Phone' => $phone,
                'Mobile' => $mobile,
                'SourceApplicationID' => $sourceApplicationId > 0 ? $sourceApplicationId : '',
            ]);
            header('Location: index.php?route=admin/default-addresses-edit' . ($id > 0 ? '&id=' . urlencode((string)$id) : ''));
            exit;
        }

        if ($id > 0) {
            $stmt = $this->db->prepare("
                UPDATE dbo.tblPortalDefaultAddresses
                SET EmployeeID = :eid,
                    Address1 = :a1,
                    Address2 = :a2,
                    Address3 = :a3,
                    Suburb = :suburb,
                    State = :state,
                    PostCode = :postcode,
                    SourceApplicationID = :app_id,
                    ConfirmedAt = SYSUTCDATETIME(),
                    UpdatedAt = SYSUTCDATETIME(),
                    UpdatedBy = :updated_by
                WHERE DefaultAddressID = :id
            ");
            $stmt->execute([
                'eid' => $employeeId,
                'a1' => $address1,
                'a2' => $address2 !== '' ? $address2 : null,
                'a3' => $address3 !== '' ? $address3 : null,
                'suburb' => $suburb,
                'state' => $state,
                'postcode' => $postcode,
                'app_id' => $sourceApplicationId > 0 ? $sourceApplicationId : null,
                'updated_by' => $userId > 0 ? $userId : null,
                'id' => $id,
            ]);
            $this->audit('UPDATE', 'PortalDefaultAddress', (string)$id, ['employee_id' => $employeeId]);
        } else {
            $updateExisting = $this->db->prepare("
                UPDATE dbo.tblPortalDefaultAddresses
                SET Address1 = :a1,
                    Address2 = :a2,
                    Address3 = :a3,
                    Suburb = :suburb,
                    State = :state,
                    PostCode = :postcode,
                    SourceApplicationID = :app_id,
                    ConfirmedAt = SYSUTCDATETIME(),
                    UpdatedAt = SYSUTCDATETIME(),
                    UpdatedBy = :updated_by
                WHERE EmployeeID = :eid
            ");
            $updateExisting->execute([
                'eid' => $employeeId,
                'a1' => $address1,
                'a2' => $address2 !== '' ? $address2 : null,
                'a3' => $address3 !== '' ? $address3 : null,
                'suburb' => $suburb,
                'state' => $state,
                'postcode' => $postcode,
                'app_id' => $sourceApplicationId > 0 ? $sourceApplicationId : null,
                'updated_by' => $userId > 0 ? $userId : null,
            ]);

            if ($updateExisting->rowCount() > 0) {
                $lookup = $this->db->prepare("
                    SELECT DefaultAddressID
                    FROM dbo.tblPortalDefaultAddresses
                    WHERE EmployeeID = :eid
                ");
                $lookup->execute(['eid' => $employeeId]);
                $existingId = (string)($lookup->fetchColumn() ?? '');
                $this->audit('UPDATE', 'PortalDefaultAddress', $existingId !== '' ? $existingId : null, ['employee_id' => $employeeId]);
            } else {
                $stmt = $this->db->prepare("
                    INSERT INTO dbo.tblPortalDefaultAddresses
                        (EmployeeID, Address1, Address2, Address3, Suburb, State, PostCode, SourceApplicationID, CreatedBy, UpdatedBy)
                    VALUES
                        (:eid, :a1, :a2, :a3, :suburb, :state, :postcode, :app_id, :created_by, :updated_by)
                ");
                $stmt->execute([
                    'eid' => $employeeId,
                    'a1' => $address1,
                    'a2' => $address2 !== '' ? $address2 : null,
                    'a3' => $address3 !== '' ? $address3 : null,
                    'suburb' => $suburb,
                    'state' => $state,
                    'postcode' => $postcode,
                    'app_id' => $sourceApplicationId > 0 ? $sourceApplicationId : null,
                    'created_by' => $userId > 0 ? $userId : null,
                    'updated_by' => $userId > 0 ? $userId : null,
                ]);
                $newId = (int)$this->db->lastInsertId();
                $this->audit('CREATE', 'PortalDefaultAddress', (string)$newId, ['employee_id' => $employeeId]);
            }
        }

        $this->createAddressChangeRequestsForEmployee($employeeId, [
            'address1' => $address1,
            'address2' => $address2,
            'address3' => $address3,
            'suburb' => $suburb,
            'state' => $state,
            'postcode' => $postcode,
            'phone' => $phone,
            'mobile' => $mobile,
            'apply_all_cards' => 1,
        ], $userId);

        SessionHelper::set('flash.message', ['type' => 'success', 'text' => 'Portal default address saved.']);
        header('Location: index.php?route=admin/default-addresses');
        exit;
    }

    public function defaultAddressDelete(): void
    {
        $this->ensureAdminAccess();
        $this->requirePostAndCsrf('admin/default-addresses');

        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Missing default address id.']);
            header('Location: index.php?route=admin/default-addresses');
            exit;
        }

        $lookup = $this->db->prepare("
            SELECT EmployeeID
            FROM dbo.tblPortalDefaultAddresses
            WHERE DefaultAddressID = :id
        ");
        $lookup->execute(['id' => $id]);
        $employeeId = trim((string)($lookup->fetchColumn() ?? ''));

        $stmt = $this->db->prepare("
            DELETE FROM dbo.tblPortalDefaultAddresses
            WHERE DefaultAddressID = :id
        ");
        $stmt->execute(['id' => $id]);

        if ($stmt->rowCount() > 0) {
            $this->audit('DELETE', 'PortalDefaultAddress', (string)$id, ['employee_id' => $employeeId]);
            SessionHelper::set('flash.message', ['type' => 'success', 'text' => 'Portal default address deleted.']);
        } else {
            SessionHelper::set('flash.message', ['type' => 'warning', 'text' => 'Default address was not found.']);
        }

        header('Location: index.php?route=admin/default-addresses');
        exit;
    }

    private function ensureAdminAccess(): void
    {
        $allowed = Rbac::canAny(['ADMIN_ALL', 'SYSADMIN']) || Rbac::hasRole('admin');
        if ($allowed) {
            return;
        }
        SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Access denied.']);
        header('Location: index.php?route=home/index');
        exit;
    }

    private function requirePostAndCsrf(string $fallbackRoute): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            http_response_code(405);
            echo __t('method_not_allowed');
            exit;
        }
        if (!csrf_check($_POST['_csrf'] ?? '')) {
            SessionHelper::set('flash.message', ['type' => 'danger', 'text' => 'Security check failed. Please try again.']);
            header('Location: index.php?route=' . $fallbackRoute);
            exit;
        }
    }

    private function loadApplicationTypes(): array
    {
        $stmt = $this->db->query("
            SELECT ApplicationTypeID, ApplicationTypeName
            FROM dbo.tblApplicationTypes
            WHERE IsActive = 1
            ORDER BY ApplicationTypeName
        ");
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private function normalizeDateTimeInput(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return null;
        }
        return date('Y-m-d H:i:s', $ts);
    }

    private function customSuburbFilePath(): string
    {
        return dirname(__DIR__, 2) . '/public/assets/data/au_suburbs_custom.json';
    }

    private function mergedSuburbFilePath(): string
    {
        return dirname(__DIR__, 2) . '/public/assets/data/au_suburbs.json';
    }

    private function sourceSuburbCsvPath(): string
    {
        return dirname(__DIR__, 2) . '/public/assets/data/au_suburbs_source.csv';
    }

    private function loadCustomSuburbEntries(): array
    {
        $path = $this->customSuburbFilePath();
        if (!is_file($path)) {
            return [];
        }
        $json = file_get_contents($path);
        if ($json === false || trim($json) === '') {
            return [];
        }
        $rows = json_decode($json, true);
        return is_array($rows) ? array_values(array_filter($rows, static fn($row): bool => is_array($row))) : [];
    }

    private function saveCustomSuburbEntries(array $entries): void
    {
        $path = $this->customSuburbFilePath();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents(
            $path,
            json_encode(array_values($entries), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    private function rebuildManagedSuburbDataset(): void
    {
        $rows = [];
        foreach ($this->loadBaseSuburbRows() as $row) {
            $rows[$row['n'] . '|' . $row['s'] . '|' . $row['p']] = $row;
        }
        foreach ($this->loadCustomSuburbEntries() as $entry) {
            $row = [
                'n' => $this->normalizeManagedSuburbName((string)($entry['n'] ?? '')),
                's' => strtoupper(trim((string)($entry['s'] ?? ''))),
                'p' => substr(preg_replace('/\D+/', '', (string)($entry['p'] ?? '')) ?? '', 0, 4),
            ];
            if ($row['n'] === '' || $row['s'] === '' || $row['p'] === '') {
                continue;
            }
            $rows[$row['n'] . '|' . $row['s'] . '|' . $row['p']] = $row;
        }
        ksort($rows, SORT_NATURAL);
        file_put_contents(
            $this->mergedSuburbFilePath(),
            json_encode(array_values($rows), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    private function loadBaseSuburbRows(): array
    {
        $path = $this->sourceSuburbCsvPath();
        if (!is_file($path)) {
            $merged = @file_get_contents($this->mergedSuburbFilePath());
            $rows = json_decode((string)$merged, true);
            return is_array($rows) ? $rows : [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        $header = fgetcsv($handle);
        if (!is_array($header)) {
            fclose($handle);
            return [];
        }

        $index = array_flip($header);
        $validStates = ['ACT', 'NSW', 'NT', 'QLD', 'SA', 'TAS', 'VIC', 'WA'];
        $rows = [];

        while (($data = fgetcsv($handle)) !== false) {
            $locality = trim((string)($data[$index['locality'] ?? -1] ?? ''));
            $state = strtoupper(trim((string)($data[$index['state'] ?? -1] ?? '')));
            $postcode = trim((string)($data[$index['postcode'] ?? -1] ?? ''));
            if ($locality === '' || $state === '' || $postcode === '' || !in_array($state, $validStates, true)) {
                continue;
            }
            $row = [
                'n' => $this->normalizeManagedSuburbName($locality),
                's' => $state,
                'p' => substr(preg_replace('/\D+/', '', $postcode) ?? '', 0, 4),
            ];
            if ($row['n'] === '' || $row['p'] === '') {
                continue;
            }
            $rows[$row['n'] . '|' . $row['s'] . '|' . $row['p']] = $row;
        }

        fclose($handle);
        return array_values($rows);
    }

    private function normalizeManagedSuburbName(string $value): string
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            return '';
        }
        return mb_substr($value, 0, 21);
    }

    private function validateManagedSuburbEntry(array $entry, string $ignoreId = ''): array
    {
        $errors = [];
        $suburb = (string)($entry['n'] ?? '');
        $state = (string)($entry['s'] ?? '');
        $postcode = (string)($entry['p'] ?? '');

        if ($suburb === '') {
            $errors['suburb'] = 'Suburb is required.';
        }
        if ($state === '' || !in_array($state, ['ACT', 'NSW', 'NT', 'QLD', 'SA', 'TAS', 'VIC', 'WA'], true)) {
            $errors['state'] = 'State must be one of ACT, NSW, NT, QLD, SA, TAS, VIC, WA.';
        }
        if ($postcode === '' || !preg_match('/^\d{4}$/', $postcode)) {
            $errors['postcode'] = 'Postcode must be 4 digits.';
        }

        $key = $suburb . '|' . $state . '|' . $postcode;
        foreach ($this->loadCustomSuburbEntries() as $existing) {
            if ((string)($existing['id'] ?? '') === $ignoreId) {
                continue;
            }
            $existingKey = $this->normalizeManagedSuburbName((string)($existing['n'] ?? '')) . '|'
                . strtoupper(trim((string)($existing['s'] ?? ''))) . '|'
                . substr(preg_replace('/\D+/', '', (string)($existing['p'] ?? '')) ?? '', 0, 4);
            if ($existingKey === $key) {
                $errors['suburb'] = 'That suburb/state/postcode combination already exists in custom suburbs.';
                return $errors;
            }
        }

        foreach ($this->loadBaseSuburbRows() as $row) {
            if (($row['n'] ?? '') . '|' . ($row['s'] ?? '') . '|' . ($row['p'] ?? '') === $key) {
                $errors['suburb'] = 'That suburb/state/postcode combination already exists in the base suburb dataset.';
                return $errors;
            }
        }

        return $errors;
    }

    private function validateDefaultAddressInput(array $input): array
    {
        $errors = [];

        $employeeId = trim((string)($input['employee_id'] ?? ''));
        $address1 = trim((string)($input['address1'] ?? ''));
        $address2 = trim((string)($input['address2'] ?? ''));
        $address3 = trim((string)($input['address3'] ?? ''));
        $suburb = trim((string)($input['suburb'] ?? ''));
        $state = trim((string)($input['state'] ?? ''));
        $postcode = trim((string)($input['postcode'] ?? ''));
        $phone = trim((string)($input['phone'] ?? ''));
        $mobile = trim((string)($input['mobile'] ?? ''));

        if ($employeeId === '') {
            $errors['employee_id'] = 'EmployeeID is required.';
        }
        if ($address1 === '') {
            $errors['address1'] = 'Address 1 is required.';
        } elseif (mb_strlen($address1) > 30) {
            $errors['address1'] = 'Address 1 must be 30 characters or less.';
        }
        if ($address2 !== '' && mb_strlen($address2) > 30) {
            $errors['address2'] = 'Address 2 must be 30 characters or less.';
        }
        if ($address3 !== '' && mb_strlen($address3) > 30) {
            $errors['address3'] = 'Address 3 must be 30 characters or less.';
        }
        if ($suburb === '') {
            $errors['suburb'] = 'Suburb is required.';
        } elseif (mb_strlen($suburb) > 22) {
            $errors['suburb'] = 'Suburb must be 22 characters or less.';
        }
        if ($state === '') {
            $errors['state'] = 'State is required.';
        }
        if ($postcode === '') {
            $errors['postcode'] = 'PostCode is required.';
        } elseif (!ctype_digit($postcode)) {
            $errors['postcode'] = 'PostCode must contain only numbers.';
        } elseif (mb_strlen($postcode) > 4) {
            $errors['postcode'] = 'PostCode must be 4 digits or less.';
        }
        if ($phone !== '' && mb_strlen($phone) > 30) {
            $errors['phone'] = 'Phone must be 30 characters or less.';
        }
        if ($mobile !== '' && mb_strlen($mobile) > 30) {
            $errors['mobile'] = 'Mobile must be 30 characters or less.';
        }

        return $errors;
    }

    private function loadEmployeeOptions(string $term = '', int $limit = 500): array
    {
        global $capsConn;

        if (!($capsConn instanceof \PDO)) {
            return [];
        }

        try {
            $limit = max(1, min($limit, 500));
            $sql = "
                SELECT TOP {$limit} EmployeeID
                FROM (
                    SELECT DISTINCT NULLIF(LTRIM(RTRIM(EmployeeID)), '') AS EmployeeID
                    FROM dbo.tblCAPSCDMCPortal
                ) src
                WHERE EmployeeID IS NOT NULL
            ";
            $params = [];
            if ($term !== '') {
                $sql .= " AND EmployeeID LIKE :term";
                $params['term'] = $term . '%';
            }
            $sql .= " ORDER BY EmployeeID";

            $stmt = $capsConn->prepare($sql);
            $stmt->execute($params);
            return array_values(array_filter(array_map(
                static fn($v): string => trim((string)$v),
                $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: []
            ), static fn(string $v): bool => $v !== ''));
        } catch (\Throwable $e) {
            error_log('[EligibilityAdminController::loadEmployeeOptions] ' . $e->getMessage());
            return [];
        }
    }

    private function loadEmployeeSearchResults(string $term = '', int $limit = 25): array
    {
        global $capsConn;

        if (!($capsConn instanceof \PDO)) {
            return [];
        }

        try {
            $limit = max(1, min($limit, 500));
            $sql = "
                SELECT TOP {$limit}
                    src.EmployeeID,
                    ISNULL(src.Title, '') AS Title,
                    ISNULL(src.FirstName, '') AS FirstName,
                    ISNULL(src.LastName, '') AS LastName,
                    ISNULL(src.Phone, '') AS Phone,
                    ISNULL(src.Mobile, '') AS Mobile,
                    ISNULL(src.Address1, '') AS Address1,
                    ISNULL(src.Address2, '') AS Address2,
                    ISNULL(src.Address3, '') AS Address3,
                    ISNULL(src.Suburb, '') AS Suburb,
                    ISNULL(src.State, '') AS State,
                    ISNULL(src.PostCode, '') AS PostCode
                FROM (
                    SELECT
                        NULLIF(LTRIM(RTRIM(EmployeeID)), '') AS EmployeeID,
                        MAX(NULLIF(LTRIM(RTRIM(Title)), '')) AS Title,
                        MAX(NULLIF(LTRIM(RTRIM(Firstname)), '')) AS FirstName,
                        MAX(NULLIF(LTRIM(RTRIM(Surname)), '')) AS LastName,
                        MAX(NULLIF(LTRIM(RTRIM(TelephoneNumber)), '')) AS Phone,
                        MAX(NULLIF(LTRIM(RTRIM(MobileNumber)), '')) AS Mobile,
                        MAX(NULLIF(LTRIM(RTRIM(OutAddr1)), '')) AS Address1,
                        MAX(NULLIF(LTRIM(RTRIM(OutAddr2)), '')) AS Address2,
                        MAX(NULLIF(LTRIM(RTRIM(OutAddr3)), '')) AS Address3,
                        MAX(NULLIF(LTRIM(RTRIM(OutSuburb)), '')) AS Suburb,
                        MAX(NULLIF(LTRIM(RTRIM(OutState)), '')) AS State,
                        MAX(NULLIF(LTRIM(RTRIM(OutPostCode)), '')) AS PostCode
                    FROM dbo.tblCAPSCDMCPortal
                    GROUP BY NULLIF(LTRIM(RTRIM(EmployeeID)), '')
                ) src
                WHERE src.EmployeeID IS NOT NULL
            ";
            $params = [];
            if ($term !== '') {
                $sql .= "
                    AND (
                        src.EmployeeID LIKE :employee_contains
                        OR ISNULL(src.FirstName, '') LIKE :first_name_contains
                        OR ISNULL(src.LastName, '') LIKE :last_name_contains
                    )
                ";
                $contains = '%' . $term . '%';
                $params['employee_contains'] = $contains;
                $params['first_name_contains'] = $contains;
                $params['last_name_contains'] = $contains;
            }
            $sql .= " ORDER BY src.EmployeeID";

            $stmt = $capsConn->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
            $employeeIds = array_values(array_filter(array_map(
                static fn(array $row): string => trim((string)($row['EmployeeID'] ?? '')),
                $rows
            ), static fn(string $v): bool => $v !== ''));
            $defaultMap = $this->loadPortalDefaultAddressMap($employeeIds);

            return array_map(function (array $row) use ($defaultMap): array {
                $employeeId = trim((string)($row['EmployeeID'] ?? ''));
                $title = trim((string)($row['Title'] ?? ''));
                $firstName = trim((string)($row['FirstName'] ?? ''));
                $lastName = trim((string)($row['LastName'] ?? ''));
                $phone = trim((string)($row['Phone'] ?? ''));
                $mobile = trim((string)($row['Mobile'] ?? ''));
                $capsAddress1 = trim((string)($row['Address1'] ?? ''));
                $capsAddress2 = trim((string)($row['Address2'] ?? ''));
                $capsAddress3 = trim((string)($row['Address3'] ?? ''));
                $capsSuburb = trim((string)($row['Suburb'] ?? ''));
                $capsState = trim((string)($row['State'] ?? ''));
                $capsPostcode = trim((string)($row['PostCode'] ?? ''));
                $name = trim(implode(' ', array_filter([$title, $firstName, $lastName], static fn(string $v): bool => $v !== '')));
                $defaultRow = $defaultMap[$employeeId] ?? null;

                return [
                    'employee_id' => $employeeId,
                    'title' => $title,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'phone' => $phone,
                    'mobile' => $mobile,
                    'label' => $name !== '' ? ($employeeId . ' - ' . $name) : $employeeId,
                    'default_address' => is_array($defaultRow) ? [
                        'default_address_id' => (int)($defaultRow['DefaultAddressID'] ?? 0),
                        'address1' => trim((string)($defaultRow['Address1'] ?? '')),
                        'address2' => trim((string)($defaultRow['Address2'] ?? '')),
                        'address3' => trim((string)($defaultRow['Address3'] ?? '')),
                        'suburb' => trim((string)($defaultRow['Suburb'] ?? '')),
                        'state' => trim((string)($defaultRow['State'] ?? '')),
                        'postcode' => trim((string)($defaultRow['PostCode'] ?? '')),
                        'source_application_id' => (int)($defaultRow['SourceApplicationID'] ?? 0),
                    ] : null,
                    'caps_address' => [
                        'address1' => $capsAddress1,
                        'address2' => $capsAddress2,
                        'address3' => $capsAddress3,
                        'suburb' => $capsSuburb,
                        'state' => $capsState,
                        'postcode' => $capsPostcode,
                    ],
                ];
            }, array_filter($rows, static fn(array $row): bool => trim((string)($row['EmployeeID'] ?? '')) !== ''));
        } catch (\Throwable $e) {
            error_log('[EligibilityAdminController::loadEmployeeSearchResults] ' . $e->getMessage());
            return [];
        }
    }

    private function loadEmployeeSummary(string $employeeId): ?array
    {
        $employeeId = trim($employeeId);
        if ($employeeId === '') {
            return null;
        }
        global $capsConn;

        if (!($capsConn instanceof \PDO)) {
            return null;
        }

        try {
            $stmt = $capsConn->prepare("
                SELECT TOP 1
                    NULLIF(LTRIM(RTRIM(EmployeeID)), '') AS EmployeeID,
                    MAX(NULLIF(LTRIM(RTRIM(Title)), '')) AS Title,
                    MAX(NULLIF(LTRIM(RTRIM(Firstname)), '')) AS FirstName,
                    MAX(NULLIF(LTRIM(RTRIM(Surname)), '')) AS LastName,
                    MAX(NULLIF(LTRIM(RTRIM(TelephoneNumber)), '')) AS Phone,
                    MAX(NULLIF(LTRIM(RTRIM(MobileNumber)), '')) AS Mobile,
                    MAX(NULLIF(LTRIM(RTRIM(OutAddr1)), '')) AS Address1,
                    MAX(NULLIF(LTRIM(RTRIM(OutAddr2)), '')) AS Address2,
                    MAX(NULLIF(LTRIM(RTRIM(OutAddr3)), '')) AS Address3,
                    MAX(NULLIF(LTRIM(RTRIM(OutSuburb)), '')) AS Suburb,
                    MAX(NULLIF(LTRIM(RTRIM(OutState)), '')) AS State,
                    MAX(NULLIF(LTRIM(RTRIM(OutPostCode)), '')) AS PostCode
                FROM dbo.tblCAPSCDMCPortal
                WHERE NULLIF(LTRIM(RTRIM(EmployeeID)), '') = :eid
                GROUP BY NULLIF(LTRIM(RTRIM(EmployeeID)), '')
            ");
            $stmt->execute(['eid' => $employeeId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            if (!$row) {
                return null;
            }

            $defaultMap = $this->loadPortalDefaultAddressMap([$employeeId]);
            $defaultRow = $defaultMap[$employeeId] ?? null;

            $title = trim((string)($row['Title'] ?? ''));
            $firstName = trim((string)($row['FirstName'] ?? ''));
            $lastName = trim((string)($row['LastName'] ?? ''));
            $name = trim(implode(' ', array_filter([$title, $firstName, $lastName], static fn(string $v): bool => $v !== '')));

            return [
                'employee_id' => $employeeId,
                'title' => $title,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone' => trim((string)($row['Phone'] ?? '')),
                'mobile' => trim((string)($row['Mobile'] ?? '')),
                'label' => $name !== '' ? ($employeeId . ' - ' . $name) : $employeeId,
                'default_address' => is_array($defaultRow) ? [
                    'default_address_id' => (int)($defaultRow['DefaultAddressID'] ?? 0),
                    'address1' => trim((string)($defaultRow['Address1'] ?? '')),
                    'address2' => trim((string)($defaultRow['Address2'] ?? '')),
                    'address3' => trim((string)($defaultRow['Address3'] ?? '')),
                    'suburb' => trim((string)($defaultRow['Suburb'] ?? '')),
                    'state' => trim((string)($defaultRow['State'] ?? '')),
                    'postcode' => trim((string)($defaultRow['PostCode'] ?? '')),
                    'source_application_id' => (int)($defaultRow['SourceApplicationID'] ?? 0),
                ] : null,
                'caps_address' => [
                    'address1' => trim((string)($row['Address1'] ?? '')),
                    'address2' => trim((string)($row['Address2'] ?? '')),
                    'address3' => trim((string)($row['Address3'] ?? '')),
                    'suburb' => trim((string)($row['Suburb'] ?? '')),
                    'state' => trim((string)($row['State'] ?? '')),
                    'postcode' => trim((string)($row['PostCode'] ?? '')),
                ],
            ];
        } catch (\Throwable $e) {
            error_log('[EligibilityAdminController::loadEmployeeSummary] ' . $e->getMessage());
            return null;
        }
    }

    private function loadPortalDefaultAddressMap(array $employeeIds): array
    {
        if (!$employeeIds || !($this->db instanceof \PDO)) {
            return [];
        }

        $employeeIds = array_values(array_unique(array_filter(array_map(
            static fn($v): string => trim((string)$v),
            $employeeIds
        ), static fn(string $v): bool => $v !== '')));

        if (!$employeeIds) {
            return [];
        }

        try {
            $placeholders = [];
            $params = [];
            foreach ($employeeIds as $idx => $employeeId) {
                $key = 'eid' . $idx;
                $placeholders[] = ':' . $key;
                $params[$key] = $employeeId;
            }

            $sql = "
                SELECT
                    DefaultAddressID,
                    EmployeeID,
                    Address1,
                    Address2,
                    Address3,
                    Suburb,
                    State,
                    PostCode,
                    SourceApplicationID
                FROM dbo.tblPortalDefaultAddresses
                WHERE EmployeeID IN (" . implode(', ', $placeholders) . ")
            ";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

            $map = [];
            foreach ($rows as $row) {
                $employeeId = trim((string)($row['EmployeeID'] ?? ''));
                if ($employeeId === '') {
                    continue;
                }
                $map[$employeeId] = $row;
            }

            return $map;
        } catch (\Throwable $e) {
            error_log('[EligibilityAdminController::loadPortalDefaultAddressMap] ' . $e->getMessage());
            return [];
        }
    }

    private function hasActiveCardsForEmployee(string $employeeId): bool
    {
        $employeeId = trim($employeeId);
        if ($employeeId === '' || !($this->db instanceof \PDO)) {
            return false;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT TOP 1 1
                FROM dbo.tblPORTALCards
                WHERE EmployeeID = :eid
                  AND ISNULL(Status, '') = ''
            ");
            $stmt->execute(['eid' => $employeeId]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[EligibilityAdminController::hasActiveCardsForEmployee] ' . $e->getMessage());
            return false;
        }
    }

    private function hasPendingAddressChangeRequest(string $employeeId): bool
    {
        $employeeId = trim($employeeId);
        if ($employeeId === '' || !($this->db instanceof \PDO)) {
            return false;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT TOP 1 1
                FROM dbo.tblCardChangeRequests
                WHERE EmployeeID = :eid
                  AND RequestType IN ('CONTACT_CHANGE', 'ADDRESS_CHANGE')
                  AND Status = 'Addr Update Subm'
            ");
            $stmt->execute(['eid' => $employeeId]);
            return (bool)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[EligibilityAdminController::hasPendingAddressChangeRequest] ' . $e->getMessage());
            return false;
        }
    }

    private function hydrateDefaultAddressContactFields(array $row): array
    {
        if (!empty($row['Phone']) || !empty($row['Mobile'])) {
            return $row;
        }

        $employeeId = trim((string)($row['EmployeeID'] ?? ''));
        if ($employeeId === '' || !($this->db instanceof \PDO)) {
            return $row;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT TOP 1
                    ISNULL(NULLIF(LTRIM(RTRIM(HomePhone)),''), NULLIF(LTRIM(RTRIM(WorkPhone)),'')) AS Phone,
                    NULLIF(LTRIM(RTRIM(MobilePhone)),'') AS Mobile
                FROM dbo.tblPORTALCards
                WHERE EmployeeID = :eid
                  AND ISNULL(Status,'') = ''
                ORDER BY CardID DESC
            ");
            $stmt->execute(['eid' => $employeeId]);
            $cardRow = $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
            if (!$cardRow) {
                return $row;
            }

            $row['Phone'] = trim((string)($cardRow['Phone'] ?? ''));
            $row['Mobile'] = trim((string)($cardRow['Mobile'] ?? ''));
            if ($row['Phone'] === '' || $row['Mobile'] === '') {
                $capsSummary = $this->loadEmployeeSummary($employeeId);
                if ($row['Phone'] === '' && !empty($capsSummary['phone'])) {
                    $row['Phone'] = trim((string)$capsSummary['phone']);
                }
                if ($row['Mobile'] === '' && !empty($capsSummary['mobile'])) {
                    $row['Mobile'] = trim((string)$capsSummary['mobile']);
                }
            }
            return $row;
        } catch (\Throwable $e) {
            error_log('[EligibilityAdminController::hydrateDefaultAddressContactFields] ' . $e->getMessage());
        }

        $capsSummary = $this->loadEmployeeSummary($employeeId);
        if ($capsSummary) {
            if (trim((string)($row['Phone'] ?? '')) === '' && !empty($capsSummary['phone'])) {
                $row['Phone'] = trim((string)$capsSummary['phone']);
            }
            if (trim((string)($row['Mobile'] ?? '')) === '' && !empty($capsSummary['mobile'])) {
                $row['Mobile'] = trim((string)$capsSummary['mobile']);
            }
        }

        return $row;
    }

    private function createAddressChangeRequestsForEmployee(string $employeeId, array $payload, int $userId): void
    {
        $employeeId = trim($employeeId);
        if ($employeeId === '' || !($this->db instanceof \PDO)) {
            return;
        }

        try {
            $stmt = $this->db->prepare("
                SELECT CardID
                FROM dbo.tblPORTALCards
                WHERE EmployeeID = :eid
                  AND ISNULL(Status,'') = ''
            ");
            $stmt->execute(['eid' => $employeeId]);
            $cardIds = array_map(
                static fn(array $r): int => (int)($r['CardID'] ?? 0),
                $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: []
            );

            if (!$cardIds) {
                return;
            }

            $existsStmt = $this->db->prepare("
                SELECT TOP 1 1
                FROM dbo.tblCardChangeRequests
                WHERE CardID = :card_id
                  AND RequestType IN ('CONTACT_CHANGE', 'ADDRESS_CHANGE')
                  AND Status = 'Addr Update Subm'
            ");
            $insertStmt = $this->db->prepare("
                INSERT INTO dbo.tblCardChangeRequests
                    (CardID, EmployeeID, RequestType, Status, PayloadJson, SubmittedAt, UpdatedAt, UpdatedBy)
                VALUES
                    (:card_id, :eid, 'CONTACT_CHANGE', 'Addr Update Subm', :payload, SYSUTCDATETIME(), SYSUTCDATETIME(), :updated_by)
            ");

            $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            foreach ($cardIds as $cardId) {
                if ($cardId <= 0) {
                    continue;
                }

                $existsStmt->execute(['card_id' => $cardId]);
                if ($existsStmt->fetchColumn()) {
                    continue;
                }

                $insertStmt->execute([
                    'card_id' => $cardId,
                    'eid' => $employeeId,
                    'payload' => $payloadJson ?: null,
                    'updated_by' => $userId > 0 ? $userId : null,
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[EligibilityAdminController::createAddressChangeRequestsForEmployee] ' . $e->getMessage());
        }
    }

    private function audit(string $action, string $entity, ?string $entityKey = null, array $details = []): void
    {
        try {
            $audit = new AuditModel($this->db);
            $audit->insert([
                'UserID'       => SessionHelper::get('auth.user_id'),
                'Username'     => SessionHelper::get('auth.username', 'guest'),
                'Action'       => $action,
                'Entity'       => $entity,
                'EntityKey'    => $entityKey,
                'IPAddress'    => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'Details'      => $details,
                'FiscalYearID' => SessionHelper::get('FiscalYearID'),
                'VersionID'    => SessionHelper::get('VersionID'),
            ]);
        } catch (\Throwable $e) {
            error_log('[EligibilityAdminController::audit] ' . $e->getMessage());
        }
    }
}
