Permanent deletion is disabled by default. Archive remains available independently. This procedure is documentation only; no installed or production configuration has been enabled.

Two explicit verification modes are supported:

- `--verify --schema-changes-excluded`: `globally_privileged`. The operator connection must have direct global SELECT and TRIGGER grants. This retains the original complete server metadata prerequisite. The normal web connection need not have global grants.
- `--verify-shared-hosting --schema-changes-excluded --external-dependencies-excluded`: `schema_scoped_shared_hosting`. This is intended for an isolated shared-hosting database such as Hostinger. It requires direct schema-wide SELECT and TRIGGER grants for every visible application schema. Table-only, column-only, or unrecognized inherited-role grants are insufficient. It never falls back from the global mode.

The scoped mode checks SHOW GRANTS FOR CURRENT_USER(), SHOW DATABASES, INFORMATION_SCHEMA.TABLES, COLUMNS, STATISTICS, KEY_COLUMN_USAGE, REFERENTIAL_CONSTRAINTS, and TRIGGERS. It enumerates all visible application schemas, requires their schema-wide visibility, and binds a fingerprint of their visible tables, FK components/rules, and triggers. It rejects visible incoming/outgoing cross-schema PRISM FKs, unresolved FK rules, and all visible application triggers. PRISM tables, engines, ordered columns/types/nullability/defaults/keys, indexes and index column order, schema-qualified FK targets and DELETE/UPDATE rules, triggers, and schema version must exactly match `includes/account_lifecycle_schema.json`. The manifest is unchanged; a hosting difference must not be erased to force a pass.

The scoped mode records `complete_visibility = false`. It cannot establish that an inaccessible database has no incoming foreign key. The additional exclusion acknowledgement is an operational assurance, not a metadata result. Before using it, the operator must establish with the hosting provider and all database administrators that hidden schemas do not reference PRISM and no other application/definer process adds dependencies or changes the schema during the enabled window. An isolated tenant/database ownership arrangement is the intended environment. Merely seeing one database in phpMyAdmin is insufficient proof. If this exclusion cannot be established, leave permanent deletion disabled and use Archive.

Foreign-key checks remain enabled on the deletion connection. An unexpected hidden RESTRICT/NO ACTION reference fails deletion and rolls back the audit and all cleanup, returning 409. Foreign-key enforcement does NOT stop hidden CASCADE or SET NULL effects. The external-dependency exclusion must rule these out before scoped mode is used. Application code adds no cascades, disables no constraints, and removes no unknown dependencies. Arbitrary external DDL bypasses the application advisory lock. These limitations also explain why the global mode's DDL exclusion remains necessary when a scoped web connection follows a privileged operator verification.

Verification evidence version 2 is bound to the exact manifest SHA-256, database name, server hostname/port/server ID/version, assurance mode, schema-change exclusion, and issue/expiry timestamps. Scoped evidence additionally binds the external-dependency policy/acknowledgement and visibility assessment. It expires after 24 hours. The timestamp uses Unix time, independent of timezone. The two emitted PHP definitions are a trusted operator configuration artifact, not a signed database certificate: protect `config.local.php` from unauthorized edits. Old evidence without an explicit mode and lifetime must be regenerated. Editing dates, mode, database bindings, or assertions is not re-verification.

Every permanent-delete request repeats the live inventory comparison and evidence validation under the existing `prism_migrate` advisory lock. Scoped mode also repeats grants, visible-schema enumeration, and the visible metadata fingerprint. A schema move, server upgrade, manifest change, privilege/scope change, metadata deviation, expiry, or missing evidence disables deletion. The Admin UI's read-only availability request checks the same deployment gate; it never decides account eligibility and cannot override a later server refusal.

No-SSH Hostinger procedure (local PHP/XAMPP, Remote MySQL, File Manager, phpMyAdmin):

1. Keep `PRISM_HARD_DELETE_SCHEMA_VERIFIED` false in production. Arrange a short controlled maintenance window: exclude PRISM DDL, incoming external references, external trigger/definer writes, and uncoordinated import/direct-SQL jobs. Ensure the scoped external-dependency assurance above is established. Take the normal operator backup. This task does not deploy code or change production data.
2. When separately authorized to deploy the reviewed code, use Hostinger File Manager to upload the changed application PHP/JS/CSS files and the unchanged manifest together. Keep the local verifier CLI/tests/reports off the public website. Match the local verification checkout to the deployed manifest/code. Do not upload local `config.local.php` or overwrite production secrets. Keep deletion disabled while replacing files.
3. In hPanel, open this website's Databases > Remote MySQL. Add the local workstation's public IP for the exact production database, and copy the MySQL hostname shown there. Use the production DB name/user (including their Hostinger prefixes), port, and existing password. Do not use local `localhost` as the remote hostname. The deployed web connection may use `localhost`; both routes must resolve to the same database server. [Hostinger Remote MySQL instructions](https://www.hostinger.com/support/1583546-how-to-set-up-remote-mysql-access-in-hostinger/).
4. Open PowerShell locally in this checkout. Run the following, replacing the three nonsecret placeholders. The password prompt is hidden; credentials are process environment variables and are not written to the repository or passed as command arguments.

```powershell
Set-Location 'C:\xampp\htdocs\rpms_system'
$env:PRISM_SCHEMA_VERIFY_HOST = 'HOSTNAME_FROM_HOSTINGER_REMOTE_MYSQL'
$env:PRISM_SCHEMA_VERIFY_PORT = '3306'
$env:PRISM_SCHEMA_VERIFY_DATABASE = 'EXACT_HOSTINGER_DATABASE_NAME'
$env:PRISM_SCHEMA_VERIFY_USER = 'EXACT_HOSTINGER_DATABASE_USER'
$prismVerifierSecret = Read-Host 'Production database password' -AsSecureString
try {
    $env:PRISM_SCHEMA_VERIFY_PASSWORD = [Net.NetworkCredential]::new('', $prismVerifierSecret).Password
    & 'C:\xampp\php\php.exe' 'tools\verify-account-lifecycle-schema.php' --verify-shared-hosting --schema-changes-excluded --external-dependencies-excluded
    if ($LASTEXITCODE -ne 0) { throw 'Schema verification refused; leave permanent deletion disabled.' }
} finally {
    Remove-Item Env:\PRISM_SCHEMA_VERIFY_PASSWORD -ErrorAction SilentlyContinue
    Remove-Item Env:\PRISM_SCHEMA_VERIFY_USER -ErrorAction SilentlyContinue
    Remove-Item Env:\PRISM_SCHEMA_VERIFY_DATABASE -ErrorAction SilentlyContinue
    Remove-Item Env:\PRISM_SCHEMA_VERIFY_HOST -ErrorAction SilentlyContinue
    Remove-Item Env:\PRISM_SCHEMA_VERIFY_PORT -ErrorAction SilentlyContinue
    $prismVerifierSecret.Dispose()
}
```

5. Review the success output: mode must be `schema_scoped_shared_hosting`, complete visibility must be false, all visible application databases must be expected, and the DB/server binding must identify production. The CLI connection uses a read-only transaction, loads no application config, calls no migrations/seed routines, and writes no DB data. If there is no success output and no generated definitions, stop. phpMyAdmin can help inspect discrepancies; it is not a substitute for running this verifier as the application DB user. Missing schema-level TRIGGER visibility requires provider review, not a boolean bypass.
6. In Hostinger File Manager, edit the existing protected production `config.local.php`. Replace any existing hard-delete definitions with BOTH exact definitions emitted by the successful verifier: `PRISM_HARD_DELETE_SCHEMA_VERIFIED` and `PRISM_HARD_DELETE_VERIFICATION`. Paste only the PHP `define(...)` statements inside PHP, without the human-readable success lines. Do not duplicate constants, change the generated fields, replace DB credentials, or copy verification evidence from local XAMPP. The output contains no verifier password.
7. Sign in as an Admin and reload Student Management. Its availability message must show valid verification; an archived account's red trash control becomes available. If runtime verification fails or expires, the control explains the block. Use browser Network inspection of `account_lifecycle_api.php?action=availability` if needed; do not publish the config. A remote/local visibility difference fails closed and requires matching direct schema access and new evidence.
8. For a genuinely unused new test/demo Student: Archive first, open Permanent Delete, enter the CURRENT Admin password and exact Student ID, and check both acknowledgements. For an Adviser, use the exact Employee ID after Archive. A 409 preserves the account and history; use Archive. Do not edit identity/progress/history or backfill provenance to make an institutional record deletable.
9. At the end of the window, set `PRISM_HARD_DELETE_SCHEMA_VERIFIED` false and remove/revoke its evidence. Remove the temporary Remote MySQL IP allowance if no longer needed. Disable deletion before any maintenance. Repeat local remote verification to open another window; the evidence is not a permanent enable switch.

Admin authorization, current password verification, exact targeting, same-origin write protection, audit persistence, archived/inactive-first rules, immutable creation provenance, login ownership, identity restrictions, dependency checks, and transaction/row locks remain mandatory. Student/Adviser institutional documents/versions, meaningful IERB history, research progress, notifications, deadline recipients, protected audit/workflow evidence, or report/AI attribution ambiguity require Archive. Admin lifecycle remains self-only and another active Admin must remain.

Concurrency validation uses disposable MariaDB 10.4.32 with READ COMMITTED and REPEATABLE READ. Additional writers, database versions/isolation levels, or direct SQL/import jobs require operator validation/coordination. Hostinger production has not been contacted by this implementation or its tests. [MySQL trigger visibility](https://downloads.mysql.com/docs/mysql-infoschema-excerpt-8.0-en.pdf), [referential actions](https://dev.mysql.com/doc/refman/26.7/en/constraint-foreign-key.html).
