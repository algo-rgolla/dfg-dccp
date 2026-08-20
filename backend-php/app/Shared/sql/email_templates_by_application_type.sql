IF OBJECT_ID('dbo.tblEmailTemplates', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tblEmailTemplates (
        EmailTemplateID int IDENTITY(1,1) NOT NULL PRIMARY KEY,
        TemplateKey nvarchar(100) NOT NULL,
        ApplicationTypeID int NULL,
        Subject nvarchar(500) NOT NULL,
        BodyHtml nvarchar(max) NOT NULL,
        IsActive bit NOT NULL CONSTRAINT DF_tblEmailTemplates_IsActive DEFAULT (1),
        CreatedAt datetime2(0) NOT NULL CONSTRAINT DF_tblEmailTemplates_CreatedAt DEFAULT SYSUTCDATETIME(),
        CreatedBy nvarchar(100) NULL,
        UpdatedAt datetime2(0) NOT NULL CONSTRAINT DF_tblEmailTemplates_UpdatedAt DEFAULT SYSUTCDATETIME(),
        UpdatedBy nvarchar(100) NULL
    );
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.foreign_keys
    WHERE name = 'FK_tblEmailTemplates_ApplicationType'
)
BEGIN
    ALTER TABLE dbo.tblEmailTemplates
    ADD CONSTRAINT FK_tblEmailTemplates_ApplicationType
        FOREIGN KEY (ApplicationTypeID)
        REFERENCES dbo.tblApplicationTypes (ApplicationTypeID);
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'UX_tblEmailTemplates_TemplateKey_ApplicationTypeID'
      AND object_id = OBJECT_ID('dbo.tblEmailTemplates')
)
BEGIN
    CREATE UNIQUE INDEX UX_tblEmailTemplates_TemplateKey_ApplicationTypeID
        ON dbo.tblEmailTemplates (TemplateKey, ApplicationTypeID)
        WHERE ApplicationTypeID IS NOT NULL;
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'UX_tblEmailTemplates_TemplateKey_Default'
      AND object_id = OBJECT_ID('dbo.tblEmailTemplates')
)
BEGIN
    CREATE UNIQUE INDEX UX_tblEmailTemplates_TemplateKey_Default
        ON dbo.tblEmailTemplates (TemplateKey)
        WHERE ApplicationTypeID IS NULL;
END;
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'IX_tblEmailTemplates_IsActive_TemplateKey'
      AND object_id = OBJECT_ID('dbo.tblEmailTemplates')
)
BEGIN
    CREATE INDEX IX_tblEmailTemplates_IsActive_TemplateKey
        ON dbo.tblEmailTemplates (IsActive, TemplateKey, ApplicationTypeID);
END;
GO
