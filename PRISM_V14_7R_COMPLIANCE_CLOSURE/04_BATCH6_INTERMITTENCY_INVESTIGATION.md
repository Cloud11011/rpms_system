# 04 · Batch 6 UI intermittency investigation (F02)

**For:** the code reviewer and whoever maintains the regression harness.

## 1. The original failure (7R evidence, unchanged)

[PRISM_V14_7R_EVIDENCE/runs/fx1232/01-schema11-full-batch6-intermittent-failure](../PRISM_V14_7R_EVIDENCE/runs/fx1232/01-schema11-full-batch6-intermittent-failure/):

- **Where:** Batch 6 responsive matrix, iteration **1440 px light**, after 390/768/1024 passed. `maintenance/ierb-ui-tests/integration.cjs:385`: Admin opens `ierbprog.php`, `page.click('#addIerbEntry')` succeeds, then `#ierbEntryModal` stays hidden for 30 s.
- **Log:** `62 × locator resolved to hidden <div class="ierb-modal" aria-hidden="true" id="ierbEntryModal">`. The modal never received `aria-hidden="false"`.
- **Rate:** 1 failure in 16 executions in 7R; a rerun on the same fixture passed.
- **Not captured:** screenshot, DOM state, page errors (the test aborted before printing its error list).

## 2. What the code says

- `openEntryModal()` (`assets/js/ierbprog.js`) is synchronous: it sets `aria-hidden="false"` and `display:flex` in the same call that handles the click. Nothing asynchronous sits between the click and the modal.
- Playwright reported the click as delivered. Its actionability and hit-target checks require that the element at the click point is the button (or a child). An overlay or a dialog on top would have failed the **click**, not the later wait.
- Therefore the modal stayed hidden because either the click handler did not run (initialization threw, or the listener was not bound), or it threw before setting `display`, or the document was replaced after the click.

## 3. Cause categories assessed

| Category (brief) | Assessment | Evidence |
| --- | --- | --- |
| First-visit onboarding tour | **Ruled out** | The test seeds the Admin tour key before page scripts run. The tour is a modal `<dialog>`, which would make the *click* fail actionability. The 7R tour race affected a different module (Adviser key not seeded). |
| Browser timing / animation | **Not reproduced** | The sidebar collapse that `open()` triggers (Escape) can shift layout, but Playwright waits for a stable box and re-checks the hit target. 210 isolated attempts at all 5 widths × 2 themes: 0 misses. |
| Asynchronous initialization | **Possible, unproven** | The academic catalog is inlined by the server (no fetch). No page error occurred in 210 isolated page loads. |
| Genuine UI defect | **No evidence** | No reproduction in 210 isolated attempts or in 10+ full Batch 6 executions in this pass (§4). |
| Nondeterministic data | **Ruled out** | Opening the Add dialog does not depend on data. |
| Reused test state | **A separate defect of this kind was found and fixed** (§5). It did not cause this failure, but it does make later suites fail. | §5 |
| Environment (PHP built-in server) | **Observed once, separately** | Stress run 1 stopped at iteration 11 when `page.goto` never reached `load` within 30 s: the single-threaded PHP dev server stalled. That would fail at `goto`, not at the modal wait, so it is a second, independent intermittency. Not reproduced in the next 200 attempts. |

**Verdict: the root cause remains UNRESOLVED.** It is not reproducible in isolation and nothing in the code explains it. It is retained as an open, test-only finding (N4 in [09](09_REMAINING_RELEASE_BLOCKERS.md)). No assertion was weakened and no delay was added.

## 4. Stability evidence (this pass, MariaDB 12.3.2 / general_ci fixture `cc1232`)

| Execution | Batch 6 assertions | Result |
| --- | --- | --- |
| Isolated stress, run 1 ([f02-stress/run1-test](evidence/f02-stress/run1-test/)) | 10 attempts | 0 misses; aborted by one PHP server load stall (§3) |
| Isolated stress, run 2 ([f02-stress/run2-test](evidence/f02-stress/run2-test/)) | 200 attempts | 0 misses, 0 stalls, 0 page errors |
| 01-schema11-full-batch6 (baseline, fresh fixture) | full suite 428 | PASS |
| 08-batch6-only-schema11 r1–r5 (code before B7C-02) | 130 each | 5 × PASS |
| 08-batch6-only-schema11 r6 (with B7C-02 assertion) | 131 | PASS |
| 06-batch6-schema15 (first chain) | 130 | PASS |
| 01-schema11-full-batch6-r2 (final chain) | full suite 429 | PASS |
| 03-schema12-full-all-flags (includes Batch 6) | full suite 860 | PASS |
| 06-batch6-schema15-r2 (final chain, includes B7C-05) | 130 | PASS |
| 08-batch6-only-schema11-r7 (fully final code) | 131 | PASS |
| **Total full Batch 6 executions in this pass** | | **12 PASS, 0 FAIL** |

With 7R's record (16 executions, 1 failure), that is **28 executions with 1 failure**. That is not enough to call the failure eliminated, because its cause is unknown, so it stays open.

Receipts: [evidence/runs/cc1232/](evidence/runs/cc1232/) (`receipt.json` and `console.log` per run).

## 5. Changes made

1. **Failure diagnostics, not a fix** (`maintenance/ierb-ui-tests/integration.cjs`): the three Add-entry clicks go through `openEntry()`. If the modal does not open, it saves a screenshot and the DOM state, then rethrows the same error: `ierb-entry-failure-<label>.json` with page errors, URL, navigation type, modal state, the element at the button's centre, sidebar class, focus and open dialogs. The next occurrence will be diagnosable.
2. **Reused-test-state defect, fixed (B7C-03, pre-existing):** the Batch 6 cleanup deleted its synthetic Students but left their `account_identity_*` audit rows. After enough Batch 6 runs, auto-increment IDs reached 40–43, the IDs the 7R module inserts explicitly. Their stale identity audits then made login resolution refuse Student 40 ("Login identity conflicts with this record"), and the whole 7R module failed at setup. It surfaced here because this pass ran Batch 6 six extra times first.
   - **Fix:** the Batch 6 cleanup now removes identity audits for the Students it deletes.
   - **Defence in depth:** the 7R and closure fixtures clear stale identity audits for their reserved IDs.
   - Captured failure: [runs/cc1232/10-batch7r-schema15](evidence/runs/cc1232/10-batch7r-schema15/).
3. Investigation tool: [evidence/tooling/b6-entry-stress.cjs](evidence/tooling/b6-entry-stress.cjs). It replays `open()` exactly and records the click log, the listener state via CDP, the DOM and the server log on a miss or a load stall.
