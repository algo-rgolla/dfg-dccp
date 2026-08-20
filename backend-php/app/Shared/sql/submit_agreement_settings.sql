IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblSystemSettings
    WHERE SettingKey = 'SUBMIT_AGREEMENT_TEXT'
)
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'SUBMIT_AGREEMENT_TEXT',
            'By submitting this application, you confirm the details provided are true and correct.',
            'string',
            'Agreement text shown to users in the submission confirmation modal for all applications.',
            'system',
            SYSDATETIME()
        );
END
GO
