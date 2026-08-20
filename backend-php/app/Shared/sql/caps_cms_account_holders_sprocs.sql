SET ANSI_NULLS ON
GO

SET QUOTED_IDENTIFIER ON
GO

CREATE OR ALTER PROCEDURE dbo.spCAPSGetCmsAccountHolders
    @EmployeeID NVARCHAR(50)
AS
BEGIN
    SET NOCOUNT ON;

    SELECT
        user_name,
        active_indicator
    FROM dbo.tblCAPSProMasterUser
    WHERE employee_id = @EmployeeID
    ORDER BY
        CASE WHEN active_indicator = 'Y' THEN 0 ELSE 1 END,
        user_name;
END
GO

CREATE OR ALTER PROCEDURE dbo.spCAPSIsCmsAccountHolderActive
    @EmployeeID NVARCHAR(50),
    @UserName NVARCHAR(255)
AS
BEGIN
    SET NOCOUNT ON;

    SELECT TOP 1
        1 AS IsActive
    FROM dbo.tblCAPSProMasterUser
    WHERE employee_id = @EmployeeID
      AND user_name = @UserName
      AND active_indicator = 'Y';
END
GO
