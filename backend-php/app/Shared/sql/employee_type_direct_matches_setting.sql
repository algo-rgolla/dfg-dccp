-- Run this on the CCPortal database.
-- Controls which EmployeeType values remain unchanged.
-- Any other EmployeeType will be normalized to 'Defence'.

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblSystemSettings
    WHERE SettingKey = 'EMPLOYEE_TYPE_DIRECT_MATCHES'
)
BEGIN
    INSERT INTO dbo.tblSystemSettings
        (SettingKey, SettingValue, SettingType, Description, UpdatedBy, UpdatedAt)
    VALUES
        (
            'EMPLOYEE_TYPE_DIRECT_MATCHES',
            'ASA,ASD,ANNPSR',
            'string',
            'Comma-separated EmployeeType values that should remain unchanged. All other EmployeeType values are normalized to Defence.',
            'system',
            SYSDATETIME()
        );
END;
