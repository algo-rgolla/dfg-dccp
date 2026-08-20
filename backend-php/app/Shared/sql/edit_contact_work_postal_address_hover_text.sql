SET NOCOUNT ON;

IF NOT EXISTS (SELECT 1 FROM dbo.tblSystemSettings WHERE SettingKey = 'EDIT_CONTACT_WORK_POSTAL_ADDRESS_HOVER_TEXT')
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'EDIT_CONTACT_WORK_POSTAL_ADDRESS_HOVER_TEXT',
            'Enter the hover text shown for the Work Postal Address info icon on the Edit Contact details screen.',
            'string',
            'Edit Contact details Work Postal Address label hover text.',
            'system',
            SYSDATETIME()
        );
END;
