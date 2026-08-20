SET NOCOUNT ON;

IF OBJECT_ID('dbo.tblLimitChangeReasons', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tblLimitChangeReasons
    (
        ReasonID INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        ApplicationTypeID INT NOT NULL,
        ReasonLabel NVARCHAR(200) NOT NULL,
        SortOrder INT NOT NULL CONSTRAINT DF_tblLimitChangeReasons_SortOrder DEFAULT (0),
        IsActive BIT NOT NULL CONSTRAINT DF_tblLimitChangeReasons_IsActive DEFAULT (1),
        CreatedAt DATETIME2(0) NOT NULL CONSTRAINT DF_tblLimitChangeReasons_CreatedAt DEFAULT (SYSUTCDATETIME()),
        UpdatedAt DATETIME2(0) NULL,
        CONSTRAINT FK_tblLimitChangeReasons_ApplicationType FOREIGN KEY (ApplicationTypeID)
            REFERENCES dbo.tblApplicationTypes(ApplicationTypeID)
    );
END;

IF NOT EXISTS (
    SELECT 1
    FROM sys.indexes
    WHERE name = 'UX_tblLimitChangeReasons_AppType_ReasonLabel'
      AND object_id = OBJECT_ID('dbo.tblLimitChangeReasons')
)
BEGIN
    CREATE UNIQUE INDEX UX_tblLimitChangeReasons_AppType_ReasonLabel
        ON dbo.tblLimitChangeReasons(ApplicationTypeID, ReasonLabel);
END;

DECLARE @SeedReasons TABLE
(
    ApplicationTypeKey NVARCHAR(100) NOT NULL,
    ReasonLabel NVARCHAR(200) NOT NULL,
    SortOrder INT NOT NULL
);

INSERT INTO @SeedReasons (ApplicationTypeKey, ReasonLabel, SortOrder)
VALUES
    ('dtc_limit_change', 'Business requirement', 10),
    ('dtc_limit_change', 'Role change', 20),
    ('dtc_limit_change', 'Travel increase', 30),
    ('dtc_limit_change', 'Project requirement', 40),
    ('dtc_limit_change', 'Other', 50),
    ('dpc_limit_change', 'Business requirement', 10),
    ('dpc_limit_change', 'Role change', 20),
    ('dpc_limit_change', 'Travel increase', 30),
    ('dpc_limit_change', 'Project requirement', 40),
    ('dpc_limit_change', 'Other', 50),
    ('lodge_limit_change', 'Business requirement', 10),
    ('lodge_limit_change', 'Role change', 20),
    ('lodge_limit_change', 'Travel increase', 30),
    ('lodge_limit_change', 'Project requirement', 40),
    ('lodge_limit_change', 'Other', 50);

INSERT INTO dbo.tblLimitChangeReasons
    (ApplicationTypeID, ReasonLabel, SortOrder, IsActive, UpdatedAt)
SELECT
    at.ApplicationTypeID,
    s.ReasonLabel,
    s.SortOrder,
    1,
    SYSUTCDATETIME()
FROM @SeedReasons s
INNER JOIN dbo.tblApplicationTypes at
    ON at.ApplicationTypeKey = s.ApplicationTypeKey
WHERE NOT EXISTS (
    SELECT 1
    FROM dbo.tblLimitChangeReasons r
    WHERE r.ApplicationTypeID = at.ApplicationTypeID
      AND r.ReasonLabel = s.ReasonLabel
);
