/*
    Create dbo.tblCancelCardReasons and seed the default cancel card reasons.
*/

IF OBJECT_ID('dbo.tblCancelCardReasons', 'U') IS NULL
BEGIN
    CREATE TABLE dbo.tblCancelCardReasons (
        ReasonID int IDENTITY(1,1) NOT NULL PRIMARY KEY,
        ReasonLabel nvarchar(100) NOT NULL,
        SortOrder int NOT NULL CONSTRAINT DF_tblCancelCardReasons_SortOrder DEFAULT (0),
        IsActive bit NOT NULL CONSTRAINT DF_tblCancelCardReasons_IsActive DEFAULT (1),
        CreatedAt datetime2(0) NOT NULL CONSTRAINT DF_tblCancelCardReasons_CreatedAt DEFAULT (SYSUTCDATETIME()),
        UpdatedAt datetime2(0) NULL
    );

    CREATE UNIQUE INDEX UX_tblCancelCardReasons_ReasonLabel
        ON dbo.tblCancelCardReasons (ReasonLabel);
END;

MERGE dbo.tblCancelCardReasons AS target
USING (
    VALUES
        (N'Leaving Defence', 10),
        (N'No Longer Required', 20),
        (N'SERCAT 2', 30),
        (N'Other', 40)
) AS src (ReasonLabel, SortOrder)
ON target.ReasonLabel = src.ReasonLabel
WHEN NOT MATCHED BY TARGET THEN
    INSERT (ReasonLabel, SortOrder, IsActive, UpdatedAt)
    VALUES (src.ReasonLabel, src.SortOrder, 1, SYSUTCDATETIME())
WHEN MATCHED THEN
    UPDATE SET
        target.SortOrder = src.SortOrder,
        target.UpdatedAt = SYSUTCDATETIME();
