USE [CAPS];
GO

IF OBJECT_ID('dbo.tblCAPSCDMCPortal', 'U') IS NULL
BEGIN
    SELECT TOP (0) *
    INTO dbo.tblCAPSCDMCPortal
    FROM dbo.tblCAPSCDMC;
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tblCAPSCDMCPortal')
      AND name = 'IX_tblCAPSCDMCPortal_EmployeeID'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_tblCAPSCDMCPortal_EmployeeID
    ON dbo.tblCAPSCDMCPortal (EmployeeID);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE object_id = OBJECT_ID('dbo.tblCAPSCDMCPortal')
      AND name = 'IX_tblCAPSCDMCPortal_EmployeeID_EmailAddress'
)
BEGIN
    CREATE NONCLUSTERED INDEX IX_tblCAPSCDMCPortal_EmployeeID_EmailAddress
    ON dbo.tblCAPSCDMCPortal (EmployeeID, Email_Address);
END;
GO

