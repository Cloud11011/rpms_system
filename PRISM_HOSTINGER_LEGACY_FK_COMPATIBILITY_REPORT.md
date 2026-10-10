# PRISM final-v9 Hostinger legacy FK-name compatibility

Local coding remediation on 2026-10-09. The reported Hostinger staging failure was an exact-name mismatch: the four relationships and referential actions were correct, but the final-v9 validator expected canonical constraint names. The manifest/verifier should continue rejecting those legacy names after migration. The fix therefore normalizes only the four reviewed legacy definitions inside the existing guarded migration.

**Exact mappings**

| Source table.column | Accepted legacy name | Canonical final-v9 name | Reference | UPDATE / DELETE |
|---|---|---|---|---|
| calendar_deadlines.creator_user_id | `1` | calendar_deadlines_ibfk_1 | users.id | RESTRICT / SET NULL |
| calendar_deadline_groups.deadline_id | `1` | calendar_deadline_groups_ibfk_1 | calendar_deadlines.id | RESTRICT / CASCADE |
| calendar_deadline_recipients.deadline_id | fk_deadline_recipient_deadline | calendar_deadline_recipients_ibfk_1 | calendar_deadlines.id | RESTRICT / CASCADE |
| calendar_deadline_recipients.student_id | fk_deadline_recipient_student | calendar_deadline_recipients_ibfk_2 | students.id | RESTRICT / RESTRICT |

Both source and referenced schemas must be the selected PRISM database. No other legacy aliases are accepted. The two `1` names are table-qualified metadata matches and backtick-quoted DDL identifiers.

**Normalization algorithm and release behavior**

- The existing `prism_migrate` advisory lock covers normalization and the remaining migration. PHP also checks that the current connection owns it before any legacy FK DDL. Manual SQL acquires the same lock and now refuses a NULL lock result as well as an unsuccessful acquisition. FOREIGN_KEY_CHECKS must remain 1; no normalization path disables it.
- Preflight all four mappings before changing any FK. Query every component whose constraint has either the legacy/canonical name or whose source column is the expected column. Use the source schema, source table, constraint name, and table-qualified referential-constraint metadata join. Require exactly one component at ordinal position 1, the exact source column, the same referenced schema, exact target table/column, and exact UPDATE_RULE/DELETE_RULE. PHP uses strict value comparison; manual preflight uses binary identifier/rule comparisons.
- A valid canonical constraint is left unchanged. A valid known legacy constraint is replaced by one static, quoted `ALTER TABLE ... DROP FOREIGN KEY ..., ADD CONSTRAINT ... FOREIGN KEY ... ON UPDATE ... ON DELETE ...` statement. PHP verifies the resulting canonical name immediately. Existing final inventory checks validate all relationships afterwards.
- Missing relationships, unknown names, legacy/canonical duplicates, composite/extra components, wrong source columns, external schemas, wrong targets, and wrong rules refuse with a controlled error. These four FKs are required pre-existing v8 relationships: normalization never invents a missing one or repairs an unknown FK.
- A retry can contain any mixture of already canonical and still-valid legacy names. Partial prior-v9 additive columns/indexes/tables, including account_invitations, are handled by the existing idempotent migration. No version stamp occurs in normalization. MariaDB DDL may persist after an error; failed migrations retain version 8, and retry must pass all existing final validation and backfill requirements before the version-9 stamp.
- PHP retains final inventory validation before backfill, transactional identity backfill, and final inventory validation afterwards; its caller stamps only after return. Manual SQL retains its complete table/column/index/FK/trigger validation, successful transactional identity backfill, and final stamp after those steps. Validation and backfill failures continue to prevent stamping.

Final source schema version remains **9**. No v10 was created. The final manifest is unchanged, including its **12 canonical FK components**, 17 tables, 188 columns, 60 ordered index entries, zero triggers, and SHA-256 `53806ff7217085f843660260dec8c59d4bd37e412a69e014d7e1f116549d265c`. Runtime and global/scoped verifier implementations are unchanged. Retention, purge, invitation, onboarding, authorization and password recovery production code are unchanged.

**Exact files changed relative to the start of this remediation**

- `config.php`: four constant mappings, strict all-mapping preflight, guarded single-statement normalization, and invocation at the start of final-v9 migration.
- `tools/schema-v9-retention-manual.sql`: matching preflight/normalization and fail-closed advisory-lock result check.
- `tests/account-hostinger-fk-migration.php`: new genuine-v8 success/refusal/failure/retry fixture matrix for both migration implementations.
- `tests/account-lifecycle-runner.php`: focused FK suite, retry/guard diagnostic modes and optional portable binary directory; the server still uses its own verified private datadir, random credential and ephemeral loopback port. Its SQL temporary files now also stay under that private directory. FK fixture context is included in failure diagnostics.
- `tests/account-retention-mysql.php`: includes the new matrix in the full retained regression run.
- `.gitignore`: exposes only the new fixture module.
- `PRISM_HOSTINGER_LEGACY_FK_COMPATIBILITY_REPORT.md`: this new report.

The pre-existing modification to `tools/schema-v8-manual.sql`, user review artifacts, existing reports and the `backups/` directory were preserved. The backups and private installed configuration were not read. No production or Hostinger database was accessed, modified, or used as a test fixture.

**Test design**

The runner constructs v8 through the guarded prior-version migrator, then reuses its genuine-v8 DDL/data for independent disposable cases, creating parents first with FK enforcement active. The fixture explicitly models the supplied historical constraint/supporting-index inventory, then installs the exact requested aliases. MariaDB 12.1 creates different default names on fresh unnamed constraints, so using its defaults would not reproduce the supplied historical v8 database.

The exact fixture with `1` on both tables in one database runs on a downloaded official portable MariaDB **12.1.2** server, without installing a Windows service. MariaDB 10.4 requires FK names to be unique across a database, so the existing XAMPP regression also tests each numeric alias separately alongside both named recipient aliases. The complete simultaneous fixture is covered by the separate 12.1.2 run. This version distinction is documented in [MariaDB's constraint reference](https://mariadb.com/docs/server/reference/sql-statements/data-definition/constraint).

For each driver, the fixture checks exact legacy names, final manifest equality and canonical names, 12 FK components, unchanged source/target schemas/tables/columns/actions, preserved original row counts/business fields, successful identity backfill, and an idempotent completed rerun. Existing identity/creator-name backfills refresh automatic `updated_at` timestamps in students/advisers/calendar_deadlines; that existing behavior remains. The injected failure immediately before/after FK DDL separately checks all original row values, including those timestamps, with no backfill allowance, proving FK renaming itself does not mutate data.

Each of all four mappings is tested against 11 refusal states: wrong target table, target column, UPDATE rule, DELETE rule, unexpected constraint name, canonical+legacy duplicate, absent FK, wrong source column, external referenced schema, composite FK, and a canonical name with a wrong target. Failure must occur before any FK normalization, preserve FK metadata, leave schema version 8, retain FK checks, and release the advisory lock.

Each driver also injects a failure before normalization, after the first completed normalization, during backfill, before stamping, and at final validation. Another case simulates the previously failed script's additive final-v9 DDL plus all still-legacy names with schema version 8. Every case must retry to the exact canonical final-v9 manifest while preserving legacy business data. A separately instrumented manual run observes advisory-lock ownership and FK enforcement immediately before every rename; its canonical rerun must perform no normalization ALTER. The ordinary success/refusal cases execute the actual phpMyAdmin script with only its two deliberate confirmation settings substituted.

**Executed results and final Git state**

All requested final checks passed.

| Executed command / scope | Passing total |
|---|---:|
| `C:/xampp/php/php.exe tests/account-lifecycle-runner.php --hostinger-fk-migration --mariadb-bin <portable-12.1.2-bin>`: exact simultaneous Hostinger fixture | 2,392 compatibility assertions; plus 1 runner ownership assertion = 2,393 total |
| PHP portion of exact 12.1.2 compatibility matrix | 1,196 |
| Actual phpMyAdmin SQL portion of exact 12.1.2 compatibility matrix | 1,195 |
| Additional compatibility connection/datadir assertion | 1 |
| `C:/xampp/php/php.exe tests/account-lifecycle-runner.php --retention`: full private XAMPP 10.4.32 regression | 4,480 |
| New compatibility assertions within full XAMPP run | 2,608 (PHP 1,305 + manual 1,302 + connection/datadir 1) |
| PHP syntax | 141 current files |
| JS/CJS syntax | 32 checks: 31 standalone files + existing async-context snippet wrapper |
| UTF-8 decoding | 223 current/review source files |
| Protected manifest/verifier/v8/report/patch hashes | 24 unchanged |
| `git diff --check` | Passed |
| Staged files | None |

The exact Hostinger fixture succeeds for **both** guarded PHP and the actual manual script: genuine v8 with the two numeric aliases plus both named recipient aliases -> canonical final-v9 inventory -> schema version 9. All original FK schemas/tables/columns/actions match before/after; there are exactly 12 final components and canonical names. Both failed-additive-v9 retry cases succeed. The normalization/backfill/stamp/validation failure cases retain version 8 until a successful retry. Foreign-key enforcement remains enabled throughout.

The full XAMPP regression is the prior 1,872 assertions plus 2,608 new compatibility assertions. Its retained suite totals remain:

| Retained scope | Assertions |
|---|---:|
| Guarded PHP/manual migration and schema | 37 |
| Single-account ownership/retention/files | 279 |
| File failure/recovery and basic bulk | 137 |
| Extended provenance/crash/Adviser bulk/Admin | 37 |
| Retention concurrency at both supported isolation levels | 212 |
| Global/scoped verifier | 86 |
| Actual private HTTP including onboarding remediation | 484 |
| Existing final-v9 backfill/failure phases | 85 |
| Onboarding service/lifecycle | 315 |
| Onboarding concurrency at both supported isolation levels | 200 |

Initial diagnostic runs exposed fixture portability/instrumentation mistakes: new-server default FK/index names, existing automatic backfill timestamps, Windows line-ending fault hooks, expected validation-message capitalization, and string formatting of the server's FK-check boolean. These were corrected in the new test harness. SQL temporary files were isolated under the runner-owned directory after temporary-file errors in shared Windows TEMP. One parallel run hit the existing ten-second bulk budget; the final database suites ran sequentially and all retained bulk assertions passed without changing their limits or expectations. No outstanding failure remains.

All **31 CLI regression suites passed**, using `C:/xampp/php/php.exe -d extension=zip tests/<suite>.php`. Counts below retain each suite's own assertion/check/case units:

| Suite | Passed |
|---|---:|
| auth-audit | 16 |
| auth-flows | 52 |
| session-idle-audit | 25 |
| security-audit | 46 |
| rate-limit-audit | 45 |
| logout-audit | 28 |
| crud-audit | 56 |
| document-workflow-audit | 43 |
| notification-delivery-audit | 293 |
| research-group-audit | 197 |
| academic-audit | 591 |
| academic-api-audit | 1,343 |
| alignment-audit | 228 |
| report-format-audit | 274 |
| backend-audit | 87 |
| tonight-polish-audit | 99 |
| readiness-audit | 426 |
| email-format-audit | 26 |
| cache-audit | 149 |
| final-regression-audit | 242 |
| hardening-audit | 114 |
| calendar-deadlines-audit | 190 |
| document-extraction-audit | 37 |
| document-summary-audit | 297 |
| document-summary-transport-audit | 41 |
| archive-operational-audit | 85 |
| csv-export-audit | 93 |
| academic-migration-audit | 331 |
| schema-v6-audit | 392 |
| migration-cli-audit | 145 assertions / 16 cases |
| deadline-migration-cli-audit | 145 assertions / 16 cases |

Starting and ending branch: `prism-v2-final-v8`.
Starting and ending HEAD: `d7dc5123948cc9dba11844c1adbfa958f41da387`.
Source version: **9 -> 9**, manifest unchanged. All changes are local and unstaged.
Ignored local run records are in `tests/final-regression-results/hostinger-fk-*.json` / `.log`; they are not deployment or production verification evidence. A portable MariaDB archive was extracted to a new temporary directory solely for the disposable test instance; no Windows database service was installed. The installed application database and Hostinger staging/production were not queried.

Ending `git status --short`:

```
 M .gitignore
 M config.php
 M tests/account-lifecycle-runner.php
 M tests/account-retention-mysql.php
 M tools/schema-v8-manual.sql
 M tools/schema-v9-retention-manual.sql
?? PRISM_AUTH_VISUAL_REFINEMENT_REPORT.md
?? PRISM_COMBINED_V9_DESIGN.md
?? PRISM_COMBINED_V9_IMPLEMENTATION_REPORT.md
?? PRISM_FINAL_COMBINED_V9_REVIEW.patch
?? PRISM_FINAL_V9_PRECOMMIT_REMEDIATION_REPORT.md
?? PRISM_HOSTINGER_LEGACY_FK_COMPATIBILITY_REPORT.md
?? PRISM_HOSTINGER_LIFECYCLE_FIX_REPORT.md
?? PRISM_ITEM8_REMEDIATION_REPORT.md
?? PRISM_RETENTION_PURGE_DESIGN.md
?? PRISM_RETENTION_PURGE_FULL_REVIEW.patch
?? PRISM_RETENTION_PURGE_IMPLEMENTATION_REPORT.md
?? PRISM_RETENTION_PURGE_REMEDIATION_REPORT.md
?? PRISM_RETENTION_PURGE_REVIEW.patch
?? PRISM_UI_ACCOUNT_LIFECYCLE_REVIEW.md
?? PRISM_V8_BEFORE_RECONCILE.patch
?? PRISM_V8_BEFORE_RECONCILE_STATUS.txt
?? backups/
?? tests/account-hostinger-fk-migration.php
```

No commit, push, merge, tag, deployment, staging, installed database migration,
Hostinger database modification, production access, production verification
installation, or production modification performed.
