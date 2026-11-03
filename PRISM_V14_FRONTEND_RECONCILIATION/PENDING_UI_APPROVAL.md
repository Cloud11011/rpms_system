# Proposed exceptions to exact ZIP presentation

Status: APPROVED by the user in this session: “Approve these three exceptions only”. Approval applies only to the three changes below.

1. **Adviser requirements dialog — `assets/js/ierb-requirements.js`.** The ZIP displays “Request a resubmission” to Advisers and calls the retired `v14_correction_request` route. V14 forbids Advisers from initiating official cases. Proposal: omit this one control for Advisers; keep ordinary document “Request Revision” and the ZIP dialog styling. Keeping a callable official-case request would contradict the approved authority model; keeping a dead control would misrepresent the available action.
2. **RPMS Student Records Add/Invite dialogs and actions — `admin_people.php`, `assets/js/admin-management.js`, `assets/js/invitations.js`, `assets/js/research-assignment.js`.** The ZIP predates the verified V14 research assignment interface. Proposal: retain the existing V14 assignment fields and audited group-information/review actions inside the ZIP's existing styled dialogs. This preserves creation/join decisions, PI/protocol assignment, pending-eligibility review, and approved group enrollment. The ZIP fields alone cannot express all of these required decisions without losing verified functionality.
3. **Archiving a verified group member — `assets/js/admin-management.js`.** V14 requires a reason and warns about retained membership and PI/submission-holder transition. Proposal: retain that reason field and warning in the ZIP's archive confirmation design, only for canonical members. The server rejects archival without the reviewed reason; silently inventing one is not acceptable.

All other layout, colors, spacing, assets and styles remain governed by the original ZIP. These exceptions do not authorize production activity or an institutional workflow change.

## Item 4 — APPROVED by the user on 2026-11-03 with this exact wording: "You can add academic years now. Other academic-year settings will become available after the database update." Approval is limited to that sentence; layout and styling stay as in the ZIP.

4. **Settings → Academic Years status sentence on schema 15 — `assets/js/academic-years.js`.**
   - **Problem.** The ZIP assumes schema 16 exists. On production schema 15, its script disabled **Add academic year**, which removed the existing V14 operation. Codex's API adaptation had matched that.
   - **Internal fix already made (no layout or style change).** The API now returns `canAdd`, and the script enables only the add field and button from it.
   - **Still visible.** On schema 15 the panel still shows the ZIP's sentence "Academic-year management is read-only until the approved database migration is applied." Adding a year actually works, so the sentence is now only partly true.
   - **Proposal.** On schema 15 only, show: "You can add academic years. Registration availability, current-year and removal settings are read-only until the approved database migration is applied." Same element, styling and position. On schema 16 the ZIP text is unchanged.
   - **Why internal adaptation cannot fix it.** The sentence is written into the ZIP script, so correcting it is a visible text change.
   - **Until approval.** The ZIP sentence stays verbatim. The problem disappears once schema 16 is applied.
