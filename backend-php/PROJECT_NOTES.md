# Project Handoff Notes (CCPortal)

Date: 2026-02-07

## What’s Implemented
- **PortalCardsList** shows active cards + actions (Edit Address, Request Limit Change, Cancel).
- **Card Change Requests** stored in `tblCardChangeRequests` for:
  - Address Change (`RequestType = ADDRESS_CHANGE`, `Status = Addr Update Subm` / `Addr Update Done`)
  - Cancel Card (`RequestType = CANCEL_CARD`, `Status = Cancel Subm`)
- **Edit Address** screen:
  - Address + contact validations copied from Application screen.
  - Submit confirmation modal + “apply to all cards” checkbox.
  - Progress list driven by latest `tblCardChangeRequests` status.
  - Change history list included.
- **Cancel Card** handled via modal (PortalCardsList):
  - Reason dropdown + Other reason.
  - Cancellation date (defaults to today, future allowed).
  - Card number masked, expiry formatted `YYYY/MM`.
  - Submit stored in `tblCardChangeRequests` with payload.
- **Limit Change Request** now stored in **`tblApplications`** (workflow tracked):
  - `cards/request-limit-change` creates/loads Draft/InProgress application by `ApplicationTypeKey`.
  - Payload saved to `tblApplicationSteps` (StepKey=`application`).
  - Progress list reads application status.
  - Checklist items: card_details, limits_complete, justification_complete, application_submitted.
  - Form has Justification section (reason + other + attachments).
  - Inline validation for Credit Limit New, Approver, Reason, Other Reason (when Reason=Other).
  - Transaction limit fields hidden for DTC card type.
- **Limit Change Approval** screen exists (read-only, modal reject reason).
- **Consistent error screen**: global error handler now used (raw PHP output removed unless `APP_DEBUG=true`).
- **Address-change confirmation email** sent after submit (MailService).

## Key Files
- `app/Controllers/CardsController.php`
  - `changeAddressSave()` saves address changes + sends email.
  - `limitChangeSave()` saves limit change app data to `tblApplications`.
  - `limitChangeApprove()` renders approval screen.
  - Helpers: `ensureLimitChangeApplication()`, `buildLimitChangeProgress()`, `buildLimitChangeRuntimeSteps()`, `upsertRuntimeStep()`.
- `app/Views/cards/EditAddress.php`
- `app/Views/cards/LimitChange.php`
- `app/Views/cards/LimitChangeApprove.php`
- `app/Views/portalcards/PortalCardsList.php`
- `public/index.php` (error handler integration)

## Status Values
- **Card Change Requests** (`tblCardChangeRequests.Status`)
  - Address submitted: `Addr Update Subm`
  - Address completed: `Addr Update Done`
  - Cancel submitted: `Cancel Subm`

## Known Gotchas / Constraints
- `tblCardChangeRequests.Status` has a short length; long status strings truncate and throw errors.
- ODBC driver does not like repeated named parameters → use **positional placeholders** in prepared statements.
- Limit Change is tied to **active cards only** (`Status = ''` or `Active = 'Y'`).

## Pending / Next Steps
1. **Approval workflow wiring** for limit change:
   - Load payload + application in approval screen.
   - Approve/Reject/Forward actions with status updates + audit.
2. **Approver Email** on submit with approval link.
3. **Requestor Email / Phone** data source on approval summary (currently placeholders).
4. **Attach files** storage (currently UI only on Limit Change).
5. **Inline validation** for other fields as required.

## Quick Test Checklist
1. Submit Address Change → creates row in `tblCardChangeRequests`, sends email.
2. Update status to `Addr Update Done` → new address request allowed.
3. Submit Limit Change → creates/updates `tblApplications` + steps.
4. Limit Change checklist updates after submit.

