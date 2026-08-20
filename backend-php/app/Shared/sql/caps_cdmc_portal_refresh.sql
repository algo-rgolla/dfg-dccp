USE [CAPS];
GO

IF OBJECT_ID('dbo.tblCAPSCDMCPortal', 'U') IS NULL
BEGIN
    RAISERROR('dbo.tblCAPSCDMCPortal does not exist. Run caps_cdmc_portal.sql first.', 16, 1);
    RETURN;
END;
GO

TRUNCATE TABLE dbo.tblCAPSCDMCPortal;
GO

DECLARE @ColumnList NVARCHAR(MAX);
DECLARE @Sql NVARCHAR(MAX);

SELECT @ColumnList = STUFF((
    SELECT ', ' + QUOTENAME(c.name)
    FROM sys.columns c
    WHERE c.object_id = OBJECT_ID('dbo.tblCAPSCDMCPortal')
    ORDER BY c.column_id
    FOR XML PATH(''), TYPE
).value('.', 'NVARCHAR(MAX)'), 1, 2, '');

IF @ColumnList IS NULL OR LTRIM(RTRIM(@ColumnList)) = ''
BEGIN
    RAISERROR('No columns found for dbo.tblCAPSCDMCPortal.', 16, 1);
    RETURN;
END;

SET @Sql = '
SET IDENTITY_INSERT dbo.tblCAPSCDMCPortal ON;
INSERT INTO dbo.tblCAPSCDMCPortal (' + @ColumnList + ')
SELECT ' + @ColumnList + '
FROM dbo.tblCAPSCDMC;
SET IDENTITY_INSERT dbo.tblCAPSCDMCPortal OFF;';

EXEC sp_executesql @Sql;
GO
