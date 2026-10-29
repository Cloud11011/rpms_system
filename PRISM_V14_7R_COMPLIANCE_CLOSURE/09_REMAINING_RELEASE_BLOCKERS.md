# 09 · Remaining release blockers

**For:** the release approver and the repository owner. None of these was cleared by this compliance pass. This pass did not connect to Hostinger and performed no release operation.

| # | Blocker | Status after this pass | Owner | Clears when | Blocks staging? | Blocks production? |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | **R1** legacy-collation contract amendment (extension tables use the verified legacy collation) | **Open.** Not re-litigated; the closure regression re-confirmed the general_ci path (migration to 12/15 with `extensionCollation: utf8mb4_general_ci`, 0 legacy rows changed). | Reviewer | Signed approval of spec §4 R1 | Yes (staging should run the code that will ship) | Yes |
| 2 | **Independent code review** of all 7R changes **plus this pass's changes** ([08](08_ADDITIONAL_FIXES_AND_TEST_RESULTS.md) §1) | **Open.** This pass reviewed the areas it touched and found B7C-01. It is not an independent full review. | Reviewer | Review completed with findings resolved | Yes | Yes |
| 3 | **Commit authorization** to a non-deploying branch | **Open.** Everything is still uncommitted in `.batch7r-worktree` on `remediation/prism-v14-7r`. | Owner | Written authorization; commit to a branch that does not auto-deploy | Yes (staging deploys from reviewed code) | Yes |
| 4 | Live production **`staff_code_singleton` CHECK** | **Not verified.** Needs one read-only query (runbook §2). | Operator | Query result saved | Yes (decides whether the fidelity repair is valid) | Yes |
| 5 | **Faithful hPanel backup and restore** verification | **Open** | Operator | Runbook §3 step 4 passes | Yes | Yes |
| 6 | **Hostinger cron runner** compatibility (`proc_open`, PHP CLI path) | **Not proven on Hostinger** | Operator | `probe` and `preflight` receipts on staging | Yes | Yes |
| 7 | **Isolated Hostinger staging** setup | **Open.** The owner states a separate database is available; nothing was created. | Operator | Runbook §1, §4 done | — | Yes |
| 8 | **Schema 11 → 15 staging migration** with populated data | **Open** | Operator | Runbook §6 receipts, contract PASS, identical legacy row counts | — | Yes |
| 9 | **12-item staging acceptance list** | **Open.** Item 8 (member Final Report completes IERB) is now also proven locally over HTTP (F01). | RPMS + developer | All 12 PASS on staging | — | Yes |
| 10 | **D1–D12** | D11 decided; D5 and D12 owner-decided pending confirmation; D1–D4, D6–D10 open ([02](02_RPMS_DECISION_REGISTER_D1_D12.md)) | RPMS | Written decisions | D1 before staging items 3–8 | Yes (D1, D9; D3/D4/D5/D10 for compliance sign-off) |
| 11 | **Live AI accuracy** evaluation | **NOT VERIFIED**; protocol ready ([06](06_AI_ACCURACY_EVALUATION.md)) | RPMS | Signed scoring sheet meeting the thresholds | No (it runs on staging) | Yes, for AI sign-off |
| 12 | **Test-data cleanup** | **Scope confirmed** (keep Admins 1, 13, 46; remove test Students and Advisers; 28 reports are deletion candidates under separate authorization; keep the 6 orphan notices). Rehearsed on an isolated restore of the real export (PASS, 26). **Open: the audit-retention decision for the 115 Admin-action rows the purge removes** ([11](11_CLEAN_PRODUCTION_DATABASE_PLAN.md) §4); production execution and report deletion not authorized. | Owner + RPMS | [11](11_CLEAN_PRODUCTION_DATABASE_PLAN.md) §10 complete | No (the rehearsal runs on staging) | Yes, for the clean-database goal |

## New conditions found in this pass

| # | Condition | Why it matters | Clears when |
| --- | --- | --- | --- |
| N1 | **Deletion-verification evidence issuer** (former B4) | **Proposed and implemented for review:** `maintenance/issue-deletion-verification.php` plus the cron-runner `deletion-verification` step. Tested on isolated databases only: refusals, key and tamper rejection, a clean receipt, and real-export issuance. Every database needs its own evidence, and a database migrated to 12+ needs re-issued evidence. | Independent review of the tool and cron step; run on staging |
| N2 | **The MariaDB 10.4 / unicode_ci fixture was not re-run** with this pass's changes | 7R ran both fixtures; this pass re-ran only the production-shaped 12.3.2 / general_ci fixture | Re-run the chain on a 10.4 fixture (about 30 minutes), or accept 12.3.2-only evidence for these small changes |
| N3 | Browser and assistive-technology coverage | Chromium only; no screen reader (B7-F18 unchanged) | Optional for staging; recommended before production |
| N4 | F02 root cause unresolved | Test-only intermittency; diagnostics now capture the next occurrence ([04](04_BATCH6_INTERMITTENCY_INVESTIGATION.md)) | A captured occurrence is analysed, or the risk is accepted |
| N5 | C-1: no Student UI for member formal submission | **Resolved (UX-01, independent review).** The Student portal now offers "Submit to RPMS" to every verified member for an Adviser approval that was not forwarded automatically; browser-tested at 390 and 1440 px ([corrections report](../PRISM_V14_7R_REVIEW_CORRECTIONS/PRISM_V14_7R_REVIEW_CORRECTIONS_REPORT.md)) | None |

## Operator order (unchanged from 7R except the cleanup branch)

Blockers 1–3 → 4–5 → 7 → 6 → 8 → 9 (with D1) → AI sample (11) → cleanup rehearsal on a second staging restore (12, after N1) → separate production release plan and separate cleanup authorization.

**Do not push `feature/prism-v14-major-revisions` until that plan is approved:** pushing it deploys to Hostinger.
