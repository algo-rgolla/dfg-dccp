USE [CCPortal]
GO

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblSystemSettings
    WHERE SettingKey = 'SMTP_FROM_NAME'
)
BEGIN
    INSERT INTO dbo.tblSystemSettings (
        SettingKey,
        SettingValue,
        Description,
        UpdatedAt
    )
    VALUES (
        'SMTP_FROM_NAME',
        'Defence Credit Card Portal',
        'Display name used in the From header for outbound portal emails.',
        SYSUTCDATETIME()
    );
END
GO
