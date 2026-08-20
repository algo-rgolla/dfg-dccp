IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblSystemSettings
    WHERE SettingKey = 'PORTAL_CONTACT_MSG'
)
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'PORTAL_CONTACT_MSG',
            '',
            'string',
            'Contact details appended to portal warning messages when users need help resolving configuration issues.',
            'system',
            SYSDATETIME()
        );
END
GO
