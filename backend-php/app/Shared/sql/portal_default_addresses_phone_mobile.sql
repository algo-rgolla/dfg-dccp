IF COL_LENGTH('dbo.tblPortalDefaultAddresses', 'Phone') IS NULL
BEGIN
    ALTER TABLE dbo.tblPortalDefaultAddresses
    ADD Phone NVARCHAR(50) NULL;
END
GO

IF COL_LENGTH('dbo.tblPortalDefaultAddresses', 'Mobile') IS NULL
BEGIN
    ALTER TABLE dbo.tblPortalDefaultAddresses
    ADD Mobile NVARCHAR(50) NULL;
END
