# 10 · Final staging readiness verdict

**For:** the release approver, RPMS and the repository owner.

## Verdict

**NOT READY FOR PRODUCTION RELEASE, MIGRATION, V14 ACTIVATION OR DATABASE CLEANUP.**

**READY to proceed to an isolated Hostinger staging rehearsal of the 11 → 15 migration**, once three gates are cleared:

1. Approval of R1.
2. Independent review of the 7R diff plus this pass's changes.
3. Authorization to commit the reviewed work to a branch that does **not** auto-deploy.

**The staging cleanup rehearsal (confirmed D12 scope) is ready to schedule once:**

- the new deletion-verification issuer and cron step pass independent review;
- the Admin identities are revalidated read-only on the staging copy.

**The production cleanup additionally needs:**

- RPMS's audit-retention decision for the Admin-action rows the purge removes (recommended: an off-line retention extract, [11](11_CLEAN_PRODUCTION_DATABASE_PLAN.md) §4);
- a separate authorization for the cleanup, and another for the 28-report deletion.

The pre-staging summary and the precise operator actions are in [12](12_PRE_STAGING_READINESS_REPORT.md).

It already passes locally on an isolated restore of the real export: 27 Students and 4 Advisers removed through the lifecycle, and the three Administrators verified unchanged ([11](11_CLEAN_PRODUCTION_DATABASE_PLAN.md)).

Production is unchanged: schema 11, currently deployed code, nothing touched by this pass.

## Basis

| Area | Result |
| --- | --- |
| 7R work re-checked | Spec, inventory, changed files, DB compatibility, workflow, security, runbook, cleanup manifest, matrix and readiness reports read in full; the original 3-page PDF read; claims re-checked against source. One claim was inaccurate (Research Title protection on every edit path); it is now fixed (B7C-01). |
| F01: non-PI member Final Report over HTTP | **VERIFIED**: 81 module assertions covering all 10 brief items, including prerequisite refusals, security guards, duplicate safety, isolation and synchronized completion ([03](03_NON_PI_FINAL_REPORT_HTTP_TEST.md)) |
| F02: Batch 6 intermittency | Root cause **unresolved**; not reproduced in 210 isolated attempts or 12 full Batch 6 executions (12/12 PASS); diagnostics added; a separate reused-test-state defect fixed (B7C-03) |
| F03 / F04 / F05 | Inventories complete ([05](05_FORMS_AND_SORTING_INVENTORY.md)); accessibility fix B7C-02; every Student ID write path enforces digits only |
| F06: three-role account review | All automated checks PASS; asterisk fix B7C-05; three observations for D10 |
| F07: AI accuracy | **NOT VERIFIED** (no approved sample, no live key); protocol ready |
| F08: versioning scope | Mapped; recommendation for D8 |
| Final regression (12.3.2 / general_ci) | **Every suite PASS on the final code:**<br>• 429 / 99 / 860 / 144 / 245 / 130 / 96 / 94 / 310 / 131;<br>• teardown with 0 legacy rows changed.<br>Matches or exceeds 7R's counts; each increase is a new check ([08](08_ADDITIONAL_FIXES_AND_TEST_RESULTS.md) §3). The 10.4 / unicode_ci fixture was not re-run (N2). |
| Clean-database decision (revised: preserve all Admins) | Plan, preview/verifier, deletion-verification issuer and cron step. Synthetic rehearsal PASS (68). **Isolated restore of the real export PASS (21): 27 Students and 4 Advisers removed; Admins 1/13/46 unchanged.** No blocking dependency; 4 owner acknowledgements (O2–O5). |
| Panel requirements | 10 PASS · 6 PASS PENDING ACTIVATION · 5 PARTIAL · 1 NOT VERIFIED · 0 FAIL; **0 of 22 institutionally accepted** ([01](01_FINAL_REQUIREMENTS_MATRIX.md)) |

## Is further coding necessary before staging?

- **Before the staging migration rehearsal: no.** The fixes in this pass are complete and covered by the final chain. Committing them is subject to review.
- **Before the staging cleanup rehearsal: no new code.** The deletion-verification issuer and its cron step were written and tested on isolated databases in this pass; they need independent review before use. The earlier proposal to change deadline deletion is withdrawn, because no Administrator account is removed.
- **Optional, by decision:** before/after audits for more Student fields (D8); a date sort for Adviser records (D3).

## Every remaining condition before a production release

1. R1 approved (blocker 1).
2. Independent code review of 7R + closure changes (blocker 2).
3. Commit to a non-deploying branch authorized (blocker 3).
4. Production `staff_code_singleton` CHECK confirmed read-only (blocker 4).
5. Faithful hPanel backup + storage backup, restore verified (blocker 5).
6. Isolated staging created and integrations neutralised (blocker 7).
7. No-SSH route proven on Hostinger (blocker 6).
8. Staging migration 11 → 15 on populated data with contract PASS and identical legacy counts (blocker 8).
9. All 12 staging acceptance items PASS with `PRISM_IERB_V14_OPERATIONS` only on staging (blocker 9), after D1.
10. D1 decided; D3, D4, D5, D8, D10 decided for compliance sign-off; D2, D6, D7 recorded.
11. Live AI accuracy sample signed off (D9).
12. Clean-database work:
    - Admin IDs 1, 13, 46 revalidated on production;
    - O2–O5 recorded; issuer tool and cron step reviewed;
    - staging cleanup rehearsal with `verify-final` PASS and post-cleanup V14 validation;
    - signed preview of a fresh production backup;
    - **separate written cleanup authorization**.
13. Optional but recommended: re-run the chain on a MariaDB 10.4 / unicode_ci fixture (N2); screen-reader check (N3).
14. A separate, approved production release plan (maintenance window, fresh backup, deploy, preflight at 11, migration steps, preflight at 15, activation per D1). **Not authorized by this report.**

**Full compliance is not declared**, and readiness is not inferred from local tests alone.
