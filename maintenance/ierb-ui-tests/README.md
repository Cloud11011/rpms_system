# Batch 6 form, account and deletion verification

Use only the disposable synthetic fixture described in `maintenance/ierb-monitor-tests/README.md`. Every helper verifies the actual MariaDB datadir before it reads or writes. Production configuration, credentials and email are never used.

`integration.cjs` runs inside the shared runner when `PRISM_TEST_BATCH6=1`. It checks:

- **Student ID rule.** The server rule (`onboarding_student_id`) rejects letters, spaces, symbols, full-width digits and signs, and accepts leading zeros. Student Records, IERB entry and profile completion apply it. Duplicate IDs are rejected. An unchanged historical alphanumeric ID can still be saved, a changed one cannot, and a Student with a legacy ID can still sign in.
- **Deletion API.** Role and assignment checks on `delete_preview`. The preview returns the exact filename, the recorded uploader (or "Not recorded"), document type, version and previous-version restoration. Submitted-document protection and the reason requirement still apply. Stale confirmations (`expectedVersion`) are refused. An injected persistence failure rolls back and is reported as a failure. Bulk previews list exactly the server-resolved targets. On schema 12+, retained V14 workflow evidence is protected.
- **Chromium checks** (real interactions):
  - IERB entry: required markers match server requirements, placeholders, numeric typing, invalid and valid paste, Save gating, Cancel, and double-click/Enter duplicate prevention.
  - Single deletion dialog: escaping, encoding, focus trap, Cancel, Escape, missing reason, a single request, and a rejected request.
  - Bulk deletion review list.
  - Adviser deletion dialog.
  - Account page: dirty Save, failure recovery, duplicate Enter, password visibility and match.
  - Student and Adviser accents, role chips and contrast in both themes.
  - Responsive matrix at 390/768/1024/1440/1920 in light and dark.

From the repository root, after `maintenance/ierb-group-tests/setup.ps1`:

```powershell
$env:PRISM_TEST_PLAYWRIGHT='<absolute path>\node_modules\playwright-core'
$env:PRISM_TEST_EVIDENCE_DIR=(Join-Path (Get-Location) 'PRISM_V14_BATCH_06_EVIDENCE')
# Full legacy schema-11 regression plus Batch 6
$env:PRISM_TEST_BATCH6='1'; node maintenance/ierb-group-tests/run.cjs
# Batch 6 on the complete V14 schema: run after the schema-12 full runner (see the monitor README),
# with the fixture migrated to 15 by the Batch 5 helper, then expected schema 15
```

The module adapts to the schema it finds:

- On schemas 11–12 it also creates an IERB entry through the UI with a new cohort group.
- On 13+, group creation through IERB entry is deliberately unavailable (canonical projects). The module instead verifies that Save stays disabled until a group is chosen.

It cleans up every synthetic `b6aa…` document and `batch6-…@example.test` account it creates.

Each run signs in several times. The synthetic sandbox's login rate limit is 60 attempts per 15 minutes per IP, so rapid repeated runs may need the sandbox counters in `PRISM_V13_GROUP_ADVANCEMENT_REVIEW/sandbox/app/storage/auth_rate_limits` cleared. This applies to the synthetic sandbox only.
