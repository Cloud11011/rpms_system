# Batch 8: final audit

Claude Code, 2026-11-03. Branch `reconcile/prism-v14-frontend-20261102`, baseline `f3d90c0`, previous local checkpoint `51f6858`. This document is part of the Batch 8 commit; the commit's own SHA is reported in the hand-off message (a commit cannot contain its own SHA).

**No production action was taken.** Nothing was pushed, merged, deployed, migrated or activated, no cron job was changed, and no e-mail was sent. All tests used disposable databases and simulated providers.

## 0. Update after your decisions C1, C2 and C3 (2026-11-03)

Applied and re-verified. Only the approved change was made to the frontend: **two lines in `assets/css/ierbprog.css`** (a comment and one rule). All other work is test tooling and documentation.

| Decision | Result |
| --- | --- |
| **C2: mobile IERB Progress (Option B)** | Applied. At 520 px and below the status badge sits below the Student name. Measured at 320, 360, 390 and 520 px, light and dark: names readable, no card or page overflow. At 521, 700, 1000, 1024 and 1440 px the page is **pixel-identical** to before, in both themes (`card-validate.cjs`, `card-validate-compare.py`). At 320 px the old page was also 352 px wide, a page overflow the rule removes |
| **"No other ZIP element changed"** | Proven four ways: the CSS diff is exactly 2 added lines; wide widths are pixel-identical; the full 120-pair re-comparison has the **same 76 identical pairs** and only the two IERB Progress 390 px pairs changed signature; reconciled-only wrap anomalies went from 2 pairs to **0**. Below 520 px only the card list differs; content above it is identical, and the footer is identical apart from a sub-pixel offset caused by the shorter cards |
| **C3: W1 to W5** | Approved and unchanged. Verified in the final capture (text and attribute comparison) |
| **C1: allowlist, with safeguards** | Approved. Safeguards completed: necessity (all 20 referenced), sensitivity, CLI guards, **HTTP denial of every real `maintenance/`, `includes/` and `vendor/` file in the built artifact** (§6) |
| Regression | Schema 15: **408 pass**. Schema 16: **418 pass**. Same counts as before the CSS rule |

## 1. Verdict

**PASS WITH CONDITIONS, now a release candidate ready for owner-operated release.** C1 to C3 are resolved. The conditions that remain cannot be closed from a development machine; each is a check or a decision for the owner on the Hostinger account, and each has a stop condition in `OWNER_RELEASE_RUNBOOK.md`.

| # | Remaining condition | How it is closed |
| --- | --- | --- |
| C4 | Production facts are assumed, not verified: schema 15, server version and collation, `zip` and `posix` PHP extensions, which branch Hostinger deploys, whether a Git deploy preserves `config.local.php` and `storage/`, LiteSpeed's handling of the access rules | Runbook Parts B and C: each is a checked step with a stop condition |
| C5 | Real Gmail and Hostinger mail were never exercised (not authorized) | The worker stays disabled; activation is the owner's separate decision (Part F) |
| C6 | Backups: the hPanel backup must be shown restorable | Runbook Part C.1 (restore into a throwaway database and compare counts). Stop if it cannot be verified |

## 2. Git state

| Item | Value |
| --- | --- |
| Branch | `reconcile/prism-v14-frontend-20261102` |
| Baseline | `f3d90c0f46132bd4dcb350dd673d6bdbeef47d16` (also `origin/feature/prism-v14-major-revisions`) |
| Local commits | `51f6858` (Batch 6 checkpoint) and `3d3c45d8162f7571351c164d843b6887281de880` (Batches 5, 7, 8), both authorized, both local |
| After `3d3c45d` | **Uncommitted** (you will commit them, runbook Part A): the approved CSS rule, the new release-verification tooling, the runbook and updated documents, and the latest evidence consoles |
| ZIP | `rpms_system_new.zip` SHA-256 `2c3133af45d4d320ce86e81856ccdbfdc273ef00b68834fdb30829f48f4b4a57`, verified unchanged |
| Pushed or merged | No. The branch exists only on this machine. The repository's one remote is `origin` (`https://github.com/Cloud11011/rpms_system.git`) |
| Fast-forward possible | Yes: `origin/feature/prism-v14-major-revisions` (`f3d90c0`) is an ancestor of this branch |
| Hostinger deploy branch | **Not discoverable from the repository.** Earlier project documents record that pushing `feature/prism-v14-major-revisions` auto-deploys; confirm in hPanel (runbook B.0). The GitHub workflow in the repository triggers on two other branches and only runs tests |

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
- `tests/claude-v14-s16-08/visual-parity/`: the final captures, composites and sheets (git-ignored)

### 4.2 Result

| Classification | Pairs |
| --- | --- |
| **Identical**: text, controls, computed styles and pixels | **76** |
| **Dynamic data**: V14 data or labels in existing ZIP components, no style change | **40** |
| **Dynamic data plus approved exception**: IERB Progress (all four captures) | **4** |
| **Actual visual regression** | **0** (the two 390 px captures were resolved by the approved rule) |

Zero JavaScript errors on any reconciled page. The one error in the whole run, `togglePassword is not defined`, is in the **original ZIP's** Student Records page.

### 4.3 Identical (76 pairs, 19 routes)
Admin: Account, Research Advisers, AI Reports, Legal Policies, Notifications, Settings, Calendar, Data Export, Documents, Reports. Adviser: Account, Calendar, Help. Public: login, forgot password, privacy, terms. Student: Help and Profile.

### 4.4 R1: resolved by the approved Option B (C2)

**Status: applied and validated** (see §0). The analysis below is retained as the record of why.
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

Option B's CSS, as applied (the only change to `ierbprog.css`; every other rule remains the ZIP's):
```css
@media (max-width:520px){ body.prism-workspace .ierb-pc-head{grid-template-columns:minmax(0,1fr)} body.prism-workspace .ierb-pc-status{justify-self:start} }
```
Evidence: `tests/claude-v14-s16-07/visual-parity/card-fix/`.

### 4.5 Wording differences W1 to W5: approved by you (C3), unchanged

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
| Current-V14 flow and pages, **schema 15** (`claude-v14-fresh-13`, on the final CSS) | **408 pass** |
| Same, **schema 16** + Settings (`claude-v14-s16-08`, on the final CSS, with the 120-pair capture) | **418 pass** |
| Broad suite, current phases (`claude-broad-v14-15`) | foundation 99 · forms, roles and responsive UI 134 · 7R 120 · closure 96 · extension migration · approval race 4 · extended 7R 120 · extended closure 96 · historical guards 3 · empty-extension rollback |
| Notification worker and mail-provider scenarios | schema 15 **98**, schema 16 **103**, on MariaDB 12.3.2 **and** 10.4.32 |
| Settings, PHP route | settings-first 75 · onboarding-first 40 · rollback 17 |
| Schema-16 SQL route | **60** on 12.3.2 general_ci, 12.3.2 uca1400 and 10.4.32 unicode_ci |
| Option A, the real cron runner, **including rollback and refusal** | **23** (probe, wrong-database refusal, preflight, dry-runs, applies, preflight at 16, receipts without secrets, `settings` then `v16` rollback, re-apply, rollback refused once data exists) |
| Security matrix, inside both current runs | **9** (§7) |
| Apache `.htaccess` regression (H3) | **388 pass** (LiteSpeed unverified) |
| Publication builder tests | **15 pass** |
| Built artifact (C1 safeguards) | 293 runtime files, `unexpected_files: []`, no credentials. **0 leaks across 519 public-HTTP requests** for every real `maintenance/`, `includes/` and `vendor/` file (GET and HEAD) plus 159 path-trick variants, with a negative control that does leak. Manifest review: 0 hard failures, 15 of 15 CLI scripts self-guarded, 0 secret patterns |
| Checksums | `make-runtime-checksums.py`: all 293 runtime files verified by `sha256sum -c` against a no-autocrlf checkout, and a one-byte change is detected |
| Whole tracked tree | 1,382 files: no sensitive file types, nothing over 5 MB, no secret patterns, only four `.sql` files (schema and review scripts, no data) |
| Syntax / smoke / checklist / `git diff --check` | 272 / 65 / 18 / clean |

The schema-11 and schema-12 legacy subsuites (01, 03, 04, 05, 09 and 10 of the old broad run) test Student-stage operations retired under the approved V14-only cleanup. They were not re-run. Their earlier receipts remain as historical evidence, and each corrected expectation is documented in `BATCH_05_07_NOTIFICATIONS_REPORTS_REGRESSION.md`.

Failures met and fixed during this batch are all in the test code or in the items below. **No application defect was found in the security, Settings, schema or notification paths.**

## 6. Release and publication findings

| ID | Severity | Finding | Status |
| --- | --- | --- | --- |
| P1 | **High** | `maintenance/release/public-runtime-files.json`, the reviewed publication allowlist, **omitted 20 files** the reconciliation added: 18 runtime files and the 2 migrators that Option A's `v16` and `settings` steps launch. A public build from it would break `documents.php`, the Adviser and IERB pages, Settings and the schema contract check | Fixed in this commit: the allowlist goes from 273 to 293 runtime entries (a pure 20-line addition). **Needs your approval**, because the file says additions require an explicit runtime review |
| P2 | Info | After the fix, the closure check of a real build reports one reference: `config.php` → `tools/schema-v9-contract.json`. That is baseline migration-only code reading a git-ignored file | Pre-existing; not a blocker |
| P3 | Info | Two non-referenced files stay off the allowlist (`assets/css/academic-settings.css`, an orphan; `includes/schema_v10_seed.json`, baseline) | Unchanged |

**C1 safeguards (completed after your approval):**
- **Necessary:** each of the 20 additions is referenced by a real runtime file, or is a cron-runner step script.
- **Not sensitive:** the builder's own rules, an extension deny-list and a content scan all pass.
- **Not publicly reachable:** the maintenance scripts also carry their own CLI guard as their first active statement, and the Apache check requests the real files.
- **Five pre-existing allowlist entries are unreferenced dead assets** from the earliest commit (`student-records-reference.css`, three images, `batch11-public.js`). They are harmless static files outside this release's scope; removing them is optional cleanup.
- **If Hostinger deploys from Git, every tracked file ships, not just the allowlist.** The `.htaccess` rules are then the protection. The H3 and artifact tests passed on Apache; **LiteSpeed must be checked on the server** (runbook B.8).
- **Build and verify only from a no-autocrlf checkout.** On this Windows machine `core.autocrlf=true` rewrites line endings, so a build from the working tree does not match the committed bytes.

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
| Option A (cron runner) | 23 of 23: probe, wrong-database refusal, preflight, dry-runs, applies, preflight at 16, receipts with no password or DSN, plus rollback in the correct order (`settings` then `v16`), refusal in the wrong order, and refusal once Settings is used |
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

**Superseded by the owner-operated release.** C1 to C3 are approved and implemented. You will personally perform every Git commit and push, the Hostinger deployment, the schema migration and the production configuration, using `OWNER_RELEASE_RUNBOOK.md` (Parts A to G). Nothing in this repository performs a production action, and none was performed.

Still your decisions, each with its own stop condition in the runbook:
1. Deployment (runbook A.5 and B.3), after the backup in C.1 is verified.
2. The schema-16 migration (Part D), only after the new code is deployed and verified.
3. Whether and when to activate the notification worker, and its cutoff (Part F).
4. Whether `PRISM_MAIL_FALLBACK_WHEN_GMAIL_NOT_ATTEMPTED` ever changes (currently `false`, as you decided).

`PRISM_V14_DEPLOYMENT_AND_ROLLBACK_PROCEDURE.md` is kept for reference only.

## 12. Changed-file inventory
241 files against the baseline once the release-preparation commit is made (149 added, 92 modified, none deleted, no binaries). By area: application includes 37, pages and APIs 21, scripts 19, stylesheets 21, tooling 47, release pipeline 2, schema-16 package 4, notification-worker documents and SQL 2, reconciliation reports, consoles and evidence 87, plus `CLAUDE.md`. Full list: `BATCH_08_CHANGED_FILE_INVENTORY.md`.

## 13. Verdict
**PASS WITH CONDITIONS, release candidate for owner-operated release.** Conditions C4 to C6 (section 1) are checks on your Hostinger account, each with a stop condition in `OWNER_RELEASE_RUNBOOK.md`. Nothing was committed by this release preparation, pushed, deployed, migrated or sent.
