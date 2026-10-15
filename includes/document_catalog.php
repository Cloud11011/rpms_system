<?php
/** New-upload catalog only: never normalize or rewrite historical document labels. */
function prism_document_types(): array
{
    return [
        'Form 11 Review Checklist', 'Form 12 Registration and Application Form',
        'Form 14 Informed Consent Assessment', 'Form 13 Study Protocol Assessment Form',
        'Study Protocol', 'Letter to IERB Chair', 'Certificate of Ethics', 'Payment',
        'Informed Consent - Local Language', 'Informed Consent - English',
        'Data Collection Questionnaire', 'Diagrammatic Workflow', 'Curriculum Vitae',
        'Other Supporting Document',
    ];
}
