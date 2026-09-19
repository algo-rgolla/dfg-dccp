# Limit-Increase Approval Rules — Defence, ASA, ASD, ANNPSR (up to $100,000)

Direct answers to: *"Are these different rules for different groups? Are there different workflows?"*

**Short answer: one workflow, different rules.** There is a single approval engine/code path
(`CardsController::limitChangeApprove*`, described in `LIMIT_APPROVAL_WORKFLOW.md`). It does not branch into
separate code per group. What differs between Defence, ASA, ASD and ANNPSR is purely **which rows exist for them**
in two data tables — `tblWorkflowApprovalRules` (which amount bands need which approver type) and
`tblWorkflowApproverPositions` (who actually holds each approver type for that group). Change the data, change the
outcome — no code change needed.

This is grounded directly in the seed script `backend-php/app/Shared/sql/workflow_approval_rules.sql` and confirmed
against the actual data currently loaded in the database (`CC Portal script.sql:3776-3819`).

## The up-to-$100,000 band, group by group

| Employee Group | Amount band | Required approver | Source rows |
|---|---|---|---|
| **Defence** | $0 – $100,000 | **SES** rank specifically (`RequiredApproverType='SES'`, `RequiredRank='SES'`) | `workflow_approval_rules.sql:200-234`; live data `CC Portal script.sql:3780-3781` (RuleID 13, 14) |
| **ASA** | $0 – $100,000 | **ASFIN or CFO** (either one — not both) | `workflow_approval_rules.sql:269-332`; live data `CC Portal script.sql:3784-3787` (RuleID 1002-1005) |
| **ASD** | $0 – $100,000 | **ASFIN or CFO** (either one) | `workflow_approval_rules.sql:465-528`; live data `CC Portal script.sql:3794-3797` (RuleID 1012-1015) |
| **ANNPSR** | **all amounts** — no $100K tier exists | **CFO only**, always | `workflow_approval_rules.sql:647-678`; live data `CC Portal script.sql:3804-3805` (RuleID 1022-1023). `MinLimit=0`, `MaxLimit=NULL` — one rule covers every amount, so there's no separate "up to $100K" band for ANNPSR at all. |

Two application types share identical rules per group: `dpc_limit_change` (`ApplicationTypeID=5`) and
`dtc_limit_change` (`ApplicationTypeID=6`) — each group's rule is duplicated once for each card type, with the same
thresholds and approver type.

### What "either approver" means in practice

Where a band lists two acceptable approver types (e.g. ASA's "ASFIN or CFO"), those are two separate rows at the
**same `ApprovalStage`** (all seed rows default to stage 1 — see `workflow_approval_rules_stage.sql`). The
approval-engine code groups same-stage rules together and treats their approvers as **one combined list of
candidates** (`buildApprovalStageDefinitions()`, `CardsController.php:6955-6981`) — meaning **any one** of them
approving is enough to clear that stage. It is not "both must approve"; it's "whichever eligible person actions it
first."

So for all four groups, the up-to-$100,000 tier is a **single approval step** — just with a different eligible
approver type (and in Defence's case, a materially different *kind* of approver — SES rank, not a finance role).

## Why Defence's $100K tier is different in kind, not just in who

For Defence specifically, the required approver type at this band is `SES` — Senior Executive Service rank —
resolved two ways:
1. From `tblWorkflowApproverPositions` rows explicitly typed `SES` for the Defence group (none are seeded by
   default in `workflow_approval_rules.sql`/the current DB dump — none exist besides ASFIN/CFO Defence rows,
   `CC Portal script.sql:3810-3813`).
2. From a **live CAPS view**, `vwWorkflowDefenceSesApprovers`, queried only when `EmployeeGroup = 'Defence'`
   (`loadSesApproversFromView()`, `CardsController.php:7274-7296`) — this is where Defence SES candidates actually
   come from in practice, since no static SES positions are seeded.

Because the required type for this band is `SES` (not `ASFIN`/`CFO`), it also triggers the **SES confirmation
safeguard** described in `LIMIT_APPROVAL_WORKFLOW.md` §3: for any Defence request under $500,000 where the
approver type isn't ASFIN/CFO, the approving user must tick a confirmation box asserting the nominated approver is
SES Band 1 (1-Star) or above, unless that person is already found in the CAPS SES view
(`shouldRequireLimitChangeSesConfirmation()`, `CardsController.php:6428-6462`). ASA, ASD, and ANNPSR never hit this
check at this amount band, because their required type there is ASFIN/CFO, which is explicitly exempted
(`CardsController.php:6452-6454`).

## The "SES Band 1/1-Star" warning message, explained

When an approver opens a Defence request in this $0–$100,000 band, they can see this message:

> *"The nominated approver must be a SES Band 1 / 1 Star or above. Defence HR records indicate this person does
> not meet this level."*

This is a direct consequence of there being no fixed list of SES approvers to pick from, plus a live check against
Defence HR data. The mechanics:

1. **No static SES directory exists.** `tblWorkflowApproverPositions` has no rows with `ApproverType = 'SES'` for
   Defence (only ASFIN/CFO rows are seeded — `CC Portal script.sql:3810-3813`). So the request form can't offer a
   dropdown of named SES people the way it does for ASFIN/CFO.
2. **The form falls back to a generic "SES" choice + free-text email.** When the approver directory has no `SES`
   rows, the submission screen offers a generic `SES` option and lets the requester type the actual nominated
   approver's email by hand (`LimitChange.php:1447-1454`).
3. **That typed email is checked against a live HR-linked view.** `isEmailInSesApproverView()`
   (`CardsController.php:6412-6426`) looks the email up in `vwWorkflowDefenceSesApprovers` — a view queried over
   the **secondary CAPS connection** (Defence HR/personnel data), not the portal's own database.
4. **If the email isn't found there, the warning is shown** — set both at submission time
   (`CardsController.php:1279`, `1810`) and again when the approver opens the approval screen
   (`CardsController.php:2108-2114`). It only fires when both are true: `shouldRequireLimitChangeSesConfirmation()`
   says confirmation is needed (approver type isn't ASFIN/CFO, and the amount is under $500,000 —
   `CardsController.php:6452-6461`), **and** `isEmailInSesApproverView()` returns false for that email.

**This is not a hard block.** It is a self-attestation safeguard: alongside the warning, the approver sees a
checkbox — *"I confirm I am SES Band 1 / 1 Star and am authorised to approve this application"*
(`LimitChangeApprove.php:347`). Ticking it lets the approval proceed anyway (`manual_approver_confirmed = 1`); an
approval submitted without ticking it is rejected with a validation error
(`errors['manual_approver_confirmed']`, `CardsController.php:2278-2280`).

In short: Defence's $100K-and-under tier requires SES Band 1+ sign-off, but since the system has no authoritative
static list of who holds that rank, it tries to verify the nominated person against Defence HR data via CAPS —
and when it can't confirm them there, it falls back to making the approver explicitly self-certify eligibility
before the approval is allowed through. This is a compensating control for a rule that the current data can't
fully automate, not a workflow malfunction.

## Who is actually registered as an approver today (per the current DB dump)

`CC Portal script.sql:3810-3818`:

| Group | ApproverType | Position(s) seeded |
|---|---|---|
| Defence | ASFIN | 3 positions (`00546641`, `00119128`, `00504242`) |
| Defence | CFO | 1 position (`00127838`) |
| ASA | ASFIN | 1 position (`00634173`) |
| ASA | CFO | 1 position (`00686087`) |
| ASD | ASFIN | 1 position (`00686736`) |
| ASD | CFO | 1 position (`00625930`) |
| ANNPSR | CFO | 1 position (`00723023`) — **no ASFIN position exists for ANNPSR**, consistent with its rule always requiring CFO |

**Data-quality note**: in the current database dump, every one of these positions has the same placeholder email
(`andrew.bull@isidore.com`) with different display names — this looks like test/non-production data. Since
approver identity is matched by email at approval time (see `LIMIT_APPROVAL_WORKFLOW.md` §4), this is fine for
testing but **must be corrected with real individual emails before this goes live**, or multiple "different"
approver positions will all resolve to the same person's inbox.

## Beyond $100,000 (for context)

The same four groups diverge further above $100K — summarized here since it clarifies that $100K is just one of
several thresholds, not a ceiling:

| Group | $100,001 – $499,999 | $500,000+ |
|---|---|---|
| Defence | ASFIN or CFO | CFO only |
| ASA | ASFIN or CFO | CFO only |
| ASD | ASFIN or CFO | CFO only |
| ANNPSR | *(same flat CFO-only rule covers this too)* | *(same flat CFO-only rule covers this too)* |

Interestingly, Defence's own $100,001–$499,999 tier drops the SES requirement and reverts to ASFIN/CFO — the SES
requirement is specific to Defence's $0–$100,000 band, not "Defence in general."

## Bottom line

- **Different rules, yes** — each group has its own row(s) in `tblWorkflowApprovalRules` for the same $0–$100,000
  band, and three of the four groups (ASA, ASD, ANNPSR-implicitly) use ASFIN/CFO while Defence alone requires SES
  rank for that band.
- **Different workflows, no** — it's the same single-stage-per-band approval mechanism, same code, same submission
  and decision routes. The only thing that varies by group is which data rows match, which changes who is asked to
  approve and (for Defence only) triggers an extra confirmation step.
- ANNPSR is the outlier in structure, not just values: it has no $100K threshold at all — one CFO-only rule covers
  every dollar amount.
