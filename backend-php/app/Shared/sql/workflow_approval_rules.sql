-- Workflow approval rules + approver position mapping (SQL Server)
-- Note: run this on the CCPortal database.

IF OBJECT_ID('dbo.tblWorkflowApprovalRules', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tblWorkflowApprovalRules (
        RuleID INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        ApplicationTypeID INT NOT NULL,
        EmployeeGroup NVARCHAR(120) NULL,
        ApprovalStage INT NOT NULL CONSTRAINT DF_tblWorkflowApprovalRules_ApprovalStage DEFAULT (1),
        MinLimit DECIMAL(18,2) NOT NULL CONSTRAINT DF_tblWorkflowApprovalRules_MinLimit DEFAULT (0),
        MaxLimit DECIMAL(18,2) NULL,
        RequiredApproverType NVARCHAR(50) NOT NULL, -- e.g. SES, ASFIN, CFO
        RequiredRank NVARCHAR(50) NULL,            -- e.g. SES (used when type = SES)
        IsActive BIT NOT NULL CONSTRAINT DF_tblWorkflowApprovalRules_IsActive DEFAULT (1),
        CreatedAt DATETIME2 NOT NULL CONSTRAINT DF_tblWorkflowApprovalRules_CreatedAt DEFAULT (SYSUTCDATETIME()),
        UpdatedAt DATETIME2 NULL
    );
END;

IF COL_LENGTH('dbo.tblWorkflowApprovalRules', 'ApprovalStage') IS NULL
BEGIN
    ALTER TABLE dbo.tblWorkflowApprovalRules
    ADD ApprovalStage INT NOT NULL
        CONSTRAINT DF_tblWorkflowApprovalRules_ApprovalStage DEFAULT (1);
END;

-- Defence: >= $500,000 for ApplicationTypeID 5 and 6
-- Only CFO (position-number based).
IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 5
      AND MinLimit = 500000
      AND MaxLimit IS NULL
      AND RequiredApproverType = 'CFO'
      AND EmployeeGroup = 'Defence'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (5, 'Defence', 500000, NULL, 'CFO');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 6
      AND MinLimit = 500000
      AND MaxLimit IS NULL
      AND RequiredApproverType = 'CFO'
      AND EmployeeGroup = 'Defence'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (6, 'Defence', 500000, NULL, 'CFO');
END;

-- Seed approver positions (Defence)
IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApproverPositions
    WHERE ApproverType = 'ASFIN'
      AND EmployeeGroup = 'Defence'
      AND PositionNumber = '00546641'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApproverPositions
        (ApproverType, EmployeeGroup, PositionNumber)
    VALUES
        ('ASFIN', 'Defence', '00546641');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApproverPositions
    WHERE ApproverType = 'ASFIN'
      AND EmployeeGroup = 'Defence'
      AND PositionNumber = '00119128'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApproverPositions
        (ApproverType, EmployeeGroup, PositionNumber)
    VALUES
        ('ASFIN', 'Defence', '00119128');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApproverPositions
    WHERE ApproverType = 'ASFIN'
      AND EmployeeGroup = 'Defence'
      AND PositionNumber = '00504242'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApproverPositions
        (ApproverType, EmployeeGroup, PositionNumber)
    VALUES
        ('ASFIN', 'Defence', '00504242');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApproverPositions
    WHERE ApproverType = 'CFO'
      AND EmployeeGroup = 'Defence'
      AND PositionNumber = '00127838'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApproverPositions
        (ApproverType, EmployeeGroup, PositionNumber)
    VALUES
        ('CFO', 'Defence', '00127838');
END;

-- Defence: $100,001 to $499,999 for ApplicationTypeID 5 and 6
-- Approver can be ASFIN or CFO (position-number based).
IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 5
      AND MinLimit = 100001
      AND MaxLimit = 499999
      AND RequiredApproverType = 'ASFIN'
      AND EmployeeGroup = 'Defence'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (5, 'Defence', 100001, 499999, 'ASFIN');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 5
      AND MinLimit = 100001
      AND MaxLimit = 499999
      AND RequiredApproverType = 'CFO'
      AND EmployeeGroup = 'Defence'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (5, 'Defence', 100001, 499999, 'CFO');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 6
      AND MinLimit = 100001
      AND MaxLimit = 499999
      AND RequiredApproverType = 'ASFIN'
      AND EmployeeGroup = 'Defence'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (6, 'Defence', 100001, 499999, 'ASFIN');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 6
      AND MinLimit = 100001
      AND MaxLimit = 499999
      AND RequiredApproverType = 'CFO'
      AND EmployeeGroup = 'Defence'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (6, 'Defence', 100001, 499999, 'CFO');
END;

IF OBJECT_ID('dbo.tblWorkflowApproverPositions', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tblWorkflowApproverPositions (
        ApproverPositionID INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
        ApproverType NVARCHAR(50) NOT NULL,   -- e.g. ASFIN, CFO
        EmployeeGroup NVARCHAR(120) NULL,     -- optional scoping to group
        PositionNumber NVARCHAR(50) NOT NULL,
        Email NVARCHAR(255) NULL,
        DisplayName NVARCHAR(255) NULL,
        IsActive BIT NOT NULL CONSTRAINT DF_tblWorkflowApproverPositions_IsActive DEFAULT (1),
        CreatedAt DATETIME2 NOT NULL CONSTRAINT DF_tblWorkflowApproverPositions_CreatedAt DEFAULT (SYSUTCDATETIME()),
        UpdatedAt DATETIME2 NULL
    );
END;

-- Seed: First approval rule for ApplicationTypeID 5 and 6
-- Limits up to $100,000 require EmployeeRank = SES
IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 5
      AND MinLimit = 0
      AND MaxLimit = 100000
      AND RequiredApproverType = 'SES'
      AND RequiredRank = 'SES'
      AND EmployeeGroup = 'Defence'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType, RequiredRank)
    VALUES
        (5, 'Defence', 0, 100000, 'SES', 'SES');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 6
      AND MinLimit = 0
      AND MaxLimit = 100000
      AND RequiredApproverType = 'SES'
      AND RequiredRank = 'SES'
      AND EmployeeGroup = 'Defence'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType, RequiredRank)
    VALUES
        (6, 'Defence', 0, 100000, 'SES', 'SES');
END;

-- ============================================
-- ASA rules and approver positions (AppType 5, 6)
-- ============================================

-- ASA approver positions (replace PositionNumber values with actual ASA positions)
IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApproverPositions
    WHERE ApproverType = 'ASFIN'
      AND EmployeeGroup = 'ASA'
      AND PositionNumber = 'ASA_ASFIN_POSITION_1'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApproverPositions
        (ApproverType, EmployeeGroup, PositionNumber)
    VALUES
        ('ASFIN', 'ASA', 'ASA_ASFIN_POSITION_1');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApproverPositions
    WHERE ApproverType = 'CFO'
      AND EmployeeGroup = 'ASA'
      AND PositionNumber = 'ASA_CFO_POSITION_1'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApproverPositions
        (ApproverType, EmployeeGroup, PositionNumber)
    VALUES
        ('CFO', 'ASA', 'ASA_CFO_POSITION_1');
END;

-- ASA: up to $100,000 requires ASFIN or CFO
IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 5
      AND MinLimit = 0
      AND MaxLimit = 100000
      AND RequiredApproverType = 'ASFIN'
      AND EmployeeGroup = 'ASA'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (5, 'ASA', 0, 100000, 'ASFIN');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 5
      AND MinLimit = 0
      AND MaxLimit = 100000
      AND RequiredApproverType = 'CFO'
      AND EmployeeGroup = 'ASA'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (5, 'ASA', 0, 100000, 'CFO');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 6
      AND MinLimit = 0
      AND MaxLimit = 100000
      AND RequiredApproverType = 'ASFIN'
      AND EmployeeGroup = 'ASA'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (6, 'ASA', 0, 100000, 'ASFIN');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 6
      AND MinLimit = 0
      AND MaxLimit = 100000
      AND RequiredApproverType = 'CFO'
      AND EmployeeGroup = 'ASA'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (6, 'ASA', 0, 100000, 'CFO');
END;

-- ASA: $100,001 to $499,999 requires ASFIN or CFO
IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 5
      AND MinLimit = 100001
      AND MaxLimit = 499999
      AND RequiredApproverType = 'ASFIN'
      AND EmployeeGroup = 'ASA'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (5, 'ASA', 100001, 499999, 'ASFIN');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 5
      AND MinLimit = 100001
      AND MaxLimit = 499999
      AND RequiredApproverType = 'CFO'
      AND EmployeeGroup = 'ASA'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (5, 'ASA', 100001, 499999, 'CFO');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 6
      AND MinLimit = 100001
      AND MaxLimit = 499999
      AND RequiredApproverType = 'ASFIN'
      AND EmployeeGroup = 'ASA'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (6, 'ASA', 100001, 499999, 'ASFIN');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 6
      AND MinLimit = 100001
      AND MaxLimit = 499999
      AND RequiredApproverType = 'CFO'
      AND EmployeeGroup = 'ASA'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (6, 'ASA', 100001, 499999, 'CFO');
END;

-- ASA: >= $500,000 requires CFO only
IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 5
      AND MinLimit = 500000
      AND MaxLimit IS NULL
      AND RequiredApproverType = 'CFO'
      AND EmployeeGroup = 'ASA'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (5, 'ASA', 500000, NULL, 'CFO');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 6
      AND MinLimit = 500000
      AND MaxLimit IS NULL
      AND RequiredApproverType = 'CFO'
      AND EmployeeGroup = 'ASA'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (6, 'ASA', 500000, NULL, 'CFO');
END;

-- ============================================
-- ASD rules and approver positions (AppType 5, 6)
-- ============================================

-- ASD approver positions (replace PositionNumber values with actual ASD positions)
IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApproverPositions
    WHERE ApproverType = 'ASFIN'
      AND EmployeeGroup = 'ASD'
      AND PositionNumber = 'ASD_ASFIN_POSITION_1'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApproverPositions
        (ApproverType, EmployeeGroup, PositionNumber)
    VALUES
        ('ASFIN', 'ASD', 'ASD_ASFIN_POSITION_1');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApproverPositions
    WHERE ApproverType = 'CFO'
      AND EmployeeGroup = 'ASD'
      AND PositionNumber = 'ASD_CFO_POSITION_1'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApproverPositions
        (ApproverType, EmployeeGroup, PositionNumber)
    VALUES
        ('CFO', 'ASD', 'ASD_CFO_POSITION_1');
END;

-- ASD: up to $100,000 requires ASFIN or CFO
IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 5
      AND MinLimit = 0
      AND MaxLimit = 100000
      AND RequiredApproverType = 'ASFIN'
      AND EmployeeGroup = 'ASD'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (5, 'ASD', 0, 100000, 'ASFIN');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 5
      AND MinLimit = 0
      AND MaxLimit = 100000
      AND RequiredApproverType = 'CFO'
      AND EmployeeGroup = 'ASD'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (5, 'ASD', 0, 100000, 'CFO');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 6
      AND MinLimit = 0
      AND MaxLimit = 100000
      AND RequiredApproverType = 'ASFIN'
      AND EmployeeGroup = 'ASD'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (6, 'ASD', 0, 100000, 'ASFIN');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 6
      AND MinLimit = 0
      AND MaxLimit = 100000
      AND RequiredApproverType = 'CFO'
      AND EmployeeGroup = 'ASD'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (6, 'ASD', 0, 100000, 'CFO');
END;

-- ASD: $100,001 to $499,999 requires ASFIN or CFO
IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 5
      AND MinLimit = 100001
      AND MaxLimit = 499999
      AND RequiredApproverType = 'ASFIN'
      AND EmployeeGroup = 'ASD'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (5, 'ASD', 100001, 499999, 'ASFIN');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 5
      AND MinLimit = 100001
      AND MaxLimit = 499999
      AND RequiredApproverType = 'CFO'
      AND EmployeeGroup = 'ASD'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (5, 'ASD', 100001, 499999, 'CFO');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 6
      AND MinLimit = 100001
      AND MaxLimit = 499999
      AND RequiredApproverType = 'ASFIN'
      AND EmployeeGroup = 'ASD'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (6, 'ASD', 100001, 499999, 'ASFIN');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 6
      AND MinLimit = 100001
      AND MaxLimit = 499999
      AND RequiredApproverType = 'CFO'
      AND EmployeeGroup = 'ASD'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (6, 'ASD', 100001, 499999, 'CFO');
END;

-- ASD: >= $500,000 requires CFO only
IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 5
      AND MinLimit = 500000
      AND MaxLimit IS NULL
      AND RequiredApproverType = 'CFO'
      AND EmployeeGroup = 'ASD'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (5, 'ASD', 500000, NULL, 'CFO');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 6
      AND MinLimit = 500000
      AND MaxLimit IS NULL
      AND RequiredApproverType = 'CFO'
      AND EmployeeGroup = 'ASD'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (6, 'ASD', 500000, NULL, 'CFO');
END;

-- ============================================
-- ANNPSR rules and approver positions (AppType 5, 6)
-- ============================================

-- ANNPSR approver positions (replace PositionNumber value with actual ANNPSR CFO position)
IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApproverPositions
    WHERE ApproverType = 'CFO'
      AND EmployeeGroup = 'ANNPSR'
      AND PositionNumber = 'ANNPSR_CFO_POSITION_1'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApproverPositions
        (ApproverType, EmployeeGroup, PositionNumber)
    VALUES
        ('CFO', 'ANNPSR', 'ANNPSR_CFO_POSITION_1');
END;

-- ANNPSR: all amounts require CFO
IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 5
      AND MinLimit = 0
      AND MaxLimit IS NULL
      AND RequiredApproverType = 'CFO'
      AND EmployeeGroup = 'ANNPSR'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (5, 'ANNPSR', 0, NULL, 'CFO');
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblWorkflowApprovalRules
    WHERE ApplicationTypeID = 6
      AND MinLimit = 0
      AND MaxLimit IS NULL
      AND RequiredApproverType = 'CFO'
      AND EmployeeGroup = 'ANNPSR'
)
BEGIN
    INSERT INTO dbo.tblWorkflowApprovalRules
        (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType)
    VALUES
        (6, 'ANNPSR', 0, NULL, 'CFO');
END;
