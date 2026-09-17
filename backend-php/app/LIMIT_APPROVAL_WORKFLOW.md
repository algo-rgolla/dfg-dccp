# Credit Limit Increase — Approval Workflow

Reference document explaining how a card credit-limit-change request moves from submission to a final
approve/reject decision. All logic lives in `CardsController.php` (not `WorkflowController`/`ApplicationsController`,
which handle new-card DPC applications) plus two admin-configurable tables that drive approver routing. Every
line reference below was read directly from the file at the time this document was written — re-check line numbers
after any edits to `CardsController.php`, since it is an 8,500+ line file and line numbers will drift.

## Routes

Defined in `backend-php/config/routes.php:184-194`:

| Route | Controller action |
|---|---|
| `cards/request-limit-change` | `CardsController::requestLimitChange()` |
| `cards/request-limit-change-agree` | `CardsController::requestLimitChangeAgree()` |
| `cards/on-behalf-limit-change-start` | `CardsController::onBehalfLimitChangeStart()` |
| `cards/limit-change-save` | `CardsController::limitChangeSave()` |
| `cards/limit-change-delete` | `CardsController::limitChangeDelete()` |
| `cards/limit-change-approve` | `CardsController::limitChangeApprove()` (view) |
| `cards/limit-change-approve-save` | `CardsController::limitChangeApproveSave()` (POST decision) |
| `cards/limit-change-approver-email-check` | `CardsController::limitChangeApproverEmailCheck()` |
| `cards/limit-change-scope-save` | `CardsController::limitChangeScopeSave()` |
| `cards/my-limit-change-approvals` | `CardsController::myLimitChangeApprovals()` |
| `cards/limit-change-approvals` | `CardsController::limitChangeApprovals()` |

**ACL note**: `CardsController::$acl` (`CardsController.php:20-23`) declares only
`'*' => ['auth' => true]` (plus one unrelated `ADMIN_ALL`/`SYSADMIN` rule for `processDueCancellations`). None of the
limit-change actions have a permission-code (`permsAny`/`permsAll`) requirement — anyone logged in can hit these
routes. All real authorization (who may submit for whom, who may approve) is enforced in application code, not the
ACL layer.

## 1. Submission

Entry point: `CardsController::requestLimitChange()`, `CardsController.php:448`.

- Requires an active `tblPORTALCards` record (`loadPortalCard`/`loadPortalCardById`).
- Creates or resumes a draft in `dbo.tblApplications` (`ApplicationTypeID` resolved from card type: DTC/DPC/Lodge
  limit-change types).
- **On-behalf-of submission**: query params `on_behalf=1`, `ob_employee_id`, `ob_card_type`, `ob_last4`
  (`CardsController.php:461-464`) let a user submit for another employee's card. When resuming an existing
  application, `$isOnBehalf` is also re-derived from the stored payload (`payload['on_behalf'] == '1'`,
  `CardsController.php:483-485`). Card lookup falls back from employee-ID match to a direct `CardID` lookup for
  on-behalf links, since the target employee's ID format doesn't always match `tblPORTALCards.EmployeeID`
  (`CardsController.php:502-506`, comment explains why).
- Form fields — new credit limit, new transaction limit, reason, free-text "other" reason, permanent-vs-temporary
  duration — are persisted as a **JSON blob** in `tblApplicationSteps.DataJson` (loaded/saved via
  `loadPayload()`/`savePayload()`), not as normalized columns.
- Reasons are sourced from `tblLimitChangeReasons`, scoped by application type (`LimitChangeReasonModel`).
- On submit, application status moves to `ToBeApproved`, the record is locked (`Locked = 1`), and the full
  multi-stage approval chain is resolved and snapshotted into the payload (see §2) before the applicant ever leaves
  the submission flow — `CardsController.php:1232-1266` (`buildApprovalStageDefinitions`, then
  `hydrateApprovalStagesWithCandidates`/`filterApplicantFromApprovalStages`).

## 2. Routing rules — how approvers are chosen

Two tables drive routing, managed via `WorkflowApprovalRulesController` / `WorkflowApproverPositionsController`
(gated by `WORKFLOW_ADMIN`/`WORKFLOW_VIEW`/`ADMIN_ALL` — see those controllers' `$acl`).

### `dbo.tblWorkflowApprovalRules`

Columns used (`loadApprovalRules`, `CardsController.php:6751-6807`):
`RuleID, ApplicationTypeID, EmployeeGroup, ApprovalStage, MinLimit, MaxLimit, RequiredApproverType, RequiredRank,
IsActive`.

- Rules are looked up by `ApplicationTypeID` + `EmployeeGroup` (exact group match, or group-agnostic rows where
  `EmployeeGroup IS NULL`, ordered so a group-specific match wins ties) and filtered to `IsActive = 1`.
- `filterApprovalRulesForAmount()` (`CardsController.php:6921-6953`) keeps only rules where the requested new
  credit limit falls in `[MinLimit, MaxLimit]` (`MaxLimit` nullable = unbounded), then sorts by `ApprovalStage`,
  then `MinLimit`, then `RequiredApproverType`.
- `buildApprovalStageDefinitions()` (`CardsController.php:6955+`) turns the matched, amount-filtered rules into an
  ordered list of approval **stages**. A larger requested increase can match rules spanning more stages, so bigger
  increases can require a longer sequential approval chain, not just a "more senior" single approver.

### `dbo.tblWorkflowApproverPositions`

Columns used (`loadApproverDirectory`, `CardsController.php:6809-6919`):
`ApproverType, EmployeeGroup, PositionNumber, Email, DisplayName, IsActive`.

- For a resolved `EmployeeGroup`, returns the directory of real approvers (email/display name) keyed by
  `ApproverType`, used to fill in the candidate list for each stage's `RequiredApproverType`.
- The directory is merged with SES approvers pulled from a CAPS view for `Defence` employees (§3) before being
  returned (`CardsController.php:6881-6916`).

### Employee-group resolution

`resolveEmployeeGroup()` (`CardsController.php:6562`) determines routing group from CAPS data first (card's
`GroupName`, then `qryCAPSCDMCHistoryActive`, then a CAPS employee-group lookup), falling back to session data.
`resolveEmployeeGroupDisplay()` (`CardsController.php:6666`) is the display-only counterpart.

### Snapshotting

Once resolved, the entire stage chain is written into the application's JSON payload as `approval_stages` (each
entry: stage number, matching rule(s), candidate approvers, chosen approver, approval/forward timestamps) plus
`approval_current_stage` / `approval_stage_total`. **Consequence**: editing `tblWorkflowApprovalRules` or
`tblWorkflowApproverPositions` after a request has already been submitted does not retroactively change that
request's already-snapshotted stages — only new submissions pick up rule changes.

## 3. Special case: Defence SES approvers

- `loadSesApproversFromView()` (`CardsController.php:7274-7296`) only runs for `EmployeeGroup = 'Defence'`
  (case-insensitive `strcasecmp` check, hard-gated — no other group ever queries this view).
- `loadAllSesApproversFromView()` (`CardsController.php:7298+`) queries the **secondary CAPS connection**
  (`global $capsConn`), first defensively checking `OBJECT_ID('dbo.vwWorkflowDefenceSesApprovers', 'V') IS NOT NULL`
  before querying `SELECT * FROM dbo.vwWorkflowDefenceSesApprovers` — if the view doesn't exist or `$capsConn` isn't
  connected, it silently returns no SES candidates rather than erroring (consistent with CLAUDE.md's note that the
  CAPS connection is optional/non-fatal).
- Matching rows are merged into the approver directory under `ApproverType = 'SES'`, deduplicated against any
  `tblWorkflowApproverPositions` rows already typed `SES`.
- **$500,000 confirmation rule** — `shouldRequireLimitChangeSesConfirmation()` (`CardsController.php:6428-6462`):
  if the current stage's approver type is **not** `ASFIN` or `CFO`, and the requested new credit limit is
  **under $500,000** (or unparseable), the approve action requires the acting approver to explicitly tick
  a confirmation checkbox (`manual_approver_confirmed`) asserting the nominated approver is SES Band 1 / 1-Star or
  above — enforced only when the selected approver's email isn't already found in the SES view
  (`isEmailInSesApproverView()`, `CardsController.php:6412-6426`, and the check site at
  `CardsController.php:2233-2237`). This is a soft, checkbox-based override, not a hard block — the approval still
  fails validation (`errors['manual_approver_confirmed']`, `CardsController.php:2278-2280`) if the box isn't ticked
  when required.
- A raw-email "manual" approver type is also supported per stage as a fallback when no fixed position/SES match
  applies (`parseApproverSelection`, `resolveApproverContact`).

## 4. Progressing through stages — `limitChangeApproveSave()`

`CardsController.php:2145-2522`. POST-only, CSRF-checked (`csrf_check`, `CardsController.php:2153-2158`).

### Authorization checks (in code, not ACL)

1. Application must exist and not already be finalized — `currentStatus` in
   `['approved','rejected','senttobank','sent_to_bank','limitchanged','limit_changed']` is rejected
   (`CardsController.php:2186-2197`).
2. **Self-approval is blocked**: `isLimitChangeSelfRequest()` (`CardsController.php:8144-8179`) checks the acting
   user against the application's `UserID`, the applicant's `EmployeeID` (application row, `target_employee_id` in
   payload, or the card's `EmployeeID`), and finally the applicant's resolved email — any match rejects the action
   (`CardsController.php:2203-2207`).
3. **Assigned-approver check**: `isCurrentUserAssignedApprover()` (`CardsController.php:8099-8142`) requires the
   acting user's email to `hash_equals` either one of the current stage's `candidate_approvers[].email` entries, or
   the email resolved from the stored `approver` selection (type + position) via `resolveApproverContact()`.
   Non-matching users are rejected (`CardsController.php:2214-2218`).

### Decision handling

Three decisions, validated at `CardsController.php:2263-2283`:

- **`approve`**: Records `approved_by_user_id`/`approved_at` on the current stage and appends an entry to the
  in-payload approval-history log (`appendLimitChangeApprovalHistory`).
  - If a **next stage** exists (`CardsController.php:2318-2367`): resolves that stage's candidate approver(s)
    (auto-selecting the sole candidate if there's exactly one), sets `approval_current_stage` to the next stage
    number, status stays/returns to `ToBeApproved`, and a routing email fires
    (`sendLimitChangeApprovalEmail()`, `CardsController.php:7757`). If no valid candidates exist for the next
    stage, the whole submit is rejected with a flash error before anything is saved
    (`CardsController.php:2281-2283`, `2322-2329`).
  - If this was the **last stage** (`CardsController.php:2368-2424`): status → `Approved`. If CAPS writes are
    enabled (`isCapsWriteEnabled()`), the application is exported to CAPS via
    `exportLimitChangeApplicationToCaps()` (`CardsController.php:5633`, inserts into `tblCAPSApplication`) and then
    `runCapsLimitChangeApprovalInsert()` (`CardsController.php:5903`, a CAPS stored procedure) — any failure
    (`<= 0` app ID, or stored-proc output `< 0` or `=== 0`) aborts with a specific flash error and **does not**
    mark the application approved. If CAPS writes are disabled by config, the app is still marked `Approved`
    locally but flagged `payload['caps_writes_skipped'] = '1'` (`CardsController.php:2412-2414`) — it never reaches
    CAPS/the bank system in that case.
  - **The portal does not directly update `tblPORTALCards`'s limit fields** at approval time — that's left to
    whatever process syncs the card record from CAPS.
- **`reject`**: requires `reject_reason` (validated `CardsController.php:2266-2268`); status → `Rejected`
  (`CardsController.php:2425-2447`).
- **`forward`**: reroutes the *current* stage to a different valid candidate without advancing the stage number
  (`CardsController.php:2448-2483`). The chosen target must be in the pre-computed `allowedForwardValues` list
  built from `buildForwardApproverOptions()` (`CardsController.php:2219-2227`), or validation fails
  (`CardsController.php:2269-2276`).

### Side effects on every decision

- `savePayload()` persists the updated JSON payload; `syncLimitChangeApprovalState()`
  (`CardsController.php:6128`) updates `tblApplications.Status`/lock state.
- Email notification: `sendLimitChangeApprovalEmail()` on advance/forward, `sendLimitChangeDecisionEmail()`
  (`CardsController.php:8232`) on final approve/reject (`CardsController.php:2487-2497`, wrapped so a mail failure
  never blocks the decision — only logged via `error_log`).
- Audit log entry via `$this->auditLog()` (backed by `AuditModel`), action = uppercased decision
  (`APPROVE`/`REJECT`/`FORWARD`), object type `LimitChangeApproval`, object ID = application ID, with context
  including stage number, total stages, acting user, current/forward approver, and reject reason
  (`CardsController.php:2498-2517`).

## 5. Status lifecycle

`tblApplications.Status` values seen across this flow:

```
Draft / InProgress → ToBeApproved → (loop: approve advances stage, or forward reroutes same stage)
                                   → Approved  → (CAPS export) → SentToBank / LimitChanged
                                   → Rejected
```

`Locked = 1` from submission onward; finalized statuses (`approved`, `rejected`, `senttobank`, `sent_to_bank`,
`limitchanged`, `limit_changed` — checked case-insensitively) block any further action via
`limitChangeApproveSave()`.

## Known gotchas

- **No permission-code gating on approval** — authorization is purely "is your email the resolved approver for
  this stage" (§4). A stale or wrong email in `tblWorkflowApproverPositions` or the SES view silently locks out the
  intended approver or misroutes the request to the wrong person; there is no admin/permission-based override path
  in this controller.
- **Rules aren't retroactive** — each application's approval chain is frozen as JSON at submission time
  (`approval_stages` in the payload). Changing `tblWorkflowApprovalRules`/`tblWorkflowApproverPositions` only
  affects applications submitted afterward.
- `backend-php/app/PROJECT_NOTES.md` documents an earlier, thinner single-approver version of this flow with no
  stages, no SES handling, and no CAPS export step — treat it as historical background only; the code described in
  this document supersedes it.
- CAPS export failures (bad app ID, stored-proc error/zero output) abort the *local* approval too — an approver
  cannot mark something `Approved` in the portal if the CAPS write fails, when CAPS writes are enabled. But when
  CAPS writes are administratively disabled, `Approved` is recorded locally with nothing sent downstream
  (`caps_writes_skipped`), which is easy to miss without checking that payload flag.
