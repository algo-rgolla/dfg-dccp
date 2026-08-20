SET NOCOUNT ON;

IF NOT EXISTS (SELECT 1 FROM dbo.tblSystemSettings WHERE SettingKey = 'PORTAL_CARD_HOVER_TEXT_DTC')
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'PORTAL_CARD_HOVER_TEXT_DTC',
            'Enter the hover text shown on the portal card title for DTC.',
            'string',
            'Portal Cards title hover text for DTC.',
            'system',
            SYSDATETIME()
        );
END;

IF NOT EXISTS (SELECT 1 FROM dbo.tblSystemSettings WHERE SettingKey = 'PORTAL_CARD_HOVER_TEXT_DPC')
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'PORTAL_CARD_HOVER_TEXT_DPC',
            'Enter the hover text shown on the portal card title for DPC.',
            'string',
            'Portal Cards title hover text for DPC.',
            'system',
            SYSDATETIME()
        );
END;

IF NOT EXISTS (SELECT 1 FROM dbo.tblSystemSettings WHERE SettingKey = 'PORTAL_CARD_HOVER_TEXT_DUAL')
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'PORTAL_CARD_HOVER_TEXT_DUAL',
            'Enter the hover text shown on the portal card title for Dual.',
            'string',
            'Portal Cards title hover text for Dual.',
            'system',
            SYSDATETIME()
        );
END;

IF NOT EXISTS (SELECT 1 FROM dbo.tblSystemSettings WHERE SettingKey = 'PORTAL_CARD_HOVER_TEXT_LODGE')
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'PORTAL_CARD_HOVER_TEXT_LODGE',
            'Enter the hover text shown on the portal card title for Lodge.',
            'string',
            'Portal Cards title hover text for Lodge.',
            'system',
            SYSDATETIME()
        );
END;
