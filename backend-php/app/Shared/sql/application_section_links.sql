SET NOCOUNT ON;

IF NOT EXISTS (SELECT 1 FROM dbo.tblSystemSettings WHERE SettingKey = 'ApplicationBrandingLink')
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'ApplicationBrandingLink',
            '',
            'string',
            'Application Branding section managed link URL.',
            'system',
            SYSDATETIME()
        );
END;

IF NOT EXISTS (SELECT 1 FROM dbo.tblSystemSettings WHERE SettingKey = 'ApplicationBrandingLinkLabel')
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'ApplicationBrandingLinkLabel',
            'Branding',
            'string',
            'Application Branding section managed link label.',
            'system',
            SYSDATETIME()
        );
END;
