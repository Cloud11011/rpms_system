# PRISM schema 15 → 16: Academic Year and Communication Settings

**Status: prepared and rehearsed on disposable databases only. Not approved for production.** Running any file in this folder against the live database needs your separate, explicit approval. Nothing here has touched Hostinger.

Files in this folder:

| File | What it does | Changes data? |
| --- | --- | --- |
| `PRISM_SCHEMA_16_VERIFY.sql` | Read-only report. Last row says `PASS`, `NOT_MIGRATED` or `FAIL`. | No |
| `PRISM_SCHEMA_15_TO_16.sql` | Checks every precondition, then creates four tables, seeds defaults and stamps schema 16. | Yes |
| `PRISM_SCHEMA_16_ROLLBACK.sql` | Returns to schema 15, only while no Settings data or Settings audit history exists. | Yes |

The SQL is generated from the reviewed PHP table definitions by `maintenance/frontend-reconciliation/build-schema16-sql.py`. Do not edit the `.sql` files by hand; regenerate them instead.

## 1. Why schema 16 is needed

Production can keep running on schema 15. On schema 15 the reconciled application works normally:
- Every academic year in the existing catalog is offered for registration.
- The Settings page shows default values.
- Saving a Settings change is refused with "requires the approved settings migration".

The ZIP's Settings page needs to **store** administrator choices that schema 15 has no place for:

| Setting | New table | Why no schema-15 table fits |
| --- | --- | --- |
| Registration availability and the current academic year | `academic_year_settings` | `academic_years` holds only the year, a `historical` flag, the creator and a timestamp. The `historical` flag already has a meaning, enforced by a CHECK constraint. |
| Notification templates and reminder intervals | `prism_settings` | V14 has no settings table. `schema_meta` is the verified migration ledger and must not hold application data. |
| One reminder per Student, deadline, date and interval | `prism_deadline_reminder_dispatches` | Without it, reminders repeat on every worker run. |
| Remove a year from registration choices while keeping it | `academic_year_removals` | Deleting catalog rows would break historical Student assignments. |

The migration is **additive only**:
- It adds four tables.
- It does not alter, rename or drop any existing table or column.
- It never changes any Student's academic year, program or year level.
- It reuses the existing academic-year catalog and Add Academic Year operation.

## 2. Before you run anything

1. Get written approval for this production change.
2. **Deploy the reconciled code first.** It runs correctly on schema 15, so deploying it does not depend on this migration. It also contains the verifiers that confirm the result.
3. **Take a faithful backup.** Use hPanel → Files → Backups → Database backups. Do not rely on a phpMyAdmin Export: the project's 7R review showed that it drops a CHECK constraint.
4. Choose a quiet window. Pause the hPanel cron job that runs `notification-dispatch.php` until step 4.5 is complete.
5. Open phpMyAdmin and select **only** the PRISM database.

## 3. Option A — the project's reviewed runner (no SSH)

Production reached schema 15 through `maintenance/hostinger-cron-runner.php`, which now also has `v16` and `settings` steps. The project's earlier review (`PRISM_V14_7R_DATABASE_COMPATIBILITY.md` §8) preferred this route over phpMyAdmin DDL. It applies the exact PHP verifier at every step. Run each line as its own one-time cron job, and check its receipt before scheduling the next:

```
… hostinger-cron-runner.php <private.json> preflight x          → contract: PASS, schema_version 15
… hostinger-cron-runner.php <private.json> v16 dry-run          → ok: true
… hostinger-cron-runner.php <private.json> v16 apply            → ok: true, to: "16", studentAssignmentsChanged: 0
… hostinger-cron-runner.php <private.json> settings dry-run     → ok: true
… hostinger-cron-runner.php <private.json> settings apply       → ok: true, migrationApplied: true
… hostinger-cron-runner.php <private.json> preflight x          → contract: PASS, schema_version 16
```

The private JSON file rules are the same as in `PRISM_V14_7R_STAGING_MIGRATION_RUNBOOK.md` §5.

## 4. Option B — manual SQL in phpMyAdmin

Run each file in the **SQL** tab:
- Paste the whole file and click **Go**.
- Leave "Enable foreign key checks" ticked.
- Do not tick anything that continues after errors.

1. **`PRISM_SCHEMA_16_VERIFY.sql`**. Expect `schema_version` = 15 and `overall` = `NOT_MIGRATED`. If it says `FAIL`, stop and keep the result.
2. **`PRISM_SCHEMA_15_TO_16.sql`**. Expect a final result row reading `MIGRATED_TO_16 - now run PRISM_SCHEMA_16_VERIFY.sql`.
3. **`PRISM_SCHEMA_16_VERIFY.sql`** again. Expect:
   - `overall` = `PASS`
   - `schema_version` = 16
   - `prism_settings_v1` = 1
   - `table list` = `schema16 exact`
4. Optional but recommended: run the runtime preflight (Option A's `preflight` step). It applies the application's exact contract comparison, which plain SQL cannot fully reproduce.
5. Re-enable the notification cron job.
6. Sign in as an RPMS Administrator, open **Settings**, and save one harmless value (for example, set the reminder intervals to their current values). A save that succeeds confirms the application accepted the schema.

Save every result grid as CSV for the evidence log.

### What a stop looks like

Each precondition, if it fails, ends the script with an error such as:

```
#1054 - Unknown column 'PRISM ABORT: schema_version must be 15 - nothing was changed' in 'field list'
```

This is deliberate. phpMyAdmin stops at the first error. Messages ending "nothing was changed" happen **before** any table is created. Read the message, keep the result, and do not retry until the cause is understood.

| Message (shortened) | Meaning |
| --- | --- |
| `another schema or settings change is running` | The shared `prism_migrate` lock is held. Wait and retry. |
| `schema_version must be 15` | Not on schema 15, or already migrated. Run VERIFY. |
| `table list differs from reviewed schema 15` | Unknown or partial tables exist (for example, from an interrupted earlier attempt). Run VERIFY, then ROLLBACK to clean up the empty partial tables. |
| `legacy anchor collations are missing, mixed or unreviewed` | The database collation is not one of the reviewed three (`utf8mb4_unicode_ci`, `utf8mb4_general_ci`, `utf8mb4_uca1400_ai_ci`). |
| `created tables do not match the reviewed contract - version NOT stamped` | Tables were created but differ from the contract, and the version was **not** changed. Run VERIFY, then ROLLBACK. |

## 5. What the SQL guarantees, and what it does not

Built into `PRISM_SCHEMA_15_TO_16.sql`:
- It takes the same `prism_migrate` advisory lock that the application's Settings writers and the PHP migrators use.
- It refuses to start unless:
  - the server is MariaDB
  - the version is 15 and the Settings marker is absent
  - the table list exactly matches reviewed schema 15 (plus the optional research-onboarding extension when its marker is set)
  - foreign-key checks are on
  - the six legacy anchor columns share one reviewed utf8mb4 collation
- It creates the tables in that derived collation, exactly as the PHP migrator does.
- Before stamping, it compares each new table's columns, types, defaults, indexes, foreign keys and named CHECK constraints against pinned fingerprints.
- It writes `schema_version = 16` and the `prism_settings_v1` marker **last**, in one transaction, only after that comparison succeeds.

Not reproduced by SQL (covered by Option A's preflight and by the application itself):
- Byte-exact `SHOW CREATE TABLE` comparison, including the CHECK clause text.
- The full schema 11–15 base contract (hundreds of columns, indexes and constraints).

The application runs both checks whenever it uses Settings, and fails closed: on a mismatch, Settings is refused, never silently accepted.

DDL cannot be rolled back by MariaDB. If the script stops after creating tables, the version stays at 15. The application then refuses the unexpected tables until `PRISM_SCHEMA_16_ROLLBACK.sql` removes them; it removes only empty tables.

## 6. Non-reversible effects and recovery

- **Historical catalog rows.** As in the PHP migrator, any `students.academic_year` value missing from `academic_years` is added as a `historical` catalog row. Rollback keeps these rows: they only describe existing assignments.
- **Rollback window.** `PRISM_SCHEMA_16_ROLLBACK.sql` (and the PHP `rollback` modes) refuse while any of the following exist:
  - a saved setting
  - a reminder receipt
  - a removed year
  - a non-default availability or current-year choice
  - `communication_setting_updated` or `academic_year_removed_from_registration` audit history

  After Settings has been used, the recovery path is **not** to roll back. Keep the data, and if needed restore defaults through the Settings page or redeploy the previous code. Never delete Settings data or audit records to make a rollback pass.
- **Permanent-deletion verification.** The account-lifecycle verification hash now includes the schema-16 and Settings contracts. Any existing deletion-verification evidence (`PRISM_HARD_DELETE_SCHEMA_VERIFIED`) becomes invalid after migration. Reissue it with the reviewed `deletion-verification` step. Until then, permanent deletion stays unavailable; archiving still works.
- **Full restore.** If anything unexpected happens, restore the hPanel backup from step 2.3.

## 7. Rehearsal evidence (disposable databases only)

Each run built a fresh synthetic schema-11 database, migrated it to 15 with the reviewed runners, then ran one scenario. Runner: `maintenance/frontend-reconciliation/settings-run.ps1`. Checks: `maintenance/frontend-reconciliation/settings-tests.php`. Results are in each fixture's `settings-results.json` under the ignored `tests/` folder.

| Scenario | Server / collation | Result |
| --- | --- | --- |
| SQL route: refuse partial state, migrate, VERIFY, refuse re-run, roll back, re-migrate, use Settings, refuse rollback with data | MariaDB 12.3.2 / general_ci | 50 / 50 pass |
| Same SQL route | MariaDB 10.4.32 / unicode_ci | 50 / 50 pass (after fixing a VERIFY report collation issue found here) |
| Same SQL route | MariaDB 12.3.2 / uca1400_ai_ci | 50 / 50 pass |
| PHP route: schema 15 behaviour, v16 + Settings, drift, authorization/audit, academic years, registration enforcement, reminders, blocked rollbacks, onboarding added afterwards, VERIFY.sql on the PHP result | MariaDB 12.3.2 / general_ci | 75 / 75 pass |
| PHP route: onboarding first, then v16 + Settings | MariaDB 12.3.2 / general_ci | 36 / 36 pass |
| PHP route: clean rollback, custom-setting refusal | MariaDB 12.3.2 / general_ci | 17 / 17 pass |

The production export recorded earlier in this project came from MariaDB 12.3.2 with `utf8mb4_general_ci`. That is the first row's configuration, but production itself was not queried. Confirm with Option B step 1 (VERIFY) before migrating.
