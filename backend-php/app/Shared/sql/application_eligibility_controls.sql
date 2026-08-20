/*
  Application eligibility controls
  - Blacklist employees who cannot apply for cards
  - Override specific eligibility checks for selected employees
*/

IF OBJECT_ID('dbo.tblCardApplicationBlacklist', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tblCardApplicationBlacklist (
        BlacklistID INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        EmployeeID NVARCHAR(50) NOT NULL,
        AppliesToApplicationTypeID INT NULL,
        IsActive BIT NOT NULL CONSTRAINT DF_tblCardApplicationBlacklist_IsActive DEFAULT (1),
        Reason NVARCHAR(500) NULL,
        EffectiveFrom DATETIME2(0) NULL,
        EffectiveTo DATETIME2(0) NULL,
        CreatedAt DATETIME2(0) NOT NULL CONSTRAINT DF_tblCardApplicationBlacklist_CreatedAt DEFAULT (SYSUTCDATETIME()),
        CreatedBy INT NULL,
        UpdatedAt DATETIME2(0) NOT NULL CONSTRAINT DF_tblCardApplicationBlacklist_UpdatedAt DEFAULT (SYSUTCDATETIME()),
        UpdatedBy INT NULL
    );

    CREATE INDEX IX_tblCardApplicationBlacklist_EmployeeID
        ON dbo.tblCardApplicationBlacklist (EmployeeID, IsActive, AppliesToApplicationTypeID);
END;
GO

IF OBJECT_ID('dbo.tblApplicationEligibilityOverride', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tblApplicationEligibilityOverride (
        OverrideID INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        EmployeeID NVARCHAR(50) NOT NULL,
        OverrideType NVARCHAR(50) NOT NULL,
        AppliesToApplicationTypeID INT NULL,
        IsActive BIT NOT NULL CONSTRAINT DF_tblApplicationEligibilityOverride_IsActive DEFAULT (1),
        Reason NVARCHAR(500) NULL,
        EffectiveFrom DATETIME2(0) NULL,
        EffectiveTo DATETIME2(0) NULL,
        CreatedAt DATETIME2(0) NOT NULL CONSTRAINT DF_tblApplicationEligibilityOverride_CreatedAt DEFAULT (SYSUTCDATETIME()),
        CreatedBy INT NULL,
        UpdatedAt DATETIME2(0) NOT NULL CONSTRAINT DF_tblApplicationEligibilityOverride_UpdatedAt DEFAULT (SYSUTCDATETIME()),
        UpdatedBy INT NULL
    );

    CREATE INDEX IX_tblApplicationEligibilityOverride_EmployeeID
        ON dbo.tblApplicationEligibilityOverride (EmployeeID, OverrideType, IsActive, AppliesToApplicationTypeID);
END;
GO

/*
Example override types:
  POSITION_TYPE_CHECK
*/
