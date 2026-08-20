SET NOCOUNT ON;

IF NOT EXISTS (SELECT 1 FROM dbo.tblSystemSettings WHERE SettingKey = 'LIMIT_CHANGE_MAX_CREDIT_AMOUNT')
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'LIMIT_CHANGE_MAX_CREDIT_AMOUNT',
            '999900',
            'number',
            'Maximum allowed New Credit Limit amount on the Request Limit Change form.',
            'system',
            SYSDATETIME()
        );
END;
