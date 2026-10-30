# Local onboarding acceptance and regression

These tools create **new synthetic fixtures only**. They never load a deployed `config.local.php`. Every PHP fixture connection checks the dedicated loopback port, database name and database datadir. The application is copied into the fixture, and its real HTTP APIs run with Gmail credentials empty, `mail()` disabled and cURL replaced by the existing synthetic provider. No production account, database, email provider or AI provider is used.

Prerequisites: PHP 8.2 with `pdo_mysql`, `mbstring`, `fileinfo` and ZIP; MariaDB 12.3.2 tools; Node; the existing Playwright Core and Chrome paths in `local-env.ps1`; dependencies matching `composer.lock` in ignored `vendor/`. The environment file contains local development paths only.

From the repository root in PowerShell:

```powershell
$bin = '<absolute path to MariaDB 12.3.2 bin>'
& maintenance/onboarding-tests/run-focused.ps1 -MariaDbBin $bin `
  -ReviewName PRISM_ONBOARDING_FINAL_REVIEW_EVIDENCE -DbPort 13937 -AppPort 14937
& maintenance/onboarding-tests/run-regression.ps1 -MariaDbBin $bin `
  -ReviewName PRISM_ONBOARDING_REGRESSION_RELEASE -DbPort 13947 -AppPort 14947
```

Both commands refuse to replace an existing fixture datadir. For another clean run, choose a new review directory and unused loopback ports. They stop their own MariaDB process on completion and preserve the datadir and evidence. Do not start overlapping runs against the same fixture. The full regression driver runs the reviewed schema-11 → schema-15 → schema-11 chain, then additionally tests the existing group-document suites with the proposed extension installed. It removes only empty extension tables during the isolated rollback test.

The focused suite covers account provisioning, durable pending assignments, first and later memberships, independent PDO activation races, invitation expiration and resend, profile conflicts, live staff authority, protocol uniqueness and audited corrections, historical PDF/snapshot preservation, all thirteen initial requirements, conditional amendment, institutional Form 33, Final Report completion and duplicate prevention. Chromium exercises existing Add/Invite dialogs at 1440 and 390 pixels in both themes, real saves, authoritative Join fields, Cancel/focus, and overflow. Decorative remote font/icon styles are replaced with empty CSS for offline, reproducible browser runs.

Outputs include result JSON, a synthetic project PDF, fixture schema/version evidence, and per-suite regression output. The user waived a separate screenshot deliverable; existing automated captures are retained as supplemental evidence. Earlier development attempts are retained separately; use the final review and regression release files named in the implementation report for the final verdict.

Additional checks:

```powershell
node maintenance/onboarding-tests/source-check.cjs capture <evidence>/source-sha256.json
node maintenance/onboarding-tests/source-check.cjs verify <evidence>/source-sha256.json
git diff --check
```

`source-check.cjs` hashes runtime sources, schema contracts and test tools without changing Git. Syntax results and the existing publication-builder tests are recorded separately. No staging, commit, push, deployment or runtime export is part of these tests.

The proposed `research_onboarding_v1` extension is **not a production migration instruction**. Its approval and activation dependencies are documented in `PRISM_V14_ONBOARDING_IMPLEMENTATION_REPORT.md`.

Independent audit checks can be added to a fresh focused run with
`PRISM_TEST_INDEPENDENT_AUDIT=1` (canonical follow-ups, attention, sorting,
activation metadata and member restore) and `PRISM_TEST_INIT_INVESTIGATION=1`
(normal, delayed and reload navigation in both themes at 390/1440px, plus an
explicitly injected missing-asset failure). The injected failure is a diagnostic
control, not proof of the historical incident's root cause. Preserve its receipt.
`python maintenance/onboarding-tests/audit-inventory.py` writes a conservative
dependency inventory under `PRISM_V14_INDEPENDENT_AUDIT`; single-reference
functions are investigation candidates and never authorize automatic deletion.
