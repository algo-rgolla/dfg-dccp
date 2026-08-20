SET NOCOUNT ON;

IF NOT EXISTS (SELECT 1 FROM dbo.tblSystemSettings WHERE SettingKey = 'RESTRICTED_LIST_DEFAULT_REASON')
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'RESTRICTED_LIST_DEFAULT_REASON',
            'restricted by credit card team',
            'string',
            'Default reason shown on the Restricted List add/edit form when no reason has been provided.',
            'system',
            SYSDATETIME()
        );
END;
