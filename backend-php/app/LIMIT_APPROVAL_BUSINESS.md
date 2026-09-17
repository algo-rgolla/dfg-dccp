# How Credit Limit Increases Get Approved (Business Guide)

A plain-language explanation of what happens when someone asks for their card's credit limit to be raised. For the
technical/developer version with exact code references, see `LIMIT_APPROVAL_WORKFLOW.md` in this same folder.

## 1. Making a request

A cardholder logs into the portal and requests a new credit (or transaction) limit for their card. They:

- Choose the new limit amount.
- Give a reason (picked from a standard list of reasons, or "other" with a short explanation).
- Say whether the increase is **permanent** or **temporary** (and for how long, if temporary).

**Requesting on someone else's behalf**: A manager or admin can also submit this request for another cardholder,
instead of the cardholder doing it themselves.

Once submitted, the request is locked in — the cardholder can no longer edit it, and it's sent into the approval
queue.

## 2. Who has to approve it

This isn't a single yes/no from one person. The portal automatically works out **how many approval steps are
needed and who each approver should be**, based on two things:

- **How big the requested increase is.** Bigger increases can require more people to sign off, not just one more
  senior person.
- **Which part of the organisation the cardholder belongs to** (their "employee group" — e.g. Defence, ASA, ASD,
  ANNPSR). Different groups can have different approval rules.

These rules and the list of who holds each approval position are maintained by administrators in the system (not
hardcoded) — so the business can change dollar thresholds or swap out who holds an approval role without a code
change.

Once a request is submitted, the full chain of approval steps it will need to go through is worked out and locked
in for that request. **If the approval rules change later, it doesn't retroactively affect requests that are
already in progress** — only new requests pick up the change.

### Special rule for Defence

For Defence cardholders, the system can also pull in eligible senior (SES-level) approvers from another connected
system. If the requested increase is **under $500,000** and the approver isn't already one of the recognised
Finance leadership roles, the person actioning the approval has to explicitly confirm the nominated approver is
SES Band 1 (1-Star) or above before the approval can go through. This is a safeguard, not an automatic block — it's
a checkbox confirmation the approver has to tick.

## 3. What an approver can do

Whoever the request is currently sitting with gets notified and can take one of three actions:

- **Approve** — signs off their step. If more approval steps remain, the request automatically moves to the next
  approver and they're notified. If this was the last step, the request is marked **Approved** and gets sent
  through to the bank/back-office system to actually put the new limit in place.
- **Reject** — stops the request. A reason must be given, and the cardholder is notified.
- **Forward** — hands their step to someone else who's also eligible to approve at that step (e.g. a delegate),
  without skipping ahead in the process.

**A person can never approve their own request** — even if they happen to hold an approver position, the system
blocks self-approval.

## 4. What happens after final approval

Once every required approval step is signed off, the system automatically sends the change through to the bank's
system to update the actual card limit. The portal itself doesn't just flip the limit locally — it waits for
confirmation that the bank side accepted the change. If that hand-off fails for any reason, the request is **not**
considered fully approved yet, and an error is shown so it can be looked into — this prevents a situation where the
portal says "approved" but the bank never actually changed the limit.

## 5. Status of a request, in plain terms

| Status | Meaning |
|---|---|
| Draft | Cardholder is still filling it in — not yet submitted. |
| Awaiting approval | Submitted, currently sitting with an approver. |
| Approved | All required approvers have signed off. |
| Sent to bank / Limit changed | Approved and successfully pushed through to the bank system. |
| Rejected | An approver declined the request, with a reason recorded. |

Once a request has reached **Approved**, **Rejected**, or later, it's locked — no one can go back and change the
decision through the normal approval screen.

## 6. Notifications and record-keeping

- Everyone involved gets an email at the key moments: when a request moves to a new approver, and when it's
  finally approved or rejected.
- Every decision (approve, reject, forward) is written to the system's audit log — who did it, when, and why —
  so there's always a record of exactly how a request was decided.

## Things worth knowing

- Approval eligibility is based on matching the approver's registered email address to the position the request is
  currently sitting with. If someone's contact details in the system are out of date, they may not be recognised as
  the correct approver — this is an admin-data-quality issue to watch for, not a workflow bug.
- If the bank-side system is temporarily switched off for testing/maintenance, a request can be marked Approved in
  the portal without actually reaching the bank — this is a deliberate configuration option used in
  non-production environments, but worth confirming isn't accidentally left on in production.
