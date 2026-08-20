IF COL_LENGTH('dbo.tblCardChangeRequests', 'CancelDate') IS NULL
BEGIN
    ALTER TABLE dbo.tblCardChangeRequests
    ADD CancelDate date NULL;
END
GO

IF COL_LENGTH('dbo.tblCardChangeRequests', 'ProcessedAt') IS NULL
BEGIN
    ALTER TABLE dbo.tblCardChangeRequests
    ADD ProcessedAt datetime2 NULL;
END
GO

UPDATE dbo.tblCardChangeRequests
SET CancelDate = TRY_CONVERT(date, JSON_VALUE(PayloadJson, '$.cancel_date'))
WHERE RequestType = 'CANCEL_CARD'
  AND CancelDate IS NULL
  AND ISJSON(PayloadJson) = 1;
GO

UPDATE dbo.tblCardChangeRequests
SET ProcessedAt = COALESCE(ProcessedAt, CompletedAt)
WHERE RequestType = 'CANCEL_CARD'
  AND ProcessedAt IS NULL
  AND CompletedAt IS NOT NULL;
