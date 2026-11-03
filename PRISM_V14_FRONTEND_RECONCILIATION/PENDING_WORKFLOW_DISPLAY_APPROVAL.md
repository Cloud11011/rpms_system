# Workflow display corrections requiring approval

Status: APPROVED by the user in this session: “Approve these state corrections only”. Separate from the three previously approved exceptions.

Affected: Student dashboard/progress (`assets/js/role-portal.js`), Student Records stage filters/cards (`assets/js/admin-management.js`), IERB historical records (`ierbprog.php`, `assets/js/ierbprog.js`), and the checklist stage indicator (`assets/js/ierb-requirements.js`).

The ZIP reuses historical numeric stage values for its newer four-stage meaning. For example, historical `Stage 4` means Certificate of Approval in V14; the ZIP can display it as completed. Historical Stage 5/Completed filter choices are also removed. Separately, its checklist can give conditional Stage 2 a completed checkmark simply because the project reached Stage 3.

Proposal: retain V14's recorded historical labels, percentages and filter choices; use the authoritative four semantic stages for enrolled V14 projects; keep conditional Stage 2 numbered/conditional rather than marked completed when no case was completed. Reuse the ZIP's existing cards, labels, chips and styles. Only the displayed state/content and historical choices differ.

An adapter cannot truthfully map old Stage 4 to new completion: these are different retained institutional states. Leaving the ZIP behavior would misrepresent progress and hide retained history. No stored stage values or institutional transition rules will change.
