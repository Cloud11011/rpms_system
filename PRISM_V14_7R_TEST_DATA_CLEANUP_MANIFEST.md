# PRISM V14 7R: Test-record cleanup manifest and procedure (for review; NOT executed)

> **Superseded scope (compliance closure, 2026-10-28).** The owner has since decided:
> - **preserve every RPMS Administrator account (IDs 1, 13, 46);**
> - **remove every test Student and Research Adviser account** with their test research records.
>
> The "protected" and "confirmed list" decision rules below no longer apply. They were preview heuristics, not lifecycle rules; the four "protected" records are purged by the normal lifecycle.
>
> The current plan, preview tool (`maintenance/clean-database-preview.php --retain-admins=…`), deletion-verification issuer and rehearsals are in [PRISM_V14_7R_COMPLIANCE_CLOSURE/11_CLEAN_PRODUCTION_DATABASE_PLAN.md](PRISM_V14_7R_COMPLIANCE_CLOSURE/11_CLEAN_PRODUCTION_DATABASE_PLAN.md). The text below is kept unchanged as the 7R record.

**For:** the PRISM system owner and RPMS, who must confirm which records are test data. **Status:** preview only. **Nothing was deleted, archived or modified**, and cleanup is not coupled with any schema migration or V14 enrollment.

## 1. Tool

[maintenance/test-data-cleanup-preview.php](maintenance/test-data-cleanup-preview.php) is a CLI-only, read-only tool:

- It takes an explicit `PRISM_CLEANUP_DB_DSN` connection and never loads `config.php`.
- It runs inside a `READ ONLY` transaction that is always rolled back.
- Options: `--confirmed=<json>` (the owner-confirmed test IDs), `--storage=<dir>` (file-existence check) and `--redact` (omits names, emails and Student/Employee IDs).

For each Student and Adviser record it reports:

- **Signals:** test-like words in name, title or group, and reserved test email domains. These are hints only and never confirm a record.
- **Completeness and protections:**
  - protocol code assigned;
  - formally submitted documents;
  - IERB Completed;
  - V14 workflow or project history;
  - Retention Hold;
  - assigned Students (Advisers).
- **Dependencies:** documents and stored file names, history rows, owned reports, notifications, deadline recipients and audit rows.
- **A decision**, plus a `decisionSha256` that binds the reviewed decision list.

**Decision rules:**

| Condition | Decision |
| --- | --- |
| Not in the owner's confirmed list | `retain_unconfirmed` |
| Confirmed, but protected (protocol code, formal submission, Completed, V14 history, hold, assigned Students) | `retain_protected` |
| Confirmed and complete (not incomplete) | `retain_complete_requires_separate_approval` |
| Confirmed, incomplete and unprotected | `delete_via_admin_lifecycle` |

"Incomplete" means the profile is not completed, **or** the record is still at Stage 1 with no formally submitted document and no protocol code.

## 2. Preview against the supplied schema-11 export (restored locally)

Redacted output: [PRISM_V14_7R_EVIDENCE/cleanup/cleanup-preview-redacted.json](PRISM_V14_7R_EVIDENCE/cleanup/cleanup-preview-redacted.json). The unredacted copy for owner review stays outside the repository.

| | Count |
| --- | --- |
| Student records | 27 (26 active, 1 archived) |
| Protected (must be retained) | 4: database IDs **2** and **13** (formally submitted documents), **3** (protocol code), **14** (IERB Completed) |
| Incomplete and unprotected (**candidates**, awaiting confirmation) | 23: IDs 4, 5, 8, 9, 10, 17, 18, 19, 23, 29, 30, 31, 32, 33, 34, 35, 36, 37, 38, 40, 41, 42, 43 |
| Candidates with a test-marker signal | 1 (ID 4, research title) |
| Advisers | 4: IDs 1 (9 assigned Students) and 4 (3 assigned) are protected; IDs 3 and 5 have none assigned |
| Documents / reports / history / notifications / audit rows | 7 / 29 / 26 / 114 / 1,450 |

**No record is currently proposed for deletion:** the owner has not yet confirmed any ID (all are `retain_unconfirmed`). A sample run confirming IDs 4 and 5 correctly produced `delete_via_admin_lifecycle` for exactly those two records.

## 3. What is preserved, what is removed

| Data | Treatment |
| --- | --- |
| Records not confirmed, protected, or complete | Retained unchanged |
| Activity logs / audit evidence | Retained (the lifecycle writes its own deletion audit) |
| Legal policy versions, approvals and acceptance evidence | Retained or handled by the reviewed lifecycle, never by ad hoc SQL |
| Confirmed incomplete test accounts | Removed only through the existing Admin lifecycle (§4), which handles files, notifications and dependencies in one guarded operation with recovery jobs |
| Raw SQL `DELETE`/cascades | **Not used** |

## 4. Procedure (each step separately authorized)

1. **Prerequisite:** a verified full database backup and `storage/` backup ([runbook §3](PRISM_V14_7R_STAGING_MIGRATION_RUNBOOK.md)), with recorded SHA-256 values.
2. The owner reviews the unredacted preview and writes `confirmed.json` (`{"students":[…],"advisers":[…]}`) listing **only** IDs known to be test data.
3. Re-run the preview with `--confirmed=confirmed.json --storage=<storage dir>`. Record `decisionSha256` and the `delete_via_admin_lifecycle` list. Any change in data changes the hash, which means review again.
4. **Rehearse on staging first.** In the staging Admin UI, for each listed record:
   - **Archive** (reasoned);
   - then **Permanent delete**, with a reason, after the grace period, or with the audited `grace_period_override`.

   The service refuses records with Retention Hold, an unresolved workflow, V14 or summary history, future deadlines, or a running notification delivery. It also requires valid deployment verification evidence (`PRISM_HARD_DELETE_VERIFICATION`). Archive is unaffected.
5. Confirm on staging:
   - expected row counts (re-run the preview: the deleted IDs are gone, everything else is unchanged);
   - no orphan files;
   - audit rows present.
6. Production: only after the staging evidence is approved. Do it in a separate window, **not combined with schema migration or V14 enrollment**, after a fresh backup, using the same Admin UI steps.
7. **Cancellation:** stop at any step. Archiving is reversible (Restore); permanent deletion is not. Failed purges leave recovery jobs that the lifecycle's recovery action completes or reverses.

## 5. Ordering note

Run cleanup **before** V14 enrollment of any affected record: V14 workflow and project history deliberately block permanent deletion afterwards. Cleanup at schema 11 is supported by the existing lifecycle. Its relation to the migration window is an owner decision (Final Readiness, D12).

## 6. Not verified

- Production file storage (only the database export was supplied), so file-existence parity is pending.
- Whether `PRISM_HARD_DELETE_VERIFICATION` evidence is currently valid on production (B7-F20). Without it, permanent deletion refuses (fail-closed) and only Archive is available.
