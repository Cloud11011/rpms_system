# Batch 2 — Backend compatibility remediation

Verdict: PASS. Visual changes: none in this batch.

## Exact modified/added implementation inventory

| File | Reason |
| --- | --- |
| `includes/pagination.php` | Add caller-controlled bounded page-size sets and ZIP history pagination presentation options; retain the original defaults and unique bound search parameters. |
| `includes/ierb_monitor.php` | Add card-layout status counts, five-row support and scoped read-only requirement details. Preserve canonical-project scoping, protocol search/fields and the verified V14 dashboard KPI queries. |
| `ierb_api.php` | Expose only the new read-only `monitor_requirements` operation through the existing authenticated staff route and error handling. |
| `advisers_api.php` | Add scoped status counts for the ZIP Adviser record cards, retaining existing list permissions and pagination. |
| `maintenance/ierb-group-tests/run.cjs` | Opt-in hook for additive compatibility tests; default suite behavior unchanged. |
| `maintenance/frontend-reconciliation/compatibility.cjs` | New actual-session HTTP coverage for the adapters. |
| `maintenance/frontend-reconciliation/checklist-contract.cjs` | New renderer contract coverage; its candidate results are preparatory, not proof that application UI integration is complete. |

Baseline harness correction (Batch 1): `maintenance/ierb-project-tests/uploads.cjs` now waits for the authorized catalog refresh before asserting native file-picker tab order. The existing assertion was retained and rerun. The initial failure and all subsequent receipts remain under the ignored baseline fixture.

## Tests actually executed

- Baseline schema 11: 429 assertions passed. Foundation: 99 passed. Schema 12 all-flags rerun: 843 passed; the original attempt failed after 588 successful assertions at the asynchronous keyboard-focus check. The full continuation remains in progress and is not counted as completed here.
- Batch 2 isolated PHP HTTP suite: **29 passed, 0 failed**, including real logins, unauthenticated/Student rejection, Adviser assignment boundaries, no audit mutations from reads, bounded card/table pagination, protocol field preservation and literal wildcard search.
- Current source syntax: **244 passed, 0 failed**.
- V14 source/static smoke: **65 passed, 0 failed**.
- Prepared checklist renderer: **14 passed, 0 failed** with synthetic request contracts in Chromium. Checks include one atomic bulk acceptance call, correct submission holder/revision, idempotency key, three-cycle display, no Adviser official initiation, and required Amendment visibility. This is not a database-backed renderer acceptance result.

Commands and evidence:

```powershell
python maintenance/frontend-reconciliation/check-syntax.py PRISM_V14_FRONTEND_RECONCILIATION/batch-02-syntax.json
node maintenance/onboarding-tests/v14-simplification-smoke.cjs
# Fresh fixture: local-env.ps1 -ReviewName tests/frontend-reconciliation/batch02-runtime -DbPort 13982 -AppPort 14982
# setup.ps1, then PRISM_TEST_PHASE=documents and PRISM_TEST_FRONTEND_COMPATIBILITY=1:
node maintenance/ierb-group-tests/run.cjs
```

See `batch-02-integration.json`, `batch-02-console.txt`, `batch-02-syntax.json`, `batch-02-smoke.txt`; baseline continuation: `baseline-checks/regression-resume.txt`.

## Retained safeguards and risks

No ZIP version of the correction, workflow, requirements, document mutation, onboarding or authorization services was imported. V14's explicit enrollment, current-stage upload restrictions, successor-version protection, atomic bulk acceptance, RPMS-only official cases and three-cycle limit remain authoritative. The ZIP's automatic remapping of legacy numeric stages is rejected.

The application UI has not been integrated or visually certified yet. Schema 16 and Communication Settings require separate Batch 6 work; reviewed risks are in `SCHEMA_16_REVIEW.md`. Next: dashboard/navigation integration and matching-fixture browser checks.
