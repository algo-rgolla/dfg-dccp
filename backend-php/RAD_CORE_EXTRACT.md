# Isidore RAD – Core Platform Extraction Notes

Date: 2026-02-07

## 1) Core Capabilities Identified (from CCPortal)

### Authentication & Session
- **SSO / Login flow:** `app/Controllers/AuthController.php`
- **Session helper:** `shared/SessionHelper.php`
- **CSRF:** `shared/csrf.php`
- **Env loading:** `shared/env.php`

### RBAC (Roles & Permissions)
- **RBAC engine:** `app/Core/Rbac.php`
- **Roles & permissions UI:** `app/Controllers/RolesController.php`, `app/Controllers/UsersController.php`
- **Navigation gating:** `shared/nav_tree.php` uses roles/perms to hide menu items.

### Navigation / Layout
- **Main layout:** `app/Views/layouts/main.php`
- **Menu tree logic:** `shared/nav_tree.php`

### Workflow Engine
- **Workflow tasks:** `app/Controllers/WorkflowController.php`, `app/Models/WorkFlowTaskModel.php`
- **Application workflow:** `tblApplicationWorkFlowSteps`, `tblApplicationSteps` (used in Applications + Limit Change).

### System Settings
- **System settings controller/model:** `app/Controllers/SystemSettingsController.php`, `app/Models/SystemSettingsModel.php`

### Email
- **Mail service:** `app/Services/MailService.php`
- **Email queue:** `app/Models/EmailQueueModel.php`, `app/Controllers/EmailQueueController.php`

### Diagnostics & Logging
- **Error handling:** `shared/error_handler.php`, `public/index.php`
- **Logging:** `shared/logger.php`, `logs/`
- **Diagnostics:** `app/Controllers/DiagnosticsController.php`

### Localization
- **Language helpers:** `shared/lang.php`, `lang/en.php` (and other locale files)

### Contextual Help
- **Help system:** `app/Controllers/HelpController.php`, `app/Views/help/`

### DataObject / Metadata Patterns
- **DataObject access:** `app/Controllers/DataObjectsController.php`
- **DataObject codes + access:** `app/Controllers/DataObjectCodesController.php`

---

## 2) Recommended Core Platform Boundaries

**Core Platform** (always deployed):
- Auth/SSO + Session
- RBAC + Users/Roles/Permissions
- System Settings
- Workflow engine
- Email + Queue
- Diagnostics + Logging
- Localization
- Help system
- Navigation & Layout

**Modules** (plug-in applications):
- HR, Finance, Sales, Procurement, etc.
- Share common data from core (org, employee, finance master data)

---

## 3) Suggested Module Contract (Draft)

Each module should include:
- `Module.php` metadata (name, routes, permissions, menu)
- `Controllers/`, `Models/`, `Views/` within module namespace
- Optional `migrations/` for module tables
- Optional `seed/` data

**Permissions** should be prefixed per module:
- e.g. `HR_VIEW`, `HR_EDIT`, `SALES_VIEW`

**Routes** should be prefixed:
- e.g. `hr/*`, `sales/*`

---

## 4) Target Folder Structure (Draft)

```
isidore-rad/
  app/
    Core/                 # Auth, RBAC, Workflow, BaseController, etc.
    Modules/
      HR/
      Sales/
      Finance/
  config/
    routes.php
  shared/
    env.php
    error_handler.php
    logger.php
    nav_tree.php
  public/
    index.php
  lang/
    en.php
```

---

## 5) Database Core Tables (to extract)

**Security / RBAC**
- `tblUsers`
- `tblRoles`
- `tblPermissions`
- `tblUserRoles`
- `tblRolePermissions`

**Workflow**
- `tblWorkFlowTasks`
- `tblWorkFlowTaskTypes`
- `tblWorkFlowTaskStatuses`

**System Settings**
- `tblSystemSettings`

**Email**
- `tblEmailQueue`

**Audit / Logging**
- `tblAudit`

---

## 6) Next Steps

1. Confirm target folder structure for Isidore RAD base.
2. Decide module metadata format (PHP array? JSON? DB-backed?)
3. Extract core tables into a standalone SQL seed script.
4. Identify which CCPortal controllers/models become “Core” vs “Module”.

