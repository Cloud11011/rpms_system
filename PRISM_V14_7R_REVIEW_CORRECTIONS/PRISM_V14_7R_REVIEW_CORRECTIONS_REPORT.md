# PRISM V14 Batch 7R: independent review corrections

**For:** the repository owner and the independent reviewer. **Date:** 2026-10-29. **Verdict on the package:** PASS WITH CONDITIONS.

| | |
| --- | --- |
| Worktree / branch | `.batch7r-worktree`, local `remediation/prism-v14-7r`, HEAD = baseline `f944220` |
| State | **Uncommitted.** Nothing was staged in the worktree, committed, pushed, merged, deployed or migrated, and no record was deleted. No Hostinger, staging or production system was contacted. |
| Scope of this pass | SEC-01, SEC-02, UX-01 and REL-01 as stated in the correction brief |

> **Limitation.** `PRISM_V14_7R_INDEPENDENT_REVIEW_FINDINGS.md` was not available in the workspace. This pass addresses the four priorities exactly as the brief states them. If the findings file contains other items, they are **not** addressed here.

**R1 (collation adaptation):** no code change. The reviewer accepts R1 for **staging only**. Production migration stays blocked until the staging rehearsal (runbook §5–§7) passes and the reviewer confirms it.

## 1. Summary

| ID | Finding | Correction | Regression evidence | Status |
| --- | --- | --- | --- | --- |
| SEC-01 | Deletion-verification evidence creation | `--out` is mandatory, and evidence is never printed. The output directory must be private and is checked before any database work. The file is created exclusively with mode 0600 before any content is written, and links are refused. | Private-output test: Windows 36/36 (incl. TLS), Linux 39/39 (root) and 38/38 (uid 1000). Cleanup rehearsal: 73/73 on a real database. | **Fixed** |
| SEC-02 | Cron receipt storage; probe TLS and database | `receiptDir` must be private, and every receipt is created exclusively with mode 0600. The probe and preflight use the migration runner's TLS policy and are bound to the configured database. | Same tests. A TLS server signed by a test CA proves the policy end to end. The pre-correction probe is shown to accept an untrusted CA and a mismatched database. | **Fixed** |
| UX-01 | No Student "Submit to RPMS" action | Every verified member gets a Student portal action, but only for an Adviser approval that was **not** forwarded automatically. Help text explains automatic forwarding. | 7R module 114/114 (was 96), including Chromium at 390 and 1440 px. Closure 94/94. | **Fixed** |
| REL-01 | `git add -A` commit procedure | A strict pinned allowlist (549 files) and a gate that stages only those files. The gate scans the **staged** blobs for private data and refuses the six unredacted originals. | Gate test 19/19. The full procedure was rehearsed on a disposable clone: 530 staged (all files at that point), 0 forbidden, sanitized reports staged, no commit. | **Fixed** |

## 2. SEC-01: deletion-verification evidence

**Files:** [`includes/migration_runner.php`](../includes/migration_runner.php) (new `prism_private_directory()`, `prism_private_target()`, `prism_private_write()`) and [`maintenance/issue-deletion-verification.php`](../maintenance/issue-deletion-verification.php).

- **Private output.** `--out=<file>` is required. Without it the tool refuses before connecting, and stdout stays empty. The old optional mode that printed the evidence is gone.
- **Directory checks** (before any database work):
  - the directory exists and is writable;
  - it is not a link, and its path is canonical, so a link anywhere in the path is refused;
  - it is outside the deployed application;
  - on POSIX, it has no group or other permission bits (chmod 700) and belongs to the running account.
- **File creation.** The name is restricted to `[A-Za-z0-9._-]`, and an existing file or link at the target is refused. The file is opened with `x` (O_CREAT|O_EXCL) under `umask 077`. Before any content is written, it is re-checked as not a link, its inode is matched against the path (POSIX) and it is set and verified to 0600. A partial file is removed.
- **No evidence file without valid evidence.** The file is created only after issuance and self-validation succeed.
- **Stdout** carries the host-free summary and the file's basename only.

## 3. SEC-02: cron receipts and the connection probe

**Files:** [`maintenance/hostinger-cron-runner.php`](../maintenance/hostinger-cron-runner.php), [`includes/migration_runner.php`](../includes/migration_runner.php) and [`maintenance/runtime-preflight.php`](../maintenance/runtime-preflight.php).

- **Private receipts.**
  - `receiptDir` goes through the same `prism_private_directory()` checks before any step runs (`unsafe_configuration` otherwise).
  - Receipts and evidence are written through `prism_private_write()`, with mode 0600 and never overwritten.
  - Names carry a random suffix, so two runs in the same second cannot collide.
- **One connection policy.** `prism_migration_pdo()` is now shared by the migration runners, the cron probe and the runtime preflight:
  - with a CA, TLS verifies the server certificate, and verification is never disabled;
  - a session that did not negotiate TLS although a CA was configured is refused (`tls_not_negotiated`);
  - only error classes are reported, never credentials or the DSN.
- **Expected database.**
  - The probe compares `SELECT DATABASE()` with the configured `database` and fails with `database_mismatch` (exit 1, no schema data) if they differ.
  - The preflight step receives `--expect-db` and refuses the same way.
- **Probe receipt** records `databaseMatches` and `tls: {caVerification, cipher}`.

**Shown before and after**, against a TLS-enabled MariaDB 12.3.2 whose certificate is signed by a throwaway test CA:

| Configuration | Pre-correction probe | Corrected probe |
| --- | --- | --- |
| `sslCa` = unrelated CA; configured `database` ≠ connected database | `connected: true`, **exit 0** | `connection_failed`, exit 1 |
| Correct CA, correct database | (not checked) | exit 0, CA verified, cipher recorded |
| Correct CA, wrong database | (not checked) | `database_mismatch`, exit 1 (probe and preflight) |
| CA configured, server without TLS | (not checked) | refused (`connection_failed`) |
| Wrong CA on `preflight` and `v12 dry-run` | (not checked) | `connection_failed`: the same policy on every step |

## 4. UX-01: Student formal submission

**Final decision preserved:** any verified group member may formally submit Adviser-approved evidence.

**How it fits automatic forwarding:**
- An Adviser approval recorded now **is** the formal submission. It is forwarded in the same transaction and needs no Student action.
- The member action applies only to an Adviser-approved current version that was **not** forwarded: "Ready for Formal RPMS Submission", without an RPMS override. Historical approvals are the case in practice.
- Both routes end in the same single-row conditional `UPDATE … WHERE rpms_submitted_at IS NULL`, so a version is submitted exactly once.

**Files:**
- [`documents_api.php`](../documents_api.php): a new `actions.memberSubmitToRpms` flag (Student viewer; current, unlocked, Adviser-approved, not forwarded, no override). The server still re-checks membership, the exact version and the stage under lock.
- [`assets/js/role-portal.js`](../assets/js/role-portal.js): a visible "Submit to RPMS" button on the card, labelled with the file and version.
- [`assets/js/prism-ui.js`](../assets/js/prism-ui.js):
  - `memberSubmitToRpms()` asks for confirmation naming the file and version, then posts `versionNo`. On a conflict it refreshes, so the button disappears.
  - The status tooltip and the Student help steps explain automatic forwarding and the member route.

**Tested** in [`maintenance/ierb-7r-tests/integration.cjs`](../maintenance/ierb-7r-tests/integration.cjs):
- API flags for every role and state:
  - pending: no action;
  - Adviser auto-forward: Submitted to RPMS, no action;
  - not forwarded: the action for both the holder and a non-PI member;
  - RPMS override, submitted or pending: no action;
  - Advisers and RPMS: never;
  - outsider: does not see the document.
- Real Chromium as the non-PI member:
  - at 390 and 1440 px, the button is on the eligible card only, fits the viewport, and the page does not scroll horizontally;
  - at 390 px, Cancel submits nothing;
  - at 1440 px, confirming submits, the card refreshes to "Submitted to RPMS" and the button disappears.
- After submission: the submitter is recorded as "(Student formal submission)", exactly one audit row exists, and a second member's retry gets 409.

## 5. REL-01: allowlisted commit with a staged-content privacy review

**Files:**
- [`maintenance/release/commit-gate.cjs`](../maintenance/release/commit-gate.cjs);
- [`maintenance/release/7r-commit-allowlist.tsv`](../maintenance/release/7r-commit-allowlist.tsv);
- [`maintenance/release/commit-gate.test.cjs`](../maintenance/release/commit-gate.test.cjs);
- procedure in [12 §3](../PRISM_V14_7R_COMPLIANCE_CLOSURE/12_PRE_STAGING_READINESS_REPORT.md).

- **Allowlist (549 files).** It holds the 513 files of the independent review package and the corrections of this pass. Each line is pinned to its git blob id, with branch and baseline checked.
  - The six reports the package sanitized are pinned to the **sanitized** blobs, and their unredacted originals are recorded as `original=`.
  - Everything else stays local and untracked: 1,099 files (all screenshots and PDFs, logs, cleanup evidence and production-derived migration evidence).
- **`prepare-sanitized`** moves each unredacted original byte-for-byte to a private folder outside the repository and installs the hash-verified sanitized copy. It refuses if an original changed after review.
- **`stage`**:
  - requires an empty index and stages exactly the allowlist with literal pathspecs;
  - verifies that the staged set equals the allowlist and that each staged blob equals its pin;
  - scans the **staged blobs** (forbidden paths, binaries, Hostinger prefixes, profile paths, keys and tokens, password hashes, non-synthetic emails, optional private deny-terms);
  - prints only the kind of a match, never its value, and resets the index on any failure;
  - never commits.
- **Rehearsed on a disposable clone of the real data:**
  - `check` refused the six originals;
  - `prepare-sanitized`, then `check` PASS;
  - `stage`: 530 files staged (the allowlist before the final-chain evidence was added), 0 forbidden paths, sanitized placeholders present (1 and 15), HEAD unchanged;
  - the six preserved originals are byte-identical to the worktree's.

  The clone was then deleted. The real worktree's index was never touched.

## 6. Newly discovered issues

| # | Issue | Severity | Action |
| --- | --- | --- | --- |
| N-1 | The cron `preflight` step connected without TLS verification even when `sslCa` was configured (`runtime-preflight.php` used its own PDO). | Medium (same class as SEC-02) | **Fixed:** the shared policy, `PRISM_PREFLIGHT_DB_SSL_CA` and `--expect-db` |
| N-2 | The cron receipt file name used the unvalidated `mode` argument of non-migration steps. `probe ../../x` could write a receipt outside `receiptDir`. | Medium (operator-supplied input) | **Fixed:** `mode` must match `[a-z0-9-]{1,20}`, and names are re-validated by `prism_private_write()` |
| N-3 | On Windows, PHP's exclusive `fopen('x')` follows a **dangling** symlink, and PHP 8.2 does not report junctions as links. | Low (the production host is Linux) | **Handled:** links are re-checked after opening, and the directory path must be canonical; tested on Windows and Linux |
| N-4 | The commit gate (this pass) crashed on more than 500 paths because of the Windows command-line limit, and left the index staged. | Found in rehearsal | **Fixed** before delivery: no path arguments, and any verification exception resets the index; regression test with 600 files |
| N-5 | The private cron **configuration** file (it holds the database password) is not checked for permissions. The runbook tells the operator to chmod 600. | Low (recommendation) | Not changed (outside the brief). Recommend enforcing 0600 in a later pass. |

## 7. Regression results

Fresh disposable MariaDB **12.3.2** fixtures with `utf8mb4_general_ci` (production's shape), synthetic data, PHP 8.2.12, Chromium. Evidence: [`evidence/runs/`](evidence/runs/) (receipts and results JSON; screenshots and logs stay local).

| Run | Suite | Schema | Assertions | Result |
| --- | --- | --- | ---: | --- |
| private-output (Windows) | SEC-01/02 without a database, plus TLS servers | — | 36 | PASS |
| private-output (Linux, `php:8.2-cli`) | The same with POSIX modes and ownership (root / uid 1000) | — | 39 / 38 | PASS |
| commit-gate.test | REL-01 | — | 19 | PASS |
| cxclean 12 | Cleanup rehearsal (D12): 3 Administrators preserved, `verify-final` PASS | 11 | 73 | PASS |
| cx1232 10 / 11 | 7R module (UX-01) / closure F01, targeted | 15 | 114 / 94 | PASS |
| **Final chain, cx1232** | Same order as the closure chain, on final code | | | |
| 01 | Full schema-11 suite + Batch 6 | 11 | 429 | PASS |
| 02 | V14 foundation (migration cases, rollback, contracts) | 11→12→11 | 99 | PASS |
| 03 | Full suite with every V14 flag | 12–15 | 860 | PASS |
| 04 / 05 | Batch 4 documents / Batch 5 monitor | 12+ | 144 / 245 | PASS |
| 06 | Batch 6 at schema 15 | 15 | 130 | PASS |
| 10-r2 / 11-r2 | 7R module (with UX-01) / closure F01 | 15 | 114 / 94 | PASS |
| — | Teardown 15 → 13 → 12 → 11, empty rollback | 15→11 | — | PASS, 0 legacy rows changed |
| 07 / 08 | Schema-11 rerun / Batch 6 at schema 11 | 11 | 310 / 131 | PASS |

All counts equal the closure chain's, except the 7R module: 96 → 114, from the new UX-01 assertions. No intermittent failure occurred.

**Three RPMS Administrators:**
- The cleanup plan is unchanged: `--retain-admins=1,13,46`, `verify-final` and actual removal only through `retention_purge()`.
- `clean-database-preview.php` and the retention code were not modified.
- The rehearsal again preserved all three synthetic Administrators with their deadlines, notices and configuration. Each still signs in and opens all nine main pages.

## 8. Not verified

- Any execution on Hostinger, staging or production: cron, `proc_open`, the real Remote MySQL CA. TLS was proven only against a local test CA.
- MySQL 8, and the MariaDB 10.4 / unicode_ci fixture in this pass.
- The independent reviewer's confirmation of these corrections.

## 9. Local-commit readiness

**READY FOR A LOCAL COMMIT, under these conditions:**

1. **Written commit authorization from the owner.** This pass stopped before committing.
2. **The six unredacted originals are moved out first** with `commit-gate.cjs prepare-sanitized` (12 §3). Until then, `check` and `stage` refuse them, by design. In the real worktree today, `check` reports exactly those six and nothing else.
3. **Commit only through `commit-gate.cjs stage`** and the manual review, never with `git add -A`. Screenshots, PDFs, logs, cleanup evidence and production-derived evidence stay local.
4. **No push** until the hPanel auto-deploy branch is confirmed (12 §3, G2).

**Recommended before staging:** the reviewer confirms these corrections and R1 remains staging-only. If the full findings file lists items beyond SEC-01, SEC-02, UX-01 and REL-01, address them first.
