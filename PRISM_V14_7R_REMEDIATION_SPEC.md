# PRISM V14 Batch 7R: Remediation specification

| | |
| --- | --- |
| Repository | `Cloud11011/rpms_system` |
| Reference | `feature/prism-v14-major-revisions` @ `f944220d3172a8cd2b8039d148c1d4d8fcbfae40` (verified against `origin` on 2026-10-28) |
| Work location | Local worktree `.batch7r-worktree`, local branch `remediation/prism-v14-7r` (no upstream). **Uncommitted, unpushed, not deployed.** |
| Inputs reviewed | Batch 1–6 reviews and test modules; Batch 7 handoff, final audit, findings, compliance matrix, rehearsal, readiness and runbook; "Major Revision of PRISM" (3 pages); Hostinger schema-11 export of 2026-10-28 (MariaDB 12.3.2-MariaDB-log) |
| Production | Not accessed. Schema 11 remains live. |

## 1. Scope by sub-batch

| Batch | Scope | Status |
| --- | --- | --- |
| 7R-A | Migration compatibility (collation), MariaDB 12.3.2, contracts, preflight, diagnostics, TLS, no-SSH wrapper | Implemented; rehearsed on the real export and a 10-row version/collation matrix |
| 7R-B | Group-wide uploads and formal submission, PI designation, enrollment and reconciliation tooling (CLI + gated HTTP) | Implemented |
| 7R-C | Adviser correction requests → RPMS approve/reject; RPMS-initiated cases; resolution; notifications; UI | Implemented on the schema-12 ledger |
| 7R-D | AI report titles and project aggregation, PDF Unicode, sorting (Student Records, Reports, Deadlines), Research Title policy, PI on track sheet, identifier hyphen | Implemented |
| 7R-E | Test-data cleanup preview, test-harness isolation (finally, run lock, collation-variant fixtures), SAPI guards | Implemented (preview only) |
| 7R-F | Regression, staging runbook, reports | This document set |

## 2. Authoritative decisions applied

The decisions in the Batch 7R brief (A–D) supersede earlier batch assumptions. Where an existing test encoded a superseded rule, it was rewritten (Group Workflow Review §6), never silently deleted.

## 3. Design constraints honoured

- No new schema version. No new persistent table. No change to legacy tables.
- The files hashed by schema-11 permanent-deletion verification are untouched, so production's existing evidence stays valid.
- HTTP never migrates. V14 HTTP operations stay **off** unless `PRISM_IERB_V14_OPERATIONS === true` is set in `config.local.php`.
- No membership inference. Reconciliation requires reviewed per-Student snapshot hashes.
- No credential disclosure: migration and preflight diagnostics are code-based, and cron receipts are credential-free.
- Cleanup is preview-only and executes only through the existing Admin lifecycle.

## 4. Changes requiring reviewer approval before release

| ID | Change | Why | Impact |
| --- | --- | --- | --- |
| **R1** | **Contract amendment for schemas 12–15:** extension tables are created with, and verified against, the legacy base collation (`unicode_ci`, `general_ci` or `uca1400_ai_ci`) instead of always `unicode_ci` | Production is `general_ci`. The pinned `unicode_ci` breaks FK creation (B7-F02) and, worse, the IERB monitor's search and filters (B7R-N01) | Pinned JSON unchanged; behaviour on unicode_ci databases unchanged; a database migrated to 12 by f944220 on general_ci must be rolled back while empty and re-applied (tested) |
| R2 (optional, **not implemented**) | Dedicated `ierb_correction_requests` table (schema 16) | Only if reviewers prefer a separate table to the ledger events | Would add migration step 16, its contract and runner. Not needed for the guarantees. |
| R3 | Rewritten Batch 3 isolation assertions | Decision A | Test semantics only |

## 5. Institutional decisions still open

| # | Decision | Blocks |
| --- | --- | --- |
| D1 | Activate V14 Admin HTTP operations (`PRISM_IERB_V14_OPERATIONS`), or keep operator-only CLI. Who performs enrollment and reconciliation. | V14 activation |
| D2 | Legacy blank Research Titles: keep the grandfathering of an *unchanged* blank (implemented), or require titles before activation | — |
| D3 | Confirm that the implemented sort options satisfy "Oldest/Newest/Alphabetical for all records that need it" | Compliance sign-off |
| D4 | Accept validation-on-confirm dialogs (inline error) as satisfying "click only when information is done" (also used by the new correction dialogs) | Compliance sign-off |
| D5 | 17 historical non-numeric Student IDs (and 6 blank pending onboarding): retain, or correct through the audited edit | Compliance sign-off |
| D6 | Co-investigator roles and Research Category fields (PI is now recorded) | — |
| D7 | Reason required for every Admin deletion | — |
| D8 | Scope of "data versioning for other records" | — |
| D9 | AI accuracy review protocol (provider permission received) | AI sign-off |
| D10 | Role palette / "Account UI/UX" acceptance | — |
| D11 | Amendment Form conditional per case (implemented as RPMS's choice) | — |
| D12 | Cleanup confirmation list and its window (separate from migration) | Cleanup |
