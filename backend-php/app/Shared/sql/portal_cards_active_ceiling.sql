IF COL_LENGTH('dbo.tblPORTALCards', 'ActiveCeiling') IS NULL
BEGIN
    ALTER TABLE dbo.tblPORTALCards
    ADD ActiveCeiling DECIMAL(18, 2) NULL;
END
