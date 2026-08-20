/*
  Portal default addresses
  - Stores the last confirmed application address for each employee
  - Used to prefill new applications before falling back to CAPS address data
*/

IF OBJECT_ID('dbo.tblPortalDefaultAddresses', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tblPortalDefaultAddresses (
        DefaultAddressID INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        EmployeeID NVARCHAR(50) NOT NULL,
        Address1 NVARCHAR(100) NULL,
        Address2 NVARCHAR(100) NULL,
        Address3 NVARCHAR(100) NULL,
        Suburb NVARCHAR(100) NULL,
        State NVARCHAR(50) NULL,
        PostCode NVARCHAR(20) NULL,
        Phone NVARCHAR(30) NULL,
        Mobile NVARCHAR(30) NULL,
        SourceApplicationID INT NULL,
        ConfirmedAt DATETIME2(0) NOT NULL CONSTRAINT DF_tblPortalDefaultAddresses_ConfirmedAt DEFAULT (SYSUTCDATETIME()),
        CreatedAt DATETIME2(0) NOT NULL CONSTRAINT DF_tblPortalDefaultAddresses_CreatedAt DEFAULT (SYSUTCDATETIME()),
        CreatedBy INT NULL,
        UpdatedAt DATETIME2(0) NOT NULL CONSTRAINT DF_tblPortalDefaultAddresses_UpdatedAt DEFAULT (SYSUTCDATETIME()),
        UpdatedBy INT NULL
    );

    CREATE UNIQUE INDEX UX_tblPortalDefaultAddresses_EmployeeID
        ON dbo.tblPortalDefaultAddresses (EmployeeID);
END;
GO

IF COL_LENGTH('dbo.tblPortalDefaultAddresses', 'Phone') IS NULL
BEGIN
    ALTER TABLE dbo.tblPortalDefaultAddresses
        ADD Phone NVARCHAR(30) NULL;
END;
GO

IF COL_LENGTH('dbo.tblPortalDefaultAddresses', 'Mobile') IS NULL
BEGIN
    ALTER TABLE dbo.tblPortalDefaultAddresses
        ADD Mobile NVARCHAR(30) NULL;
END;
GO
