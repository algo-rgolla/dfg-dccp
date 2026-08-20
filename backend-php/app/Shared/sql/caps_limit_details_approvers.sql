IF COL_LENGTH('dbo.tblCAPSLimitDetailsPortal', 'EMAIL') IS NULL
BEGIN
    IF COL_LENGTH('dbo.tblCAPSLimitDetailsPortal', 'Supervisor') IS NOT NULL
    BEGIN
        EXEC sp_rename 'dbo.tblCAPSLimitDetailsPortal.Supervisor', 'EMAIL', 'COLUMN';
    END
    ELSE
    BEGIN
        ALTER TABLE dbo.tblCAPSLimitDetailsPortal
        ADD EMAIL NVARCHAR(100) NULL;
    END
END;
GO

IF COL_LENGTH('dbo.tblCAPSLimitDetailsPortal', 'DIRECTOR') IS NULL
BEGIN
    IF COL_LENGTH('dbo.tblCAPSLimitDetailsPortal', 'SES') IS NOT NULL
    BEGIN
        EXEC sp_rename 'dbo.tblCAPSLimitDetailsPortal.SES', 'DIRECTOR', 'COLUMN';
    END
    ELSE
    BEGIN
        ALTER TABLE dbo.tblCAPSLimitDetailsPortal
        ADD DIRECTOR NVARCHAR(100) NULL;
    END
END;
GO

IF COL_LENGTH('dbo.tblCAPSLimitDetailsPortal', 'ASFIN') IS NULL
BEGIN
    ALTER TABLE dbo.tblCAPSLimitDetailsPortal
    ADD ASFIN NVARCHAR(100) NULL;
END;
GO

IF COL_LENGTH('dbo.tblCAPSLimitDetailsPortal', 'CFO') IS NULL
BEGIN
    ALTER TABLE dbo.tblCAPSLimitDetailsPortal
    ADD CFO NVARCHAR(100) NULL;
END;
GO
