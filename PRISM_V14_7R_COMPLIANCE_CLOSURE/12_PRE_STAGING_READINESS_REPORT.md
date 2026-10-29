# 12 · Pre-staging readiness report

**For:** the repository owner, the independent reviewer and the operator. Status as of 2026-10-28.

**Unchanged:** nothing has been committed, pushed, merged, deployed or migrated; production has not been contacted; HEAD is `f944220`, and the work is uncommitted in `.batch7r-worktree` on local branch `remediation/prism-v14-7r`, which has no upstream.

## 1. Verdict

| Question | Answer |
| --- | --- |
| Is R1 (legacy-collation contract amendment) ready for independent review? | **Yes** |
| Is the deletion verification-key generator ready for independent review? | **Yes** |
| Are the complete 7R + closure changes ready for independent review? | **Yes** |
| Is a safe non-deploying commit possible now? | **Yes, after review and your authorization**, as a **local** commit on `remediation/prism-v14-7r` (§3). Do not push any branch until you confirm in hPanel which branch auto-deploys. |
| Is the cleanup scope settled? | **Yes, except one item**: the retention treatment of the Admin-action audit rows the purge removes (§4). That blocks the *production* cleanup only. |

## 2. Review package

### R1: legacy-collation contract amendment

**Code:**
- `includes/schema_collation.php` (new);
- render-and-verify changes in `includes/schema_v12.php` … `schema_v15.php`;
- `includes/runtime_preflight.php`.

**Evidence:**
- [7R DB compatibility](../PRISM_V14_7R_DATABASE_COMPATIBILITY.md) §3–§6 (11 rehearsals, including the real export on MariaDB 12.3.2);
- this pass's final chain on a fresh 12.3.2 / general_ci fixture: migration to 12 and 15 reports `extensionCollation: utf8mb4_general_ci`, **0 legacy rows changed** on rollback, and every suite PASS ([08](08_ADDITIONAL_FIXES_AND_TEST_RESULTS.md) §3).

**Decision for the reviewer:** approve the contract *interpretation* (pinned DDL rendered in the verified legacy collation). The pinned JSON files are unchanged.

### Deletion verification-key generator

**Code:**
- `maintenance/issue-deletion-verification.php` (new);
- the `deletion-verification` step in `maintenance/hostinger-cron-runner.php`.

**Review focus:**
1. It calls only the existing issuers and validator.
2. The key is read from the environment, never printed, and redacted from receipts. Check whether the hosting environment exposes process environments to other accounts.
3. The `--external-dependencies-excluded` attestation is an operator statement; the code cannot verify it on shared hosting.
4. The `--out` file is created once, outside the application, with mode 0600.
5. It holds the `prism_migrate` mutex.
6. Evidence must be re-issued after any schema change.

**Tests (isolated databases only):** 4 invocation refusals; evidence from another key and tampered evidence both rejected by the application; a clean cron receipt; issuance on the restored export.

### The whole diff

**80 files:**

| Category | Files |
| --- | --- |
| Application | 29 (26 modified, 3 new). The closure pass changed only `students_api.php`, `assets/js/prism-ui.js` and `assets/css/prism-workspace.css`, +20 lines |
| Operator tools | 11 |
| Tests | 30 |
| 7R reports | 10 |

Plus `PRISM_V14_7R_COMPLIANCE_CLOSURE/` and `PRISM_V14_7R_EVIDENCE/`. File-by-file notes: [7R changed files](../PRISM_V14_7R_CHANGED_FILES.md) and [08](08_ADDITIONAL_FIXES_AND_TEST_RESULTS.md) §1.

**Pre-review checks run in this pass:**

| Check | Result |
| --- | --- |
| PHP lint and `node --check` on all 80 files | 0 failures |
| `git diff --check` | Clean |
| Credential-like strings | None, apart from the known synthetic fixture values |
| Real emails, hosts or names in evidence | None |
| Final regression on the production-shaped fixture | Every suite PASS: 429 / 99 / 860 / 144 / 245 / 130 / 96 / 94 / 310 / 131 |
| Schema-11 safety | Runs 01 and 07 at schema 11 |

Not re-run in this pass: the MariaDB 10.4 / unicode_ci fixture. 7R ran it; re-running is recommended, not a blocker.

## 3. Safe non-deploying commit: conditions and exact commands

1. **Deploy trigger.** No deployment configuration exists in the repository (no `.github/`); auto-deploy is configured in Hostinger. The documents record that pushing `feature/prism-v14-major-revisions` deploys. **Before pushing any branch, confirm in hPanel → Git which branch is watched.** A local commit never deploys.
2. **What to commit: exactly the allowlist** (REL-01, independent review). [`maintenance/release/7r-commit-allowlist.tsv`](../maintenance/release/7r-commit-allowlist.tsv) names every file, pinned to the git blob id that was reviewed: the files of the independent review package plus the review corrections. Everything else stays local and untracked: all screenshots and PDFs, logs, cleanup evidence and evidence derived from the production export. `config.local.php` is never committed.
3. **Unredacted originals.** Six local files are unredacted originals: the two requirements matrices (panel members' names), `PRISM_V14_7R_DATABASE_COMPATIBILITY.md` (Hostinger account prefix and database name) and three tooling scripts (local profile paths). The allowlist pins their **sanitized** review-package versions, so the gate refuses the originals. `prepare-sanitized` moves each original to a private folder outside the repository and installs the reviewed sanitized copy.
   - **The private folder (REL-02).** It must be a real folder, with no link or junction in its path, outside this repository and outside every other git working tree. The main checkout `prism-v13-release-clean` contains this worktree, so nothing under it qualifies.
   - **On Linux or macOS,** an existing folder must be yours with mode 700; the gate refuses anything looser and never changes it. A missing folder is created at 700. Every kept original and the manifest are created exclusively, at 600, and read back before any worktree file is replaced.
   - **On Windows,** the gate cannot verify NTFS permissions. Create the folder and restrict it yourself (below), then pass `--windows-acl-confirmed`. Without that flag, or if the folder does not exist, the gate refuses and prints the same steps.
4. **Stray files.** The *main* checkout (not the worktree) has stray untracked files with odd names (`e HEAD).Trim()`, `h -u origin`, …). They are outside this commit; do not add them.

**Commands, only after review and your written authorization** (local only; no push; never `git add -A`):

```bash
cd .batch7r-worktree
git switch remediation/prism-v14-7r          # local branch, no upstream
# Windows only: make the private folder first (outside every repository and outside OneDrive or other synced folders),
# remove inherited access and grant only your account, then check that icacls lists only you, as (OI)(CI)(F):
#   cmd.exe:    mkdir "<private folder>" && icacls "<private folder>" /inheritance:r /grant:r "%USERDOMAIN%\%USERNAME%:(OI)(CI)F"
#   PowerShell: mkdir "<private folder>"; icacls "<private folder>" /inheritance:r /grant:r "${env:USERDOMAIN}\${env:USERNAME}:(OI)(CI)F"
#   icacls "<private folder>"
node maintenance/release/commit-gate.cjs prepare-sanitized \
  --from=<extracted review package>/PRISM_V14_7R_INDEPENDENT_REVIEW/reports --originals-to=<private folder> \
  [--windows-acl-confirmed]                  # Windows only, after the icacls step above
node maintenance/release/commit-gate.cjs check   [--deny-terms=<private file of real names and production identifiers>]
node maintenance/release/commit-gate.cjs stage   [--deny-terms=...]   # stages only the allowlist, then scans the staged blobs
git diff --cached --stat | tail -5           # manual review: count and size
git diff --cached --name-only                # no config.local.php, dumps, screenshots, PDFs or logs
git commit -m "PRISM V14 7R remediation and compliance closure (reviewed; not deployed)"
git log -1 --stat | head -40                 # do NOT push until the deploy branch is confirmed
```

The gate refuses: another branch or baseline; an index that already has staged changes; any file whose content differs from its pin; an unredacted original; forbidden file types and paths; Hostinger prefixes, local profile paths, keys, tokens, password hashes, real email addresses and private deny-terms in the **staged** content. It prints only the kind of match, never the value. If staging fails it resets the index. To abandon after staging: `git reset -q`.

## 4. Remaining genuine blockers (only these)

| # | Blocker | Blocks | Owner |
| --- | --- | --- | --- |
| G1 | Independent code review of 7R + closure, including **R1 approval** and the key generator | Commit; staging | Reviewer |
| G2 | **Commit authorization** and **confirmation of the auto-deploy branch** in hPanel | Commit / any push | Owner |
| G3 | Read-only production checks: `staff_code_singleton` CHECK present, schema 11, Admins exactly 1/13/46 (runbook §2) | Staging restore validity | Operator |
| G4 | **Faithful hPanel backup** (database + `storage/`), SHA-256, restore verified | Staging; any cleanup | Operator |
| G5 | Isolated staging database(s) and subdomain; no-SSH route proven (`probe`, `preflight`) | Staging | Operator |
| G6 | **Audit-retention decision** for the Admin-action audit rows the purge removes (115 on the export; recommended: off-line retention extract) and its retention period ([11](11_CLEAN_PRODUCTION_DATABASE_PLAN.md) §4) | **Production cleanup only** | RPMS |
| G7 | Separate written authorizations: production cleanup window; 28-report deletion | Production cleanup | Owner |
| G8 | Release decisions and evidence (not cleanup): D1 activation, D9 AI accuracy sample, D3/D4/D5/D8/D10 compliance sign-off, 12-item staging acceptance list, separate production release plan | Production release | RPMS + Owner |

**Not blockers:** the F02 test-only intermittency and the optional 10.4 re-run. C-1 (Student "Submit to RPMS" button) is resolved by UX-01.

## 5. Next operator actions, in order

1. **Reviewer (G1):** review §2's package, and approve or reject R1 in writing.
2. **Owner (G2):** confirm the auto-deploy branch in hPanel → Git; authorize the local commit; the operator runs §3.
3. **Operator (G3), production, read-only:** run runbook §2, plus `SELECT id, role, status FROM users WHERE role='admin' ORDER BY id;`. Save the CSVs. Stop if the CHECK constraint is missing or the Admins differ.
4. **Operator (G4):** hPanel database backup + `storage/` archive; SHA-256; restore into a local MariaDB 12.3.2 and confirm the CHECK constraint and the counts (runbook §3).
5. **Operator (G5):** create **staging restore #1** and a subdomain; deploy the reviewed commit there only; neutralise mail, AI and cron (runbook §1, §4); `probe` and `preflight` by cron (runbook §5).
6. **Operator:** migrate staging #1 11 → 15 one step at a time (runbook §6); run acceptance items 1–12 with `PRISM_IERB_V14_OPERATIONS` defined only on staging (runbook §7).
7. **Operator:** create **staging restore #2** from the same backup and follow runbook §10:
   1. add a staging deletion key;
   2. run the `deletion-verification` cron step;
   3. the operating Admin signs in;
   4. take the preview; the owner signs it;
   5. take the audit extract, if G6 = A;
   6. run the cleanup;
   7. `verify-final --institution-reports=kept`;
   8. if rehearsing it, the report deletion, then `--institution-reports=deleted`;
   9. each Admin signs in (acceptance items 13–16).
8. **RPMS (G6, G8):** decide audit retention and its period; run the AI accuracy sample on staging (D9); record D1 and the compliance decisions.
9. **Owner (G7):** only after steps 6–8 pass, issue separate written authorizations for the production cleanup window and the report deletion, and approve a separate production release plan. **Never push `feature/prism-v14-major-revisions` before that plan.**
