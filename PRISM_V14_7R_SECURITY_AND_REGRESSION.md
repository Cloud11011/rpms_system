# PRISM V14 7R: Security review and regression results (7R-F)

**For:** the code reviewer and the RPMS release approver. This document covers what was tested, on which database, and with what result, plus the security properties of every new or changed path.

All runs used disposable local fixtures: synthetic data only, on loopback ports. No production or Hostinger system was accessed. Receipts (credential-free) are in [PRISM_V14_7R_EVIDENCE/runs/](PRISM_V14_7R_EVIDENCE/runs/).

## 1. Test environments

| Fixture | Server | Legacy-table collation | Why |
| --- | --- | --- | --- |
| `fx1232` | MariaDB **12.3.2** (the Hostinger version, SHA-256-verified build) | `utf8mb4_general_ci` | Matches production's measured shape (DB §2) |
| `fx104` | MariaDB 10.4.32 (XAMPP) | `utf8mb4_unicode_ci` | The Batch 1–7 reference fixture; proves the defaults are unchanged |

PHP 8.2 CLI built-in server; Chromium via playwright-core. Both chains run the same steps from a fresh schema-11 fixture:

**schema 11 → 12 → 15 → back to 11.**

## 2. Regression runs

Base: `f944220` plus the 7R working tree (uncommitted).

| Run | Suite | Schema | fx1232 (12.3.2, general_ci) | fx104 (10.4, unicode_ci) |
| --- | --- | --- | --- | --- |
| 01 | Full schema-11 suite including Batch 6 | 11 | **PASS** (428) on rerun¹ | **PASS** (428) |
| 02 | V14 foundation (migration cases, rollback, contracts) | 11→12→11 | **PASS** (99) | **PASS** (99) |
| 03 | Full suite with every V14 flag (documents, projects, report 14, monitor 15, Batch 6) | 12–15 | **PASS** (859) | **PASS** (859) |
| 04 | Batch 4 documents/summaries | 12+ | **PASS** (144) | **PASS** (144) |
| 05 | Batch 5 monitor/academic years | 12+ | **PASS** (245) | **PASS** (245) |
| 06 | Batch 6 at schema 15 | 15 | **PASS** (129) | **PASS** (129) |
| 10 | **Batch 7R module** (Decisions A–C, sorting, titles, AI PDF text, preflight, browser dialogs) | 15 | **PASS** (91) | **PASS** (91) |
| — | Teardown: clean 15 → 13 fixtures, empty rollback to 11 | 15→11 | **PASS** (0 legacy rows changed) | **PASS** (0 legacy rows changed) |
| 07 | Full schema-11 suite after rollback | 11 | **PASS** (310) | **PASS** (310) |

¹ In the final 12.3.2 chain, run 01 first failed once in the **unmodified** Batch 6 UI suite (`maintenance/ierb-ui-tests/integration.cjs:385`): the IERB entry modal did not open within 30 s at 1440 px. 7R changed nothing on that page or in its assets. That UI suite also runs inside runs 03 and 06. Across runs 01, 03 and 06 it completed 16 times in this batch and failed only this once, including a pass on the rerun on the same fixture (428 assertions). The failure evidence is kept (`runs/fx1232/01-schema11-full-batch6-intermittent-failure`). It is recorded as a **pre-existing intermittent test**, not a 7R defect, and should be stabilised separately.

Final chains: 10.4 from 19:00 to 19:26 and 12.3.2 from 19:27 to 19:52 (rerun of 01 at 19:52–19:57), each on a freshly built fixture, run one after the other, on the final code.

### Defects found by this regression, and reruns

Earlier attempts are kept as evidence. The final results above come from fresh fixtures, run one chain after the other, after both fixes.

| Attempt | What happened | Classification | Action |
| --- | --- | --- | --- |
| 1 (10.4 run 03, chains in parallel) | `SQLSTATE[HY000] [2002] Only one usage of each socket address` partway through the suite | **Environment**: Windows ephemeral-port exhaustion (~2,850 sockets in TIME_WAIT) from two suites running at once | Chains are now run serially |
| 2 (12.3.2 run 04) | Batch 4 check "Holder and other project members are never assigned invented investigator roles" failed | **Product regression introduced by 7R**: the track sheet printed "Not designated" instead of the reviewed "Not recorded" when no PI is recorded | Restored "Not recorded" ([includes/ierb_track_report.php:114](includes/ierb_track_report.php#L114)). A designated PI still prints. |
| 2 (12.3.2 run 05) | Batch 5 "Default sorting … latest audited formal submission" failed | **Test residue introduced by 7R**: the rewritten Batch 3 test named its member upload outside the fixture's `Synthetic Batch2 %` cleanup, so a formally submitted Final Report survived into later suites | Restored the cleanup naming convention ([maintenance/ierb-project-tests/projects.cjs](maintenance/ierb-project-tests/projects.cjs)) |
| 3 (10.4 run 10) | "Long-title identifiers never end with a hyphen and short titles stay readable" failed: `Ñuñoa – Study!` gave `nu-noa-study` | **Product portability defect (pre-existing in f944220)**: `iconv` transliteration differs by library. libiconv (Windows PHP) writes `~n` where glibc (Hostinger) writes `n`, so permanent identifiers depended on the server. | `research_project_slug()` drops the transliteration marks before slugging. Same result on both libraries (`nunoa-study`, `obriens-cafe-uber-study`). The reviewed `prism-rpms-workload-assistant` is unchanged. Production has no identifiers yet. |
| 3 (12.3.2 run 10) | Browser check: the correction dialog never became visible. Intermittent: it passed once in four isolated reruns. | **Test timing**: the adviser's first-visit onboarding tour opens 700 ms after page load, on top of the requirements panel, and takes focus. The captured DOM showed `prism-tour-dialog` open with its "Next" button focused. | The test marks the tour as seen before page scripts run (the same method as the Batch 4 browser checks) and uses the atomic `locator.press('Enter')`. Failure diagnostics (screenshot plus DOM state) are now captured. Then 3 of 3 isolated reruns passed. |

The migration rehearsals are a separate set of evidence ([DB §5](PRISM_V14_7R_DATABASE_COMPATIBILITY.md)):
- the real production export, migrated 11→15;
- a 10-row version × collation matrix;
- original-runner failure reproduction;
- recovery from an f944220-made schema 12.

### Run-10 coverage (Batch 7R module)

Executed against schema 15, with V14 operations activated only through the synthetic test flag:

- **Projects/identity:** explicit enrollment and reconciliation create one three-member project; identifiers never end with a hyphen; RPMS designates the PI (audited); a non-member cannot be designated.
- **Decision A:**
  - PI and non-PI members both upload; both uploads join the evidence record with individual uploaders and audit actors.
  - A different member uploads the next version, and the chain stays single.
  - Members list and open shared files (PDF magic bytes checked).
  - Member formal submission of an Adviser-approved version requires the exact version and records the submitter. Exactly one submission audit exists, and a later duplicate is refused.
  - A version without Adviser approval, or with only an RPMS override, cannot be submitted by a Student. Advisers have no separate route.
- **Isolation:** a Student outside the project gets 403 for the file, the checklist and submission, and never lists project documents.
- **Decision C:**
  - Operations are refused before activation.
  - Request rules: only the assigned Adviser may request; Students may not; a second pending request is refused.
  - A pending request leaves the stage unchanged and opens no case. Members see "Pending RPMS Review" without reasons.
  - Queue: RPMS sees the queue with proposed versions; Advisers cannot read the queue or decide.
  - Decisions: a stale revision is refused; approval requires an explicit Amendment decision.
  - Rejection is reasoned and leaves the stage unchanged. A decided request cannot be re-decided, and a new request is allowed after rejection.
  - Approval opens Stage 2 with RPMS's own Amendment decision, linked in the ledger. All members move together and are notified.
  - No second concurrent case is allowed. Resolution is refused until corrected evidence is accepted, and stays validated on retry.
- **Browser:** the correction dialog appears above the requirements modal at 390 px and 1440 px; Escape closes it; no page errors.
- **Titles:**
  - A title cannot be erased and needs at least 3 characters.
  - Before/after values are audited.
  - A canonical project title changes only through the audited rename.
- **Reports:**
  - The AI Full PDF text (parsed from the file) contains Research Titles with diacritics, says projects are "each counted once", and has the limitations/verification section.
  - Report sort is allowlisted (422 otherwise) and defaults to newest first.
- **Sorting:** the Deadlines nearest-upcoming sort is allowlisted. Student Records order newest-updated, verified against the database.
- **Preflight:** contract PASS at the migrated schema.

## 3. Security review of the 7R changes

| Area | Property | How it is enforced / verified |
| --- | --- | --- |
| Activation | V14 HTTP mutations are inert unless `config.local.php` defines `PRISM_IERB_V14_OPERATIONS === true` | Every V14 mutation service calls `ierb_v14_gate()` first: enroll, open/request/decide/resolve correction, reconcile, rename, reassign Adviser, designate PI. The project-preview endpoint calls it too. Refusal tested (run 10). |
| HTTP mutations | POST, same-origin, session generation, role | `ierb_api.php` V14 block: `require_post_same_origin()`, `require_session_generation(true)` and `api_require_login()` (Adviser only for `v14_correction_request`, Admin for the rest), applied before the body is decoded |
| Input | Typed validators; authoritative fields refused | Integers, strings, lists and booleans are type-checked. `actor_user_id`, `stage_key`, `resolved_by_user_id`, `opened_by_user_id` and `submission_holder_id` in a body are rejected. |
| Concurrency | Revision checks, row locks, idempotent request keys | Every decision re-reads the workflow under `FOR UPDATE` and compares the revision. A request key is digest-bound, so replaying it with a different body is refused. Stale revisions were tested (run 10). |
| Member access | Only explicit `research_project_members` rows grant access | `research_project_member_actor()` re-validates the live login, and membership is re-checked under lock for uploads. Names, titles and group labels never grant access. A non-member gets 403 (runs 03, 10). |
| Duplicate submission | One formal submission per version | Single-row conditional `UPDATE … WHERE rpms_submitted_at IS NULL`, required to affect exactly one row. Tested with a racing and a later duplicate (runs 03, 10). |
| Disclosure | Students never see staff correction reasons | `ierb_v14_pending_request_summary()` returns status only to Students (run 10) |
| Queue | Read-only, Admin-only | Advisers refused (run 10) |
| Migration runners | No credentials or DSN in output; TLS verification never disabled | `prism_migration_diagnostic()` emits class, SQLSTATE and errno, and omits messages for connection failures. Bad-credential runs were checked (DB §6). `PRISM_MIGRATION_DB_SSL_CA` sets `VERIFY_SERVER_CERT=true`, and no option disables it. |
| Cron wrapper | Config outside the web root; receipts credential-free | `hostinger-cron-runner.php` refuses a config inside the application directory (tested). Receipts carry no host, user, password or DSN. |
| Maintenance tools | Not reachable over HTTP | `maintenance/.htaccess` is `Require all denied`. Every new tool and every test PHP file has an explicit CLI SAPI guard (B7-F21). |
| No public migration endpoint | HTTP never applies DDL | No endpoint calls a schema runner. Preflight is read-only. |
| Cleanup | Read-only preview | `test-data-cleanup-preview.php` issues SELECTs only. Its output is redacted with `--redact`, and the unredacted preview never leaves the operator's machine. |
| Historical records | Never inferred, normalized or rewritten | Legacy titles and IDs are untouched. The erase protection only refuses a change. Correction history is append-only ledger events. |
| PDF | No injection through text | `report_pdf_encode()` emits bytes, and `pdf_escape()` escapes `\`, `(` and `)` exactly once (round-trip evidence). |

### Residual security notes

- The correction queue read is not behind the activation gate. It is Admin-only and read-only, and returns an empty list before schema 12.
- The new correction dialogs reuse the existing accessible dialog. Screen-reader coverage was **not** tested (B7-F18).
- UX observation (pre-existing, not 7R): on a user's first visit, the onboarding tour opens 700 ms after load even if a modal dialog was already opened. It is harmless (the tour closes normally), but RPMS may want it suppressed while another dialog is open.
- Turning on `PRISM_IERB_V14_OPERATIONS` exposes Admin reconciliation over HTTP. That is decision D1, and should follow a staging walkthrough (runbook §7).

## 4. Staging acceptance list

Run on the isolated Hostinger staging copy after migration to 15 (runbook §7), with synthetic or sanitised accounts. Each item must PASS before production is considered.

1. Preflight at 11 (before) and 15 (after): `contract: PASS`, with the collation reported.
2. IERB monitor search and every filter (stage, adviser, academic year, group) on a database that has legacy rows. There must be no SQL error (B7R-N01).
3. Enroll one synthetic Student. Reconcile a synthetic three-member project from a reviewed preview. Designate a PI.
4. Two different members upload versions of one requirement. Both appear in one chain, each with its uploader.
5. The Adviser approves. A member formally submits the approved version. A second member's retry is refused.
6. A non-member Student cannot open, list or submit project evidence.
7. Adviser correction request: stays "Pending RPMS Review" with the stage unchanged.
   - RPMS rejects with a reason; the stage is unchanged.
   - A new request is made; RPMS approves with an Amendment decision; the project moves to Stage 2.
   - Corrected evidence is accepted; RPMS resolves; the project returns to the interrupted stage.
8. Final Report formal submission completes IERB for every member.
9. AI Full and Summarized reports: the Research Title column and canonical project table appear, and the PDF keeps diacritics.
10. Sort controls for Student Records, Reports and Deadlines.
11. Account-deletion verification at schema 11 still verifies (before migration).
12. Rollback drill, on a second fresh staging restore only: migrate 11→15, then roll back 15→14→13→12→11 while the extension tables are still empty, and confirm preflight at 11. Once V14 tables hold data, recovery means restoring the backup (runbook §6).

**Addendum (compliance closure, 2026-10-28): cleanup acceptance.** Run on a **separate** staging restore, not the migration copy; see runbook §10.

13. End of the cleanup window: `clean-database-preview.php --mode=verify-final --retain-admins=1,13,46 --institution-reports=kept` prints `PASS`. This covers:
    - Administrators, their records and configuration unchanged;
    - 0 Students, Advisers, documents and IERB history;
    - the 28 institution-wide reports still present;
    - audit accounting PASS.
14. After the separately authorized report deletion, the same command with `--institution-reports=deleted` prints `PASS` (0 reports).
15. Each of Administrators 1, 13 and 46 signs in and opens the nine main pages; Student, Adviser, document and report lists are empty.
16. Only the six orphaned legacy notices identified in the preview remain. If RPMS approved audit option A, the retention extract's SHA-256 is recorded.
