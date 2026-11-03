# Batches 5 and 7: notification safety, Reports/exports, regression and side-by-side visual check

Claude Code, 2026-11-03.
- **Branch:** `reconcile/prism-v14-frontend-20261102`, on top of local checkpoint `51f6858`.
- **State:** everything below is uncommitted. Nothing was pushed, merged or deployed, and no production cron, migration or e-mail was touched.
- **Test environment:** disposable databases only.

## 1. Notification remediation

### 1.1 Root causes
1. **The cron worker never ran.** `maintenance/notification-dispatch.php` required `schema_version = 10`, so on production schema 15 every cron run failed.
   - Fixed under Option B: only schemas 15 and 16, each with a passing contract preflight.
   - Activation is off by default, and a mandatory backlog cutoff id holds back the existing queue.
   - Details: `notification-worker/NOTIFICATION_WORKER_ACTIVATION_GUIDE.md` §1.
2. **Duplicate-delivery path.**
   - `gmail_api_send()` returned a plain boolean, and `send_notification_email()` treated every `false` as "try `mail()`". A Gmail request that timed out or returned a server error **after** Google accepted the message was therefore sent again through `mail()`.
   - A local metadata log entry also set `sent_at`.

### 1.2 How duplicate delivery is prevented now
- **Gmail outcome.** `gmail_api_send()` returns one of four outcomes:
  - `sent`: HTTP 2xx
  - `rejected`: an explicit 4xx other than 408
  - `not_attempted`: token failure, or curl error 5/6/7, so no request reached Gmail
  - `uncertain`: everything else (timeouts, other transport errors, 408, 5xx)
- **No `mail()` after a Gmail attempt.** Once a Gmail send request is made, `send_notification_email()` never uses `mail()`.
  - `mail()` stands in only for `not_attempted`, and only when `PRISM_MAIL_FALLBACK_WHEN_GMAIL_NOT_ATTEMPTED` is `true` (default `false`).
  - With Gmail configured, a refused `mail()` fallback is a retryable failure, never `Logged`.
- **Notification status.** `deliver_notification_email()` records:
  - **uncertain outcomes**, and any exception thrown during a send, as `Failed` with `uncertain: true` and `retryable: false`. They are never retried automatically, have no `sent_at`, and are counted as `Uncertain`.
  - **`rejected` and `not_attempted`** as retryable, under the unchanged three-attempt limit.
  - **`sent_at`** only for `Sent`, never for `Logged`.
- **Unchanged:** locks, campaign completion, recipient eligibility checks, the worker gate and the backlog cutoff. No new status value and no migration.

### 1.3 Effect on immediate and scheduled notifications
- **Immediate** (sent during the web request): same path, same locks.
  - An uncertain Gmail result now leaves the copy `Failed`/uncertain instead of sending again through `mail()`.
  - A rejection stays retryable; the worker retries it once you enable it.
- **Scheduled / worker:** same rules. Uncertain rows are never resent, and only rejected or not-attempted rows are retried (at most three attempts).
- **Account invitation, setup link and password reset:** no fallback after a Gmail attempt. They report "not delivered" through the existing messages, and a resend issues a fresh token that supersedes the old link.
- **Approval needed at deploy time:** with Gmail configured, a Gmail outage before sending no longer silently uses `mail()` unless `PRISM_MAIL_FALLBACK_WHEN_GMAIL_NOT_ATTEMPTED` is set.

### 1.4 Notification test results (simulated providers only)
Real `config.php` Gmail/`mail()` code ran under `mail-provider-simulator.php`. PHP's own `mail()` and `curl_*` are disabled, and the simulator refuses to load otherwise.

| Run | Server | Result |
| --- | --- | --- |
| `tests/claude-worker15-07` | MariaDB 12.3.2, schema 15 | 98 / 98 |
| `tests/claude-worker16-06` | MariaDB 12.3.2, schema 16 + Settings | 103 / 103 |
| `tests/claude-worker15-104-05` | MariaDB 10.4.32, schema 15 | 98 / 98 |
| `tests/claude-worker16-104-04` | MariaDB 10.4.32, schema 16 + Settings | 103 / 103 |

Coverage:
- **Gmail:** success, 400/429 rejection, timeout after acceptance, 503 after acceptance, token failure, connection failure.
- **Fallback policy:** on and off; refused fallback; no Gmail configured.
- **No duplicates:** a provider ledger confirms no message was accepted by two transports.
- **Sensitive and immediate mail:** invitation, setup and password-reset e-mails; immediate notifications that are sent, uncertain, rejected or logged.
- **Worker:** the real cron entry with one campaign containing a sent, a rejected and an uncertain recipient. Only the rejected recipient is retried, and the campaign is not reported `Sent` while an uncertain recipient remains.
- **Also re-run from the earlier remediation:**
  - concurrent workers, racing dispatchers and an interrupted worker
  - the three-attempt limit, the backlog cutoff and the read-only review SQL
  - schema 14 and 17 refusal, and contract drift
- **Error log:** no message content or address appears in the copied app's error log.

## 2. Batch 5: Reports, exports, account, legal, forms

**Defect fixed:** `reports_api.php`.
- The ZIP Reports page's **Student Progress Report** tool posts `generate`. The reconciliation had made `generate` return 410 unconditionally, so the ZIP control could never succeed.
- V14's existing delegation is restored: a Student report is the Student's canonical V14 project track sheet.
- A Student without a project gets a 409 explanation. Legacy Student-stage report generation stays retired (410).
- No UI change.

**New coverage:** `maintenance/frontend-reconciliation/reports-pages.cjs`, 33 checks in the current-V14 runner.
- **Generated Reports:** Student report, project track sheet and institutional report each generate, store and auto-download a PDF.
- **Report History:** sort; single and bulk delete through the ZIP confirmation; "keep" deletes nothing; CSV export has a BOM and header, lists the reports and neutralises formula-like titles.
- **AI Reports:** summarized and full reports through the synthetic provider; view/download links; bulk delete.
- **Data Export:** all four CSV exports.
- **Admin-only:** Adviser refusals for the pages and APIs.

Account, Legal and public-policy pages and the Settings matrix were covered earlier (`settings-pages.cjs`) and pass again in both runs below.

## 3. Batch 7: regression

### 3.1 Current-V14 business and browser regression (fresh disposable databases, MariaDB 12.3.2)

| Run | Result | Scope |
| --- | --- | --- |
| `tests/claude-v14-fresh-08` (schema 15) | **399 PASS** | Full current flow and page matrix (below) |
| `tests/claude-v14-s16-02` (schema 16 + Settings) | **409 PASS** | Same, plus schema-16 Settings checks and the side-by-side ZIP captures |

The full current flow covers:
- the four official stages, with Initial Submission covering 13 requirements
- the conditional Stage 2, which completes only after resolved official cases
- Admin-only official resubmissions, with Adviser attempts refused (403), three cycles, and the fourth refused server-side
- uploads and revisions with successor versions, Adviser review, formal submission, and atomic Final Report completion with rollback on audit failure
- IERB Progress requirement names and statuses on hover
- Settings, Account, Legal, Reports and exports
- light and dark themes at 390 and 1440 px

The schema-16 Settings checks cover:
- making a year available or unavailable, which the Add Student dialog honours
- setting the current year, and refusing deletion of the current year
- Delete Academic Year through the ZIP confirmation
- saving reminder intervals and templates, and refusing a template without `{message}`

### 3.2 Older browser suite: obsolete assertions corrected
`maintenance/ierb-ui-tests/integration.cjs` targeted controls the approved V14-only cleanup removed. Each expectation was re-targeted to the current surface rather than deleted:
- **Retired IERB entry endpoint** (`ierb_api.php?action=save`): now expects 410 on every schema, and checks that no records were created.
- **Retired IERB entry dialog:** the same form contract is now tested on the ZIP **Add Student** wizard:
  - markers match exactly the required fields, and the red asterisk is hidden from the accessible name
  - the numeric Student ID keeps leading zeros; typing, paste and autofill are guarded; unchanged legacy IDs stay valid
  - Save is disabled until the form is complete; Cancel saves nothing; a reopened dialog is empty
  - double submit sends exactly one save
  - V14 requires a research group. On base schema 15 V14 refuses creating a group from this dialog; the check accepts that one refusal and requires that no record was created.
- **Role chips** (`.prism-role-chip`): now checked against the ZIP account-control role text (`.portal-profile-btn small`).
- **Responsive matrix:** uses the Add Student dialog in place of the retired entry dialog.
- **Documents bulk selection:** presses the ZIP "Select" toggle first.
- **Account page:** institutional identity is checked as the ZIP's read-only list (never editable, never marked required) instead of dashed read-only inputs.
- **Foundation check** (`ierb-v14-tests/cases.php`): stage configuration presents exactly the four V14 stages. Stored legacy identifiers are still verified byte for byte.

**Broad suite:** `run-regression.ps1` has a new `-Phases` option and a `v14` phase. It runs foundation, install15, the forms UI, 7R and closure, followed by the existing `extended` phase. The schema-11/12 legacy subsuites (01, 03, 04/05 at v12, 09, 10) exercise Student-stage operations retired under the approved cleanup. Their earlier receipts remain historical evidence; they are not current coverage.

Broad v14 run results: see §5.

## 4. Side-by-side visual validation (ZIP versus reconciled)

`visual-parity.cjs` serves the restricted ZIP source from a second isolated PHP server on the **same** disposable database. Both apps are captured with the same viewport, theme, fixed clock and sidebar handling. `visual-diff.py` produces statistics and ZIP | reconciled | difference composites.
- **Run:** `tests/claude-v14-s16-02`. 120 pairs: admin, Adviser, Student and public pages × light/dark × 390/1440. No JavaScript errors on either side, and no reconciled page overflows beyond the ZIP.
- **Top-of-page difference:** 89 pairs under 0.1%, 22 under 1%, 6 at 1–5%, and 3 above 5% (maximum 7.5%).
- **Inspected composites:** IERB Progress, Dashboard, Adviser Documents, Settings, Archived Accounts, Student portal. The layout, cards, typography, colours, spacing and footer match.
- **Every difference found traces to V14-authoritative data or an approved exception:**
  - V14 stage labels instead of legacy "Stage 2/3/4"
  - one row per canonical project, with no legacy non-project Student row
  - V14 summary counts
  - a completed Stage 2 shown as completed instead of "(Conditional)"
  - the Adviser review note without Adviser resubmission (approved exception)
  - a data-driven "IERB Completed" status chip on Archived Accounts, from the ZIP chip component and V14 project status
- **Settings at 390 px is pixel-identical to the ZIP.** The Academic Years table extending past its card is the ZIP's own design, so the earlier observation needs no change.
- **The expanded sidebar overlay** in desktop captures is identical in the ZIP.

## 5. Final results on the finished source

| Check | Result |
| --- | --- |
| Current-V14 regression, schema 15 (`tests/claude-v14-fresh-09`) | **399 PASS** |
| Current-V14 regression, schema 16 + Settings (`tests/claude-v14-s16-03`) | **403 PASS** (`s16-02`, 409, also includes the side-by-side ZIP captures) |
| Broad suite `-Phases v14,extended` (`tests/claude-broad-v14-15`) | foundation 99; forms/roles/responsive UI 134; 7R 120; closure 96; extension migration PASS; approval race 4; extended 7R 120; extended closure 96; historical guards 3; empty-extension rollback PASS |
| Notification worker and provider scenarios | 98 / 103 / 98 / 103 (§1.4) |
| Settings scenario | 75 / 75 |
| Syntax / smoke / checklist / `git diff --check` | 270 / 65 / 18 / clean |
| ZIP SHA-256 | unchanged (`2c3133af…4b57`) |

**Additional defect fixed during the run:** `assets/js/documents.js`.
- The ZIP Documents page dropped the document-types modal, but its script still closed that modal on every Escape. This raised `TypeError` in the original ZIP as well.
- `closeModal` now ignores a missing modal. No visual effect.

**Further obsolete 7R expectations re-targeted:**
- The V14 checklist dialog is now the ZIP `dialog.prq-dialog`, with the ZIP wording "Official resubmissions: 0 of 3 used". "Principal investigator" is matched case-insensitively.
- Retired `ierb_api.php?action=save` title edits now expect 410 instead of 409. The following no-mutation and no-audit checks are unchanged.

## 6. Verdicts
- **Notification duplicate-delivery remediation: PASS WITH CONDITIONS.**
  - Not verified against a real Gmail or Hostinger mail server (not authorized).
  - The fallback setting and the worker activation need your production decision.
- **Batch 5: PASS WITH CONDITIONS.** All listed Batch 5 surfaces pass in a real browser. The condition is that a few data-driven presentation differences (§4) need your review.
- **Batch 7: PASS WITH CONDITIONS.**
  - Full current regression passes on disposable schemas 15 and 16, plus the current phases of the broad suite.
  - The schema-11/12 legacy subsuites are recorded as superseded by the approved V14-only cleanup, not re-run.
  - The side-by-side comparison found no unapproved design change. It covers 120 pairs, of which I inspected a sample of composites rather than all of them.
