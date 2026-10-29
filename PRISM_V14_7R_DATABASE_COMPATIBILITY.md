# PRISM V14 7R: Database compatibility (7R-A)

Base commit `f944220`, local branch `remediation/prism-v14-7r` (uncommitted). Evidence: [PRISM_V14_7R_EVIDENCE/migration/](PRISM_V14_7R_EVIDENCE/migration/).

> No production database was accessed. Every database below was a disposable local server. The production-shaped run used the owner-supplied phpMyAdmin export `<HOSTINGER_DB_NAME_REDACTED> (7).sql` (SHA-256 `861637ac…f651c`, generated 2026-10-28 06:06 UTC by phpMyAdmin 5.2.2 on `12.3.2-MariaDB-log`), restored only into a scratch server.

## 1. Result

| Item | Result |
| --- | --- |
| B7-F02 (errno 150 at schema 13/14) | **FIXED.** Reproduced first with the unmodified f944220 runners on MariaDB 12.3.2 against the real production schema; the remediated runners then take the same database 11 → 15 with every contract passing. |
| New finding B7R-N01 (High): mixed-collation runtime failure | **FIXED.** See §3. |
| B7-F03 (server version) | **FIXED for the reviewed versions.** MariaDB 12.3.2 (Hostinger's version) plus 10.4.32, 10.11.10 and 11.4.4 all pass. MySQL 8 remains untested. |
| B7-F04 (production-shaped rehearsal) | **PARTIAL.** The real schema and data were rehearsed. The export itself is not a faithful backup (§4), so a full-fidelity restore of production is still pending. |
| B7-F06 (preflight rejects 12–15) | **FIXED.** |
| B7-F24 (generic runner errors) | **FIXED.** |
| Real export vs canonical v11 baseline | **One discrepancy:** the `staff_code_singleton` CHECK constraint is absent from the export. Tables, columns, indexes, foreign keys and triggers match the frozen manifest exactly. Whether production itself has the constraint is **NOT VERIFIED** (one read-only query, §6). |

## 2. Production facts measured from the export

| Fact | Value |
| --- | --- |
| Server | `12.3.2-MariaDB-log` |
| `schema_meta.schema_version` | `11` |
| Legacy anchor columns (`documents.id`, `reports.id`, `students.research_title`, `.research_group`, `.stage`, `.academic_year`) | all `utf8mb4_general_ci` |
| Table collations | 17 `utf8mb4_general_ci`, 5 `utf8mb4_uca1400_ai_ci` (tables created after the 11.5+ server upgrade), 1 restored with the database default because its options were missing from the export |
| 12.3.2 server default for utf8mb4 | `utf8mb4_uca1400_ai_ci` (via `character_set_collations`) |
| Student IDs | 17 non-numeric, 6 blank (pending onboarding), of 27 records (answers the B7-F15 count) |

The mixed collations are expected on a MariaDB server upgraded past 11.5: legacy DDL that states only `DEFAULT CHARSET=utf8mb4` picks up whatever default applied when each table was created.

## 3. Root cause and fix

**Two defects, one cause.** The schema 12–15 extension tables were pinned to `utf8mb4_unicode_ci`, but production's legacy tables are `utf8mb4_general_ci`.

1. **FK creation (B7-F02).** `research_projects.completion_document_id → documents.id`, `document_summary_versions.document_id → documents.id` and `ierb_report_snapshots.report_id → reports.id` are VARCHAR foreign keys. Different collations fail with `1005 … errno: 150 "Foreign key constraint is incorrectly formed"` (captured: [orig-f944220-repair/v13-ddl-direct-error.txt](PRISM_V14_7R_EVIDENCE/migration/orig-f944220-repair/v13-ddl-direct-error.txt)).
2. **Runtime failure (new, B7R-N01, High).** The IERB monitor builds `COALESCE(p.research_title, h.research_title)` and `COALESCE(w.stage_key, h.stage)` ([includes/ierb_monitor.php:30-32](includes/ierb_monitor.php#L30-L32)), then filters and searches on them ([:118-120](includes/ierb_monitor.php#L118-L120)). On 12.3.2 with the original schema 12 applied over a general_ci base, MariaDB derives `utf8mb4_bin` with coercibility NONE, so `=`, `LIKE` and `IN` fail with errors 1267/1270 ([orig-f944220-repair/monitor-coalesce-error.txt](PRISM_V14_7R_EVIDENCE/migration/orig-f944220-repair/monitor-coalesce-error.txt) and probes). **Batch 7 could not see this because schema 13 never applied on general_ci.** Fixing only the three FK columns would have left the monitor broken on Hostinger as soon as schema 12 is applied.

**Fix.** [includes/schema_collation.php](includes/schema_collation.php):

- `schema_extension_collation()` reads the six legacy anchor columns. They must all be utf8mb4, agree on one collation, and that collation must be reviewed (`utf8mb4_unicode_ci`, `utf8mb4_general_ci`, `utf8mb4_uca1400_ai_ci`). Otherwise migration refuses with a precise `unsupported_legacy_collation` diagnostic.
- `schema_extension_ddl()` renders each pinned DDL with that collation. Only the single table-default token changes; explicit `ascii_bin` keys are untouched.
- `schema_extension_matches()` compares `SHOW CREATE TABLE` byte-for-byte with only the trailing table option normalised (servers print a charset's default collation differently), **and** requires the authoritative `INFORMATION_SCHEMA.TABLES.TABLE_COLLATION` to equal the legacy collation.
- **Legacy tables are never altered.** No `ALTER … CONVERT`: a collation change can make existing unique keys collide.
- **Contracts.** The pinned JSON contracts are unchanged (byte-identical), so every existing unicode_ci fixture and installation verifies exactly as before. The contract *interpretation* for schemas 12–15 is amended to "pinned definition, rendered for the verified legacy collation". This is an amendment to the reviewed contracts, **not a new schema version**, and it needs reviewer approval (Spec §4, decision R1).

The schema-11 permanent-deletion verification hash covers `account_lifecycle_schema.php`, `schema_v10.php`, `schema_v10_plan.json`, `schema_v11.php` and the v11 manifest. **None of these files changed**, so production's existing evidence stays valid after deployment.

## 4. Export fidelity (affects backups and staging)

phpMyAdmin 5.2.2 wrote `staff_registration_code` as `CREATE TABLE … ( … ) ;`. That drops the `staff_code_singleton` CHECK constraint, the engine and the collation clause. A restore therefore fails the frozen v11 contract ("Staff Code singleton CHECK"). The original runners refuse 11→12 on it (fail-closed; [orig-f944220-norepair](PRISM_V14_7R_EVIDENCE/migration/orig-f944220-norepair/steps.log)).

**Consequences:**

1. A phpMyAdmin SQL export is **not** an acceptable sole backup. Use the hPanel database backup (or `mariadb-dump`), then verify the restored copy contains the CHECK constraint.
2. For staging rehearsals from this export, the runbook applies one documented, staging-only fidelity repair (`ALTER TABLE staff_registration_code ADD CONSTRAINT staff_code_singleton CHECK (slot=1)`). It is **only valid after** the read-only production check in §6 confirms production has the constraint. It must never be run on production.

## 5. Rehearsal matrix (remediated runners)

All rows ran `dry-run` then `apply` for 12, 13, 14 and 15, a full contract check after each step, and SHA-256 fingerprints of every table before and after. Script: [tooling/r7-rehearse.sh](PRISM_V14_7R_EVIDENCE/tooling/r7-rehearse.sh).

| Rehearsal | Server | Legacy collation | v11 | 12 | 13 | 14 | 15 | Legacy tables identical |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| **r7-prod-1232-repair (real export)** | **12.3.2** | **general_ci** | PASS | PASS | PASS | PASS | PASS | **22/22** (only `schema_meta.schema_version` changed; +3 historical academic years) |
| m1232_unicode | 12.3.2 | unicode_ci | PASS | PASS | PASS | PASS | PASS | 22/22 |
| m1232_general | 12.3.2 | general_ci | PASS | PASS | PASS | PASS | PASS | 22/22 |
| m1232_enriched_general (572 docs, 311 Students) | 12.3.2 | general_ci | PASS | PASS | PASS | PASS | PASS | 22/22 |
| m1232_serverdefault | 12.3.2 | uca1400_ai_ci | PASS | PASS | PASS | PASS | PASS | 22/22 |
| m104_unicode | 10.4.32 | unicode_ci | PASS | PASS | PASS | PASS | PASS | 22/22 |
| m104_general | 10.4.32 | general_ci | PASS | PASS | PASS | PASS | PASS | 22/22 |
| m104_enriched_general | 10.4.32 | general_ci | PASS | PASS | PASS | PASS | PASS | 22/22 |
| m1011_general | 10.11.10 | general_ci | PASS | PASS | PASS | PASS | PASS | 22/22 |
| m114_serverdefault | 11.4.4 | general_ci | PASS | PASS | PASS | PASS | PASS | 22/22 |
| m114_enriched_unicode | 11.4.4 | unicode_ci | PASS | PASS | PASS | PASS | PASS | 22/22 |

**Baseline (unmodified f944220) on 12.3.2, real export:**
- Without the fidelity repair: 12 refused (missing CHECK).
- With the repair: 12 PASS, 13 **FAIL errno 150**, and the database stays at a valid 12.

**Mixed-collation probes after the fix** (the five queries that failed before): all succeed ([r7-prod-1232-repair/mixed-collation-probes.txt](PRISM_V14_7R_EVIDENCE/migration/r7-prod-1232-repair/mixed-collation-probes.txt)).

Step timings are 8–11 s per step on local SSD, dominated by INFORMATION_SCHEMA contract checks. Expect longer over Remote MySQL.

## 6. Recovery and failure behaviour (tested)

| Scenario | Result |
| --- | --- |
| Database left at schema 12 by the **f944220** runners on a general_ci base (unicode_ci extension tables) | New runners refuse dry-run and apply with `Unexpected schema 12 definition or collation: … (legacy base utf8mb4_general_ci)`. An **empty** rollback is allowed (pinned tables accepted for rollback only) and returns to 11, then 12→15 applies cleanly with contract PASS ([recovery-from-f944220-v12/](PRISM_V14_7R_EVIDENCE/migration/recovery-from-f944220-v12/)). |
| Partial DDL (table pre-created with reviewed DDL, then apply) | Existing foundation and project migration suites (restart cases) pass on general_ci; see the regression report. |
| Hand-created or drifted extension table | Refused by definition plus collation check (fail-closed, unchanged policy). |
| Bad credentials | `{"errorClass":"connection_failed","sqlstate":"HY000","driverErrno":2054}`. No user, host, DSN or password is echoed. |
| DDL error after connecting | Error class plus SQLSTATE, driver errno and the server message (for example errno 150 text), never the DSN. |
| 15 applied | Rollback refused once historical years exist (unchanged). Only a pre-migration backup restore inside the maintenance window, before user writes; otherwise forward-only. |

## 7. Runtime preflight (B7-F06)

[includes/runtime_preflight.php](includes/runtime_preflight.php) accepts schemas 10–15. For 11–15 it runs the full reviewed contract comparison (`lifecycle_compare_schema`) inside its read-only transaction and reports `contract: PASS|FAIL` with the failing check names, the legacy collation diagnosis and the server version. Verified PASS at schema 11 and schema 15 on the production-shaped database through the cron wrapper ([cron-wrapper/](PRISM_V14_7R_EVIDENCE/cron-wrapper/)).

## 8. No-SSH execution

| Route | Verdict |
| --- | --- |
| **A. hPanel one-shot cron + [maintenance/hostinger-cron-runner.php](maintenance/hostinger-cron-runner.php)** | **Recommended.** It runs exactly one reviewed step per invocation (`probe`, `preflight`, `v12`–`v15` with `dry-run`/`apply`/`rollback`). Credentials come from a JSON file that **must** sit outside the deployed directory (refused otherwise). It writes credential-free receipts. Exercised locally 11→15 on the production-shaped database. **Not yet proven on Hostinger** (requires `proc_open`; the `probe` step checks the PHP binary, extensions and a read-only connection first). |
| **B. Remote MySQL from a workstation** | Supported. The runners now accept `PRISM_MIGRATION_DB_SSL_CA` (TLS with server-certificate verification; verification is never disabled). Requires Hostinger Remote MySQL to be enabled for one IP for the window only. Not proven against Hostinger. |
| **C. SQL-only package in phpMyAdmin** | **UNSUPPORTED for DDL.** phpMyAdmin cannot reproduce the runners' guarantees: the exact 241-column / 88-index / 26-FK / 367-check v11 precondition, collation derivation, the advisory lock across the whole step, refusal before any DDL, restart detection of partially created tables, and writing the version stamp only after verification. A script that "just runs the CREATE TABLEs" can leave an unverified half-migrated schema. phpMyAdmin remains appropriate **only** for the read-only checks in the runbook. |
