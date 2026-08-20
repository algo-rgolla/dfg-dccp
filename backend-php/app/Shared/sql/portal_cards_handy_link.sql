SET NOCOUNT ON;

IF NOT EXISTS (SELECT 1 FROM dbo.tblSystemSettings WHERE SettingKey = 'PORTAL_CARDS_HANDY_LINK_TEXT')
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'PORTAL_CARDS_HANDY_LINK_TEXT',
            'Handy Link - Before you apply for a Credit Card',
            'string',
            'Portal Cards handy link display text shown under the intro text.',
            'system',
            SYSDATETIME()
        );
END;

IF NOT EXISTS (SELECT 1 FROM dbo.tblSystemSettings WHERE SettingKey = 'PORTAL_CARDS_HANDY_LINK_URL')
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'PORTAL_CARDS_HANDY_LINK_URL',
            '',
            'string',
            'Portal Cards handy link URL shown under the intro text.',
            'system',
            SYSDATETIME()
        );
END;
