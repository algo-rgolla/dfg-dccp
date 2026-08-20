-- Run this on the CCPortal database.

IF COL_LENGTH('dbo.tblApplicationTypes', 'PrivacyAgreementRequired') IS NULL
BEGIN
    ALTER TABLE dbo.tblApplicationTypes
    ADD PrivacyAgreementRequired BIT NOT NULL
        CONSTRAINT DF_tblApplicationTypes_PrivacyAgreementRequired DEFAULT (0);
END;

IF COL_LENGTH('dbo.tblApplicationTypes', 'PrivacyAgreementText') IS NULL
BEGIN
    ALTER TABLE dbo.tblApplicationTypes
    ADD PrivacyAgreementText NVARCHAR(MAX) NULL;
END;
