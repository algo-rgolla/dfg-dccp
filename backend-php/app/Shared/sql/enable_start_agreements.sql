UPDATE dbo.tblApplicationTypes
SET
    PrivacyAgreementRequired = 1,
    PrivacyAgreementText = 'By continuing, you confirm the information you provide for this request is true, complete, and submitted in accordance with departmental policy. You must review the details carefully before proceeding.'
WHERE ApplicationTypeKey IN (
    'dpc',
    'dtc',
    'lodge',
    'dpc_limit_change',
    'dtc_limit_change',
    'lodge_limit_change'
);
