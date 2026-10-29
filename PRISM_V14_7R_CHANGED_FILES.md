# PRISM V14 7R: Changed files

Base `f944220`, worktree `.batch7r-worktree`, local branch `remediation/prism-v14-7r`. **Uncommitted.** Line endings follow `core.autocrlf=true`. `vendor/` (gitignored, copied from the Batch 7 audited Composer install) and `PRISM_V14_7R_EVIDENCE/` are not application code.

Reproduce the list with: `git -C .batch7r-worktree status --short` (51 modified files; 9 new code entries, where `maintenance/ierb-7r-tests/` holds 2 files; plus the 10 `PRISM_V14_7R_*.md` reports and the evidence folder).

## Application code (deployed)

| File | Change | Finding / decision |
| --- | --- | --- |
| `includes/schema_collation.php` **(new)** | Legacy-collation derivation, DDL rendering and contract matching | B7-F02, B7R-N01 |
| `includes/schema_v12.php` … `schema_v15.php` | Render and verify extension DDL for the legacy collation; empty-rollback acceptance of f944220-made tables; `extensionCollation` in output | B7-F02, B7R-N01 |
| `includes/migration_runner.php` **(new)** | Shared runner: error class, SQLSTATE and errno; no DSN or credentials; optional TLS CA with verification | B7-F24, B7-F05 |
| `includes/runtime_preflight.php` | Schemas 10–15; full contract check; collation diagnosis | B7-F06 |
| `includes/current_workflow_eligibility.php` | Opt-in verified-member admission | Decision A |
| `includes/research_projects.php` | Member resolution, read authorization, evidence owner, PI designation and read, reconciliation preview service, slug truncation and platform-independent transliteration, Student actors in validation | Decision A/B, B7-F01, B7-F12, B7-F22 |
| `includes/document_management.php` | Student list scope includes own project evidence | Decision A |
| `includes/document_workflow.php` | `member_submit` operation; submitter attribution and messages | Decision A |
| `includes/ierb_v14_workflow.php` | Gate accepts activated HTTP; correction opening refactored into a lock-holding core; shared document validation | B7-F01, Decision C |
| `includes/ierb_v14_corrections.php` **(new)** | Correction requests, decisions, queue, notices, read model | Decision C |
| `includes/ierb_v14_requirements.php` | Member Final Report path; shared document references; title, PI, policy, pending request, viewer role and activation in the checklist | Decision A/C/D |
| `includes/ierb_track_report.php` | Designated PI on the track sheet; notes updated | B7-F12 |
| `includes/report_pdf.php` | Windows-1252/WinAnsi text with Helvetica widths; disclosed `?` substitution | B7-F10 |
| `documents_api.php` | Member uploads to the evidence record; project-aware read check; Student `submit_to_rpms` | Decision A |
| `ierb_api.php` | V14 operation endpoints (gated); Research Title policy and audit on edit; `operationsActivated` | B7-F01, B7-F09, Decision C |
| `reports_api.php` | Research Title column, research-title table, canonical-project table, limitations and verification; list sort | B7-F08, B7-F13 |
| `reports.php`, `assets/js/reports.js` | Report sort control | B7-F13 |
| `students_api.php`, `assets/js/admin-management.js` | Student Records sort control, newest updated by default | B7-F13 |
| `calendar_deadlines_api.php`, `assets/js/calendar-deadlines.js` | Upcoming/overdue view and sort; overdue flag | B7-F13 |
| `assets/js/ierb-requirements.js` | Project facts, PI, pending status, correction request and decision dialogs | Decision C |
| `assets/css/prism-components.css` | Styles for the above | — |

## Operator tools (deployed under `maintenance/`, denied over HTTP, CLI-only)

| File | Change |
| --- | --- |
| `maintenance/migrate-*.php` (4) | Use the shared runner |
| `maintenance/runtime-preflight.php` | Supported-schema message; error class |
| `maintenance/reconcile-research-project.php` | Shared preview service; `designate-pi` |
| `maintenance/v14-operations.php` **(new)** | Enroll and correction operations (B7-F01 minimum) |
| `maintenance/hostinger-cron-runner.php` **(new)** | No-SSH one-step wrapper with a private config and credential-free receipts (B7-F05) |
| `maintenance/test-data-cleanup-preview.php` **(new)** | Read-only cleanup manifest |

## Tests (under `maintenance/`, never deployed functionality)

| File | Change |
| --- | --- |
| `ierb-group-tests/environment.php` **(new)**, `initialize.php`, `bootstrap.php`, `runtime.php`, `mock-curl.php`, `setup.ps1`, `run.cjs` | Port, collation, MariaDB binaries and output folder parameterised (defaults unchanged); legacy tables carry the fixture collation explicitly; exclusive run lock; activation flag file; Batch 7R hook (B7-F11) |
| `ierb-group-tests/backend.cjs` | Restore in `finally` (B7-F11) |
| `ierb-7r-tests/fixture.php`, `integration.cjs` **(new)** | Batch 7R regression module |
| `ierb-project-tests/projects.cjs` | Decision A assertions; non-member isolation |
| `ierb-v14-tests/cases.php`, `ierb-project-tests/migration-cases.php`, `ierb-report-tests/fixture.php`, `ierb-monitor-tests/fixture.php` | Direct DDL uses the legacy collation |
| All `maintenance/ierb-*-tests/*.php` without a guard | Explicit SAPI guard (B7-F21) |

## Not changed (deliberately)

- `includes/account_lifecycle_schema.php`, `schema_v10.php`, `schema_v10_plan.json`, `schema_v11.php`, `account_lifecycle_schema_v11.json`: these are hashed by schema-11 deletion verification.
- All pinned JSON contracts (`ierb_schema_v12.json`, `research_schema_v13.json`, `summary_schema_v14.json`, `academic_schema_v15.json`).
- `config.php` and every legacy table definition.
