IF NOT EXISTS (SELECT 1 FROM dbo.tblSystemSettings WHERE SettingKey = 'NEW_USER_ACTIVATION_ENABLED')
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'NEW_USER_ACTIVATION_ENABLED',
            '1',
            'bool',
            'Controls whether new users can start onboarding and activate accounts. When off, existing activated users can still log in.',
            'system',
            SYSDATETIME()
        );
END
