# PRISM V14 Batch 7R: final security hardening

**For:** the repository owner and the independent reviewer. **Date:** 2026-10-29. **Verdict:** PASS. Ready for an authorized local commit after independent review (§8).

| | |
| --- | --- |
| Worktree / branch | `.batch7r-worktree`, local `remediation/prism-v14-7r`, HEAD = baseline `f944220` |
| State | **Uncommitted.** Nothing was staged, committed, pushed, merged, deployed or migrated, and no record was deleted. `prepare-sanitized` and `stage` were not run. No Hostinger, staging or production system was contacted. |
| Scope | The remaining review item N-5 (cron configuration-file permissions), then a re-verification of SEC-01, SEC-02, UX-01 and REL-01 |

## 1. Summary

| Item | Result |
| --- | --- |
| N-5: the private cron configuration file (it holds the database password) was read whatever its owner, mode or location | **Fixed.** Every step now checks the file before reading it, fails closed, and changes no permissions. |
| SEC-01, SEC-02, UX-01, REL-01 | **Still present and working.** Re-verified in code and by tests (§3). |
| New findings while re-verifying | Two low-severity gaps in the shared private-output rule, both **fixed** (F-2, F-3 in §5). No critical or high finding is open. |
| Regression | All suites pass: targeted tests on Windows (with TLS) and Linux (root and uid 1000), commit gate, cleanup rehearsal, and the full schema 11 → 15 → 11 chain on MariaDB 12.3.2. Details in §4. |

**Changed files (4), plus the allowlist pins:**
- [`includes/migration_runner.php`](../includes/migration_runner.php): new `prism_private_config()`; shared helpers `prism_path_key()`, `prism_private_location()` and `prism_posix_owned()`.
- [`maintenance/hostinger-cron-runner.php`](../maintenance/hostinger-cron-runner.php): calls the check before any read. The receipt records `configurationFile.ownerAndModeVerified` and the `posix` extension.
- [`maintenance/ierb-7r-tests/private-output.php`](../maintenance/ierb-7r-tests/private-output.php): +20 cross-platform assertions and +22 POSIX assertions (20 without root). The totals go from 36 to 56 on Windows with TLS, from 39 to 81 on Linux as root and from 38 to 78 on Linux as uid 1000.
- [`PRISM_V14_7R_STAGING_MIGRATION_RUNBOOK.md`](../PRISM_V14_7R_STAGING_MIGRATION_RUNBOOK.md): §0 and §5 describe the enforced Hostinger setup; §10 refers to it.

## 2. The configuration-file check

`prism_private_config()` runs before the file is opened for reading and before any database connection. It covers every step: `probe`, `preflight`, `v12`–`v15` and `deletion-verification`.

| # | Requirement | How it is met |
| --- | --- | --- |
| 1 | Outside the served application | Refused inside the deployed application (the same rule as before) **and** inside any `public_html` directory. Hostinger's web server runs as the account, so mode 600 alone does not keep a file there from being served. |
| 2 | No links or unsafe paths | It must be a regular file. A symbolic link to the file, a link anywhere in the path (including Windows junctions), relative parts (`..`, `.`), a directory or a dangling link are all refused. The path must equal its canonical form. After opening, the handle is re-checked to be the same regular file (inode and device on POSIX). |
| 3 | Owner | POSIX: the file must belong to the process's account (`posix_geteuid()`), including when running as root. Its folder must belong to the account or root. |
| 4 | Permissions | POSIX: no group or other bits on the file (600 or 400). The folder must not be writable by group or others (700 recommended, 755 accepted). |
| 5 | Before credentials or connection | Checked on the path, then re-checked on the opened handle, before any byte is read. A connection trap in the tests proves that no refused configuration reaches a database. |
| 6 | Safe errors | `unsafe_configuration` for location, link, owner and mode rules. `invalid_invocation` for a missing file, invalid JSON, a non-object or a missing field. Messages name the rule or the field only, never a value, path content or DSN. No receipt is written. |
| 7 | Existing checks not weakened | TLS policy (`prism_migration_pdo()`), the database-identity checks and migration authorization are unchanged. The runner's environment for child steps is unchanged. |
| 8 | Hostinger setup documented | Runbook §0 and §5: chmod 600 on the file, 700 (or 755) on its folder, real absolute path, the expected receipt fields, and what to do on `unsafe_configuration`. |
| 9 | Windows | Only the path rules apply. Windows has no POSIX owner or mode bits, and NTFS ACLs are not inspected. The receipt says `ownerAndModeVerified: false` rather than implying a check that did not happen. |
| 10 | No automatic chmod | The runner never changes permissions. A file that was readable by others may already have exposed the password. Fixing it silently would hide that, so the operator fixes the mode and decides whether to rotate the password. |

**Fail-closed owner check.** On a POSIX host without PHP's `posix` extension the owner cannot be verified, so the step stops with a message naming `posix`. Whether Hostinger's cron PHP has `posix` enabled is **not verified**. The staging probe will show it, and the runbook says how to enable it.

**Before and after.** The same cases were run against the pinned pre-fix runner (reconstructed and verified byte-for-byte against its allowlist blob ids `ca2aa725…` and `9fc33345…`) and against this pass. Linux, PHP 8.2.35, with a TCP listener standing in for the database. Evidence: [`evidence/private-output/config-before-after-root.txt`](evidence/private-output/config-before-after-root.txt) and `…-uid1000.txt`.

| Case | Pre-fix runner | This pass |
| --- | --- | --- |
| Configuration file mode 644, 640 or 604 | read, **connection attempted**, receipt written | `unsafe_configuration`, no connection, no receipt |
| Mode 644 with invalid JSON | parsed (`invalid_invocation`) | refused before parsing (`unsafe_configuration`) |
| Folder 777 with a mode-600 file | read, connection attempted | refused |
| Symbolic link to the file; file through a linked folder; `..` in the path | read, connection attempted | refused |
| File inside `public_html` (outside the application) | read, connection attempted | refused |
| `receiptDir` inside `public_html` | receipt written there | refused |
| Mode-600 file owned by another account, run as root | read, connection attempted | refused |
| `posix` extension disabled | read, connection attempted | refused (fails closed) |
| Control: mode-600 file in a 700 folder | read, connection attempted | read, connection attempted (unchanged) |

No case printed a credential, before or after.

## 3. Re-verification of the four corrected findings

| ID | What was checked | Result |
| --- | --- | --- |
| **SEC-01** deletion verification | `--out` is required; the destination is validated before connecting; the file is created exclusively at 0600 before any content, never through a link or over an existing file; no evidence file is written without valid evidence; the key is never printed. Code read in `issue-deletion-verification.php` and `prism_private_write()`. | **PASS.** Private-output tests (Windows 56, Linux 81 / 78); the cleanup rehearsal issues real evidence through the cron step (73/73). Strengthened by F-2 and F-3. |
| **SEC-02** cron runner | Private receipt directory; TLS with server-certificate verification for probe, preflight and runners; downgrade refused; expected-database binding for probe and preflight; **plus the new configuration-file check**. | **PASS.** With a throwaway test CA, a TLS MariaDB 12.3.2 and a `--skip-ssl` MariaDB 12.3.2: correct CA accepted with a cipher recorded; wrong CA refused on probe, preflight and `v12`; wrong database refused on probe and preflight; a server without TLS refused. |
| **UX-01** formal RPMS submission | Server: `member_submit` requires the exact version, an Adviser (not override) approval, and project membership re-checked under lock (`current_workflow_student(…, true)`). A single-row `UPDATE … WHERE rpms_submitted_at IS NULL` means one submission only. API flag `memberSubmitToRpms` for Students only. UI button and confirmation. | **PASS.** 7R module (114/114 in the chain): outsider 403; unapproved 409; concurrent member submissions commit exactly once; a later duplicate gets 409; an RPMS-override approval gets 409; the non-PI member submits through real Chromium at 390 and 1440 px; Adviser auto-forwarding unchanged. |
| **REL-01** safe staging | Strict pinned allowlist; staged-blob privacy scan; the six unredacted originals refused; no `git add -A`; nothing staged. | **PASS.** `commit-gate.test` 19/19. `check` (with and without a private deny-terms file) refuses exactly the six originals and nothing else. The index is empty and HEAD is unchanged. |

## 4. Regression results

Disposable local fixtures only: MariaDB **12.3.2**, synthetic data, PHP 8.2.12 (Windows) and 8.2.35 (Linux, Docker, no network), Chromium. Evidence: [`evidence/`](evidence/). Receipts, result JSON and targeted-test output are allowlisted. The chain logs are in the review package only, because the commit gate never commits `.log` files. Console logs, screenshots and the cleanup rehearsal's preview files stay local.

| Run | Suite | Schema | Assertions | Result |
| --- | --- | --- | ---: | --- |
| private-output, Windows | SEC-01/02 + configuration file, with the TLS servers | — | 56 | PASS |
| private-output, Linux root | Same, with POSIX modes and ownership (TLS skipped: no network in the container) | — | 81 | PASS |
| private-output, Linux uid 1000 | Same, without the root-only foreign-owner cases | — | 78 | PASS |
| private-output, Linux root, stock `php:8.2-cli` | The same test without `pdo_mysql` (the command in the package README); the connection-trap control is skipped because no connection is possible | — | 81 | PASS |
| commit-gate.test | REL-01 | — | 19 | PASS |
| fcclean 12 | Cleanup rehearsal: cron `deletion-verification` and `probe`, three Administrators preserved | 11 | 73 | PASS |
| **Final chain, fc1232** (`utf8mb4_general_ci`, production's shape) | | | | |
| 01 | Full schema-11 suite + Batch 6 | 11 | 429 | PASS |
| 02 | V14 foundation (migration cases, rollback, contracts) | 11→12→11 | 99 | PASS |
| — | Migrate 11 → 12 (R1): extension collation `utf8mb4_general_ci`, 0 legacy rows changed | 11→12 | — | PASS, manifest PASS |
| 03 | Full suite with every V14 flag | 12–15 | 860 | PASS |
| 04 / 05 | Batch 4 documents / Batch 5 monitor | 12+ | 144 / 245 | PASS |
| — | Install schema 15 (dry run unchanged, Students preserved) | 12→15 | — | PASS |
| 06 | Batch 6 at schema 15 | 15 | 130 | PASS |
| 10 / 11 | 7R module (UX-01, group-wide documents, Adviser correction requests) / closure F01 (non-PI Final Report completion) | 15 | 114 / 94 | PASS |
| — | Teardown 15 → 13 → 12 → 11, empty rollback | 15→11 | — | PASS, 0 legacy rows changed |
| 07 / 08 | Schema-11 rerun / Batch 6 at schema 11 | 11 | 310 / 131 | PASS |
| **R1 cross-check, fcuni** (`utf8mb4_unicode_ci`) | | | | |
| — | Migrate 11 → 12 (R1): extension collation `utf8mb4_unicode_ci`, 0 legacy rows changed; install 15 | 11→15 | — | PASS, manifest PASS |
| 10 / 11 | 7R module / closure F01 | 15 | 114 / 94 | PASS |
| — | Teardown 15 → 11, empty rollback | 15→11 | — | PASS, 0 legacy rows changed |

Every count equals the previous corrections chain's: 429, 99, 860, 144, 245, 130, 114, 94, 310 and 131, plus 73 for the cleanup rehearsal.

Skips and failures: no failure and no intermittent result. The only skips are the platform skips listed in each private-output result: POSIX checks on Windows, TLS in the network-less Linux container, the root-only cases for uid 1000, and the connection-trap control in the image without `pdo_mysql`.

PHP and JavaScript syntax: `php -l` on all 55 changed or new PHP files and `node --check` on all 16 changed or new JS/CJS files: 0 errors.

## 5. Findings in this pass

| # | Finding | Severity | Action |
| --- | --- | --- | --- |
| F-1 (was N-5) | The cron configuration file was read whatever its owner or mode, and through links or non-canonical paths. | Medium (the file holds the database password and, for cleanup, the deletion-verification key) | **Fixed** (§2) |
| F-2 | A receipt or evidence directory inside `public_html` but outside the application directory was accepted. On Hostinger the web server runs as the account, so a mode-600 file there can be served. | Low (it needs an operator to choose such a folder; names carry a random suffix) | **Fixed:** one location rule for all private files (`prism_private_location()`) |
| F-3 | On a POSIX host without the `posix` extension, the private-output directory's owner check was silently skipped. | Low | **Fixed:** fails closed, with a message naming `posix` |
| F-4 | Found in this pass's own testing: without `posix`, the folder refusal for the configuration did not name the cause. | Found in testing | **Fixed** before delivery: separate mode and owner messages |

## 6. Not verified and remaining issues

- **No Hostinger, staging or production execution.** In particular, whether Hostinger's cron PHP has the `posix` extension is not verified. Without it every step stops safely with `unsafe_configuration`, which would block staging until it is enabled.
- TLS was proven only against a local throwaway CA on Windows. The Linux run had no network, so it skipped TLS.
- Only the configuration file's own folder is checked for ownership and write access; folders above it are covered only by the no-link rule. The standard layout `/home/<account>/prism-private/` belongs to the account.
- Windows NTFS ACLs are not inspected (development only).
- MySQL 8 is untested, as before. MariaDB 10.4, 10.11 and 11.4 were covered by the earlier R1 matrix, not re-run here.
- `PRISM_V14_7R_INDEPENDENT_REVIEW_FINDINGS.md` is still not in the workspace (searched again). This pass covers N-5 and the four findings as briefed.
- The independent reviewer's confirmation of SEC-01, SEC-02, UX-01, REL-01 and this pass.

## 7. R1 (collation adaptation)

**Ready for Hostinger staging, unchanged.** No R1 code changed since the reviewed package: `includes/schema_collation.php` and `schema_v12.php`–`schema_v15.php` are byte-identical. On 12.3.2 the migration adapts to the fixture's legacy collation, both `general_ci` (production's) and `unicode_ci`. It changes 0 legacy rows and rolls back to schema 11 with the manifest PASS. The owner has confirmed that production runs MariaDB 12.3.2 and has the `staff_code_singleton` CHECK constraint, which clears runbook §2's stop condition. The §2 read-only queries should still be saved as staging evidence. Production migration stays blocked until the staging rehearsal (runbook §5–§7) passes and the reviewer confirms it.

## 8. Local-commit readiness

**READY FOR AN AUTHORIZED LOCAL COMMIT, after independent review, under the same conditions as before:**

1. Written commit authorization from the owner.
2. Run `commit-gate.cjs prepare-sanitized` (12 §3) first. Until then, `check` refuses the six unredacted originals, by design. Today it reports those six and nothing else.
3. Commit only through `commit-gate.cjs stage` and the manual review, never with `git add -A`.
4. **A push is a deployment.** The owner has confirmed that Hostinger auto-deploys `feature/prism-v14-major-revisions`. The local commit stays on `remediation/prism-v14-7r`, which has no upstream. Pushing or merging into the V14 branch is a separate, explicitly authorized deployment step, and it comes only after the staging rehearsal.

**Allowlist:** the four changed files are re-pinned to their new blob ids, and this report plus 34 evidence files (receipts, result JSON, targeted-test output) are added: 549 → 584 files. After the update, `check` refuses exactly the six unredacted originals and nothing else, with and without a private deny-terms file. No file outside the allowlist changed.
