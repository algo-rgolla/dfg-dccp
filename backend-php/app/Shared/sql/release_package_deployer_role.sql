USE [CCPortal]
GO

IF OBJECT_ID('dbo.tblPermissions', 'U') IS NULL
BEGIN
    RAISERROR('dbo.tblPermissions not found.', 16, 1);
    RETURN;
END

IF OBJECT_ID('dbo.tblRoles', 'U') IS NULL
BEGIN
    RAISERROR('dbo.tblRoles not found.', 16, 1);
    RETURN;
END

IF OBJECT_ID('dbo.tblRolePermissions', 'U') IS NULL
BEGIN
    RAISERROR('dbo.tblRolePermissions not found.', 16, 1);
    RETURN;
END

DECLARE @PermissionCode NVARCHAR(100) = N'DEPLOY_PACKAGES';
DECLARE @PermissionName NVARCHAR(200) = N'Deploy Release Packages';
DECLARE @PermissionDescription NVARCHAR(500) = N'Allows controlled upload and application of approved release packages.';
DECLARE @RoleName NVARCHAR(100) = N'Release Deployer';

DECLARE @PermissionID INT;
DECLARE @RoleID INT;
DECLARE @Sql NVARCHAR(MAX);

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblPermissions
    WHERE PermissionCode = @PermissionCode
)
BEGIN
    SET @Sql = N'INSERT INTO dbo.tblPermissions (PermissionCode';

    IF COL_LENGTH('dbo.tblPermissions', 'PermissionName') IS NOT NULL
        SET @Sql += N', PermissionName';
    IF COL_LENGTH('dbo.tblPermissions', 'Description') IS NOT NULL
        SET @Sql += N', Description';
    IF COL_LENGTH('dbo.tblPermissions', 'Active') IS NOT NULL
        SET @Sql += N', Active';
    IF COL_LENGTH('dbo.tblPermissions', 'DateCreated') IS NOT NULL
        SET @Sql += N', DateCreated';
    IF COL_LENGTH('dbo.tblPermissions', 'DateUpdated') IS NOT NULL
        SET @Sql += N', DateUpdated';

    SET @Sql += N') VALUES (@PermissionCode';

    IF COL_LENGTH('dbo.tblPermissions', 'PermissionName') IS NOT NULL
        SET @Sql += N', @PermissionName';
    IF COL_LENGTH('dbo.tblPermissions', 'Description') IS NOT NULL
        SET @Sql += N', @PermissionDescription';
    IF COL_LENGTH('dbo.tblPermissions', 'Active') IS NOT NULL
        SET @Sql += N', 1';
    IF COL_LENGTH('dbo.tblPermissions', 'DateCreated') IS NOT NULL
        SET @Sql += N', SYSUTCDATETIME()';
    IF COL_LENGTH('dbo.tblPermissions', 'DateUpdated') IS NOT NULL
        SET @Sql += N', SYSUTCDATETIME()';

    SET @Sql += N');';

    EXEC sp_executesql
        @Sql,
        N'@PermissionCode NVARCHAR(100), @PermissionName NVARCHAR(200), @PermissionDescription NVARCHAR(500)',
        @PermissionCode = @PermissionCode,
        @PermissionName = @PermissionName,
        @PermissionDescription = @PermissionDescription;
END

SELECT TOP 1 @PermissionID = PermissionID
FROM dbo.tblPermissions
WHERE PermissionCode = @PermissionCode;

IF @PermissionID IS NULL
BEGIN
    RAISERROR('Failed to create or locate DEPLOY_PACKAGES permission.', 16, 1);
    RETURN;
END

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblRoles
    WHERE RoleName = @RoleName
)
BEGIN
    SET @Sql = N'INSERT INTO dbo.tblRoles (RoleName';

    IF COL_LENGTH('dbo.tblRoles', 'Active') IS NOT NULL
        SET @Sql += N', Active';
    IF COL_LENGTH('dbo.tblRoles', 'DateCreated') IS NOT NULL
        SET @Sql += N', DateCreated';
    IF COL_LENGTH('dbo.tblRoles', 'DateUpdated') IS NOT NULL
        SET @Sql += N', DateUpdated';

    SET @Sql += N') VALUES (@RoleName';

    IF COL_LENGTH('dbo.tblRoles', 'Active') IS NOT NULL
        SET @Sql += N', 1';
    IF COL_LENGTH('dbo.tblRoles', 'DateCreated') IS NOT NULL
        SET @Sql += N', SYSUTCDATETIME()';
    IF COL_LENGTH('dbo.tblRoles', 'DateUpdated') IS NOT NULL
        SET @Sql += N', SYSUTCDATETIME()';

    SET @Sql += N');';

    EXEC sp_executesql
        @Sql,
        N'@RoleName NVARCHAR(100)',
        @RoleName = @RoleName;
END

SELECT TOP 1 @RoleID = RoleID
FROM dbo.tblRoles
WHERE RoleName = @RoleName;

IF @RoleID IS NULL
BEGIN
    RAISERROR('Failed to create or locate Release Deployer role.', 16, 1);
    RETURN;
END

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblRolePermissions
    WHERE RoleID = @RoleID
      AND PermissionID = @PermissionID
)
BEGIN
    SET @Sql = N'INSERT INTO dbo.tblRolePermissions (RoleID, PermissionID';

    IF COL_LENGTH('dbo.tblRolePermissions', 'DateAssigned') IS NOT NULL
        SET @Sql += N', DateAssigned';
    IF COL_LENGTH('dbo.tblRolePermissions', 'DateCreated') IS NOT NULL
        SET @Sql += N', DateCreated';

    SET @Sql += N') VALUES (@RoleID, @PermissionID';

    IF COL_LENGTH('dbo.tblRolePermissions', 'DateAssigned') IS NOT NULL
        SET @Sql += N', SYSUTCDATETIME()';
    IF COL_LENGTH('dbo.tblRolePermissions', 'DateCreated') IS NOT NULL
        SET @Sql += N', SYSUTCDATETIME()';

    SET @Sql += N');';

    EXEC sp_executesql
        @Sql,
        N'@RoleID INT, @PermissionID INT',
        @RoleID = @RoleID,
        @PermissionID = @PermissionID;
END
GO
