SET NOCOUNT ON;

IF NOT EXISTS (SELECT 1 FROM dbo.tblSystemSettings WHERE SettingKey = 'DDPostalAddresses')
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'DDPostalAddresses',
            '',
            'string',
            'Application Employee Details DD Postal Addresses spreadsheet link.',
            'system',
            SYSDATETIME()
        );
END;

IF NOT EXISTS (SELECT 1 FROM dbo.tblSystemSettings WHERE SettingKey = 'DDPostalAddressesLabel')
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'DDPostalAddressesLabel',
            'DD Postal Addresses',
            'string',
            'Application Employee Details DD Postal Addresses spreadsheet link label.',
            'system',
            SYSDATETIME()
        );
END;
