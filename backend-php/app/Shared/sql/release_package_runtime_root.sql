/*
    Adds the RELEASE_PACKAGE_RUNTIME_ROOT system setting used by the
    controlled deployment feature to override the staging/backup base folder.

    Precedence:
    1. tblSystemSettings.RELEASE_PACKAGE_RUNTIME_ROOT
    2. .env RELEASE_PACKAGE_RUNTIME_ROOT
    3. %TEMP%\ccportal_release_packages
*/

IF OBJECT_ID('dbo.tblSystemSettings', 'U') IS NULL
BEGIN
    RAISERROR('dbo.tblSystemSettings does not exist.', 16, 1);
    RETURN;
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblSystemSettings
    WHERE SettingKey = 'RELEASE_PACKAGE_RUNTIME_ROOT'
)
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'RELEASE_PACKAGE_RUNTIME_ROOT',
            '',
            'text',
            'Optional override for the release package staging and backup base folder. Leave blank to use the env file or server temp path fallback.',
            'schema-update',
            SYSDATETIME()
        );
END;
