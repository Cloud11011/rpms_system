# Batch 8: final audit

Claude Code, 2026-11-03. Branch `reconcile/prism-v14-frontend-20261102`, baseline `f3d90c0`, previous local checkpoint `51f6858`. This document is part of the Batch 8 commit; the commit's own SHA is reported in the hand-off message (a commit cannot contain its own SHA).

**No production action was taken.** Nothing was pushed, merged, deployed, migrated or activated, no cron job was changed, and no e-mail was sent. All tests used disposable databases and simulated providers.

## 1. Verdict

**PASS WITH CONDITIONS.** The reconciled application is a development release candidate. It is **not production-ready** until the approvals in §11 and the conditions below are resolved:

| # | Condition | Why |
| --- | --- | --- |
| C1 | Decide the IERB Progress card at phone widths (§4.4, R1) | A real visual regression against the ZIP screenshots. The original ZIP does the same with the same data |
| C2 | Approve the 20-file publication allowlist addition (§6) | Without it a public build would be broken |
| C3 | Confirm five wording differences (§4.5, W1 to W5) | Each is a visible text change versus the ZIP |
| C4 | Verify the production facts that were assumed (§8) | Schema 15, collation, `zip` extension, LiteSpeed rules |
| C5 | Real Gmail and Hostinger mail were never exercised (not authorized) | Delivery semantics are proven only with simulated providers |

## 2. Git state

| Item | Value |
| --- | --- |
| Branch | `reconcile/prism-v14-frontend-20261102` |
| Baseline | `f3d90c0f46132bd4dcb350dd673d6bdbeef47d16` |
| Previous checkpoint | `51f6858b141fc3370845343315f00f73067c2b99` |
| This commit | Local only. SHA in the hand-off message |
| ZIP | `rpms_system_new.zip` SHA-256 `2c3133af45d4d320ce86e81856ccdbfdc273ef00b68834fdb30829f48f4b4a57`, verified unchanged |
| Pushed or merged | No |

## 3. Implemented functional changes (cumulative against the baseline)

**Frontend, from the ZIP, on the V14 backend:** dashboards and navigation, documents (uploads, revisions, review, formal submission), monitoring and records, IERB Progress with the requirement hover, Reports and AI Reports, Account, Legal and public policy pages, forms, Settings. V14 remains authoritative for authentication, roles, documents and versions, membership, transactions, audit and notifications.

**Workflow rules preserved and re-tested:** four official stages (Initial Submission with 13 requirements, conditional Resubmission / Amendment, Approval of Data Gathering, IERB Completed); Stage 2 completes only after a resolved official case; only RPMS Administrators open official cases; the fourth is refused server-side; ordinary Adviser revisions neither consume cycles nor change stages.

**Added after the Batch 6 checkpoint** (Batches 5 and 7 in `BATCH_05_07_*.md`, and this audit):

| Area | Change |
| --- | --- |
| Notifications | The cron worker works on schemas 15 and 16 only with a passing contract preflight. It is **disabled by default**, and a mandatory release fence (`PRISM_NOTIFICATION_WORKER_RELEASE_AFTER_ID`) holds the existing queue untouched |
| Duplicate delivery | Gmail outcomes are `sent`, `rejected`, `not_attempted` or `uncertain`. `mail()` is never used after a Gmail attempt. Uncertain outcomes are marked and never retried. `sent_at` is set only for real sends. `PRISM_MAIL_FALLBACK_WHEN_GMAIL_NOT_ATTEMPTED` stays `false` |
| Reports | The ZIP "Student Progress Report" tool delegates to V14's project track sheet. It had been blocked by an unconditional 410 |
| Documents | Pressing Escape no longer throws (the ZIP page has no document-types modal; the original also throws) |
| Release pipeline | The publication allowlist gains the 20 files that the reconciliation added (§6) |
| Schema 16 | SQL package re-verified, a half-install rollback test added, and Option A (the cron runner) rehearsed end to end |
| Tooling | Security matrix, visual parity and classifier, wrap-anomaly detector, closure check, simulated mail providers, probes |

## 4. Visual parity: all 120 side-by-side pairs

### 4.1 Method
The restricted ZIP source and the reconciled app were served on the **same disposable schema-16 database**, with the same viewport, theme, clock and sidebar handling. Pairs: 30 routes (admin 15, Adviser 6, Student 5, public 4) × light and dark × 390 and 1440 px.

Every one of the 120 pairs was compared automatically on four independent measures:
- visible text
- every control and heading, by tag and text
- computed styles of every matching control (13 properties)
- pixel difference, plus a scan for letter-by-letter text wrapping

I then looked at the composites directly for **all 44 pairs that differ** (9 route sheets, each showing the four captures; the other two routes are exact duplicates of reviewed ones). The 76 pairs with no difference on any measure (all four, including 0.000 pixel change) need no visual inspection.

Artifacts:
- `visual-parity/visual-parity-120.csv`: one row per pair with the classification
- `tests/claude-v14-s16-07/visual-parity/`: captures, composites and sheets (git-ignored)

### 4.2 Result

| Classification | Pairs |
| --- | --- |
| **Identical**: text, controls, computed styles and pixels | **76** |
| **Dynamic data**: V14 data or labels in existing ZIP components, no style change | **40** |
| **Dynamic data plus approved exception**: IERB Progress at 1440 px | **2** |
| **Actual visual regression**: IERB Progress at 390 px | **2** |

Zero JavaScript errors on any reconciled page. The one error in the whole run, `togglePassword is not defined`, is in the **original ZIP's** Student Records page.

### 4.3 Identical (76 pairs, 19 routes)
Admin: Account, Research Advisers, AI Reports, Legal Policies, Notifications, Settings, Calendar, Data Export, Documents, Reports. Adviser: Account, Calendar, Help. Public: login, forgot password, privacy, terms. Student: Help and Profile.

### 4.4 Actual visual regression: R1 (needs your permission; not changed)
**IERB Progress at 390 px, light and dark.** For a completed project, the card's Student-name column collapses to about 24 px and the name wraps letter by letter ("Sy / nth / eti / c…"). The status badge now reads "IERB Completed" (126 px wide), where the ZIP's fixture showed "Pending" (77 px).

Root cause, proven rather than assumed:
- The card markup, `ierbprog.css`, `prism-ui.css`, `ierb-monitor.css` and `ierb-monitor.js` are byte-identical to the ZIP.
- Below 1000 px the head grid is `1fr auto`, and the name has `overflow-wrap:anywhere`, so the `1fr` column's minimum is about one character. The auto-width badge wins.
- The ZIP's own `ierb_monitor.php` emits the same long statuses (`IERB Completed`, `Correction open`).
- **Probe on the original ZIP** (`zip-long-status-probe.cjs`): with a Student's status set to those values, the ZIP's name column is 24 px wide over 8 lines. With "Pending" it is 66 px over 2 lines.
- The same CSS, measured on the reconciled page, also collapses a "Pending" card at 360 px, and both cards plus the page itself at 320 px. Since the CSS is the ZIP's, the original would do the same; that part was not measured on the ZIP.

So V14's status text exposes a latent weakness of the ZIP's phone layout. It is not an adaptation bug, but it is visibly worse than the ZIP screenshots.

| Option | Effect | Measured at 390 / 360 / 320 px |
| --- | --- | --- |
| **0. Accept as is** | Same as the original ZIP given the same data | Name 24 px, 8 lines (broken) |
| **B. One rule: status on its own row below 520 px** | Name readable and one line. The "Pending" badge also moves below the name on phones | Name 156 / 135 / 95 px; no page overflow at any width |
| A. Minimum identity column plus a wrapping badge | Name readable, but the badge wraps into an unattractive four-line pill | Rejected after viewing the render |

Option B's CSS (not applied; `ierbprog.css` is currently byte-identical to the ZIP):
```css
@media (max-width:520px){ body.prism-workspace .ierb-pc-head{grid-template-columns:minmax(0,1fr)} body.prism-workspace .ierb-pc-status{justify-self:start} }
```
Evidence: `tests/claude-v14-s16-07/visual-parity/card-fix/`.

### 4.5 Wording differences needing your confirmation (not changed)

| ID | Where | ZIP | Reconciled | Reason |
| --- | --- | --- | --- | --- |
| W1 | Student Records search box (Admin and Adviser) | "Search name, ID, email or group" | "Search name, ID, research title or protocol code" | V14 searches project title and protocol code |
| W2 | Admin Dashboard monitor subtitle | "One record per research project. Older student records keep their historical stages. Times are shown in Asia/Manila." | "One record per V14 research project. Times are shown in Asia/Manila." | V14-only monitor; legacy mention removed |
| W3 | Adviser Documents banner | "…Deny or Request Revision / Resubmission requires feedback…" | "…Deny or Request Revision requires feedback…" | Keeps ordinary revision distinct from an official resubmission |
| W4 | IERB Progress | "Legacy Student Stage Distribution" and an "Add IERB Entry" button | "Research Stage Distribution", no button | Approved V14-only cleanup; heading rename needs confirmation |
| W5 | Student Documents | The ZIP prints the literal text **"null" / "nullnull"** beside the filter chips; stage label "Completion" | No "null"; label "IERB Completed" | A ZIP defect is not reproduced; "IERB Completed" is the approved Stage 4 name |

### 4.6 The "IERB Completed" chip on Archived Accounts (item 6)
**It is not a new control. It is data displayed in the ZIP's existing filter-chip component.**
- The chip code is byte-identical to the ZIP: `archiveStatusEntries()` shows three built-in chips plus one chip per extra status option the server provides.
- **Probe on the same database:** the ZIP's students API offers `On Track, Pending, Delayed`; the reconciled API offers those plus `IERB Completed`. V14's `record_filters.php` derives that status for Students whose project has completed. That code is unchanged from baseline `f3d90c0`.
- The chip's computed style equals its sibling chips' (0 style differences). At 390 px it wraps onto a third row.
- Its count is 0 on the Archived page, because option lists come from all Students while the list shows only archived ones. The ZIP's code behaves the same way for any extra status.
- The same status also appears as a badge on cards. That is where R1 arises.

### 4.7 Approved exceptions and other dynamic data (40 + 2 pairs)
- **V14 stage labels and chips** replace "Stage n" (Student Records, IERB Progress, Student portal, documents).
- **Counts and rows** from canonical projects (Dashboard, Adviser "My Students 2 to 1"). A Student without a V14 project stays in Student Records as "Research setup pending", but is not in the progress monitors.
- **Stage 2 shown as completed** in the Student portal, because the fixture's three official cases were resolved. The ZIP cannot know the case ledger.
- **Removed:** "Add IERB Entry", the legacy stage distribution and the Adviser official-resubmission action, all approved.

## 5. Full regression results (disposable databases, simulated providers)

| Suite | Result |
| --- | --- |
| Current-V14 flow and pages, **schema 15** (`claude-v14-fresh-12`) | **408 pass** |
| Same, **schema 16** + Settings (`claude-v14-s16-07`, with visual capture) | **418 pass** |
| Broad suite, current phases (`claude-broad-v14-15`) | foundation 99 · forms, roles and responsive UI 134 · 7R 120 · closure 96 · extension migration · approval race 4 · extended 7R 120 · extended closure 96 · historical guards 3 · empty-extension rollback |
| Notification worker and mail-provider scenarios | schema 15 **98**, schema 16 **103**, on MariaDB 12.3.2 **and** 10.4.32 |
| Settings, PHP route | settings-first 75 · onboarding-first 40 · rollback 17 |
| Schema-16 SQL route | **60** on 12.3.2 general_ci, 12.3.2 uca1400 and 10.4.32 unicode_ci |
| Option A, the real cron runner | **16** |
| Security matrix, inside both current runs | **9** (§7) |
| Apache `.htaccess` regression (H3) | **388 pass** (LiteSpeed unverified) |
| Publication builder tests | **15 pass** |
| Syntax / smoke / checklist / `git diff --check` | 272 / 65 / 18 / clean |

The schema-11 and schema-12 legacy subsuites (01, 03, 04, 05, 09 and 10 of the old broad run) test Student-stage operations retired under the approved V14-only cleanup. They were not re-run. Their earlier receipts remain as historical evidence, and each corrected expectation is documented in `BATCH_05_07_NOTIFICATIONS_REPORTS_REGRESSION.md`.

Failures met and fixed during this batch are all in the test code or in the items below. **No application defect was found in the security, Settings, schema or notification paths.**

## 6. Release and publication findings

| ID | Severity | Finding | Status |
| --- | --- | --- | --- |
| P1 | **High** | `maintenance/release/public-runtime-files.json`, the reviewed publication allowlist, **omitted 20 files** the reconciliation added: 18 runtime files and the 2 migrators that Option A's `v16` and `settings` steps launch. A public build from it would break `documents.php`, the Adviser and IERB pages, Settings and the schema contract check | Fixed in this commit: the allowlist goes from 273 to 293 runtime entries (a pure 20-line addition). **Needs your approval**, because the file says additions require an explicit runtime review |
| P2 | Info | After the fix, the closure check of a real build reports one reference: `config.php` → `tools/schema-v9-contract.json`. That is baseline migration-only code reading a git-ignored file | Pre-existing; not a blocker |
| P3 | Info | Two non-referenced files stay off the allowlist (`assets/css/academic-settings.css`, an orphan; `includes/schema_v10_seed.json`, baseline) | Unchanged |

Proof: the closure check on a tree built from the **old** allowlist reports 18 missing references; on the **updated** list, 1 (P2). A real build with the builder reports `unexpected_files: []` and `inventory_verified: true`. It contains no `config.local.php`, SQL, tests, `PRISM_*` documents or reconciliation tooling.

## 7. Security findings

| ID | Severity | Finding | Status |
| --- | --- | --- | --- |
| S1 | **High, procedural** | The code now in production **cannot run on schema 16**: `config.php` `migrate()` throws for it, and the old preflight lists only 10 to 15. After the migration, a code-only rollback takes the site down | Documented in the guide and procedure: deploy the new code first; roll back the database (if no Settings data) before the code, or restore the backup |
| S2 | Medium | Gmail success could be reported as failure, then sent again through `mail()` | Fixed and tested (§3) |
| S3 | Medium | The notification cron never worked on schema 15, and enabling it naively would have released the historical queue | Fixed: disabled by default, fenced, contract-checked. A normal deploy cannot dispatch (§9) |
| S4 | Low | The `preflight` step requires PHP `zip`, which this PHP lacks | Production prerequisite (§8) |
| S5 | Low | `gmail_api_send` logs up to 500 characters of Google's error body for non-sensitive mail (pre-existing) | Open. Consider truncating |
| S6 | Low | The archived-list status options come from all Students (they leak only a status label, as in the ZIP) | Accepted |
| S7 | Info | LiteSpeed's handling of the `.htaccess` rules was **not verified**; Apache was | Check four denied URLs after deploy (procedure §9) |
| S8 | Info | `composer audit` was not run (offline; `composer.json`, `composer.lock` and `vendor/` are unchanged from baseline) | Run on a connected machine |

**Verified clean:**
- **Access control:** 56 pages and APIs × 4 identities; every role is refused what its own login guard excludes, with no unguarded non-public file and no server error on a default request.
- **Origin and anonymous checks:** 16 state-changing endpoints refuse a foreign Origin (403) and anonymous callers (401 or 403), and change no row. Students and Advisers are refused on the Admin-only Reports, Settings and Academic Year mutations.
- **Library files:** direct requests leak no credentials, warnings or stack traces. The one body-reading file without an origin guard, `reset_password.php`, is unchanged baseline and only displays a token.
- **Secrets:** a scan of all 79 candidate files found no keys or tokens. The only external address is the public RPMS contact on the login page, which is in the ZIP and in the baseline. Credentials appear only as synthetic test values, and no `config.local.php`, `.env`, dump or ZIP is included.
- **Web denial:** `.htaccess`, `includes/.htaccess` and `maintenance/.htaccess` are unchanged from the baseline and deny `maintenance/`, `tests/`, `PRISM_*`, `.sql`, `.py`, `.cjs` and `.ps1`.

## 8. Schema 16 migration readiness

**Ready for your decision. Not approved and not run.**

| Check | Result |
| --- | --- |
| SQL files reproducible from the PHP source | Regenerated byte-identical |
| Migration script | Refuses on wrong version, unknown tables, mixed collation, existing marker, held lock; verifies structure before stamping; stamps last |
| VERIFY | `NOT_MIGRATED`, `PASS` or `FAIL`, agrees with the PHP verifier |
| ROLLBACK | Empty installs and half-installs only; refuses once Settings data, reminders or Settings audit events exist |
| Half-install | Migration refused with nothing changed; VERIFY `FAIL`; rollback restores the exact schema-15 table set; re-migration succeeds |
| Servers and collations | MariaDB 12.3.2 (general_ci and uca1400) and 10.4.32 (unicode_ci) |
| Option A (cron runner) | 16 of 16: probe, wrong-database refusal, preflight, dry-runs, applies, preflight at 16, receipts with no password or DSN |
| Option B (phpMyAdmin) | Tested with the MariaDB command-line client, not in phpMyAdmin itself. **Prefer Option A**; the project's own review also rated phpMyAdmin DDL "unsupported" |

**Production prerequisites (unverified; to be checked in Phase A):**
- Schema 15 with a passing preflight.
- Server MariaDB and collation (assumed 12.3.2 general_ci).
- PHP `zip`, `mbstring` and `pdo_mysql` for the cron runner.
- A restorable hPanel database backup. Never rely on a phpMyAdmin export; it drops a CHECK constraint.
- The new code deployed and confirmed first (S1).

**Rollback limits:** DDL is not transactional. After Settings is used, rollback is refused by design. The historical `academic_years` rows the migration adds are not removed. Existing permanent-deletion verification evidence becomes invalid.

**Can the current schema-15 application keep operating until then? Yes.** The new tables are touched only behind availability checks (marker, table set and exact contract). On schema 15, every year in the catalog is registrable, Add Academic Year works, policy actions and Communication Settings are read-only, and saves are refused with 409. This is proven by the 408-check schema-15 run.

## 9. Notification activation readiness

**Ready for review. The worker is disabled and nothing was released.**

| Check | Evidence |
| --- | --- |
| Default is disabled | `config.php` defines both constants as `false` and `null`; nothing reads them from the environment |
| Single entry point | The only caller of `notification_worker_run()` is `maintenance/notification-dispatch.php`. No web request, cron-runner step or release script calls it |
| Disabled run | Prints `disabled`, exits 0, **does not connect to the database** (tested with a deliberately wrong database port) |
| Enabling safely | Needs literal `true` plus an unquoted integer fence. `'1'`, `1`, `'true'`, a missing, negative or quoted fence are refused |
| Fence | Rows at or below it are never delivered, retried, recovered or cancelled. A fenced run leaves them byte-identical. Releasing is an explicit decision |
| Schema and contract | Only 15 and 16, only with a passing exact preflight. 14, 17 and a drifted `notifications` table are refused |
| Concurrency and failures | Two workers: one runs, one reports busy. Racing dispatchers send once. A crash after provider acceptance leaves a claim that is never resent. Three-attempt limit. Partial campaign failures |
| Providers | Gmail success, rejection, timeout and 5xx after acceptance, unreachable Gmail: no message accepted by two transports |
| A normal deploy | Cannot dispatch the queue: the worker is off and the fence is mandatory (`PRISM_V14_DEPLOYMENT_AND_ROLLBACK_PROCEDURE.md` §8) |
| Read-only production queue review | `notification-worker/PRISM_NOTIFICATION_QUEUE_REVIEW.sql`; tested read-only, matching the worker's own counts, no addresses or message text returned. **Not run on production** |

Not covered by the switch, and unchanged: immediate notifications created during web requests still send at once.

## 10. Outstanding risks

1. R1 and W1 to W5 are visible differences from the ZIP, pending your decision.
2. The two production-critical ordering rules: deploy code before schema 16; a code-only rollback after the migration is impossible.
3. Real Gmail and Hostinger mail were not exercised. An explicit Gmail 4xx and a refused `mail()` are trusted as "not sent". `mail()` success means only that the local server accepted the message.
4. Rows marked `uncertain` need a person to check the provider; there is no screen for it.
5. Production facts are assumed: schema 15, collation, server version, `zip`, LiteSpeed rules.
6. A held notification backlog stays held until someone handles it under a separate approval.
7. phpMyAdmin execution of the SQL was not tested in phpMyAdmin.
8. The schema-11 and schema-12 legacy suites were superseded, not re-run.

## 11. Approvals required before any production action

1. **Phase C**: deploy the verified artifact to the schema-15 production.
2. **Phase D**: the read-only queue review on production.
3. **Phase E**: the schema-16 migration, by Option A or Option B.
4. **Phase F**: worker activation and its fence value.
5. **C1 to C3**: the R1 option, the allowlist addition and the W1 to W5 wording.
6. Whether `PRISM_MAIL_FALLBACK_WHEN_GMAIL_NOT_ATTEMPTED` ever changes (currently `false`, as you decided).

Each phase has its own written gate, rollback and stop conditions in `PRISM_V14_DEPLOYMENT_AND_ROLLBACK_PROCEDURE.md`.

## 12. Changed-file inventory
233 files against the baseline (141 added, 92 modified, none deleted, no binaries). By area: application includes 37, pages and APIs 21, scripts 19, stylesheets 21, tooling 43, release pipeline 1, schema-16 package 4, notification-worker documents and SQL 2, reconciliation reports, consoles and evidence 84, plus `CLAUDE.md`. Full list: `BATCH_08_CHANGED_FILE_INVENTORY.md`.

## 13. Verdict
**PASS WITH CONDITIONS** (C1 to C5 above). Stop here for your review before any production action.
