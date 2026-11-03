# Notification worker: production queue review and safe activation

Prepared 2026-11-03 on branch `reconcile/prism-v14-frontend-20261102` (Option B, approved 2026-11-03).
**None of these steps is authorized yet.** Each production step below needs your separate, explicit approval:
the queue review, editing `config.local.php`, enabling cron, and sending real email. Nothing here has been run
against Hostinger.

## 1. What changed

| | Before (V14 HEAD) | Now |
| --- | --- | --- |
| Schema check | `schema_version` must be exactly `10`. Production is on 15, so every cron run failed. | Only `15` or `16`, **and** the read-only exact contract preflight (`prism_runtime_database_preflight`) must pass. A drifted `notifications` table, or a schema version this worker was not verified against, is refused. |
| Activation | Implicit. Any deploy that fixed the schema check would start delivering at the next cron run. | **Off by default.** `config.php` defines `PRISM_NOTIFICATION_WORKER_ENABLED = false`. While disabled, the cron script prints `{"status":"disabled",…}`, exits 0 and does not even connect to the database. |
| Existing queue | Would be delivered, retried or recovered on the first run. | A **release fence** (`PRISM_NOTIFICATION_WORKER_RELEASE_AFTER_ID`) is mandatory once enabled. The worker never delivers, retries, recovers, cancels or completes anything with a notification id at or below the fence, and it never touches a campaign made only of such rows. Each run reports the held rows as `heldBacklog`. |

Unchanged: the per-notification and per-campaign locks, the single-worker lock, the 15-minute stale-claim rule (never resent; marked uncertain), the three-attempt retry limit with backoff, cancellation for inactive, archived or changed recipients, the "unbound recipient" review state, support conversations never being e-mailed, and the Student-safe message projection.

Files: `maintenance/notification-dispatch.php`, `includes/notification_worker.php`, `config.php` (two default definitions).

**Outside the worker, also unchanged:** when staff or the workflow create a notification for immediate delivery, the web request still sends it at once. That is how production behaves today. The worker only handles scheduled notifications, retries, stale-claim recovery and (on schema 16) deadline reminders.

## 1a. Provider outcomes and duplicate prevention (added 2026-11-03)

V14 HEAD fell back to PHP `mail()` whenever a Gmail API call reported failure. A Gmail request that times out, or returns a server error after Google has already accepted it, could therefore be sent a second time through `mail()`. A local log entry also left `sent_at` set.

`gmail_api_send()` now reports one of four outcomes:

| Outcome | When | What PRISM does |
| --- | --- | --- |
| `sent` | HTTP 2xx | Notification `Sent`, with `sent_at` |
| `rejected` | Explicit 4xx refusal (except 408) | `Failed`, retryable, up to three attempts; never falls back to `mail()` |
| `not_attempted` | No send request reached Gmail: token failure, or curl errors 5/6/7 (proxy or host resolution, connection) | `Failed`, retryable. `mail()` is used **only if** `PRISM_MAIL_FALLBACK_WHEN_GMAIL_NOT_ATTEMPTED` is `true` in `config.local.php` (default `false`). |
| `uncertain` | Timeout, any other transport error, 408, 5xx or another unexpected status; also any exception thrown during a send | `Failed` with `uncertain: true`, **not retryable**, no `sent_at`; never resent automatically. Worker summary counts it as `Uncertain`. |

How each kind of mail is affected:
- **Without Gmail configured:** `mail()` remains the primary transport, as before. When it fails, notifications keep the metadata-only `Logged` record. `Logged` rows no longer get `sent_at`.
- **Account invitations, setup links and password resets:** they never fall back after an uncertain or rejected Gmail attempt, and are reported as "not delivered" through the existing messages ("use Resend Invitation" / "Contact the RPMS office"). Each resend issues a new token that supersedes the old link.
- **Status values:** no new database status or migration was needed. Uncertain rows are `Failed` plus `uncertain: true` in `delivery_info`, as stale claims already were.

**Production behaviour change needing your approval at deploy time:** with Gmail configured, a Gmail outage that happens before sending (for example a token failure) no longer sends through `mail()` unless you set `PRISM_MAIL_FALLBACK_WHEN_GMAIL_NOT_ATTEMPTED`.
- Immediate notifications then stay `Failed` and retryable, and are retried only once the worker is enabled.
- Invitations and setup e-mails report "not delivered" and can be resent.

## 2. Reviewing the production queue (read-only)

File: `PRISM_NOTIFICATION_QUEUE_REVIEW.sql` (this folder).

1. hPanel → Databases → phpMyAdmin → select the PRISM database → **SQL** tab.
2. Paste the whole file and click **Go**.
   - The file sets the session time zone to Manila, opens a `READ ONLY` transaction and ends with `ROLLBACK`.
   - Any accidental write would fail.
   - It returns ids, counts, statuses, categories and timestamps only: no subjects, message text, names or e-mail addresses.
3. Export or screenshot every result table and keep it with the activation approval.

| Section | Answers |
| --- | --- |
| 0 `context` | Schema version, review time, highest notification and campaign ids |
| 1 `status` | Counts by delivery status (live, archived and deleted counted separately) |
| 2 `worker_eligible` | What an **unfenced** worker would pick up now: due scheduled, retryable failed, stale claims. The last row is the total. |
| 3 `scheduled` | Scheduled rows: future, or overdue by under 1 day, 1–7 days, 7–30 days, or over 30 days |
| 4 `failed` | Failed rows: retryable, uncertain outcome, unbound recipient, final; by attempt count |
| 5 `sending_claims` | Rows still marked Sending: in progress or stale |
| 6 `eligible_categories` | Notification type × campaign kind × campaign status, for the eligible set |
| 7 `eligible_recipients` | Recipient category × current account state: who would actually be e-mailed, cancelled or held |
| 8 `campaign_status`, `eligible_campaigns` | Campaign totals; eligible rows per campaign (ids and counts only) |
| 9 `fence_preview` | With a candidate fence: how many eligible rows stay held, and how many the first enabled run would process |

Rehearsed against the worker on disposable databases (MariaDB 12.3.2 and 10.4.32, schemas 15 and 16): the read-only `READ ONLY` run left the data unchanged, its counts matched the worker's own count of held rows, and no address or message text was returned.

## 3. Choosing the fence

The fence is a single notification id. Rows at or below it are held indefinitely; rows above it are processed normally.

- **Recommended: hold the entire existing queue.**
  - Re-run §0 and §9 *immediately before* activation, and use `max_notification_id` as the fence.
  - Re-run them then, rather than at the first review, because the web application keeps creating notifications after a deploy. A failed immediate delivery from that period would otherwise fall above an older fence and be retried.
  - With this fence, only notifications created after activation are ever processed by the worker.
- **Releasing older rows** is a separate decision.
  - A lower fence releases *every* eligible row above it. Check the exact effect in §9 by replacing the `SET @prism_fence` value first.
  - There is no per-row selection.
  - Stale claims released this way are marked uncertain and are **not** resent.

Held rows stay as they are: visible in notification history as Scheduled or Failed. The original sender can cancel a scheduled campaign from the Notifications page. Any other handling, such as manual cancellation or reconciling uncertain rows against the Gmail Sent folder, needs its own reviewed and approved action.

## 4. Activation (only after your approval)

Prerequisites:
- The application code is deployed and verified.
- `maintenance/runtime-preflight.php --database` reports PASS.
- You have decided which mail channel will be live:
  - With Gmail API credentials, mail goes through Gmail.
  - Without them, PHP `mail()` on Hostinger may still send **real e-mail** through the host's mail server.
  - Only when both fail is a metadata-only local record written.

1. Run the review SQL (§2) and keep the results.
2. Re-run §0 and §9 and note `max_notification_id`.
3. In File Manager, edit `config.local.php`. It is outside Git and not replaced by deploys. Add the following, with the number unquoted:
   ```php
   define('PRISM_NOTIFICATION_WORKER_ENABLED', true);
   define('PRISM_NOTIFICATION_WORKER_RELEASE_AFTER_ID', 12345); // reviewed max_notification_id
   ```
   Anything other than literal `true` keeps the worker disabled. A missing, negative or quoted fence makes the run refuse and exit 1.
4. hPanel → Advanced → Cron Jobs.
   - Check whether a `notification-dispatch.php` job already exists. It has been failing since production reached schema 15.
   - Example command, every 5 minutes, using the account's real path:
     `php /home/<account>/<path>/maintenance/notification-dispatch.php --limit=25 --seconds=50`
5. Read the first run's output (the cron e-mail or log). It is one JSON line:
   - `releaseAfterId` must equal your fence.
   - `heldBacklog` shows what stays held.
   - `selected`, `Sent` and `Logged` show what was processed.
   - Exit code 1 means a refusal, or that something failed or was uncertain.
6. Re-run §1 and §2 of the review SQL to confirm only post-fence rows moved.

**To turn it off:** set `PRISM_NOTIFICATION_WORKER_ENABLED` to `false`, or delete the line. The next run prints `disabled`. A run already in progress finishes its bounded batch: at most 25 rows and 50 seconds by default.

Schema 16 note: once schema 16 and the Settings extension are approved and applied, an enabled worker also creates the configured deadline reminders (default 7, 3 and 1 days before) for active Students. These are new notifications, created above the fence by definition.

## 5. Test evidence (disposable databases, simulated provider)

The scenario is `maintenance/frontend-reconciliation/notification-worker-tests.php`, run via `settings-run.ps1 -Scenario worker15|worker16`. Child processes are in `notification-worker-child.php`.

| Run | Server | Result |
| --- | --- | --- |
| `tests/claude-worker15-07` | MariaDB 12.3.2, general_ci, schema 15 | 98 / 98 |
| `tests/claude-worker16-06` | MariaDB 12.3.2, general_ci, schema 16 + Settings | 103 / 103 |
| `tests/claude-worker15-104-05` | MariaDB 10.4.32, unicode_ci, schema 15 | 98 / 98 |
| `tests/claude-worker16-104-04` | MariaDB 10.4.32, unicode_ci, schema 16 + Settings | 103 / 103 |

Section M of the scenario runs the **real** `config.php` Gmail and `mail()` code.
- **Simulators:** `mail-provider-simulator.php` (prepend) and `mail-provider-harness.php`. PHP's own `mail()` and `curl_*` are disabled and replaced, and the simulator refuses to load if they are still active.
- **Outcomes covered:**
  - Gmail success, 400 and 429 rejection, timeout after acceptance, and 503 after acceptance
  - token failure and connection failure, with fallback configured and not configured
  - refused fallback, and no Gmail configured
- **Senders and paths covered:**
  - invitation, account setup and password-reset e-mails
  - immediate notifications that are sent, uncertain, rejected or logged
  - the cron entry with mixed outcomes in one campaign, retry of the rejected recipient only, and no resend of the uncertain one
- **Ledger check:** a provider ledger confirms that no message was accepted by two transports.

What the scenario covers:
- **Activation gate and schema check:** the gate's value combinations; contract drift; schemas 14 and 17 refused.
- **Backlog fence:** the backlog stays byte-identical across fenced runs; explicit release delivers it exactly once.
- **Normal and future-scheduled delivery.**
- **Partial provider failure** inside one campaign, with backoff, then a successful retry that completes the campaign.
- **Retry limit:** exactly three attempts, never a fourth.
- **Interrupted delivery:** a worker process killed after the provider accepted the message. The claim is not resent, the dead worker's lock is released, and the row is marked uncertain after 15 minutes and never retried.
- **Concurrency:**
  - two concurrent workers: one runs, one reports busy, each message sent once
  - two concurrent dispatchers on the same rows: each message sent once
  - on schema 16, two concurrent reminder schedulers: each reminder queued once
- **Ineligible rows:** archived Student and inactive Adviser copies are cancelled. Unbound, deleted, archived and support rows are never e-mailed.
- **The real cron script** in a copied disposable app:
  - disabled: no database contact
  - enabled without a fence: refused
  - drift and unverified version: refused
  - enabled with a fence: only post-fence rows processed
  - mail to a closed local port: a metadata-only fallback entry with no address

## 6. Remaining delivery risks

1. **No real provider was exercised.** The Gmail API and Hostinger `mail()` were not authorized, so delivery semantics are proven with a simulated provider only.
2. **Channel behaviour:**
   - The Gmail-to-`mail()` duplicate path is closed (§1a).
   - The remaining residual risks:
     - A 4xx from Gmail is trusted as "not accepted".
     - `mail()` returning `false` is trusted as "not accepted by the local mail server", so both are retried.
     - `mail()` returning `true` only means the local mail server accepted the message. On Windows, an empty `sendmail_path` even reports success with nothing sent; this was found while testing.
3. **Uncertain rows need manual reconciliation.** There is no reconciliation UI. Stale claims are never resent.
4. **The fence is one id.** Held rows remain until someone handles them under a separate approval.
5. **Immediate (web) delivery is not gated by this switch.** It behaves exactly as in production today.
6. **Schemas 10–14 are now refused by the worker** (it previously accepted only 10). Production is on 15.
7. **The production server version and collation** come from an earlier export. §0 of the review SQL and the runtime preflight confirm them before activation.
