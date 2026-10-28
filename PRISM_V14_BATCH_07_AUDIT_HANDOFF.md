# PRISM V14 — Batch 7 audit handoff

Prepared: 2026-10-28 (Asia/Manila), at the end of Batch 6. Branch: `feature/prism-v14-major-revisions`. Companion: [PRISM_V14_PDF_REQUIREMENTS_TRACEABILITY_DRAFT.md](PRISM_V14_PDF_REQUIREMENTS_TRACEABILITY_DRAFT.md).

Batch 7 is an **independent** requirement-by-requirement audit. Re-derive every conclusion from source, tests and direct UI use. Prior batch reports are leads, not evidence.

## 1. Completed work by batch

| Batch | Scope | Report |
| --- | --- | --- |
| 1 | Fixed four-stage V14 architecture, transition contract, correction cases, schema 12 | `PRISM_V14_BATCH_01_REVIEW.md` |
| 2 | Stage requirements (13 initial, Form 33, Form 33-0B, conditional Amendment), multi-file typed uploads, automatic Final Report completion | `PRISM_V14_BATCH_02_REVIEW.md` |
| 3 | Canonical research projects (schema 13), title-based identifiers, group advancement, synchronized completion, cumulative uploads | `PRISM_V14_BATCH_03_REVIEW.md` |
| 4 | Summary versioning (schema 14), private summary PDFs, AI progress assessments, IERB track sheets (four fixed rows) | `PRISM_V14_BATCH_04_REVIEW.md` |
| 5 | Shared IERB monitor, sorting, selection/comparison, requirement popovers, history, academic-year catalog (schema 15) | `PRISM_V14_BATCH_05_REVIEW.md` |
| 6 | Placeholders, required asterisks, numeric Student IDs, Save/Submit/Cancel behavior, account UX, Student/Adviser accents, deletion confirmations with filename and uploader | `PRISM_V14_BATCH_06_REVIEW.md` |

## 2. Source files relevant to the PDF

- Workflow: `includes/ierb_stages.php`, `includes/ierb_v14_workflow.php`, `includes/ierb_v14_requirements.php`, `includes/document_workflow.php`, `includes/research_projects.php`, `includes/research_project_advancement.php`, `includes/ierb_group_advancement.php`.
- Documents: `documents_api.php`, `includes/document_upload.php`, `includes/document_management.php`, `includes/document_catalog.php`, `assets/js/documents.js`, `assets/js/document-upload.js`, `assets/js/adviser-dashboard.js`.
- AI and reports: `includes/document_summary*.php`, `includes/ierb_track_report.php`, `includes/ierb_track_actions.php`, `includes/report_pdf.php`, `reports_api.php`.
- Monitoring and academic years: `includes/ierb_monitor.php`, `includes/ierb_monitor_view.php`, `assets/js/ierb-monitor.js`, `includes/academic_years.php`, `academic_years_api.php`, `includes/academic_catalog.php`.
- Forms and accounts (Batch 6): `assets/js/prism-forms.js`, `assets/css/prism-forms.css`, `includes/frontend_styles.php`, `assets/js/prism-ui.js`, `includes/account_onboarding.php`, `students_api.php`, `ierb_api.php`, `account.php`, `role_portal.php`, `admin_people.php`, `ierbprog.php`, `complete_profile.php`, `includes/prism-navigation.php`.

## 3. Schema versions and migration dependencies

| Schema | Adds | Runner | Opt-in variables |
| --- | --- | --- | --- |
| 11 | Reviewed baseline (**currently on Hostinger**) | — | — |
| 12 | `ierb_workflows`, `ierb_correction_cases`, `ierb_workflow_events` | `maintenance/migrate-ierb-v14.php` | `PRISM_ALLOW_SCHEMA_V12_MIGRATION=1`, `PRISM_SCHEMA_V12_EXPECT_DB` |
| 13 | `research_projects` and the identifier/member/event/member-history tables | `maintenance/migrate-research-projects.php` | `PRISM_ALLOW_SCHEMA_V13_MIGRATION=1`, `PRISM_SCHEMA_V13_EXPECT_DB` |
| 14 | `document_summary_versions`, `ierb_report_snapshots` | `maintenance/migrate-summary-history.php` | `PRISM_ALLOW_SCHEMA_V14_MIGRATION=1`, `PRISM_SCHEMA_V14_EXPECT_DB` |
| 15 | `academic_years` | `maintenance/migrate-academic-years.php` | `PRISM_ALLOW_SCHEMA_V15_MIGRATION=1`, `PRISM_SCHEMA_V15_EXPECT_DB` |

Every runner:

- Requires explicit `PRISM_MIGRATION_DB_DSN`, `PRISM_MIGRATION_DB_USER` and `PRISM_MIGRATION_DB_PASSWORD`.
- Requires enforced foreign keys, no open transaction, the shared `prism_migrate` mutex and exact pinned contracts.
- Defaults to a no-write dry run.
- Refuses rollback once retained data exists.

**Batch 6 adds no schema.** The developed head is still **schema 15**. Pinned contracts were verified on MariaDB 10.4.32 / PHP 8.2.12 only.

**Final migration sequence:** 11 → 12 → 13 → 14 → 15. Run each strictly in order with a dry run first, then a verified backup, then apply, then a contract check. After migration, V14 enrollment and canonical project reconciliation are **separate, explicit, CLI-gated** operator steps (`PRISM_IERB_V14_FOUNDATION=1`, `maintenance/reconcile-research-project.php`). Migration alone enrolls nothing.

## 4. Deployment context

- Pushing `feature/prism-v14-major-revisions` **automatically deploys the committed code to Hostinger**.
- The Hostinger database is **schema 11**. Code on the branch must stay schema-11 safe. Batch 6 verified this with the full legacy schema-11 regression.
- Hostinger shared hosting has **no SSH**. Batch 7 must find a viable, reviewed way to run the CLI runners against the hosted database, or an equivalent operator procedure (for example a guarded one-time runner removed after use, or a database-console-driven process). It must also prove the method on a restored copy. Do not add an HTTP-reachable migration endpoint without a separate security review.

## 5. Known historical-data limitations

- Existing alphanumeric Student IDs (for example the synthetic `S4`/`S5`) are retained. New or changed Student IDs must be numeric. Batch 7 should count real historical non-numeric IDs on a restored copy.
- Unknown historical submission dates, summary metadata, investigator roles and research categories are not reconstructed; they display as Not recorded.
- Legacy numeric stages (Stage 1–5, Completed) keep their historical meaning. They are never mapped implicitly to V14 stages.
- Pre-Batch 6 document records with an empty `uploaded_by` show **Not recorded** in deletion dialogs.

## 6. V14 enrollment and reconciliation dependencies

- V14 checklists, acceptance, corrections, Form 33 and automatic completion operate only for explicitly enrolled submission holders (schema 12+).
- Canonical projects, title-based identifiers and group-wide completion need schema 13 plus a reviewed membership mapping per project.
- Summary generation needs schema 14. Adding academic years needs schema 15.
- On schema 13+, IERB entry creation can no longer create cohort-numbered groups. Project membership goes through reconciliation. Batch 6 tests confirm that a new IERB entry's Save stays disabled until a group is chosen.

## 7. Security-sensitive flows to audit

- Authentication, session generation (`X-Prism-Generation`), same-origin POST, login rate limiting, legal consent gating, forced password change.
- Account lifecycle: invitation/setup tokens, profile completion, archive/restore, permanent deletion and retention holds.
- Role scoping: a Student sees only their own records; an Adviser sees only assigned Students; RPMS-only actions.
- Document deletion: single delete (Adviser cannot delete formally submitted documents; Admin needs a reason), the new read-only `delete_preview`, the optional `expectedVersion` stale-check, bulk deletion tokens, V14 and summary-retention protection, file cleanup after commit.
- Audit completeness for overrides, deletions, academic-year changes and correction decisions.
- Output encoding of user-controlled strings: filenames, uploader names and titles.

## 8. Required end-to-end role workflows

1. RPMS invites a Student and an Adviser. Each completes setup and profile (numeric Student ID), accepts legal policies and signs in.
2. Student uploads multiple typed documents → Adviser reviews (approve / request resubmission / deny) → automatic formal RPMS submission → RPMS accepts all 13 (enrolled project) → RPMS advances the group.
3. RPMS opens a correction case with and without a mandatory Amendment → Student uploads successors → RPMS accepts and resolves → the workflow resumes the interrupted stage.
4. Form 33 acceptance with an institutional reference → Final Report approval → automatic IERB completion for all members.
5. Summary generation, version history and PDF download; project track sheet with exactly four rows.
6. Monitor sorting, filtering, selection, comparison and history; academic-year addition.
7. Deletion: single (Adviser and Admin) and bulk, including Cancel, stale and protected cases.
8. Each role's account page: name edit, password change, password visibility, theme toggle, mobile layout.

## 9. Full-system regression requirements

Run on a restored isolated copy:

- the legacy schema-11 suite;
- the foundation suite;
- the schema-12 full suite with the documents/projects/report/monitor flags (it migrates to 14/15 internally);
- the Batch 4, Batch 5 and Batch 6 modules;
- PHP and JavaScript syntax;
- PDF rendering checks;
- responsive Chromium checks at 390/768/1024/1440/1920 in both themes.

The commands are in `maintenance/ierb-monitor-tests/README.md` and `maintenance/ierb-ui-tests/README.md`. Add direct manual UI verification for each PDF row. Cross-browser testing (Firefox/WebKit) and screen-reader testing have **not** been done.

## 10. Production migration and rollback planning (to be produced by Batch 7, not executed)

- Take a fresh full logical backup and a file-storage backup, then rehearse restoring both into isolation.
- Rehearse 11 → 15 on the restored copy, including dry runs, timing, lock impact and the runtime preflight (`maintenance/runtime-preflight.php`).
- Decide rollback: empty-table rollback is supported only before data is retained. After activation, the safe rollback is to **disable the features and retain the data**.
- Decide the maintenance window and communication plan, and whether to pause automatic deployment during migration.
- Gate operational V14 enrollment behind a separate authorization after migration.

## 11. Incomplete or uncertain PDF requirements

See the traceability draft, section "Items explicitly not fully satisfied". In summary:

- Every schema 12–15 dependent feature is not operational on Hostinger.
- Sorting has not been inventoried for every list.
- Live AI accuracy is not certified.
- Placement of the "Account UI/UX" and "data versioning for other records" scope needs institutional clarification.
- Some forms use "prevented on click with an inline error" instead of a disabled button (listed in the Batch 6 review).

## 12. Recommended independent acceptance criteria

1. Every PDF row has a requirement → code → test → UI evidence entry with a reproducible command or screenshot.
2. No critical or high finding is open in authorization, deletion safety, audit integrity or data retention.
3. The full regression suites pass on schema 11 and on the migrated schema 15 copy, with zero critical failures.
4. The 11 → 15 migration rehearsal succeeds on a restored production-shaped copy, with row-count and checksum comparisons for preserved tables.
5. A viable no-SSH Hostinger migration method is documented and rehearsed, with a backup and restore drill.
6. Role-based walkthroughs (Student, Adviser, RPMS) pass on mobile and desktop in both themes.
7. Open items that need institutional clarification are recorded with an owner and a decision.
