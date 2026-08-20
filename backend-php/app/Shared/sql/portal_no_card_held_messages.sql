SET NOCOUNT ON;

IF NOT EXISTS (SELECT 1 FROM dbo.tblSystemSettings WHERE SettingKey = 'PORTAL_NO_CARD_HELD_TEXT_DTC')
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'PORTAL_NO_CARD_HELD_TEXT_DTC',
            'Enter the message shown on the portal card list when no DTC card is currently held.',
            'string',
            'Portal Cards no-card-held helper text for DTC.',
            'system',
            SYSDATETIME()
        );
END;

IF NOT EXISTS (SELECT 1 FROM dbo.tblSystemSettings WHERE SettingKey = 'PORTAL_NO_CARD_HELD_TEXT_DPC')
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'PORTAL_NO_CARD_HELD_TEXT_DPC',
            'Enter the message shown on the portal card list when no DPC card is currently held.',
            'string',
            'Portal Cards no-card-held helper text for DPC.',
            'system',
            SYSDATETIME()
        );
END;

IF NOT EXISTS (SELECT 1 FROM dbo.tblSystemSettings WHERE SettingKey = 'PORTAL_NO_CARD_HELD_TEXT_DUAL')
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'PORTAL_NO_CARD_HELD_TEXT_DUAL',
            'Enter the message shown on the portal card list when no Dual card is currently held.',
            'string',
            'Portal Cards no-card-held helper text for Dual.',
            'system',
            SYSDATETIME()
        );
END;

IF NOT EXISTS (SELECT 1 FROM dbo.tblSystemSettings WHERE SettingKey = 'PORTAL_NO_CARD_HELD_TEXT_LODGE')
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'PORTAL_NO_CARD_HELD_TEXT_LODGE',
            'Enter the message shown on the portal card list when no Lodge card is currently held.',
            'string',
            'Portal Cards no-card-held helper text for Lodge.',
            'system',
            SYSDATETIME()
        );
END;
