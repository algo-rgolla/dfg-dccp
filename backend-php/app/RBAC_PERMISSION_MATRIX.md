# RBAC Permission Matrix

Last updated: 2026-02-25

## Role Intent

- `NormalUser`
  - Core portal usage only.
  - No administration or system management features.
- `Administrator`
  - Full administrative access.
  - Recommended permission baseline: `ADMIN_ALL` (or `SYSADMIN`).

## Recommended Baseline Permissions

### NormalUser

- `PORTALCARDS_VIEW`

Optional add-ons (only if needed):
- `PORTALCARDS_EDIT`
- `WORKFLOW_VIEW`

### Administrator

Recommended:
- `ADMIN_ALL`

Alternative explicit set (if not using `ADMIN_ALL`):
- `USERS_ADMIN`
- `ROLES_ADMIN`
- `AUDIT_VIEW`
- `WORKFLOW_ADMIN`
- `DATAOBJECTCODES_ADMIN`
- `SYSSETTINGS_ADMIN`
- `SESSION_VIEW`
- `METRICS_VIEW`
- `HEALTH_VIEW`
- `DIAG_VIEW`

## Enforcement Notes

- Controller ACL checks are permission-based in `BaseController`.
- `ADMIN_ALL`/`SYSADMIN` now bypass per-route `permsAny/permsAll` checks.
- Menu visibility uses roles/perms and now treats `ADMIN_ALL`/`SYSADMIN` as an override for admin feature items.

## Assignment Guide

1. Assign `NormalUser` role to standard users with `PORTALCARDS_VIEW`.
2. Assign `Administrator` role to privileged users with `ADMIN_ALL` (preferred).
3. Keep feature-specific permissions for non-admin specialist roles as needed.
