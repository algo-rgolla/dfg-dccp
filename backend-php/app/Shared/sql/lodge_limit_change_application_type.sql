SET NOCOUNT ON;

DECLARE @SourceId INT = (
    SELECT TOP 1 ApplicationTypeID
    FROM dbo.tblApplicationTypes
    WHERE ApplicationTypeKey = 'dtc_limit_change'
);

IF @SourceId IS NULL
BEGIN
    RAISERROR('Source application type dtc_limit_change not found.', 16, 1);
    RETURN;
END;

IF NOT EXISTS (
    SELECT 1
    FROM dbo.tblApplicationTypes
    WHERE ApplicationTypeKey = 'lodge_limit_change'
)
BEGIN
    INSERT INTO dbo.tblApplicationTypes
        (ApplicationTypeKey, ApplicationTypeName, Description, IsActive, UpdatedAt, PrivacyAgreementRequired, PrivacyAgreementText)
    SELECT
        'lodge_limit_change',
        'Lodge Card Limit Change',
        'Lodge card credit limit change request.',
        IsActive,
        SYSUTCDATETIME(),
        PrivacyAgreementRequired,
        PrivacyAgreementText
    FROM dbo.tblApplicationTypes
    WHERE ApplicationTypeID = @SourceId;
END;

DECLARE @NewId INT = (
    SELECT TOP 1 ApplicationTypeID
    FROM dbo.tblApplicationTypes
    WHERE ApplicationTypeKey = 'lodge_limit_change'
);

IF @NewId IS NULL
BEGIN
    RAISERROR('Failed to create lodge_limit_change.', 16, 1);
    RETURN;
END;

INSERT INTO dbo.tblApplicationWorkFlowSteps
    (ApplicationTypeID, StepKey, StepLabel, StepOrder, ViewPath, HelpText, IsRequired, AllowBackNavigation, AllowEditAfterSubmit, ValidatorKey, RulesJson, IsActive)
SELECT
    @NewId,
    s.StepKey,
    s.StepLabel,
    s.StepOrder,
    s.ViewPath,
    s.HelpText,
    s.IsRequired,
    s.AllowBackNavigation,
    s.AllowEditAfterSubmit,
    s.ValidatorKey,
    s.RulesJson,
    s.IsActive
FROM dbo.tblApplicationWorkFlowSteps s
WHERE s.ApplicationTypeID = @SourceId
  AND NOT EXISTS (
      SELECT 1
      FROM dbo.tblApplicationWorkFlowSteps t
      WHERE t.ApplicationTypeID = @NewId
        AND t.StepKey = s.StepKey
  );

INSERT INTO dbo.tblWorkflowApprovalRules
    (ApplicationTypeID, EmployeeGroup, MinLimit, MaxLimit, RequiredApproverType, RequiredRank, IsActive, CreatedAt, UpdatedAt)
SELECT
    @NewId,
    r.EmployeeGroup,
    r.MinLimit,
    r.MaxLimit,
    r.RequiredApproverType,
    r.RequiredRank,
    r.IsActive,
    SYSUTCDATETIME(),
    NULL
FROM dbo.tblWorkflowApprovalRules r
WHERE r.ApplicationTypeID = @SourceId
  AND NOT EXISTS (
      SELECT 1
      FROM dbo.tblWorkflowApprovalRules x
      WHERE x.ApplicationTypeID = @NewId
        AND ISNULL(x.EmployeeGroup, '') = ISNULL(r.EmployeeGroup, '')
        AND ISNULL(x.MinLimit, -1) = ISNULL(r.MinLimit, -1)
        AND ISNULL(x.MaxLimit, -1) = ISNULL(r.MaxLimit, -1)
        AND ISNULL(x.RequiredApproverType, '') = ISNULL(r.RequiredApproverType, '')
        AND ISNULL(x.RequiredRank, '') = ISNULL(r.RequiredRank, '')
        AND ISNULL(x.IsActive, 0) = ISNULL(r.IsActive, 0)
  );
