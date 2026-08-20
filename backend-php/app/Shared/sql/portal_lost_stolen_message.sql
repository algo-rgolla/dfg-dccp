IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblSystemSettings
    WHERE SettingKey = 'PORTAL_LOST_STOLEN_MESSAGE'
)
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'PORTAL_LOST_STOLEN_MESSAGE',
            'If your card is lost or stolen, contact the card support team immediately and follow your department''s incident reporting process.',
            'text',
            'Message shown in the Lost/Stolen modal on the portal card list.',
            'system',
            SYSDATETIME()
        );
END
