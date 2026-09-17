# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

CCPortal (Defence Card/Corporate Card Portal) — a monolithic, server-rendered PHP application (no framework) for
managing corporate cards, applications, workflow approvals, and admin/RBAC. Views are plain PHP templates rendered
into a shared Bootstrap 5 layout; there is no SPA/JS build step and no client-side framework. Notes in
`backend-php/RAD_CORE_EXTRACT.md` describe a longer-term plan to extract this into a reusable "Isidore RAD" core
platform + pluggable modules — useful background if asked about future architecture, but not yet implemented.

## Commands

There is no build step, bundler, or test runner in this repo (`backend-php/package.json`'s `test` script is a stub;
there is no PHPUnit config). Development is edit-PHP-file-and-reload.

- Install PHP deps: `composer install` (run from repo root; `composer.json` autoloads `App\` → `backend-php/app/`)
- The app is served by a normal PHP web server (e.g. IIS on Windows per `public/web.config`, or `php -S`) pointed at
  `backend-php/public/` as the document root. Entry point is `backend-php/public/index.php`.
- Config comes from `backend-php/.env` (loaded by `backend-php/shared/env.php`). Key variables: `APP_ENV`,
  `APP_DEBUG`, `APP_COOKIE_PATH`, `APP_SESSION_PREFIX`, `DB_*` (primary CCPortal DB), `CAPS_DB_*` (secondary CAPS
  DB), `LOGIN_AUTH_MODE`, `DEV_WINDOWS_ENABLED`, `ONBOARDING_*`. Never print or commit real values from `.env`.
- Database schema/reference dump: `CC Portal script.sql` at the repo root.

## Architecture

### Request lifecycle
Everything flows through `backend-php/public/index.php`:
1. Sets security headers, request ID, error handler, then requires `public/bootstrap.php` (session config, `.env`
   load, DB connect, language init, idle/absolute session-timeout enforcement).
2. Looks up `?route=foo/bar` in the flat route table `backend-php/config/routes.php` (format:
   `'route/key' => 'ControllerName@method'`), resolves `App\Controllers\{ControllerName}`, and calls the method.
3. Controllers extend `App\Controllers\BaseController` (`backend-php/app/Controllers/BaseController.php`), whose
   constructor runs auth/ACL/session/context checks *before* the concrete controller's own constructor logic —
   this is the enforcement chokepoint for the whole app.
4. Controllers call `$this->render('folder/View', $vars)` to render a PHP view from `app/Views/...` wrapped in
   `app/Views/layouts/main.php` (or `renderPartial()` for AJAX/no-layout fragments). Views are plain PHP with
   `htmlspecialchars`-style manual escaping — no templating engine.

### Auth, sessions, RBAC
- `BaseController::enforceAcl()` reads each controller's `protected array $acl` (keyed by action name, with a `'*'`
  fallback) for `auth` (bool) and `permsAny`/`permsAll` (permission code arrays), and redirects/denies accordingly.
  `ADMIN_ALL` and `SYSADMIN` bypass all per-route permission checks (superuser escape hatch) — see
  `backend-php/app/Controllers/BaseController.php:307-320`.
- `App\Core\Rbac` (`backend-php/app/Core/Rbac.php`) loads a user's roles/permissions from
  `tblUserRoles`/`tblRoles`/`tblRolePermissions`/`tblPermissions` into the session (`auth.roles`, `auth.perms`) via
  `loadForUser()`, then exposes static `hasRole/hasAnyRole/can/canAny/canAll` helpers that read from session state
  (not the DB) for the rest of the request.
- Session state lives under a namespaced bucket in `$_SESSION[<APP_SESSION_PREFIX>]` (default `cbmsv21`), accessed
  via `App\Shared\SessionHelper` (dot-path get/set/forget) rather than raw `$_SESSION`.
- Both idle and absolute session timeouts are enforced twice: once in `bootstrap.php` and again in
  `BaseController::enforceAcl()`, driven by `SystemSettingsModel` values (`SESSION_IDLE_LIMIT`,
  `SESSION_TIMEOUT_MIN`), not hardcoded.
- CSRF: `backend-php/shared/csrf.php` (`csrf_token()`, `csrf_field()`, `csrf_check()`), token stored per-session.
- Login supports SSO/Windows auth (`shared/windows_login.php`) plus normal form login with DB-backed throttling
  (`shared/login_throttle_db.php`).
- `docs`/reference: `backend-php/app/RBAC_PERMISSION_MATRIX.md` documents the intended baseline permission sets per
  role (`NormalUser`, `Administrator`) — consult it before inventing new permission codes.

### Data layer
- No ORM. Controllers/Models use raw PDO (`$this->db` from `BaseController`, or `global $conn`) with prepared
  statements. Two DB connections are wired up in `backend-php/config/db.php`: `$conn` (primary CCPortal DB) and
  `$capsConn` (secondary "CAPS" system, optional — failure to connect is non-fatal). Driver is `sqlsrv` (SQL
  Server) by default, with `mysql` supported as an alternate driver via env config.
- ODBC/sqlsrv gotcha (see `backend-php/PROJECT_NOTES.md`): the driver does not like repeated named parameters in
  one prepared statement — use positional (`?`) placeholders when a parameter would otherwise repeat.
- Models live in `app/Models/`, one per table/concept, constructed with a PDO connection.

### DataObjects / scope model
`DataObjectCodesController`/`DataObjectCodeAccessController` + their models implement a hierarchical
"data object code" scoping/access system (tree picker, per-user access grants, audit trail) used to scope other
features (e.g. fiscal context, cards) to organizational units. `DataObjectsController@picker` is the reusable picker
endpoint other features call into.

### Fiscal context
Some controllers set `protected bool $requiresContext = true;`, which makes `BaseController` call
`ensureContext()` to resolve/validate a `FiscalYearID`/`VersionID` pair (via `FiscalContextModel`) into the session
before the action runs, falling back through system-setting defaults then the latest active fiscal year/version.

### Workflow & applications
Card limit-change requests and card applications go through a workflow engine backed by `tblApplications` /
`tblApplicationSteps` / `tblApplicationWorkFlowSteps` (see `WorkflowController`, `WorkFlowTaskModel`,
`ApplicationsController`, `CardsController`). Approval routing uses `WorkflowApprovalRulesController` /
`WorkflowApproverPositionsController`, with special-cased Defence SES approver resolution via a CAPS view
(`vwWorkflowDefenceSesApprovers`). Card change requests unrelated to limit changes (address change, cancel card) are
stored separately in `tblCardChangeRequests`, whose `Status` column has a short max length — long status strings
will throw a truncation error, so keep status values short and match existing conventions
(`Addr Update Subm`, `Addr Update Done`, `Cancel Subm`, etc.).

### Audit logging
Business actions (login/logout, application submit/approve/reject, user/role/settings changes, data-object access
grants) are recorded to `dbo.tblAuditLog` via `AuditModel`, viewable at route `audit/list` (`AUDIT_VIEW`/`SYSADMIN`
gated). Audit-insert failures are intentionally non-blocking/best-effort — don't make business logic depend on the
audit insert succeeding. `backend-php/app/AUDIT_COVERAGE.md` tracks what's covered and known gaps (e.g. audit list
sorting UI exists but the backend query always orders by `AuditID DESC`, ignoring sort params).

### Logging & diagnostics
`backend-php/shared/logger.php` provides `app_log($message, array $context, string $level)`, writing to
`backend-php/logs/app-*.log` (and DB once `app_log_set_conn()` is called in bootstrap). This is separate from and
complementary to the DB audit log. `DiagnosticsController` exposes routes to deliberately trigger test
errors/emails for verifying error handling and mail delivery end-to-end.

### Localization
`backend-php/shared/lang.php` + `backend-php/lang/{en,fr}.php` back the `__t($key, $replacements)` helper used
throughout controllers/views for user-facing strings.

## Conventions / gotchas worth knowing before editing

- Routes are a single flat associative array in `config/routes.php` — check for an existing key before adding a
  new one (there are already a couple of accidental duplicate keys in that file, e.g. `systemmessages/feed`/`ack`
  appear twice with identical mappings — harmless but don't add to the pattern).
- Controller ACLs are self-declared per controller (`protected array $acl = [...]`) — there is no central route
  permission table; when adding a new controller action, add its ACL entry alongside it or it will default to
  `auth => true` with no permission requirement via the `'*'` fallback (or deny-all if no `'*'` exists and no entry
  matches, depending on the specific controller's `$acl` structure — check the individual controller).
- `backend-php/app/Models/DataObjectCodeAccessModel (2).php` is a stray duplicate of
  `DataObjectCodeAccessModel.php` left in the tree — don't edit it; treat `DataObjectCodeAccessModel.php` as
  canonical, and prefer deleting the `(2)` copy if you touch that area.
- `backend-php/app/Views/help/HelpModal x.php` is similarly a stray/experimental file, not part of the normal
  Views routing.
- The repo has no `.gitignore`; `backend-php/node_modules/`, `backend-php/vendor/`-equivalent assets, and
  `backend-php/logs/*.log` appear to be committed. Be careful not to bulk-add noisy generated output, and don't
  assume `git status` being clean means there's nothing there to search — build feature understanding from `app/`,
  not from vendored JS/CSS libs (`bootstrap`, `@popperjs`) checked in under `public/assets` / `node_modules`.
- `backend-php/app/PROJECT_NOTES.md` and `backend-php/app/SESSION_SUMMARY_2026-02-13.md` are point-in-time handoff
  notes from prior sessions (cards limit-change flow, on-behalf-of flow) — useful for historical context on why
  something was built a certain way, but treat them as a snapshot, not current truth; verify against the actual
  code.
