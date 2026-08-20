IF NOT EXISTS (SELECT 1 FROM dbo.tblSystemSettings WHERE SettingKey = 'SHOW_ACTIVATION_LINK_INSTEAD_OF_EMAIL')
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'SHOW_ACTIVATION_LINK_INSTEAD_OF_EMAIL',
            '0',
            'bool',
            'Controls whether activation uses a displayed activation URL instead of sending the activation link via email.',
            'system',
            SYSDATETIME()
        );
END
