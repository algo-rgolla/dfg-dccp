IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblSystemSettings
    WHERE SettingKey = 'LOGIN_AGREEMENT_ENABLED'
)
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'LOGIN_AGREEMENT_ENABLED',
            '0',
            'bool',
            'Controls whether users must accept a portal agreement after each successful login.',
            'system',
            SYSDATETIME()
        );
END
GO

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblSystemSettings
    WHERE SettingKey = 'LOGIN_AGREEMENT_TEXT'
)
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'LOGIN_AGREEMENT_TEXT',
            'By continuing into CC Portal, you acknowledge that access is permitted only for authorised business purposes, that portal information must be handled in accordance with departmental policy, and that all activity may be monitored and audited.',
            'string',
            'Agreement text shown to users after every successful login when LOGIN_AGREEMENT_ENABLED is turned on.',
            'system',
            SYSDATETIME()
        );
END
