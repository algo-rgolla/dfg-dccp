<?php
declare(strict_types=1);

/**
 * Config-driven, role/permission-aware menu.
 * Keys: label, route?, icon, roles[], perms[], active[], children[].
 */

return [

  // --- Home ---
  [
    'label'  => __t('menu_home'),
    'route'  => 'home/index',
    'icon'   => 'house',
    'active' => ['home/*'],
    'roles'  => [],
    'perms'  => [],
  ],

  // --- Strategy ---
  [
    'label' => __t('menu_strategy'),
    'icon'  => 'flag',
    'roles' => ['strategy'],
    'perms' => [],
    'children' => [
      [
        'label' => __t('menu_strategy_overview'),
        'route' => 'strategy/index',
        'icon'  => 'flag',
        'roles' => ['strategy'],
        'perms' => [],
      ],
    ],
  ],

  // --- Fiscal Framework ---
  [
    'label' => __t('menu_fiscal'),
    'icon'  => 'bank',
    'roles' => ['strategy'],
    'perms' => [],
    'children' => [
      ['label'=>__t('menu_fiscal_overview'),'route'=>'strategy/index','icon'=>'diagram-3','roles'=>['strategy'],'perms'=>[]],
      ['label'=>__t('menu_fiscal_envelope'),'route'=>'strategy/index','icon'=>'diagram-3','roles'=>['strategy'],'perms'=>[]],
      ['label'=>__t('menu_fiscal_ceilings'),'route'=>'strategy/index','icon'=>'diagram-3','roles'=>['strategy'],'perms'=>[]],
    ],
  ],

  // --- Estimates ---
  [
    'label' => __t('menu_estimates'),
    'icon'  => 'calculator',
    'roles' => ['estimates'],
    'perms' => [],
    'children' => [
      [
        'label'  => __t('menu_rates'),
        'route'  => 'rates/list',
        'icon'   => 'currency-dollar',
        'active' => ['rates/*'],
        'roles'  => ['estimates'],
        'perms'  => [],
      ],
      [
        'label' => __t('menu_budgets'),
        'icon'  => 'wallet2',
        'roles' => ['estimates'],
        'perms' => [],
        'children' => [
          [
            'label' => '2025',
            'icon'  => 'calendar3',
            'roles' => ['estimates'],
            'perms' => [],
            'children' => [
              ['label'=>'Department A','route'=>'budgets/2025/depA','icon'=>'building','roles'=>['estimates'],'perms'=>[]],
              ['label'=>'Department B','route'=>'budgets/2025/depB','icon'=>'building','roles'=>['estimates'],'perms'=>[]],
            ],
          ],
          [
            'label' => '2024',
            'icon'  => 'calendar3',
            'roles' => ['estimates'],
            'perms' => [],
            'children' => [
              ['label'=>'Department A','route'=>'budgets/2024/depA','icon'=>'building','roles'=>['estimates'],'perms'=>[]],
              ['label'=>'Department B','route'=>'budgets/2024/depB','icon'=>'building','roles'=>['estimates'],'perms'=>[]],
            ],
          ],
        ],
      ],
    ],
  ],

  // --- Reports ---
  [
    'label' => __t('menu_reports'),
    'icon'  => 'file-earmark-text',
    'roles' => ['reports'],
    'perms' => [],
    'children' => [],
  ],

// --- Analytics ---
[
  'label'  => __t('menu_analytics'),
  'route'  => 'analytics',          // ✅ REQUIRED
  'icon'   => 'bar-chart',
  'active' => ['analytics*'],
  'roles'  => ['admin','finance','analytics'],
  'perms'  => ['ANALYTICS_VIEW'],
  'children' => [
    [
      'label'  => 'Overview',
      'route'  => 'analytics',
      'icon'   => 'speedometer',
      'roles'  => ['admin','finance','analytics'],
      'perms'  => ['ANALYTICS_VIEW'],
    ],
  ],
],
  // --- Portal Administration ---
  [
    'label' => 'Portal Administration',
    'icon'  => 'sliders2',
    'roles' => ['admin'],
    'perms' => [],
    'children' => [
      [
        'label' => 'Overview',
        'icon'  => 'speedometer2',
        'roles' => ['admin'],
        'perms' => [],
        'children' => [
          ['label'=>'Management Summary','route'=>'admin/management-summary','icon'=>'bar-chart','active'=>['admin/management-summary*'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'User Feedback','route'=>'admin/feedback','icon'=>'star','active'=>['admin/feedback*'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'Schema Updates','route'=>'admin/schema-updates','icon'=>'database-gear','active'=>['admin/schema-updates*'],'roles'=>['admin'],'perms'=>[]],
        ],
      ],
      [
        'label' => 'Applications',
        'icon'  => 'clipboard-data',
        'roles' => ['admin'],
        'perms' => [],
        'children' => [
          ['label'=>'Applications','route'=>'admin/applications','icon'=>'card-list','active'=>['admin/applications*'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'Application Types','route'=>'admin/application-types','icon'=>'collection','active'=>['admin/application-types*'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'DPC Application Approvals','route'=>'admin/dpc-application-approvals','icon'=>'clipboard-check','active'=>['admin/dpc-application-approvals*'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'Limit Change Approvals','route'=>'cards/limit-change-approvals','icon'=>'clipboard-check','active'=>['cards/limit-change-approvals'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'Workflow Approver Positions','route'=>'admin/workflow-approver-positions','icon'=>'person-workspace','active'=>['admin/workflow-approver-positions*'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'Workflow Approval Rules','route'=>'admin/workflow-approval-rules','icon'=>'diagram-3','active'=>['admin/workflow-approval-rules*'],'roles'=>['admin'],'perms'=>[]],
        ],
      ],
      [
        'label' => 'Cards And Eligibility',
        'icon'  => 'credit-card',
        'roles' => ['admin'],
        'perms' => [],
        'children' => [
          ['label'=>'Portal Cards','route'=>'admin/portal-cards','icon'=>'credit-card-2-front','active'=>['admin/portal-cards*'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'Change Requests','route'=>'admin/change-requests','icon'=>'list-check','active'=>['admin/change-requests*'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'Pending Cancellations','route'=>'admin/pending-card-cancellations','icon'=>'calendar-check','active'=>['admin/pending-card-cancellations*'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'Limit Change Reasons','route'=>'admin/limit-change-reasons','icon'=>'chat-left-text','active'=>['admin/limit-change-reasons*'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'Cancel Card Reasons','route'=>'admin/cancel-card-reasons','icon'=>'x-circle','active'=>['admin/cancel-card-reasons*'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'Restricted List','route'=>'admin/application-blacklist','icon'=>'person-x','active'=>['admin/application-blacklist*','eligibility-admin/blacklist-*'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'Eligibility Overrides','route'=>'admin/eligibility-overrides','icon'=>'person-check','active'=>['admin/eligibility-overrides*','eligibility-admin/override-*'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'Default Addresses','route'=>'admin/default-addresses','icon'=>'house-gear','active'=>['admin/default-addresses*'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'Custom Suburbs','route'=>'admin/suburbs','icon'=>'geo-alt','active'=>['admin/suburbs*'],'roles'=>['admin'],'perms'=>[]],
        ],
      ],
      [
        'label' => 'CAPS And Communications',
        'icon'  => 'broadcast',
        'roles' => ['admin'],
        'perms' => [],
        'children' => [
          ['label'=>'ProMaster Users','route'=>'admin/caps-promaster-users','icon'=>'people','active'=>['admin/caps-promaster-users*'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'CAPS Training','route'=>'admin/caps-training','icon'=>'mortarboard','active'=>['admin/caps-training*'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'CAPS Employee Types','route'=>'admin/caps-employee-types','icon'=>'person-badge','active'=>['admin/caps-employee-types*'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'Email Templates','route'=>'admin/email-templates','icon'=>'envelope','active'=>['admin/email-templates*'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'Portal Messages','route'=>'systemmessages/index','icon'=>'megaphone','active'=>['systemmessages/*'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'Email Queue','route'=>'emailqueue/index','icon'=>'envelope-paper','active'=>['emailqueue/*'],'roles'=>['admin'],'perms'=>[]],
        ],
      ],
    ],
  ],

  // --- Diagnostics ---
  [
    'label' => 'Diagnostics',
    'icon'  => 'activity',
    'roles' => ['admin'],
    'perms' => [],
    'children' => [
      ['label'=>__t('menu_diagnostics'),'route'=>'diagnostics/index','icon'=>'activity','active'=>['diagnostics/*'],'roles'=>['admin'],'perms'=>[]],
      [
        'label'  => __t('menu_active_sessions'),
        'route'  => 'sessions/index',
        'icon'   => 'activity',
        'active' => ['sessions/*'],
        'roles'  => ['admin'],
        'perms'  => ['SESSION_VIEW'],
      ],
      [
        'label' => __t('menu_metrics'),
        'icon'  => 'graph-up',
        'roles' => ['admin'],
        'perms' => [],
        'children' => [
          ['label'=>__t('menu_metrics_failed_logins'),'route'=>'metrics/failed-logins','icon'=>'shield-exclamation','active'=>['metrics/failed-logins'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>__t('menu_metrics_errors'),'route'=>'metrics/errors-trend','icon'=>'activity','active'=>['metrics/errors-trend'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>__t('menu_metrics_health'),'route'=>'metrics/health','icon'=>'heart-pulse','active'=>['metrics/health'],'roles'=>['admin'],'perms'=>[]],
        ],
      ],
      ['label'=>__t('menu_health'),'route'=>'health/index','icon'=>'heart-pulse','active'=>['health/*'],'roles'=>['admin'],'perms'=>[]],
      ['label'=>__t('menu_session_vars'),'route'=>'session/list','icon'=>'person-badge','active'=>['session/*'],'roles'=>['admin'],'perms'=>[]],
    ],
  ],

  // --- Deployments ---
  [
    'label'  => 'Deployments',
    'icon'   => 'box-arrow-up',
    'roles'  => [],
    'perms'  => ['DEPLOY_PACKAGES'],
    'children' => [
      [
        'label'  => 'Release Packages',
        'route'  => 'admin/release-packages',
        'icon'   => 'box-arrow-up',
        'active' => ['admin/release-packages*'],
        'roles'  => [],
        'perms'  => ['DEPLOY_PACKAGES'],
      ],
      [
        'label'  => 'Deployment History',
        'route'  => 'admin/release-package-history',
        'icon'   => 'clock-history',
        'active' => ['admin/release-package-history*'],
        'roles'  => [],
        'perms'  => ['DEPLOY_PACKAGES'],
      ],
    ],
  ],

  // --- Administration ---
  [
    'label' => __t('menu_admin'),
    'icon'  => 'gear',
    'roles' => ['admin'],
    'perms' => [],
    'children' => [

      ['label'=>__t('menu_users'),'route'=>'users/list','icon'=>'people','active'=>['users/*'],'roles'=>['admin'],'perms'=>[]],
      
      ['label'=>__t('menu_roles'),'route'=>'roles/list','icon'=>'shield-lock','active'=>['roles/*'],'roles'=>['admin'],'perms'=>[]],
      ['label'=>__t('menu_audit'),'route'=>'audit/list','icon'=>'journal-text','active'=>['audit/*'],'roles'=>['admin'],'perms'=>[]],
      [
        'label' => 'Logs',
        'icon'  => 'file-earmark-text',
        'roles' => ['admin'],
        'perms' => [],
        'children' => [
          ['label'=>'Application Log','route'=>'log-maintenance/view','icon'=>'file-text','active'=>['log-maintenance/view'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'Application Log Archive','route'=>'log-maintenance/list','icon'=>'archive','active'=>['log-maintenance/list'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'Application Errors','route'=>'error-log/errors','icon'=>'exclamation-triangle','active'=>['error-log/*'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'PHP Error Log','route'=>'php-error-log/view','icon'=>'bug','active'=>['php-error-log/view'],'roles'=>['admin'],'perms'=>[]],
          ['label'=>'PHP Error Log Archive','route'=>'php-error-log/list','icon'=>'collection','active'=>['php-error-log/list'],'roles'=>['admin'],'perms'=>[]],
        ],
      ],
    ],
  ],

  // --- Configuration ---
  [
    'label' => __t('menu_config'),
    'icon'  => 'sliders',
    'roles' => ['config','admin'],
    'perms' => [],
    'children' => [
      ['label'=>__t('menu_config_syssettings'),'route'=>'system-settings/list','icon'=>'toggles','active'=>['system-settings/*'],'roles'=>['admin'],'perms'=>[]],
      ['label'=>__t('menu_config_rates'),'route'=>'rates/list','icon'=>'currency-dollar','active'=>['rates/*'],'roles'=>['config'],'perms'=>[]],
    ],
  ],

];
