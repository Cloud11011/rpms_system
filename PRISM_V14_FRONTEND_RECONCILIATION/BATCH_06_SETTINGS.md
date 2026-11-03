# Batch 6 — Academic Year and Communication Settings

Continuation by Claude Code, 2026-11-03, on top of Codex's handoff (`HANDOFF_TO_CLAUDE.md` §I). Branch `reconcile/prism-v14-frontend-20261102`, HEAD `f3d90c0`. All work is uncommitted, and Codex's 140 paths were preserved. No production access, push, merge or deployment.

**Verdict: PASS WITH CONDITIONS.** The conditions are in §6.

## 1. Checkpoint verified against the working tree

- Branch, HEAD, 67 modified + 73 untracked paths, no staged changes, no stash: matches the handoff.
- ZIP present; SHA-256 `2c3133af…4b57` matches.
- The handoff's Batch 6 description was accurate.
- Three problems were found that the handoff did not list:
  - `maintenance/migrate-prism-settings.php` loaded a non-existent `maintenance/migration_runner.php`, and lacked the HTTP guard its sibling wrappers have.
  - `includes/runtime_preflight.php` accepted schemas 10–15 only, so a correctly migrated schema 16 reported `ok:false`.
  - On schema 15 the Settings page disabled **Add academic year**, a V14 capability regression (see §3).

## 2. Schema 16 decision

Schema 15 cannot **store** the ZIP's Settings, but the application does not need schema 16 to run:
- **On schema 15:** every catalog year is registerable, the Settings page shows defaults, Settings saves are refused with 409, and Add Academic Year works.
- **Persisting Settings needs four additive tables:**
  - `academic_year_settings`
  - `prism_settings`
  - `prism_deadline_reminder_dispatches`
  - `academic_year_removals`
- **Why no schema-15 table can hold this:** `academic_years` has no policy fields, and its `historical` flag carries a CHECK-constraint meaning. V14 has no settings table, and `schema_meta` is the verified migration ledger.
- **What the migration does not do:** alter, rename or drop existing structures, or change Student assignments.

Codex's split was kept: base schema 16 (`academic_year_settings`) plus the explicitly marked `prism_settings_v1` extension. The manual SQL package applies both in one run. See `schema-16/PRISM_SCHEMA_16_MIGRATION_GUIDE.md`.

## 3. Files changed in this batch

| File | Change |
| --- | --- |
| `includes/admin_settings_schema.json` (new) | Pinned Settings contract. Taken from the datadir-verified `SHOW CREATE TABLE` capture (`tests/settings-contract/settings-ddl.json`), cross-checked column-by-column and constraint-by-constraint against the PHP DDL. Only the permitted table-collation token was normalized to `utf8mb4_unicode_ci`, as in the sibling contracts. Verifier strength unchanged. |
| `includes/runtime_preflight.php`, `maintenance/runtime-preflight.php` | Admit schema 16 (the lifecycle contract already composed it). |
| `maintenance/migrate-prism-settings.php` | Correct runner path; CLI-only guard, like its siblings. |
| `maintenance/hostinger-cron-runner.php` | `v16` and `settings` steps, using each migrator's own authorization variables. |
| `includes/prism-navigation.php` | ZIP Admin-only **Settings** link replaces Legal Policies. Now identical to the ZIP. Legal Policies stays reachable from Settings and was already Admin-only. |
| `includes/academic_catalog.php` | ZIP `currentAcademicYear` default and `academic_validate(…, $requireRegistrationYear)`. Identical to the ZIP. |
| `includes/account_onboarding.php`, `students_api.php` | New academic assignments only use registration years: profile completion (the ZIP's call site), Admin invite with assignment, Admin direct create. Edits of existing records still validate against the full catalog, and unchanged legacy values pass through. |
| `admin_people.php`, `complete_profile.php` | The ZIP's non-visual `data-registration-years` attribute on the two live academic fieldsets. |
| `academic_years_api.php`, `assets/js/academic-years.js` | Restored the schema-15 **Add academic year**: the API returns `canAdd` and the script enables only the add controls from it. Policy actions remain schema-16 only. No layout or style change. A text consequence is pending approval (§6). |
| `ierbprog.php` | Removed trailing whitespace on one inter-tag line (`git diff --check`). The ZIP has none there. |

Test and tooling files:
- `maintenance/frontend-reconciliation/settings-run.ps1` (new)
- `maintenance/frontend-reconciliation/settings-tests.php` (new)
- `maintenance/frontend-reconciliation/settings-pages.cjs` (new)
- `maintenance/frontend-reconciliation/build-schema16-sql.py` (new)
- `maintenance/frontend-reconciliation/v14-current.cjs`: invokes the Settings/Reports page module
- `maintenance/frontend-reconciliation/run-v14.ps1`: BOM-free fixture input. In this host, Windows PowerShell 5.1 prefixes piped native stdin with a UTF-8 BOM, so the first fresh run failed before any test executed. Evidence: `claude-v14-fresh-01-console.txt`.

Deliverables:
- `schema-16/PRISM_SCHEMA_15_TO_16.sql`
- `schema-16/PRISM_SCHEMA_16_VERIFY.sql`
- `schema-16/PRISM_SCHEMA_16_ROLLBACK.sql`
- `schema-16/PRISM_SCHEMA_16_MIGRATION_GUIDE.md`

## 4. Reviewed and found consistent (no change needed)

- The Settings page, panel, both scripts and CSS are byte-identical to the ZIP (apart from the `canAdd` condition). Every action name and payload key they send matches the adapted endpoints.
- Settings writes:
  - are Admin-only, with a live eligibility re-check
  - require same-origin POST and session generation
  - take the `prism_migrate` lock, then the Settings lock, with consistent release
  - run in a transaction and write audit records
  - accept plain-text templates only, with `{message}` mandatory
- Notification delivery projects the Student-safe message before applying the template. The reminder helper is top-level in the worker, so it can no longer be redeclared or called before definition.

## 5. Tests and actual results

All runs used disposable, datadir-bound synthetic databases and loopback servers. No production configuration or provider was involved.

| Check | Result |
| --- | --- |
| Parser gate (`check-syntax.py` → `claude-syntax.json`) | 263 pass, 0 fail |
| Source smoke (`v14-simplification-smoke.cjs`) | 65 pass |
| Checklist request contracts | 18 pass |
| `git diff --check` | clean (after the whitespace fix) |
| Settings PHP route, settings-first + onboarding afterwards + VERIFY.sql — 12.3.2 general_ci | 75 / 75 |
| Settings PHP route, onboarding first — 12.3.2 general_ci | 36 / 36 |
| Settings rollback scenario — 12.3.2 general_ci | 17 / 17 (before the lock/partial-restart additions; rerun in §5a) |
| SQL route — 12.3.2 general_ci | 50 / 50 |
| SQL route — 10.4.32 unicode_ci | 48 / 50 on first run. The VERIFY report hit `Illegal mix of collations for operation 'UNION'` (utf8mb3 vs utf8mb4) on 10.4. Report values are now converted to utf8mb4. Rerun: 50 / 50 |
| SQL route — 12.3.2 uca1400_ai_ci | 50 / 50 |
| Fresh schema-15 current-V14 regression `tests/claude-v14-fresh-02` (business flow, surfaces, browser, staff page matrix) | **268 assertions PASS**, schema unchanged at 15. Ran before the `canAdd` fix and the Settings/Reports page module |

Evidence:
- Each fixture's `settings-results.json`
- Fresh-run consoles: `claude-v14-fresh-01-console.txt` (failed setup, kept) and `claude-v14-fresh-02-console.txt`
- Result JSON under `tests/claude-v14-fresh-02/`

Other evidence lives under the ignored `tests/claude-*` folders.

### 5a. Final rerun on the finished source

| Check | Result |
| --- | --- |
| Fresh schema-15 current-V14 regression `tests/claude-v14-fresh-03` (MariaDB 12.3.2), including the new Settings/Reports page module | **315 assertions PASS**, exit 0, schema unchanged at 15. Console: `claude-v14-fresh-03-console.txt`. |
| — of which Settings/Reports pages (`settings-page-results.json`) | 47 pass |
| Rollback scenario, extended (partial-state restart, lock contention for migrator, Settings and academic-year writes) — 12.3.2 | 17 / 17 |
| Settings PHP route, settings-first — MariaDB 10.4.32 unicode_ci | 75 / 75 |
| Fresh regression `tests/claude-v14-fresh-04` after the approved wording (below) | **316 assertions PASS**, including the exact approved sentence; Settings/Reports module 48. Console: `claude-v14-fresh-04-console.txt` |

The Settings/Reports page module covers:
- Admin × 390/1440 × light/dark: Settings and Generated Reports render, with no JavaScript errors, no failed local responses and no horizontal overflow.
- Navigation offers Settings.
- Schema-15 Add Academic Year works end-to-end, and the new year appears in the Add Student dialog.
- Schema-16 policy and communication controls are read-only on schema 15.
- Advisers get no Settings link, are redirected away, and receive 403 from the Settings API.

Screenshots: `tests/claude-v14-fresh-03/current-v14-screenshots/admin-admin_settings-*.png` and `admin-reports-*.png`. These are current-render captures, not paired ZIP parity evidence.

## 6. Conditions and remaining risks

1. **Resolved:** you approved the schema-15 Academic Years sentence on 2026-11-03 (`PENDING_UI_APPROVAL.md` item 4). It is implemented in `assets/js/academic-years.js` only for the add-only state. Layout and styling are unchanged.
2. **Production schema 16 is not approved.** The SQL package and cron steps are prepared and rehearsed, and nothing was executed against Hostinger. Production configuration (MariaDB 12.3.2, general_ci) comes from an earlier export, not a live query. Run VERIFY.sql first.
3. **The full current-V14 business and browser flow has not been run on schema 16.** Settings, coexistence and migration were exercised at the service level. The existing runner builds schema 15 only.
4. Not covered by automated tests:
   - reminder failure rollback inside `create_notification`
   - concurrent reminder workers in two processes (dedup relies on the primary key and duplicate-key skip, which was verified sequentially)
   - real e-mail provider delivery (not authorized)
5. Still open from Batches 5 and 7:
   - full Reports interactions: generation and deletion through the ZIP UI
   - export acceptance
   - Account/Legal visual reconciliation (three CSS files still differ from the ZIP)
   - the old `.prism-role-chip` assertions in `maintenance/ierb-ui-tests/integration.cjs`
   - paired ZIP-vs-reconciled visual comparison

## 7. Next task

Batch 5/7 remainder:
1. Reports UI interactions and export acceptance.
2. Account/Legal ZIP reconciliation.
3. Reconcile the obsolete role-chip assertions explicitly.
4. A schema-16 variant of the current-V14 runner.
5. Paired visual comparison.
