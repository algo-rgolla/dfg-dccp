/*
  System Message Group Scope
  - Adds GroupName-based scope for CCPortal system messages
*/

IF COL_LENGTH('dbo.tblSystemMessage', 'ScopeGroupName') IS NULL
BEGIN
    ALTER TABLE dbo.tblSystemMessage
    ADD ScopeGroupName NVARCHAR(100) NULL;
END;
GO
