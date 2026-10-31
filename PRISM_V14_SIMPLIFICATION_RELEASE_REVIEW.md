# PRISM V14 Workflow Simplification — Development Review

**Base (deployed review):** `411739d39fe72e67bd6e44bf9d6aff577e3e4ccb`
**Branch:** `feature/prism-v14-workflow-simplification-20261031`
**Status:** IMPLEMENTED ON DEVELOPMENT BRANCH; NOT PRODUCTION-ACCEPTED OR DEPLOYED.

This work implements the user-approved six-phase V14 simplification. It intentionally avoids production database updates, destructive data cleanup, changes to schema version, and any merge into the deployed review branch.

## 1. Onboarding and authorization

- Administrator assignment remains authoritative: no second assignment approval is introduced.
- The assigned Adviser's incomplete password or legal acknowledgement no longer delays activation of a Student who completed the Student's own required setup.
- Group advancement is not conditioned on members' password/legal status or an Adviser-notification preflight.
- Actual login and resource-access checks remain intact. Inconsistent academic profile, missing current membership, duplicate historical evidence, inactive Adviser or identity conflicts still require appropriate administrative reconciliation.
- Already pending eligible assignments are resumed through the existing account-setup/login resume path. **Verify this live behavior in isolated testing**, rather than editing state flags by hand.

## 2. Authoritative V14 project workflow

- The project monitor is the active view when V14 operations are enabled: one canonical project per row, not a Student row per member.
- Historical Stage 1–5 records are accessible under a read-only section. The legacy IERB mutation endpoints reject new changes while V14 is activated.
- Stage advancement still requires RPMS confirmation after all 13 initial requirements are accepted; Final Report formal submission completes IERB automatically.
- No automatic mapping of unknown historical stages to V14 and no bulk deletion of legacy records have been attempted.

## 3. Document types and Student checklist

- The backend and Student upload selector derive allowable categories from the active V14 workflow and correction case.
- Initial Submission: 13 required document categories; Resubmission/Amendment: affected types and a required Amendment Form only; Approval of Data Gathering: Form 33, then Final Report after Form 33 acceptance; Completed: none.
- Students see the project-shared current-stage checklist directly beside uploading, with item status and an Upload shortcut where eligible.
- Existing current versions must be revised via the explicit Revision workflow rather than silently replaced by a new upload. Historical versions remain intact.
- The old Stage N value in `documents.stage` is a **private storage/version bucket**, not an independent active workflow. It cannot safely be removed without a separately verified data migration because that column is `varchar(20)` and V14 stage keys can exceed 20 characters.

## 4. Adviser and RPMS review

- Existing Adviser rejection and resubmission requests remain immediate document-level actions. A formal RPMS correction case is only used for official stage changes.
- Existing Adviser approval automatically forwards approved documents to RPMS.
- A new bulk RPMS requirement-acceptance endpoint accepts 1–13 selected eligible documents inside one transaction with individual evidence/audit events and an idempotent batch receipt. Form 33 institutional approval is still handled separately.
- RPMS official advancement and amendment decisions remain safeguarded.

## 5. V14-first UI and reporting

- Dashboard shows distinct V14 project counts and completion. Past-dated targeted deadlines are a review signal, never proof of noncompliance.
- V14 monitoring is project-based; old individual stage-edit controls are read-only.
- Project and aggregate reports take priority. AI report generation remains available under V14 with one canonical research project per record; only aggregate stage counts are sent to the provider. Historical Student reports remain in legacy mode. Report history remains available.
- Student and document exports use canonical research-title/Adviser/stage/protocol projections where available, while retaining the original document storage bucket as historical metadata.
- The four V14 stage names and sequence are fixed. RPMS guidance is editable using short isolated keys in the existing `stage_labels` table; no new schema migration is required.

## 6. Legacy retirement without evidence loss

- Old stage mutation and report-generation routes are retired from the active V14 experience.
- Historical rows, document versions, audit events, stored report snapshots, and original stage columns are deliberately **preserved**. The user approved simplifying workflows, not destroying required institutional evidence.
- Before deleting old code or migrating archival data, prove that every active student group uses one canonical V14 project and that all exports, history pages, notices, and audit trails remain readable. Do **not** run DELETE/DROP/TRUNCATE statements to retire the old workflow.

## Required release gates (still pending)

1. Run `node maintenance/onboarding-tests/v14-simplification-smoke.cjs` from the branch root with Node and PHP installed. This checks PHP syntax, JS syntax and key source contracts. A missing PHP executable fails the gate.
2. On an **isolated MariaDB schema-15 database with disposable accounts**, verify: account setup/auto activation, new/join group, two-member shared document evidence, all 13 initial types, disallowed type server rejection, revision-only type selection, correct conditional Amendment, Form 33 and Final Report, Adviser review, RPMS bulk acceptance rollback and idempotency, and project advancement.
3. Browser test Student/Adviser/Admin navigation, dark mode, mobile checklists, keyboard operation, reports, CSV exports, fixed V14 guidance editing and disabled legacy mutations.
4. Confirm no active production integrations depend on the retired legacy report endpoints. Compare historical rows, document versions, audit counts and reconciliation before/after using read-only checks.
5. Peer review any security/permission changes, take a **new** production backup, perform a controlled release and conduct live non-destructive acceptance checks.

**Release decision:** No production deployment or database cleanup until the tests above pass. The prior deployed review branch and production schema remain untouched by this development work.
