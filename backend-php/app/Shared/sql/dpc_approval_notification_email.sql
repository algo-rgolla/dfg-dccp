IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblSystemSettings
    WHERE SettingKey = 'DPC_APPROVAL_NOTIFICATION_EMAIL'
)
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'DPC_APPROVAL_NOTIFICATION_EMAIL',
            '',
            'string',
            'Email address notified when a DPC application is submitted for supervisor approval.',
            'system',
            SYSDATETIME()
        );
END
GO
