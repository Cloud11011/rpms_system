<?php
/** New-upload catalog only: never normalize or rewrite historical document labels. */
function prism_document_types(): array
{
    return [
        'Form 11 Review Checklist', 'Form 12 Registration and Application Form',
        'Form 14 Informed Consent Assessment Form', 'Form 13 Study Protocol Assessment Form',
        'Study Protocol', 'Letter to IERB Chair', 'Certification of Ethics', 'Payment Receipt',
        'Informed Consent in Local Language', 'Informed Consent in English',
        'Data Collection Questionnaire', 'Diagrammatic Workflow', 'Curriculum Vitae',
        'Other Supporting Document',
        'Amendment Form', 'Form 33 - Certificate of Approval', 'Form 33-0B - Final Report Form',
    ];
}

/** Keep existing catalog clients valid without showing duplicate names in the new selector. */
function prism_document_upload_types(): array
{
    return array_merge(prism_document_types(), ['Certificate of Ethics', 'Payment',
        'Form 14 Informed Consent Assessment', 'Informed Consent - Local Language', 'Informed Consent - English']);
}
