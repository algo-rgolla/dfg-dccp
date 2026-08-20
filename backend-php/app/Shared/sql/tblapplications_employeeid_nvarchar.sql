/*
Convert dbo.tblApplications.EmployeeID to NVARCHAR(50) and backfill existing null/blank values.
*/

IF OBJECT_ID('dbo.tblApplications', 'U') IS NULL
BEGIN
    THROW 50000, 'dbo.tblApplications was not found.', 1;
END;

IF COL_LENGTH('dbo.tblApplications', 'EmployeeID') IS NULL
BEGIN
    THROW 50000, 'dbo.tblApplications.EmployeeID was not found.', 1;
END;

IF EXISTS (
    SELECT 1
    FROM sys.columns c
    INNER JOIN sys.types t
        ON t.user_type_id = c.user_type_id
    WHERE c.object_id = OBJECT_ID('dbo.tblApplications')
      AND c.name = 'EmployeeID'
      AND (
            t.name <> 'nvarchar'
            OR c.max_length <> 100
          )
)
BEGIN
    ALTER TABLE dbo.tblApplications
        ALTER COLUMN EmployeeID NVARCHAR(50) NULL;
END;

;WITH employee_source AS (
    SELECT
        a.ApplicationID,
        NULLIF(LTRIM(RTRIM(CAST(u.EmployeeID AS NVARCHAR(50)))), '') AS UserEmployeeID,
        NULLIF(LTRIM(RTRIM(JSON_VALUE(s.DataJson, '$.employee_id'))), '') AS PayloadEmployeeID
    FROM dbo.tblApplications a
    LEFT JOIN dbo.tblUsers u
        ON u.UserID = a.UserID
    LEFT JOIN dbo.tblApplicationSteps s
        ON s.ApplicationID = a.ApplicationID
       AND s.StepKey = 'application'
)
UPDATE a
SET EmployeeID = COALESCE(src.PayloadEmployeeID, src.UserEmployeeID)
FROM dbo.tblApplications a
INNER JOIN employee_source src
    ON src.ApplicationID = a.ApplicationID
WHERE NULLIF(LTRIM(RTRIM(CAST(a.EmployeeID AS NVARCHAR(50)))), '') IS NULL
  AND COALESCE(src.PayloadEmployeeID, src.UserEmployeeID) IS NOT NULL;
