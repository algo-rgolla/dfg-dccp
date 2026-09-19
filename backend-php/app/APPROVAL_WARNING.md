# The "SES Band 1 / 1-Star" Approval Warning — Explained

When an approver opens a Defence credit-limit-increase request in the **$0 – $100,000** band, they may see this
message on the approval screen:

> *"The nominated approver must be a SES Band 1 / 1 Star or above. Defence HR records indicate this person does
> not meet this level.*
>
> *By continuing, you are confirming that the approver meets this requirement in line with Policy."*

This document explains why it appears, the rules behind it, and what it does (and doesn't) block. For the broader
approval workflow this sits inside, see `LIMIT_APPROVAL_WORKFLOW.md` and `LIMIT_WORKFLOW_100K_RULES.md` in this
same folder.

## Why this rule exists

Defence's approval rules require an **SES-ranked** approver (Senior Executive Service, Band 1 / 1-Star or above)
for credit-limit increases up to $100,000 — this is data-driven, not hardcoded: it's a row in
`dbo.tblWorkflowApprovalRules` with `RequiredApproverType = 'SES'`, `RequiredRank = 'SES'`
(`backend-php/app/Shared/sql/workflow_approval_rules.sql:200-234`; confirmed in the live database,
`CC Portal script.sql:3780-3781`, RuleID 13 and 14). No other employee group (ASA, ASD, ANNPSR) has an SES-rank
requirement anywhere in the rule table — they use named finance positions (ASFIN/CFO) instead.

## Why the warning specifically appears

Unlike ASFIN/CFO, there is no static list of named SES approvers configured in the system
(`dbo.tblWorkflowApproverPositions` has zero rows with `ApproverType = 'SES'` for Defence —
`CC Portal script.sql:3810-3813` only has ASFIN/CFO rows). So the system can't offer a dropdown of pre-verified SES
people the way it does for a finance role. Instead:

1. **The request form falls back to a generic "SES" choice plus a free-text email field.** When the approver
   directory has no `SES` entries, the submission screen shows a generic `SES` option and lets the requester type
   in the nominated approver's actual email address by hand (`app/Views/cards/LimitChange.php:1447-1454`).
2. **That typed email is checked against Defence HR data.** `isEmailInSesApproverView()`
   (`app/Controllers/CardsController.php:6412-6426`) looks the email up in a view called
   **`vwWorkflowDefenceSesApprovers`**, queried over the **secondary CAPS database connection** — i.e. real
   Defence HR/personnel records, not anything stored in the portal's own database
   (`loadAllSesApproversFromView()`, `CardsController.php:7298-7346`).
3. **If the email isn't found in that HR view, the warning is shown.** This happens both when the request is first
   submitted (`CardsController.php:1279`, `1810`) and again whenever an approver opens the approval screen for that
   request (`CardsController.php:2108-2114`).

## The exact rule that triggers it

The warning only appears when **both** of these are true (`shouldRequireLimitChangeSesConfirmation()`,
`CardsController.php:6428-6462`, combined with `isEmailInSesApproverView()`):

1. The current approval stage's required approver type is **not** `ASFIN` or `CFO` (i.e. it's `SES`) — these two
   finance roles are explicitly exempted from ever needing this confirmation
   (`CardsController.php:6452-6454`), and
2. The requested new credit limit is **under $500,000** (or the amount couldn't be parsed) — at $500,000 and
   above, only the CFO can approve regardless of group, so the SES question doesn't arise
   (`CardsController.php:6456-6461`),

**and**

3. The nominated approver's email is not found in the live `vwWorkflowDefenceSesApprovers` HR view.

If the nominated approver's email *is* found in that view, no warning appears at all — the system has independently
confirmed their SES rank and lets the approval proceed normally.

## What it does — and doesn't — block

**This is not a hard block.** It's a self-attestation safeguard. Alongside the warning text, the approval screen
shows a checkbox:

> *"I confirm I am SES Band 1 / 1 Star and am authorised to approve this application."*
> (`app/Views/cards/LimitChangeApprove.php:347`)

- If the approver **ticks the box**, the approval proceeds — the system records that the confirmation was given
  (`manual_approver_confirmed = 1`) and lets the decision through despite the HR-view mismatch.
- If the approver **does not tick the box** and tries to submit an approval anyway, `limitChangeApproveSave()`
  rejects the submission with a validation error and nothing is saved
  (`errors['manual_approver_confirmed']`, `CardsController.php:2278-2280`).

So in practice: the system tries to verify SES rank automatically via HR data, and when it can't, it falls back to
asking the human approver to personally certify they meet the policy requirement before the decision is accepted.

## Why this can happen even for a legitimate SES approver

Because the check depends entirely on the `vwWorkflowDefenceSesApprovers` CAPS view being complete and up to date,
a genuinely eligible SES Band 1+ approver can still trigger this warning if:

- Their record is missing or out of date in the underlying Defence HR data feeding that view, or
- The email address they're nominated under in the portal doesn't exactly match the email/identifier used in that
  HR view, or
- The CAPS connection is unavailable when the check runs — `loadAllSesApproversFromView()` fails closed (returns
  no candidates) rather than erroring if the CAPS connection isn't available or the view doesn't exist
  (`CardsController.php:7298-7312`), which would also produce this warning for every Defence approver until
  connectivity/data is restored.

This is why the confirmation checkbox exists as a fallback — it lets a legitimately eligible approver proceed even
when the automated HR check can't confirm them, while still creating an explicit, recorded acknowledgement of the
policy requirement (captured in the audit log entry for that decision, per `LIMIT_APPROVAL_WORKFLOW.md` §4).
