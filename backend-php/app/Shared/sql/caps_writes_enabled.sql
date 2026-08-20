IF NOT EXISTS (SELECT 1 FROM dbo.tblSystemSettings WHERE SettingKey = 'CAPS_WRITES_ENABLED')
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'CAPS_WRITES_ENABLED',
            '1',
            'bool',
            'Controls whether CCPortal writes outbound records into the CAPS database. Turn off to pause CAPS exports while keeping portal workflows available.',
            'system',
            SYSDATETIME()
        );
END
