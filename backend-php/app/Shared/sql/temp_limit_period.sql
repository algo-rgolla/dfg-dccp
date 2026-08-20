IF NOT EXISTS (SELECT 1 FROM dbo.tblSystemSettings WHERE SettingKey = 'TEMP_LIMIT_PERIOD')
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, Description, UpdatedAt, UpdatedBy)
    VALUES
        (
            'TEMP_LIMIT_PERIOD',
            '48',
            'Maximum number of months allowed between Period of Change From and Period of Change To for temporary limit changes.',
            SYSUTCDATETIME(),
            NULL
        );
END;
