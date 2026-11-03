# PRISM V14 reconciliation

Read [the detailed handoff](PRISM_V14_FRONTEND_RECONCILIATION/HANDOFF_TO_CLAUDE.md) before continuing. The user transferred development to Claude Code; Codex stopped implementation on 2026-11-03. This is an incomplete development checkout, not a release.

- Workspace: `C:\PRISM_Workspaces\prism-v14-reconciliation`.
- Working branch: `reconcile/prism-v14-frontend-20261102`.
- Handoff HEAD: `f3d90c0f46132bd4dcb350dd673d6bdbeef47d16`. Verify actual branch/HEAD/status before editing; preserve all uncommitted/untracked work. No reconciliation commits exist.
- Backend authority: `feature/prism-v14-major-revisions`, repository `https://github.com/Cloud11011/rpms_system`.
- Stack: PHP8.2, MariaDB/MySQL, HTML/CSS/JavaScript, Composer, Windows/XAMPP; Hostinger shared hosting, historically no SSH.

## Source authority and workflow

The exact visual master is `C:\PRISM_Workspaces\prism-frontend-master\rpms_system_new.zip`; SHA256 `2c3133af45d4d320ce86e81856ccdbfdc273ef00b68834fdb30829f48f4b4a57`. Preserve its layout, cards, typography, colors, spacing, assets, modals, responsiveness, themes, animations and interactions. No redesign. Any additional visible deviation needs explicit user approval. Inspect the restricted reference under ignored `tests/frontend-reconciliation/reference/`; never import ZIP Git/vendor/uploads/dumps/secrets or replace the V14 repository.

V14 is authoritative for authentication, roles, authorization, documents/versions, membership, transactions, audit, notifications, reporting/exports and security. Preserve its newer guards when adapting ZIP views/API contracts.

Only four official stages are active: Initial Submission (13 requirements), conditional Resubmission / Amendment, Approval of Data Gathering (Form33), IERB Completed (Final Report). Use semantic V14 keys. Stage2 completes only after an actual authorized official case resolves, not from reaching Stage3/4. Stage4 remains IERB Completed. Preserve applicable requirement names/statuses on IERB Progress hover.

RPMS Administrators alone initiate official cases; maximum THREE over project lifetime, with fourth rejected server-side. Ordinary Adviser revisions neither consume cycles nor change official stages, and earlier versions remain available. Preserve current-stage upload rules, no silent overwrite, atomic acceptance, Amendment decisions, group authority, institutional reference and transactional/idempotent Final completion.

Legacy-only Stage1–5 UI, historical filters/percentages/Student Stage Records and obsolete controls may be removed under explicit approval. Retain necessary numeric storage/version keys, migrations, data/history, compatibility and security fences. Do not revive old Stage4 Certificate-of-Approval stage labels in current monitoring. The old historical-display approval document is superseded by the final V14-only instruction.

Three other approved exceptions: omit Adviser official resubmission initiation; retain V14 Add/Invite assignment fields/actions in ZIP dialogs; retain reviewed member-archival reason/warnings. No other appearance exceptions are assumed.

## Database and production restrictions

Production is schema15 per the user. Reuse existing Add Academic Year/catalog. Integrate ZIP Academic Year/Communication Settings; schema16 only if genuinely needed. Preserve assignments, onboarding/protocol authority, exact contracts, audit/retention protections and migration ordering. Develop/test only on disposable databases. If later justified, prepare reviewed manual phpMyAdmin SQL; no production execution without separate approval.

Do not push, open a PR, merge, deploy, touch production, delete real data/tables/history, overwrite ZIP, reset/clean/stash/rebase, or commit without permission. Preserve existing changes. The handoff-only turn authorized documentation, not implementation; resume development only in the continuation context requested by the user.

## Current checkpoint and validation

Exact next unfinished batch: **Batch6 Settings/database compatibility**. Critical: `includes/admin_settings_schema.php` references missing `includes/admin_settings_schema.json`. Settings/schema16 composition, registration enforcement, navigation, worker behavior and latest Reports UI are incomplete/untested. Read handoff sections I–L before fixing. Do not weaken schema checks or claim the current checkout passes.

Historical checkpoint results: current-V14 business/surface/browser182; later browser-only136 (including staff-page86); checklist contracts18; last full parser250; source smoke65. They overlap and predate latest Settings changes. Full current regression and visual parity are unfinished. Earlier full baseline had recorded failures; preserve them and replace obsolete expectations explicitly.

Previously executed checks, to rerun after completing interrupted source:

```powershell
python maintenance/frontend-reconciliation/check-syntax.py PRISM_V14_FRONTEND_RECONCILIATION/claude-syntax.json
node maintenance/onboarding-tests/v14-simplification-smoke.cjs
node maintenance/frontend-reconciliation/checklist-contract.cjs
git diff --check
```

Fresh isolated workflow runner: `maintenance/frontend-reconciliation/run-v14.ps1`; safe environment/ports, exact commands, fixture caveats and tool paths are in the handoff. Clear `PRISM_TEST_V14_BROWSER_ONLY` before full tests. No production credentials/providers. Preserve ignored `tests/` screenshots, candidates and receipts; ordinary Git transfer omits them. Treat prepared candidates as review material, never wholesale replacements. Use UTF-8-safe edits and keep meaningful current regression coverage.
