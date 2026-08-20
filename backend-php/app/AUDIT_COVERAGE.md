# Audit Coverage Matrix

Last updated: 2026-02-25

## Audit Store and UI

- Audit table: `dbo.tblAuditLog`
- Model: `app/Models/AuditModel.php`
- Viewer route: `audit/list`
- Viewer ACL: `AUDIT_VIEW` or `SYSADMIN`

## Coverage by Area

| Area | Controller | Current Coverage | Status |
|---|---|---|---|
| Authentication | `AuthController` | `LOGIN`, `LOGOUT`, `ACTIVATE`, denied login/activation attempts (`DENIED`) | Covered |
| Applications | `ApplicationsController` | `CREATE`/`RESUME` on start, `SAVE_DRAFT`/`SUBMIT` on save, `DELETE`, denied submit validation (`DENIED`) | Covered |
| Cards - Limit Change | `CardsController` | `SAVE_DRAFT`/`SUBMIT` limit change, `DELETE` limit change app, approval decision (`APPROVE`/`REJECT`/`FORWARD`) | Covered |
| Cards - Change Requests | `CardsController` | `SUBMIT` for address change and card cancellation requests | Covered |
| Users | `UsersController` | Create/update/unlock/save roles actions | Covered |
| Roles | `RolesController` | Create/update role actions | Covered |
| Workflow Tasks | `WorkflowController` | Create/update/delete task actions | Covered |
| System Settings | `SystemSettingsController` | Update and denied updates (`DENIED`) | Covered |
| Data Object Access | `DataObjectCodeAccessController` | `GRANT_ACCESS` and `REVOKE_ACCESS` | Covered |

## Known Gaps / Follow-ups

- Menu item `dataobjectcodes/access_audit` exists, but no matching route/action implementation.
- `AuditListView` exposes sortable headers, but backend currently always orders by `AuditID DESC` (sort params are not applied by `AuditModel::listLogs`).
- Consider adding standard action naming conventions globally (for example: `SUBMIT`, `APPROVE`, `DENIED`, `DELETE`) and centralizing enums.

## Notes

- Audit insert failures are intentionally non-blocking in business flows (best-effort logging).
- File logging (`shared/logger.php`) still exists for operational diagnostics and is complementary to `tblAuditLog`.
