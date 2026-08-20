<?php
return [
    // Interface générale
    'menu'        => 'Menu',
    'navigation'  => 'Navigation',
    'user'        => 'Utilisateur',
    'account'     => 'Compte',
    'logout'      => 'Déconnexion',
    'guest'       => 'Invité',
    'login'       => 'Connexion',
    'home'        => 'Accueil',
    'back'        => 'Retour',
    'close'       => 'Fermer',

    // Erreurs & messages (global)
    'error_generic_title'   => 'Désolé, une erreur est survenue',
    'error_generic_message' => 'Notre équipe technique a été avertie. Veuillez réessayer plus tard.',
    'application_error'     => 'Erreur de l’application',
    'fatal_error'           => 'Erreur fatale',
    'method_not_allowed'    => 'Méthode non autorisée',
    'security_check_failed' => 'Échec de la vérification de sécurité.',
    'reference_id'          => 'ID de référence',

    // Accueil
    'home_title'   => 'Bienvenue à CBMSv21',
    'hello_user'   => 'Bonjour, <strong>:user</strong>.',

    // Auth / Connexion
    'username_label'             => 'Nom d’utilisateur',
    'password_label'             => 'Mot de passe',
    'login_title'                => 'Connexion',
    'login_submit'               => 'Connexion',
    'username_password_required' => 'Nom d’utilisateur et mot de passe requis.',
    'invalid_login'              => 'Nom d’utilisateur ou mot de passe invalide.',
    'account_disabled'           => 'Le compte est désactivé.',
    'welcome_user'               => 'Bienvenue, :user !',
    'session_expired_idle'       => 'Votre session a expiré après inactivité.',
    'session_expired_absolute'   => 'Votre session a expiré en raison de la durée maximale autorisée.',
    'logged_out'                 => 'Vous avez été déconnecté.',
    'please_login'               => 'Veuillez vous connecter.',
    'login_password_notice'      => 'Votre mot de passe n’est jamais stocké par le navigateur.',

    // Compte & Accès
    'account_access'        => 'Compte et accès',
    'account_access_intro'  => 'Consultez vos rôles et permissions actuels. Utilisez « Rafraîchir l’accès » pour vous resynchroniser depuis la base de données.',
    'user_id'               => 'ID utilisateur',
    'last_refreshed'        => 'Dernier rafraîchissement',
    'not_refreshed_yet'     => '(pas encore rafraîchi)',
    'roles'                 => 'Rôles',
    'no_roles_in_session'   => 'Aucun rôle dans la session.',
    'permissions'           => 'Permissions',
    'no_perms_in_session'   => 'Aucune permission dans la session.',
    'refresh_access'        => 'Rafraîchir l’accès',
    'access_refreshed'      => 'Accès rafraîchi',
    'at_time'               => 'à :time',
    'db_label'              => 'BD',
    'back_to_account'       => 'Retour au compte',
    'before'                => 'Avant',
    'after'                 => 'Après',
    'none'                  => 'Aucun',
    'added'                 => 'Ajouté',
    'removed'               => 'Supprimé',
    'refresh_again'         => 'Rafraîchir à nouveau',

    // Paramètres système
    'system_settings'       => 'Paramètres du système',
    'settings'              => 'Paramètres',
    'key'                   => 'Clé',
    'value'                 => 'Valeur',
    'type'                  => 'Type',
    'description'           => 'Description',
    'updated_by'            => 'Mis à jour par',
    'updated_at'            => 'Mis à jour le',
    'action'                => 'Action',
    'save'                  => 'Enregistrer',
    'string'                => 'Texte',
    'bool'                  => 'Booléen',
    'int'                   => 'Entier',
    'json'                  => 'JSON',
    'missing_setting_key'   => 'Clé de paramètre manquante.',
    'setting_saved'         => "Paramètre « :key » enregistré.",
    'save_failed_detail'    => 'Échec de l’enregistrement : :msg',

    // Diagnostics
    'diagnostics_title'          => 'Diagnostics du système',
    'diagnostics_database'       => 'Base de données',
    'diagnostics_log_test'       => 'Test du journal',
    'diagnostics_mail_test'      => 'Test de courriel',
    'diagnostics_force_db_error' => 'Forcer une erreur BD',
    'send_test_email'            => 'Envoyer un courriel de test',
    'trigger_db_error'           => 'Déclencher une erreur BD',
    'log_entries_written'        => 'Entrées écrites dans app.log',

    // Statuts
    'ok'       => 'OK',
    'fail'     => 'Échec',
    'error'    => 'Erreur',
    'enabled'  => 'Activé',
    'disabled' => 'Désactivé',

    // Requête lente
    'slow_request_threshold'       => 'Seuil de requête lente',
    'slow_request_alerts_enabled'  => 'Alertes de requête lente',

    // Étiquettes du menu
    'menu_home'               => 'Accueil',
    'menu_strategy'           => 'Stratégie',
    'menu_strategy_overview'  => 'Vue d’ensemble',
    'menu_fiscal'             => 'Cadre budgétaire',
    'menu_fiscal_overview'    => 'Vue d’ensemble',
    'menu_fiscal_envelope'    => 'Enveloppe',
    'menu_fiscal_ceilings'    => 'Plafonds',
    'menu_estimates'          => 'Prévisions',
    'menu_rates'              => 'Taux',
    'menu_budgets'            => 'Budgets',
    'menu_execution'          => 'Exécution',
    'menu_execution_warrants' => 'Mandats',
    'menu_execution_realloc'  => 'Réaffectation',
    'menu_execution_virements'=> 'Virements',
    'menu_reports'            => 'Rapports',
    'menu_analytics'          => 'Analytique',
    'menu_admin'              => 'Administration',
    'menu_users'              => 'Utilisateurs',
    'menu_roles'              => 'Rôles et permissions',
    'menu_audit'              => 'Journal d’audit',
    'menu_diagnostics'        => 'Diagnostics',
    'menu_session_vars'       => 'Variables de session',
    'menu_logs'               => 'Journaux',
    'menu_app_log'            => 'Journal d’application',
    'menu_app_log_archives'   => 'Archives du journal d’application',
    'menu_error_log'          => 'Journal des erreurs',
    'menu_php_error'          => 'Journal des erreurs PHP',
    'menu_php_error_archives' => 'Archives des erreurs PHP',
    'menu_config'             => 'Configuration',
    'menu_config_syssettings' => 'Paramètres système',
    'menu_config_rates'       => 'Taux',
    // Nouveau pour l’entrée de menu (optionnel)
    'menu_health_check'       => 'Vérification d’intégrité',

    // Liste d’audit
    'audit_log_title' => 'Journal d’audit',
    'filters'         => 'Filtres',
    'search'          => 'Rechercher',
    'entity'          => 'Entité',
    'all'             => 'Toutes',
    'start_date'      => 'Date de début',
    'end_date'        => 'Date de fin',
    'page_size'       => 'Taille de page',
    'apply'           => 'Appliquer',
    'reset'           => 'Réinitialiser',
    'event_time'      => 'Heure de l’événement',
    'details'         => 'Détails',
    'ip'              => 'IP',
    'fiscal_year'     => 'Exercice financier',
    'version'         => 'Version',
    'view'            => 'Voir',
    'prev'            => 'Préc.',
    'next'            => 'Suiv.',

    // Santé (de base)
    'health_check'     => 'Vérification d’intégrité',
    'environment'      => 'Environnement',
    'version'          => 'Version',
    'server_time'      => 'Heure du serveur',
    'database_status'  => 'État de la base de données',
    'healthy'          => 'Sain',
    'unhealthy'        => 'Défaillant',
    'actions'          => 'Actions',
    'refresh'          => 'Actualiser',
    'view_json'        => 'Voir JSON',

    // Santé (étendue / liste des vérifications)
    'overall_status'   => 'Statut global',
    'created_at'       => 'Créé le',
    'request_id_short' => 'ID req.',
    'checks'           => 'Vérifications',
    'status'           => 'Statut',
    'message'          => 'Message',
    'meta'             => 'Méta',
    'pass'             => 'Réussi',
    'degraded'         => 'Dégradé',
    'unknown'          => 'Inconnu',
    'optional'         => 'Optionnel',
    'skipped'          => 'Ignoré',
    'value'            => 'Valeur',
    'menu_health'        => 'Vérification d’intégrité',
    'menu_health_check'  => 'Vérification d’intégrité', // alias facultatif

    // Libellés des vérifications
    'php_extensions'      => 'Extensions PHP',
    'log_dir_writable'    => 'Dossier des journaux inscriptible',
    'session_roundtrip'   => 'Lecture/écriture de session',
    'disk_free_space'     => 'Espace disque libre',
    'database_latency'    => 'Latence de la base de données',
    'time_drift'          => 'Dérive heure BD vs appli',
    'smtp_reachability'   => 'Joignabilité SMTP',
    'debug_sanity_check'  => 'Cohérence du mode debug',

    // Libellés méta communs
    'driver'          => 'Pilote',
    'required'        => 'Requis',
    'path'            => 'Chemin',
    'min_required_mb' => 'Min requis (Mo)',
    'db_epoch_sec'    => 'Époque BD (s)',
    'php_epoch_sec'   => 'Époque PHP (s)',
    'threshold_sec'   => 'Seuil (s)',

    'too_many_attempts'   => 'Trop de tentatives. Réessayez dans :minutes minute(s).',
    'remaining_attempts'  => 'Il reste :count tentative(s).',
    'unlock_login'        => 'Déverrouiller la connexion',
    'ip_optional'         => 'IP (optionnelle)',
    'lock_reset_success'  => 'Verrou de connexion réinitialisé pour :user (:count élément(s) supprimé(s)).',
    'lock_reset_fail'     => 'Échec du déverrouillage : :msg',
    'unlock_help'         => 'Efface les entrées de limitation/verrouillage pour cet utilisateur. Indiquez une IP pour ne nettoyer que cette IP ; laissez vide pour toutes les IP de l’utilisateur.',

    'account_locked_permanent' => 'Votre compte a été verrouillé de façon permanente. Veuillez contacter un administrateur.',

    // User administration
    'email'               => 'Courriel',
    'last_login'          => 'Dernière connexion',
    'last_login_ip'       => 'IP de la dernière connexion',
    'failed_attempts'     => 'Tentatives échouées',
    'last_failed_login'   => 'Dernière tentative échouée',
    'force_reset'         => 'Réinitialisation forcée',
    'yes'                 => 'Oui',
    'no'                  => 'Non',
    'edit'                => 'Modifier',
    'confirm_unlock_user' => 'Êtes-vous sûr de vouloir déverrouiller cet utilisateur ?',

    'active'        => 'Actif',
    'date_created'  => 'Date de création',
    'date_updated'  => 'Date de mise à jour',

   // ==========================
// Utilisateurs (écrans Admin)
// ==========================
'menu_users'             => 'Utilisateurs',
'create_user'            => 'Créer un utilisateur',
'edit_user'              => 'Modifier un utilisateur',
'user_created'           => "Utilisateur ':user' créé.",
'user_updated'           => "Utilisateur ':user' mis à jour.",
'user_save_failed'       => "Échec de l'enregistrement de l'utilisateur",
'invalid_user'           => 'Utilisateur invalide',

// Champs utilisateur
'first_name'             => 'Prénom',
'last_name'              => 'Nom',
'display_name'           => "Nom d'affichage",
'phone'                  => 'Téléphone',
'department'             => 'Département',
'job_title'              => 'Poste',
'notes'                  => 'Notes',
'role'                   => 'Rôle',
'must_change_password'   => 'Doit changer le mot de passe',
'force_password_reset'   => 'Forcer la réinitialisation du mot de passe',
'is_2fa_enabled'         => '2FA activée',
'tfa_method'             => 'Méthode 2FA',

// Filtres et pagination de la liste des utilisateurs
'all_departments'        => 'Tous les départements',
'all_status'             => 'Tous les statuts',
'filter'                 => 'Filtrer',
'no_records_found'       => 'Aucun enregistrement trouvé',
'showing'                => 'Affichage',
'of'                     => 'de',
'users'                  => 'utilisateurs',

// Statuts du compte
'account_status'         => 'Statut du compte',
'active'                 => 'Actif',
'disabled'               => 'Désactivé',
'locked_until'           => 'Verrouillé jusqu’au',
'permanently_locked'     => 'Verrouillé définitivement',

// Boutons / actions
'save'                   => 'Enregistrer',
'back'                   => 'Retour',
'action'                 => 'Action',
'unlock_login'           => 'Déverrouiller la connexion',
'confirm_unlock_user'    => 'Êtes-vous sûr de vouloir déverrouiller cet utilisateur ?',

'access_denied'                => 'Accès refusé',
'access_denied_missing_any'    => 'Accès refusé (il manque au moins une des permissions : :perms)',
'access_denied_missing_all'    => 'Accès refusé (il manque toutes les permissions : :perms)',

// --- Ajouts pour les rapports PDF ---
'user_details'           => 'Détails de l’utilisateur',
'user_meta_data'         => 'Métadonnées de l’utilisateur',
'assign_roles'           => 'Rôles assignés',
'assigned'               => 'Attribué',
'user_report'            => 'Rapport utilisateur',
'generated_by'           => 'Généré par',
'generated_on'           => 'Généré le',


    // ==========================================
    // Codes d’objets de données (écrans Admin)
    // ==========================================

    // Titres / en-têtes
    'docodes_title'        => 'Codes d’objets de données',
    'docodes_edit_title'   => 'Modifier un code d’objet de données',
    'docodes_add_title'    => 'Ajouter un code d’objet de données',

    // Filtres / placeholders
    'docodes_search_ph'    => 'Rechercher code / nom / description',
    'all_types'            => 'Tous les types',

    // Libellés de colonnes
    'code'                 => 'Code',
    'name'                 => 'Nom',
    'parent'               => 'Parent',
    'parent_code'          => 'Code parent',
    'type_id'              => 'TypeID',
    'updated'              => 'Mis à jour',
    'records'              => 'enregistrements',

    // Actions / boutons (communs)
    'add'                  => 'Ajouter',
    'delete'               => 'Supprimer',
    'back_to_list'         => 'Retour à la liste',
    'pagination'           => 'Pagination',
    'page'                 => 'page',
    'this_item'            => 'cet élément',
    'confirm_delete'       => 'Supprimer',
    'confirm_delete_item'  => 'Êtes-vous sûr de vouloir supprimer',
    'cannot_undo'          => 'Cette action est irréversible.',

    // Statuts supplémentaires
    'inactive'             => 'Inactif',

    // Formulaires (aide/erreurs)
    'docodes_code_ph'      => 'ex. DEP001',
    'docodes_code_help'    => '20 caractères max. Lettres, chiffres, underscore, tiret, point.',
    'docodes_code_invalid' => 'Veuillez saisir un code valide.',
    'docodes_name_ph'      => 'ex. Ministère de l’Éducation',
    'docodes_name_invalid' => 'Le nom est requis (100 caractères max).',
    'docodes_parent_help'  => 'Doit être un code valide dans le même exercice financier. Laisser vide pour le niveau racine.',
    'docodes_parent_invalid'=> 'Code parent invalide.',
    'docodes_type_invalid' => 'Veuillez sélectionner un type.',
    'docodes_status_invalid'=> 'Veuillez choisir un statut valide.',
    'docodes_desc_invalid' => 'Description invalide.',
    'docodes_parent_same'  => 'Le parent ne peut pas être identique au code.',

    // Indications
    'docodes_create_hint'  => 'Création d’un nouveau code pour le contexte d’exercice financier en cours.',
    'docodes_edit_hint'    => 'Modification de :code. Les changements s’appliquent au contexte d’exercice financier en cours.',
    'docode'               => 'Code d’objet de données',
];
