<?php
declare(strict_types=1);

return [
   ''           => 'AuthController@sso',
'home'       => 'AuthController@sso',
'home/index' => 'HomeController@index',   // or whatever your real home is
    // Auth
    'auth/loginForm' => 'AuthController@loginForm',    // ← most important fix
    'auth/loginFormTrace' => 'AuthController@loginFormTrace',
    'auth/logout'        => 'AuthController@logout',  // GET
    'auth/login' => 'AuthController@login',
    'auth/login-agreement' => 'AuthController@loginAgreement',
    'auth/login-agreement-accept' => 'AuthController@loginAgreementAccept',
    'auth/account'       => 'AuthController@account',
    'auth/refreshAccess' => 'AuthController@refreshAccess',
    'auth/activate' => 'AuthController@activate',


    // SSO + Onboarding
    'auth/sso'           => 'AuthController@sso',
    'onboarding/start'   => 'OnboardingController@start',
    'onboarding/save'    => 'OnboardingController@save',   // POST
        'onboarding/activation-sent' => 'OnboardingController@activationSent',


    // System Settings
    'system-settings/list' => 'SystemSettingsController@list',
    'system-settings/save' => 'SystemSettingsController@save',

    // Users (Admin)
    'users/list'   => 'UsersController@list',
    'users/edit'   => 'UsersController@edit',
    'users/save'   => 'UsersController@save',
    'users/resetActivation' => 'UsersController@resetActivation',
    'users/unlock' => 'UsersController@unlock',
    'users/saveRoles' => 'UsersController@saveRoles',
    'users/exportPdf' => 'UsersController@exportPdf',
    'users/exportUserPdf' => 'UsersController@exportUserPdf',
    'users/upload'         => 'UsersController@upload',         // GET: show form
    'users/uploadProcess'  => 'UsersController@uploadProcess',  // POST: handle file
    'users/exportExcel'   => 'UsersController@exportExcel',   // ✅ NEW Excel route

    // Roles (Admin)
    'roles/list' => 'RolesController@list',
    'roles/edit' => 'RolesController@edit',
    'roles/save' => 'RolesController@save',
    'roles/view' => 'RolesController@view',   // for role + permissions detail

    // Audit
    'audit/list' => 'AuditController@list',

    // Eligibility Admin
    'admin/applications' => 'ApplicationAdminController@index',
    'admin/applications-view' => 'ApplicationAdminController@view',
    'admin/applications-status' => 'ApplicationAdminController@updateStatus',
    'admin/applications-reset-approval' => 'ApplicationAdminController@resetApproval',
    'admin/applications-delete' => 'ApplicationAdminController@delete',
    'admin/application-types' => 'ApplicationTypesController@list',
    'admin/application-types-edit' => 'ApplicationTypesController@edit',
    'admin/application-types-save' => 'ApplicationTypesController@save',
    'admin/application-types-delete' => 'ApplicationTypesController@delete',
    'admin/limit-change-reasons' => 'LimitChangeReasonsController@list',
    'admin/limit-change-reasons-edit' => 'LimitChangeReasonsController@edit',
    'admin/limit-change-reasons-save' => 'LimitChangeReasonsController@save',
    'admin/limit-change-reasons-delete' => 'LimitChangeReasonsController@delete',
    'admin/cancel-card-reasons' => 'CancelCardReasonsController@list',
    'admin/cancel-card-reasons-edit' => 'CancelCardReasonsController@edit',
    'admin/cancel-card-reasons-save' => 'CancelCardReasonsController@save',
    'admin/cancel-card-reasons-delete' => 'CancelCardReasonsController@delete',
    'admin/schema-updates' => 'SchemaUpdatesController@index',
    'admin/schema-updates-run' => 'SchemaUpdatesController@run',
    'admin/release-packages' => 'ReleasePackagesController@index',
    'admin/release-package-history' => 'ReleasePackagesController@history',
    'admin/release-packages-upload' => 'ReleasePackagesController@upload',
    'admin/release-packages-apply' => 'ReleasePackagesController@apply',
    'admin/release-packages-delete' => 'ReleasePackagesController@delete',
    'admin/release-packages-rollback' => 'ReleasePackagesController@rollback',
    'admin/application-blacklist' => 'EligibilityAdminController@blacklistList',
    'admin/application-blacklist-edit' => 'EligibilityAdminController@blacklistEdit',
    'admin/application-blacklist-save' => 'EligibilityAdminController@blacklistSave',
    'admin/application-blacklist-delete' => 'EligibilityAdminController@blacklistDelete',
    'admin/eligibility-overrides' => 'EligibilityAdminController@overrideList',
    'admin/eligibility-overrides-edit' => 'EligibilityAdminController@overrideEdit',
    'admin/eligibility-overrides-save' => 'EligibilityAdminController@overrideSave',
    'admin/eligibility-overrides-delete' => 'EligibilityAdminController@overrideDelete',
    'admin/default-addresses' => 'EligibilityAdminController@defaultAddressList',
    'admin/default-addresses-edit' => 'EligibilityAdminController@defaultAddressEdit',
    'admin/default-addresses-save' => 'EligibilityAdminController@defaultAddressSave',
    'admin/default-addresses-delete' => 'EligibilityAdminController@defaultAddressDelete',
    'admin/default-addresses-lookup' => 'EligibilityAdminController@defaultAddressLookup',
    'admin/change-requests' => 'CardChangeRequestsAdminController@list',
    'admin/dpc-application-approvals' => 'ApplicationsController@dpcApprovals',
    'admin/pending-card-cancellations' => 'CardChangeRequestsAdminController@pendingCancellations',
    'admin/feedback' => 'FeedbackController@index',
    'admin/management-summary' => 'ManagementSummaryController@index',
    'admin/change-requests-delete' => 'CardChangeRequestsAdminController@delete',
    'admin/suburbs' => 'EligibilityAdminController@suburbList',
    'admin/suburbs-edit' => 'EligibilityAdminController@suburbEdit',
    'admin/suburbs-save' => 'EligibilityAdminController@suburbSave',
    'admin/suburbs-delete' => 'EligibilityAdminController@suburbDelete',
    'admin/workflow-approver-positions' => 'WorkflowApproverPositionsController@list',
    'admin/workflow-approver-positions-edit' => 'WorkflowApproverPositionsController@edit',
    'admin/workflow-approver-positions-save' => 'WorkflowApproverPositionsController@save',
    'admin/workflow-approver-positions-delete' => 'WorkflowApproverPositionsController@delete',
    'admin/workflow-approval-rules' => 'WorkflowApprovalRulesController@list',
    'admin/workflow-approval-rules-edit' => 'WorkflowApprovalRulesController@edit',
    'admin/workflow-approval-rules-save' => 'WorkflowApprovalRulesController@save',
    'admin/workflow-approval-rules-delete' => 'WorkflowApprovalRulesController@delete',
    'admin/workflow-approval-rules-upload' => 'WorkflowApprovalRulesController@upload',
    'admin/workflow-approval-rules-upload-process' => 'WorkflowApprovalRulesController@uploadProcess',
    'admin/workflow-approval-rules-template' => 'WorkflowApprovalRulesController@downloadTemplate',
    'admin/workflow-approval-rules-upload-report' => 'WorkflowApprovalRulesController@downloadUploadReport',
    'admin/caps-promaster-users' => 'CAPSProMasterUsersController@list',
    'admin/caps-promaster-users-edit' => 'CAPSProMasterUsersController@edit',
    'admin/caps-promaster-users-save' => 'CAPSProMasterUsersController@save',
    'admin/caps-promaster-users-delete' => 'CAPSProMasterUsersController@delete',
    'admin/caps-training' => 'CAPSTrainingController@list',
    'admin/caps-training-edit' => 'CAPSTrainingController@edit',
    'admin/caps-training-save' => 'CAPSTrainingController@save',
    'admin/caps-training-delete' => 'CAPSTrainingController@delete',
    'admin/caps-employee-types' => 'CAPSEmployeeTypesController@list',
    'admin/caps-employee-types-edit' => 'CAPSEmployeeTypesController@edit',
    'admin/caps-employee-types-save' => 'CAPSEmployeeTypesController@save',
    'admin/caps-employee-types-delete' => 'CAPSEmployeeTypesController@delete',
    'admin/email-templates' => 'EmailTemplateAdminController@index',
    'admin/email-templates-edit' => 'EmailTemplateAdminController@edit',
    'admin/email-templates-save' => 'EmailTemplateAdminController@save',
    'eligibility-admin/blacklist-list' => 'EligibilityAdminController@blacklistList',
    'eligibility-admin/blacklist-edit' => 'EligibilityAdminController@blacklistEdit',
    'eligibility-admin/blacklist-save' => 'EligibilityAdminController@blacklistSave',
    'eligibility-admin/blacklist-delete' => 'EligibilityAdminController@blacklistDelete',
    'eligibility-admin/override-list' => 'EligibilityAdminController@overrideList',
    'eligibility-admin/override-edit' => 'EligibilityAdminController@overrideEdit',
    'eligibility-admin/override-save' => 'EligibilityAdminController@overrideSave',
    'eligibility-admin/override-delete' => 'EligibilityAdminController@overrideDelete',

    // Logs
    'log-maintenance/view' => 'LogMaintenanceController@view',
    'log-maintenance/list' => 'LogMaintenanceController@list',
    'log-maintenance/download' => 'LogMaintenanceController@download',
    'error-log/errors' => 'ErrorLogController@errors',
    'php-error-log/view' => 'PhpErrorLogController@view',
    'php-error-log/list' => 'PhpErrorLogController@list',
    'php-error-log/download' => 'PhpErrorLogController@download',

    // Diagnostics
    'diagnostics/index'          => 'DiagnosticsController@index',
    'diagnostics/sendTestEmail'  => 'DiagnosticsController@sendTestEmail',
    'diagnostics/throwException' => 'DiagnosticsController@throwException',
    'diagnostics/forceDbError'   => 'DiagnosticsController@forceDbError',
    'diagnostics/fatalError'     => 'DiagnosticsController@fatalError',

    // Language
    'lang/switch' => 'LangController@switch',
    'feedback/save' => 'FeedbackController@save',

    // Health
    'health'       => 'HealthController@index',
    'health/index' => 'HealthController@index',

    // Metrics
    'metrics/failed-logins' => 'MetricsController@failedLogins',
    'metrics/errors-trend'  => 'MetricsController@errorsTrend',
    'metrics/health'        => 'MetricsController@health',

    // Workflow
    'workflow/list'   => 'WorkflowController@list',
    'workflow/edit'   => 'WorkflowController@edit',
    'workflow/save'   => 'WorkflowController@save',
    'workflow/delete' => 'WorkflowController@delete',

    // Cards
    'portalcards'       => 'PortalCardsController@list',
    'portalcards/list'  => 'PortalCardsController@list',
    'portalcards/edit'  => 'PortalCardsController@edit',
    'portalcards/save'  => 'PortalCardsController@save',
    'portalcards/apply' => 'PortalCardsController@apply',
    'admin/portal-cards' => 'PortalCardsController@adminList',
    'admin/portal-cards-edit' => 'PortalCardsController@adminEdit',
    'admin/portal-cards-save' => 'PortalCardsController@adminSave',
    'admin/portal-cards-delete' => 'PortalCardsController@adminDelete',
    'cards/history'     => 'CardsController@history',
    'cards/request-limit-change' => 'CardsController@requestLimitChange',
    'cards/request-limit-change-agree' => 'CardsController@requestLimitChangeAgree',
    'cards/on-behalf-limit-change-start' => 'CardsController@onBehalfLimitChangeStart',
    'cards/limit-change-save' => 'CardsController@limitChangeSave',
    'cards/limit-change-delete' => 'CardsController@limitChangeDelete',
    'cards/limit-change-approve' => 'CardsController@limitChangeApprove',
    'cards/limit-change-approve-save' => 'CardsController@limitChangeApproveSave',
    'cards/limit-change-approver-email-check' => 'CardsController@limitChangeApproverEmailCheck',
    'cards/limit-change-scope-save' => 'CardsController@limitChangeScopeSave',
    'cards/my-limit-change-approvals' => 'CardsController@myLimitChangeApprovals',
    'cards/limit-change-approvals' => 'CardsController@limitChangeApprovals',
    'cards/change-address' => 'CardsController@changeAddress',
    'cards/cancel-card' => 'CardsController@cancelCard',
    'cards/change-address-save' => 'CardsController@changeAddressSave',
    'cards/cancel-card-submit' => 'CardsController@cancelCardSubmit',
    'cards/process-due-cancellations' => 'CardsController@processDueCancellations',
    'cards/change-requests' => 'CardsController@changeRequests',


    // Applications (Wizard)
    'applications/start'    => 'ApplicationsController@start',    // GET
    'applications/start-agree' => 'ApplicationsController@startAgree', // POST
    'applications/wizard'   => 'ApplicationsController@wizard',   // GET
    'applications/saveStep' => 'ApplicationsController@saveStep', // POST
    'applications/goStep'   => 'ApplicationsController@goStep',   // GET (optional)  
    
    // Applications (Single page)
    'applications/edit' => 'ApplicationsController@edit', // GET
    'applications/my-dpc-approvals' => 'ApplicationsController@myDpcApprovals',
    'applications/save' => 'ApplicationsController@save', // POST
    'applications/caps-options' => 'ApplicationsController@capsOptions', // GET (AJAX)
    'applications/wbs-search' => 'ApplicationsController@wbsSearch', // GET (AJAX)
    'applications/supervisor-search' => 'ApplicationsController@supervisorSearch', // GET (AJAX)
    'applications/approve' => 'ApplicationsController@approve', // GET
    'applications/approve-save' => 'ApplicationsController@approveSave', // POST
    'applications/delete' => 'ApplicationsController@delete', // POST
    
    // DataObject scope picker + setter
    'dataobjects/picker'   => 'DataObjectsController@picker',
    'dataobjects/select'   => 'DataObjectsController@select',
    'dataobjects/children' => 'DataObjectsController@children',

    // Fiscal context
    'context/set'          => 'ContextController@set',          // POST
    'context/listVersions' => 'ContextController@listVersions', // GET (AJAX)

   
    // Data Object Codes (PHP CRUD views)
    'dataobjectcodes/index'  => 'DataObjectCodesController@index',
    'dataobjectcodes/create' => 'DataObjectCodesController@create',
    'dataobjectcodes/edit'   => 'DataObjectCodesController@edit',
    'dataobjectcodes/save'   => 'DataObjectCodesController@save',   // POST
    'dataobjectcodes/delete' => 'DataObjectCodesController@delete', // POST
    'dataobjectcodes/export'   => 'DataObjectCodesController@exportXlsx',
    'dataobjectcodes/exportPdf' => 'DataObjectCodesController@exportPdf',


    // API (JSON)
    'dataobjectcodes.api/list'   => 'DataObjectCodesApiController@list',
    'dataobjectcodes.api/get'    => 'DataObjectCodesApiController@get',
    'dataobjectcodes.api/save'   => 'DataObjectCodesApiController@save',   // POST + CSRF
    'dataobjectcodes.api/delete' => 'DataObjectCodesApiController@delete', // POST + CSRF

    // Session variables
    'session/list'  => 'SessionController@list',
    'session/index' => 'SessionController@list', // optional alias

    // User Sessions
    'sessions/index'       => 'SessionsController@index',
    'sessions/forcelogout' => 'SessionsController@forceLogout',

    // Help
    'help/show' => 'HelpController@show',

    // DataObject Workflow Status
    'dataobjectworkflow/getStatus' => 'DataObjectWorkflowController@getStatus',
    'dataobjectworkflow/setStatus' => 'DataObjectWorkflowController@setStatus',

    // System Messages (Admin + Feed + Email queue)
    'systemmessages/index'      => 'SystemMessageAdminController@index',      // GET
    'systemmessages/createForm' => 'SystemMessageAdminController@createForm', // GET
    'systemmessages/create'     => 'SystemMessageAdminController@create',     // POST
    'systemmessages/editForm'   => 'SystemMessageAdminController@editForm',   // GET
    'systemmessages/update'     => 'SystemMessageAdminController@update',     // POST
    'systemmessages/preview'    => 'SystemMessageAdminController@preview',    // GET

    'systemmessages/feed'       => 'SystemMessageFeedController@feed',        // GET (JSON)
    'systemmessages/ack'        => 'SystemMessageFeedController@ack',         // POST

    'emailqueue/index'          => 'EmailQueueController@index',              // GET
    'emailqueue/recipients'     => 'EmailQueueController@recipients',         // GET
    'emailqueue/send'           => 'EmailQueueController@send',               // POST
    'emailqueue/process'        => 'EmailQueueController@process',            // GET (can be called by scheduler)

    'systemmessages/feed' => 'SystemMessageFeedController@feed',
    'systemmessages/ack'  => 'SystemMessageFeedController@ack',

    // DataObjectCode Access Management
    'dataobjectcodes/access'        => 'DataObjectCodeAccessController@index',
    'dataobjectcodes/access_form'   => 'DataObjectCodeAccessController@form',
    'dataobjectcodes/access_save'   => 'DataObjectCodeAccessController@save',
    'dataobjectcodes/access_revoke' => 'DataObjectCodeAccessController@revoke',
    'dataobjectcodes/access_report' => 'DataObjectCodeAccessController@userAccessReport',

    'dashboard/index' => 'DashboardController@index',
    'dashboard'       => 'DashboardController@index',
    'dashboard/flexdash' => 'DashboardController@flexdash',  // ← THIS IS THE NEW ONE

    //Analytics
    'analytics' => 'AnalyticsController@index'








];
