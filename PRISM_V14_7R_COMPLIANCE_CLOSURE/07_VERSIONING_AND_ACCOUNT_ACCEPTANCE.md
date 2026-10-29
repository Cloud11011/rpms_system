# 07 · Data versioning scope and account UI/UX review (F08, F06; decisions D8, D10)

**For:** RPMS reviewers deciding D8 and D10.

## A. Data versioning (panel row 9: "Data versioning for all data that needs it. Specifically AI Summarization, applies to other records that need it too.")

**Terms used here:**
- *Versioned* means every prior value is kept as its own retrievable record.
- *Audit-logged* means a change is recorded in `activity_logs`, sometimes with before/after values, but the full prior record is not kept.

Full event history is **not** the same as record versioning: from an audit row you can see that a field changed, but not always what the whole record looked like before.

Immutability is enforced by the application, not the database. Schemas 12–15 define no triggers. No code path updates or deletes ledger rows, and `RESTRICT` foreign keys stop parent deletion. The account lifecycle refuses to purge an account that has V14, project or summary history.

| Data | Kind | What is kept | Can it be deleted? | Schema |
| --- | --- | --- | --- | --- |
| **AI document summaries** | **Versioned** | Every generation is an append-only version tied to the exact document version, with its own PDF | No: retained summaries block document deletion and account purge | 14 |
| Documents | **Versioned** (chain) | `version_no`, `supersedes_id`, `is_current`; earlier versions stay readable | Yes, by staff, audited with filename and uploader. A formally submitted version needs an Admin and a reason. V14 evidence is protected at 12+. | 11 |
| IERB track-sheet reports | **Versioned** (snapshots) | `ierb_report_snapshots` freeze the inputs of each generated track sheet | Snapshot rows have no delete path | 14 |
| Generated reports (AI and progress) | Each generation is a new record | Title, type, generator, file | Yes (Admin, audited) | 11 |
| V14 workflow decisions | **Versioned ledger** | `ierb_workflow_events`: every acceptance, advancement, correction request/decision, case open/resolve and completion, with revision, actor, reason and evidence | No | 12 |
| Research projects | **Versioned history** | `research_project_events`, `research_project_member_history`, retained title aliases in `research_project_identifiers` | No | 13 |
| Legal policies | **Versioned** | `legal_policy_versions` with approvals | No | 11 |
| Legacy IERB stage history | Event history | `ierb_history` row per progress change | Removed with the Student by purge | 11 |
| Student identity (email, Student ID, login) | Audit-logged **with before/after** | `account_identity_field_changed` | Removed with the Student by purge | 11 |
| Research Title | Audit-logged **with before/after** | `research_title_changed`: IERB entry path (7R) and now Student Records path (B7C-01, this pass) | as above | 11 |
| Other Student record fields (academic unit/program/year level, academic year, group, Adviser, protocol code, requirements) | Audit-logged **without** before/after for most fields | `student_saved` / `ierb_saved` (identifier only); academic-year changes have their own action | as above | 11 |
| Adviser records | Audit-logged | Save and lifecycle actions | Lifecycle | 11 |
| Official deadlines | Audit-logged | Create and cancel; cancelled rows remain | No delete operation | 11 |
| Notifications | Not versioned | Archive and delete (content erased) | Soft delete only | 11 |
| Academic-year catalog | Additive and audited | New years are added, never edited in place | No | 15 |

### Recommendation for D8

1. **Accept** the versioned set above as meeting "AI summarization" and the evidence-bearing records.
2. **Add before/after audit values** for the remaining Student record fields: academic unit, program, year level, academic year, research group, assigned Adviser, protocol code and requirements. These drive reports and the monitor, so a reviewer should be able to see the previous value. This needs no new table (it reuses `activity_logs.before_value/after_value`), but it is new code with tests, so it waits for RPMS approval.
3. **Do not** add full-record snapshot tables. That would need a new schema version and goes beyond what the panel row asks.

Row 9 stays **PARTIAL** until RPMS records the D8 scope.

## B. Account UI/UX review: Student, Adviser, RPMS Administrator (panel rows 6 and 7)

### B1. Evidence

| Role | Page | Checks executed in this pass | Result | Screenshots |
| --- | --- | --- | --- | --- |
| Student | `role_portal.php#profile` | Batch 6 module (6 runs at schema 11 + final chain): labels; read-only Student ID not marked required; Save disabled until a change; placeholder contrast 4.92:1 light / 7.17:1 dark; asterisk contrast ≥ 3:1; role chip; no overflow at 390–1920 px in both themes | PASS | [runs/cc1232/08-batch6-only-schema11-r6](evidence/runs/cc1232/08-batch6-only-schema11-r6/) `student-profile-*` |
| Adviser | `account.php` | Batch 6: Save enables only after a real change and reverts; blank name blocked; failure preserves input; repeated Enter sends one request; password show/hide named with `aria-pressed`; mismatch keeps Change password disabled with a field message; touch targets ≥ 24 px | PASS | same folder, `account-adviser-*` |
| RPMS Admin | `account.php` | New F06 script: every visible control labelled; read-only fields dashed with a linked note; Save disabled until a change; both password toggles named with state; required markers present; no horizontal overflow; theme applied; no page errors. 390/1440 px, light/dark | PASS | [evidence/f06-account](evidence/f06-account/) (before fix), [f06-account-after-asterisk-fix](evidence/f06-account-after-asterisk-fix/) |
| Role colours | all | Student magenta / Adviser indigo, text and icon chips, active navigation ≥ 4.5:1 in both themes | PASS | `role-colors.json` in the Batch 6 run folders |

### B2. Defect found and fixed

**B7C-05 (Low, visual):** on the Admin and Adviser account pages, the red asterisk for each password field sat alone on the line under its label. The cause was a shared `display:grid` rule for labels in form containers. **Fixed** in `assets/css/prism-workspace.css` with one rule for labels that do not wrap their control. Before and after screenshots are linked above.

### B3. Observations for RPMS (not changed: they are design choices or need a decision)

1. **Admin navigation drawer on a new browser session.** At desktop widths a new tab opens with the navigation drawer expanded over the page and the content dimmed, until the user presses Escape or clicks outside. The choice is remembered for that tab only. RPMS may prefer it collapsed by default.
2. **Different page names by role.** Students see "Profile"; staff see "Account & Activity". The Students' "Save name" button has no icon, unlike the staff one.
3. **Student header at 390 px.** The avatar at the right edge of the top bar is slightly clipped. There is no page overflow (measured), only a tight fit.
4. **No role chip for Admins.** Only Students and Advisers have a portal chip, by Batch 6 design.
5. **Not covered:** screen readers, and browsers other than Chromium (B7-F18).

### B4. Recommendation for D10

Present the B1 screenshots to RPMS and record either acceptance or a change list. Items 1–3 above are small, separate UI tasks if RPMS wants them. Rows 6 and 7 are technically complete. Row 6 stays **PARTIAL** until RPMS accepts "Account UI/UX", because the requirement is open-ended. Row 7 is **PASS** technically, with palette acceptance under D10.
