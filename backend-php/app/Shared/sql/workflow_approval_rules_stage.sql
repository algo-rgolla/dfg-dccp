-- Adds approval stage ordering to workflow approval rules for sequential approvals.
-- Run this on the CCPortal database.

IF COL_LENGTH('dbo.tblWorkflowApprovalRules', 'ApprovalStage') IS NULL
BEGIN
    ALTER TABLE dbo.tblWorkflowApprovalRules
    ADD ApprovalStage INT NOT NULL
        CONSTRAINT DF_tblWorkflowApprovalRules_ApprovalStage DEFAULT (1);
END;
