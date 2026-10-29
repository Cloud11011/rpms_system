# PRISM V14 7R: Final release readiness

**For:** the RPMS release approver and the repository owner.

## Verdict

**NOT READY FOR PRODUCTION MIGRATION OR V14 ACTIVATION.**

**READY FOR REVIEW AND AN ISOLATED HOSTINGER STAGING REHEARSAL.**

Production is safe as it is. It stays on schema 11 with the currently deployed code. Nothing in this batch touched it.

| | |
| --- | --- |
| Reviewed reference | `feature/prism-v14-major-revisions` @ `f944220` |
| 7R work | Local branch `remediation/prism-v14-7r` in `.batch7r-worktree`. **Uncommitted, unpushed, undeployed.** Pushing the V14 branch auto-deploys to Hostinger, so it must not be pushed until approval. |
| Production database | Not accessed. Schema 11. No migration, cleanup, deletion or activation performed. |

## What 7R resolved

**Database (7R-A):**
- The reviewed schema-12–15 runners **cannot migrate production as written**. On MariaDB 12.3.2 with production's `utf8mb4_general_ci` tables, schema 13 fails (errno 150).
- Worse, a database left at schema 12 by those runners **breaks the IERB monitor's search and filters** (new finding B7R-N01).
- Both are fixed by rendering extension tables in the verified legacy collation (amendment R1).
- The real production export now migrates 11→15 with every legacy table byte-identical. This was repeated across 4 MariaDB versions × 3 collations.
- Preflight accepts 10–15 with a full contract check. Runner errors are classified and credential-free. A no-SSH cron wrapper exists.

**Decision A (7R-B):** every verified member uploads, revises, opens and formally submits Adviser-approved evidence for the one project record. Duplicate submissions are impossible, non-members are isolated, and the PI is a recorded tag with no permission effect.

**Decision B (7R-B):**
- Enrollment, reconciliation, rename, Adviser reassignment and PI designation are available by CLI and by gated Admin HTTP.
- There is no membership inference.
- The identifier trailing-hyphen defect is fixed.

**Decision C (7R-C):**
- Adviser requests stay "Pending RPMS Review" with the stage unchanged.
- RPMS approves (choosing the Amendment requirement per case) or rejects with a reason. RPMS can also initiate a case.
- Revision, idempotency and duplicate guards apply, history is immutable, and members are notified.
- No new schema version was needed.

**Decision D:** the four stages and their rules are unchanged.

**Reports and UI (7R-D):**
- AI reports now carry Research Titles and count canonical projects once.
- The PDF keeps Windows-1252 text (ñ, à, ã, €, curly quotes) and discloses any substitution.
- Sorting was added to Student Records, Reports and Deadlines.
- Research Titles cannot be erased, and edits are audited.
- The track sheet shows the designated PI.

**Hygiene (7R-E):**
- A read-only cleanup manifest was produced: 4 protected records and 23 candidates awaiting confirmation. Nothing was deleted.
- Test isolation was hardened.

## Test results

The final regression ran on two freshly built fixtures, one after the other: MariaDB **12.3.2 / general_ci** (production's shape) and **10.4 / unicode_ci** (the reference fixture). Each went 11→12→15→11. **Every suite passes on both:**

| Run | Assertions per fixture |
| --- | --- |
| Schema 11 + Batch 6 | 428 |
| Foundation | 99 |
| All V14 flags | 859 |
| Batch 4 | 144 |
| Batch 5 | 245 |
| Batch 6 at 15 | 129 |
| **Batch 7R module** | **91** |
| Teardown/rollback | 0 legacy rows changed |
| Schema 11 after rollback | 310 |

That is 2,305 passing assertions per fixture.

- **One disclosed intermittency:** a single failure in the *unmodified* Batch 6 UI suite (12.3.2, run 01), which passed on rerun. That suite completed 16 times and failed once.
- **This regression found and fixed three 7R defects and one pre-existing defect:**
  - track-sheet wording regression;
  - test residue from the rewritten Batch 3 test;
  - an onboarding-tour race in the new browser check;
  - iconv transliteration portability in project identifiers (pre-existing).
- Migration rehearsals: all passed (DB §5).

Full table: [Security and regression §2](PRISM_V14_7R_SECURITY_AND_REGRESSION.md).

## Blockers before any production release

| # | Blocker | Owner | Clears when |
| --- | --- | --- | --- |
| 1 | **R1 approval:** contract amendment (extension tables use the legacy collation) | Reviewer | Signed approval of Spec §4 R1 |
| 2 | **Code review and commit authorization** for the 7R diff (51 modified, 9 new files) | Owner | Reviewed and committed to a non-deploying branch |
| 3 | **Production CHECK constraint** (`staff_code_singleton`) present? | Operator | Read-only query result (runbook §2). If it is missing: stop, because the database has drifted from v11. |
| 4 | **Faithful backup** (hPanel, not phpMyAdmin export) restored and verified | Operator | Runbook §3 step 4 passes |
| 5 | **No-SSH route proven on Hostinger** (cron wrapper or Remote MySQL with TLS) | Operator | Runbook §5 receipts |
| 6 | **Staging rehearsal** 11→15 on Hostinger with identical legacy row counts and contract PASS | Operator | Runbook §6 receipts |
| 7 | **Staging acceptance list** (12 items) | RPMS + developer | Security and regression §4, all PASS |
| 8 | **D1** activation of V14 Admin operations, and who reconciles projects | RPMS | Written decision |
| 9 | **D9** AI accuracy review on real (sanitised) documents | RPMS | Accuracy sample signed off |
| 10 | Remaining institutional decisions D2–D8, D10–D12 | RPMS | Recorded (none block migration, but D3/D4/D5/D10 block compliance sign-off) |

## Exact operator actions, in order

**Do not push `feature/prism-v14-major-revisions` until step 10.**

1. **Review** the 7R diff in `.batch7r-worktree`. Use [Changed files](PRISM_V14_7R_CHANGED_FILES.md) and [Spec](PRISM_V14_7R_REMEDIATION_SPEC.md).
2. **Approve or reject R1** in writing. If R1 is rejected, the only alternative is converting production's legacy tables to `unicode_ci`. That is a separately reviewed data migration and is not recommended (it can make existing unique keys collide).
3. **Authorize a commit** of the reviewed 7R work to a branch that does **not** auto-deploy.
4. **Production, read-only:** run the SELECT-only checks in runbook §2 and keep the CSVs. Confirm `12.3.2-MariaDB-log`, schema 11, general_ci anchors and the CHECK constraint.
5. **Back up production** through hPanel database backups plus `storage/`. Record the hashes, and verify the restore locally (runbook §3).
6. **Create an isolated staging database and subdomain**, restore, sanitise, and neutralise mail, AI and cron (runbook §1, §4).
7. **Prove the no-SSH route** on staging with `probe` and `preflight` (runbook §5).
8. **Migrate staging 11→15** one cron step at a time, checking each receipt (runbook §6). Compare legacy row counts.
9. **Run the staging acceptance list** ([Security and regression §4](PRISM_V14_7R_SECURITY_AND_REGRESSION.md)), with `PRISM_IERB_V14_OPERATIONS` defined only in the staging `config.local.php`. Run the AI accuracy sample (D9). Record decisions D1–D12.
10. **Only after** steps 1–9 pass and RPMS approves a dated release window, prepare a **separate production release plan**: maintenance window, fresh backup, deploy code, preflight at 11, migration steps, preflight at 15, then activation as decided in D1. That plan needs its own approval. It is not authorized by this report.
11. **Test-record cleanup** (separate approval, separate window, D12): follow [Cleanup manifest §4](PRISM_V14_7R_TEST_DATA_CLEANUP_MANIFEST.md), confirming each candidate by ID through the Admin lifecycle. Never cleanup by SQL.

## Not verified

- MySQL 8.
- Screen readers.
- Browsers other than Chromium.
- Live AI accuracy.
- Hostinger cron PHP environment (`proc_open`, PHP CLI path).
- Whether production itself has the CHECK constraint.
- End-to-end member Final Report formal submission over HTTP. It is covered at the code level, and the Adviser route is covered over HTTP.
