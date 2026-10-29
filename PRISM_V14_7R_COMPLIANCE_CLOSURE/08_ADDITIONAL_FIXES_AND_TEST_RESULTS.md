# 08 · Additional fixes and test results (compliance closure pass)

**For:** the code reviewer and the release approver.

**Base:** `f944220` + the uncommitted 7R working tree in `.batch7r-worktree` (branch `remediation/prism-v14-7r`). Every change below is **uncommitted, unpushed and undeployed**. No production or Hostinger system was contacted. No migration, DDL, cleanup or deletion was run outside disposable local fixtures.

## 1. Files changed in this pass

### Application code (deployed if later committed): 3 files, +20 lines

| File | Change | Finding |
| --- | --- | --- |
| `students_api.php` | The Student Records **edit** path applies the 7R Research Title policy: an existing title cannot be erased (422), a changed title must be 3–255 characters, and changes are audited with before/after values. | **B7C-01** |
| `assets/js/prism-ui.js` | The shared reasoned dialog gives its inline error an ID; a too-short reason sets `aria-invalid="true"` and `aria-describedby` on the field; both clear once the reason is long enough. | **B7C-02** |
| `assets/css/prism-workspace.css` | One rule: labels in the shared form containers that do not wrap their control render on one line, so the red asterisk sits beside the label text. | **B7C-05** |

### Operator tools (CLI-only; `maintenance/` is denied over HTTP; never load `config.php`)

| File | Purpose |
| --- | --- |
| `maintenance/clean-database-preview.php` **(new)** | D12 (revised) preview: `--retain-admins=1,13,46`, `--storage`, `--redact`, `--keep-institution-reports`. Post-cleanup verifier: `--mode=verify-final --expect-from=<approved preview>`. Per-Admin credential fingerprints and record hashes; orphaned legacy notices identified. SELECTs only, in a `READ ONLY` transaction that is rolled back. |
| `maintenance/issue-deletion-verification.php` **(new; for review)** | Issues `PRISM_HARD_DELETE_VERIFICATION` evidence for one database with the reviewed issuers, then self-validates it. The key is never printed; the migration mutex is held; `--expect-db` binds the database. |
| `maintenance/hostinger-cron-runner.php` (7R file; **changed**) | New `deletion-verification` step; the key is redacted from receipts |

### Tests and harness (never deployed functionality)

| File | Change |
| --- | --- |
| `maintenance/ierb-7r-tests/closure.cjs`, `closure-fixture.php` **(new)** | F01: non-PI member Final Report over HTTP ([03](03_NON_PI_FINAL_REPORT_HTTP_TEST.md)) |
| `maintenance/ierb-7r-tests/cleanup-rehearsal.cjs`, `cleanup-fixture.php` **(new)** | D12 (revised) rehearsal on a dedicated synthetic fixture, including the issuer and cron-step tests ([11](11_CLEAN_PRODUCTION_DATABASE_PLAN.md) §6) |
| `maintenance/ierb-7r-tests/cleanup-rehearsal-restored.cjs` **(new)** | D12 (revised) rehearsal on an isolated restore of the owner's export; standalone runner; logs IDs, counts and hashes only |
| `maintenance/ierb-7r-tests/integration.cjs` | 4 new checks for B7C-01 on the Student Records path |
| `maintenance/ierb-7r-tests/fixture.php` | Cleanup also clears stale identity audits for its reserved IDs 40–43 (B7C-03) |
| `maintenance/ierb-ui-tests/integration.cjs` | F02 failure diagnostics on the three Add-entry clicks; B7C-02 assertion; cleanup removes identity audits of the Students it deletes (B7C-03) |
| `maintenance/ierb-group-tests/run.cjs` | Hooks `PRISM_TEST_CLOSURE=1` and `PRISM_TEST_CLEANUP_REHEARSAL=dedicated-fixture` |
| `maintenance/ierb-group-tests/runtime.php` | Test server loads locally issued deletion-verification evidence **only** while the rehearsal has created `sandbox/hard-delete-verification.json` |

### Evidence and tooling

`PRISM_V14_7R_COMPLIANCE_CLOSURE/evidence/`: run receipts, stress results, cleanup previews (redacted), rehearsal receipts, account screenshots, and tooling (`cc-chain.sh`, `b6-entry-stress.cjs`, `account-ux-capture.cjs`).

**No file in `PRISM_V14_7R_EVIDENCE/` or any `PRISM_V14_BATCH_0*_EVIDENCE/` folder was created or modified** (checked by modification time).

## 2. Defects found in this pass

| ID | Severity | Defect | Status | Verification |
| --- | --- | --- | --- | --- |
| **B7C-01** | Medium | The 7R Research Title policy (no erasure, ≥ 3 characters, before/after audit) covered only the IERB-entry edit path. **Student Records** (`students_api.php`, used by Admins and the assigned Adviser) could still erase or shorten a non-canonical title without any audit. This contradicted the 7R statement "Research Titles cannot be erased, and edits are audited". Canonical project titles were already protected on both paths. | **FIXED** | 7R module: erase → 422; "ab" → 422; valid change → 200 with a before/after audit (§3, run 10) |
| **B7C-02** | Low (accessibility) | In the reasoned dialog (pattern B, 15 dialogs) the missing-reason error was announced, but the field carried no `aria-invalid` and was not linked to the error. | **FIXED** | Batch 6 assertion "missing-reason error marks the reason field invalid and is programmatically linked to it" |
| **B7C-03** | Low (test isolation, pre-existing) | The Batch 6 cleanup left `account_identity_*` audits for the Students it deleted. Once auto-increment reached the IDs the 7R module inserts (40–43), login resolution refused Student 40 and the 7R module failed at setup. | **FIXED** (harness) | Reproduced ([runs/cc1232/10-batch7r-schema15](evidence/runs/cc1232/10-batch7r-schema15/)); passes after the fix (§3) |
| **B7C-04** | Info | No Student UI for member formal submission (C-1), and a technical refusal message when an Adviser approves a Final Report before Form 33 acceptance (C-2) | Recorded for acknowledgement | [02](02_RPMS_DECISION_REGISTER_D1_D12.md) |
| **B7C-05** | Low (visual) | Required asterisks wrapped onto their own line for labels that do not wrap their control (account password fields; any such label in the shared form containers) | **FIXED** | Computed style `display:block`, marker inline; screenshots before and after; all F06 account checks PASS |

**Test defects of this pass, found and fixed before the final results** (kept as evidence, not counted as passes):
- F01 first attempt uploaded the correction successor without RPMS first returning the affected version. The reviewed flow requires the return, so the upload was correctly refused.
- The cleanup rehearsal went through five attempts:
  1. a hand-written PDF was rejected by the validator;
  2. the wrong Adviser approved Student 5's document and was correctly refused;
  3. the test used a Student ID that did not exist on a fresh fixture;
  4. a rerun hit the correctly locked, already-submitted document;
  5. one assertion mis-described why a Student invitation was refused (it named a purged Adviser; "Unassigned" invitations are allowed).

  The final run used a freshly built fixture.

## 3. Test results

### 3.1 Final regression chain on the final code

| | |
| --- | --- |
| Fixture `cc1232` | Freshly built disposable MariaDB **12.3.2**, `utf8mb4_general_ci` (production's shape); ports 13737/14737; PHP 8.2; Chromium 156 via playwright-core 1.64.1 |
| Chain | [evidence/tooling/cc-chain.sh](evidence/tooling/cc-chain.sh) |
| Log | [evidence/runs/cc1232/chain.log](evidence/runs/cc1232/chain.log), section "FINAL CHAIN" and "FOLLOW-UP" |

| Run | Suite | Schema | Result | 7R (same fixture shape) |
| --- | --- | --- | --- | --- |
| 01-r2 | Full schema-11 suite + Batch 6 | 11 | **PASS (429)** | 428 (+1: B7C-02 assertion) |
| 02 | V14 foundation | 11→12→11 | **PASS (99)** | 99 |
| 03 | Schema-12 full, every V14 flag (documents, projects, report 14, monitor 15, Batch 6) | 12–15 | **PASS (860)** | 859 (+1) |
| 04 | Batch 4 documents / summaries | 12+ | **PASS (144)** | 144 |
| 05 | Batch 5 monitor / academic years | 12+ | **PASS (245)** | 245 |
| 06-r2 | Batch 6 at schema 15 | 15 | **PASS (130)** | 129 (+1) |
| 10-r3 | Batch 7R module (+ B7C-01 checks) | 15 | **PASS (96)** | 91 (+5) |
| 11-r2 | **Closure F01: non-PI member Final Report** | 15 | **PASS (94; module 81)** | new |
| — | Teardown 15 → 13 → 12 → 11 (empty rollback) | 15→11 | **PASS** (0 legacy rows changed), twice | same |
| 07-r2 | Full schema-11 suite after rollback | 11 | **PASS (310)** | 310 |
| 08-r7 | Batch 6 at schema 11 on fully final code (after the CSS fix) | 11 | **PASS (131)** | — |

### 3.2 Other executed tests

| Test | Result |
| --- | --- |
| Batch 6 repeated at schema 11 for F02 (08 r1–r6) | **6/6 PASS** (130 ×5; 131 after B7C-02) |
| First-chain runs on intermediate code: 01 (428), 06 (130), 07 (310) | PASS |
| F02 isolated stress of the failing interaction | 210 attempts, **0 misses**; one PHP-server load stall in run 1 ([04](04_BATCH6_INTERMITTENCY_INVESTIGATION.md)) |
| Cleanup rehearsal, **revised scope**, synthetic (fresh 12.3.2 fixture `ccclean3`, final tool versions) | **PASS (68; module 55)** |
| Cleanup rehearsal, **revised scope**, isolated restore of the real export (`ccexport`, earlier tool version) | PASS (21): 27 Students + 4 Advisers removed, 0 refusals; Admins 1/13/46 unchanged and eligible |
| Cleanup rehearsal, **confirmed scope**, isolated restore of the real export (re-imported) | **PASS (26)**: reports 29 → 28 (window, verified) → 0 (separate step, verified); 506 pre-existing audit rows removed within the 508 predicted, all others preserved; 115 Admin-action rows removed |
| Cleanup rehearsal, **confirmed scope**, synthetic (fresh `ccclean4`) | **PASS (68; module 55)** |
| Cleanup rehearsal, superseded "keep one Admin" scope | PASS (69), kept in `evidence/cleanup/superseded-retain-one-admin/` |
| `clean-database-preview.php` (revised): `verify-final` FAILS on an uncleaned database and PASSES after each rehearsal; the restored preview predicted the retained Adviser notices exactly (10) | as expected |
| `issue-deletion-verification.php` + cron step (isolated fixtures only): 4 invocation refusals; evidence rejected with another key and when tampered; the cron receipt holds no key, password, DSN or host; issuance on the restored export | PASS |
| F06 Admin account script (390/1440 px, light/dark), before and after B7C-05 | **PASS** (8/8 checks) |
| PHP lint of every changed or new PHP file; `node --check` of every changed or new JS/CJS file | PASS |

### 3.3 Failed runs kept as evidence (not counted as passes)

| Run | Cause | Classification | Resolution |
| --- | --- | --- | --- |
| 11-closure… (first) | Correction successor uploaded without RPMS returning the affected version | Test defect (wrong flow) | Test follows the reviewed return → revision flow |
| 10-batch7r… (first) | "Login identity conflicts" for Student 40 | **Pre-existing harness defect B7C-03** (Batch 6 residue) | Fixed in the Batch 6 cleanup and the 7R/closure fixtures |
| 10-batch7r…-r2 | New B7C-01 check sent `group: "__keep__"`, which the UI never sends | Test defect | Payload now matches the Student Records modal; the refusal messages are asserted |
| Cleanup rehearsal runs 1–4 (superseded scope) | See §2 | Test defects | Fixed; superseded |
| Restored rehearsal run 1 | Student 2 purge refused: "unresolved workflow" | **Real-data finding:** a current version formally submitted and then returned for revision blocks the purge; its Admin deletion needs a reason | Procedure and preview text updated (delete with a reason); the runner deletes exactly the preview's versions |
| Restored rehearsal run 2 | Verifier: operator fingerprint changed; 6 Student notices remained | **Findings:** sign-in upgrades older password hashes (the operating Admin must sign in before the preview); 6 orphaned legacy notices that no account owns | Verifier compares exactly the owner's Admin list per Admin; orphans identified and retained; procedure updated |
| Restored rehearsal run 3 | PASS (20), before the preview's Adviser-notice estimate was corrected | — | Final run on a re-imported copy with the final tool |
| Stress run 1 | PHP built-in server stalled at iteration 11 | Environment | Diagnostics added; not reproduced in 200 attempts |

### 3.4 Not executed in this pass

- MariaDB 10.4 / unicode_ci fixture (N2).
- Browsers other than Chromium; screen readers.
- Any Hostinger, staging or production system.
- A live AI model.
- A real production database (only the local restored copy of the owner's export, read-only).
