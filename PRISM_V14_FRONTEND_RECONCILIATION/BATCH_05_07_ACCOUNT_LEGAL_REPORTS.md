# Batches 5/7 continuation — Account, Legal, AI Reports, ZIP coverage audit

Claude Code, 2026-11-03, after checkpoint commit `51f6858b141fc3370845343315f00f73067c2b99`. Everything below is uncommitted. You authorized only one commit. Nothing was pushed, merged or deployed, and production was not touched.

**Verdict for this stage: PASS WITH CONDITIONS.** The conditions are in §5.

## 1. Whole-application ZIP coverage audit

I compared every relevant ZIP reference file (303 PHP/JS/CSS/JSON) with the working tree. Each differing file was then classified three ways against the ZIP's source commit `770bed2`, which is an ancestor of V14:

- **226 files identical to the ZIP.** After this stage, **every CSS file matches the ZIP.** The only exception is the intentionally removed `ierb-entry-steps.css`.
- **15 files differ only because V14 evolved after the ZIP's base.** The ZIP never changed these, so V14 stays:
  - `onboarding.js`, `invitations.js`, `document-upload.js`
  - `ierb_track_report.php`, `ierb_track_actions.php`
  - `research_project_advancement.php`, `document_workflow.php`, `document_management.php`
  - `ierb_v14_corrections.php`, `current_workflow_eligibility.php`
  - `data_exports_api.php`, `send_followup.php`, `account_invitation_api.php`
  - two test fixtures
- **62 files the ZIP changed.** Codex merged most of them; their remaining differences are deliberate V14 behaviour (see the handoff §D/§F). The ZIP changes Codex never merged are resolved in §2.
- **ZIP-only files not imported:**
  - `ierb-entry-steps.css/.js`: the retired entry workflow.
  - `maintenance/preview/*` and `maintenance/ierb-monitor-tests/dashboard.php`: ZIP preview tooling.

## 2. Files changed

| File | Change | Basis |
| --- | --- | --- |
| `account.php`, `assets/js/account.js`, `assets/css/account-clean.css` | Adopted from the ZIP verbatim: two-column layout, live password-rule checklist, read-only identity list, auto-applying Activity Log filters | Every hunk is presentation. Password change, session-generation, lifecycle and staff-registration calls are unchanged. The ZIP drops the explicit Apply button and the Clear-filters control; filters still apply on input and on Enter. |
| `admin_legal_policies.php`, `assets/css/admin-legal.css`, `assets/css/admin-legal-clean.css` | Adopted from the ZIP verbatim: Settings breadcrumb, policy-name headings, document rendering, paginated and tab-linked history | Publishing, approval and acknowledgement logic is identical. V14's pagination already supports the options used. |
| `includes/legal_policy.php` | Adds the ZIP rendering helper `legal_render_policy_body` | Pure rendering, no security effect |
| `includes/public_legal.php`, `assets/js/login-legal.js`, `assets/js/legal-management.js` | Adopted from the ZIP (V14 had not changed them since the ZIP's base) | Policy-document presentation; tab↔URL sync; page-size auto-submit |
| `assets/js/prism-forms.js` | ZIP presentation adopted: required-mark placement for checkboxes, Student Records ID message anchor, silent gate status | The ZIP's **4–5-digit Student ID rule was rejected**. V14 keeps digits-only (1–100) IDs, as documented in the handoff. |
| `admin_ai.php`, `assets/js/admin-ai.js` | ZIP AI-report history with page selection and bulk delete; ZIP heading "AI Progress Reports", which matches the navigation label | Bulk delete loops V14's existing per-report `reports_api.php?action=delete`. No new backend surface. V14's explanatory code comment kept. |
| `maintenance/frontend-reconciliation/settings-pages.cjs` | Browser coverage for Account (Admin/Adviser), Admin Legal Policies, and public Privacy/Terms | — |

ZIP changes deliberately **not** adopted (V14 is authoritative):
- `account_retention_bulk.php`: the ZIP's `direct_delete` bulk shortcut is a rejected deletion shortcut.
- `research_groups.php`: the change exists only for the removed IERB entry form.
- `ierb_progress.php`: covers retired legacy routes.
- `includes/pagination.php`: V14 keeps its unique search-binding fix.
- `complete_profile.php`: the 4–5-digit ID field.
- `ierb_stages.php`: a "(Conditional)" suffix inside the Stage 2 label. The dashboard and the stage sequence already append it where the ZIP shows it, so adopting it would print the suffix twice. This is noted for the paired visual check.
- `maintenance/ierb-v14-tests/cases.php`: the ZIP test assumes a four-entry `STAGE_SEQUENCE`, but V14 deliberately keeps the numeric storage sequence.

## 3. Tests and actual results

| Check | Result |
| --- | --- |
| Parser / smoke / checklist / `git diff --check` | 264 / 65 / 18 / clean |
| Fresh schema-15 current-V14 regression `tests/claude-v14-fresh-05` (MariaDB 12.3.2) | **366 assertions PASS**, exit 0, schema unchanged. Console: `claude-v14-fresh-05-console.txt` |
| — new Account/Legal/public-policy browser checks within it | 50 pass: identity, password rules, gating, viewport fit, Legal breadcrumb/tabs/history, public policy documents; no JavaScript errors or failed local responses |

## 4. Visual-fidelity observations (from current captures, not paired parity evidence)

1. **Settings at 390 px.** The Academic Years table is wider than its card, so the Registration column is clipped inside the card. The page itself does not scroll horizontally. Whether the ZIP renders identically must be settled by the paired comparison. No CSS was changed.
2. **Desktop Account capture.** The expanded ZIP navigation overlays the content, which is the ZIP's own behaviour. My capture helper collapses the sidebar only when the backdrop is present at page load. This is a capture limitation to fix in the paired harness, not an application defect.

## 5. Conditions, pending decision and remaining work

**Notification cron entry point: remediated under Option B (approved 2026-11-03), in this branch only.**
- **Defect.** At V14 HEAD, `maintenance/notification-dispatch.php` ran only on schema `10`, so every production (schema 15) cron run failed.
- **Fix.** It is narrower than the ZIP's version range 10–16:
  - Schemas 15 and 16 are accepted only with a passing exact contract preflight.
  - Activation is off by default (`PRISM_NOTIFICATION_WORKER_ENABLED`, set in `config.local.php` only).
  - A mandatory release fence (`PRISM_NOTIFICATION_WORKER_RELEASE_AFTER_ID`) holds the entire pre-activation queue untouched.
- **New files:**
  - Read-only phpMyAdmin review: `notification-worker/PRISM_NOTIFICATION_QUEUE_REVIEW.sql`
  - Operator procedure and risks: `notification-worker/NOTIFICATION_WORKER_ACTIVATION_GUIDE.md`
- **Tests:**
  - Worker scenarios: schema 15 70/70 and schema 16 75/75, on both MariaDB 12.3.2 and 10.4.32.
  - Settings settings-first: 75/75.
  - Fresh current-V14 regression `tests/claude-v14-fresh-06`: 366 PASS.
  - Static gates: syntax 266, smoke 65, checklist 18, diff-check clean.
- Production cron was not activated, no real e-mail was sent, and Hostinger was not touched.

Remaining work, in order:
1. Re-target the obsolete IERB-entry and role-chip assertions in `maintenance/ierb-ui-tests/integration.cjs` to the live Add Student dialog and account-button role text, then run the broad baseline suite. Its earlier recorded failures must be explicitly mapped.
2. A schema-16 variant of the current-V14 business-flow runner.
3. ~~Reminder partial-failure, concurrent-worker and retry tests with simulated delivery.~~ Done in the notification remediation above.
4. Reports and export interaction tests through the ZIP UI (generate, delete, CSV export, data export).
5. Paired ZIP-vs-reconciled visual comparison, including §4.
6. Final audit and readiness report.
