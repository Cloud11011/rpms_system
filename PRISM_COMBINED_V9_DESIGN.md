# Final combined v9 design

Read-only source review completed before implementation, 2026-10-09.

Starting branch: prism-v2-final-v8. HEAD: be75eaf6d4c46bdeca7e1a6f889d2ccfa18dd0dc.
Source schema: 9. Existing implementation/remediation reports state that installed development and production remain unmigrated; only disposable test instances used intermediate v9. No contrary evidence found. This is documentary confirmation, not production database inspection.

## Creation inventory and constraints

- students_api.php save: complete-record creation by Admin/Adviser; Adviser assignment derives from authenticated email. Retain complete-record creation, strengthen collision and pending-edit checks.
- ierb_api.php save: complete-record Student creation by Admin with existing standardized group requirement; retain with shared collision checks.
- advisers_api.php save: complete-record creation by Admin only. Retain it.
- register_process.php: registration-code-gated Admin registration only. No public Student/Adviser registration. Retain it, include profile email claims in collision checks.
- config.php seed: explicitly configured bootstrap Admin only. Retain it.
- config.php send_account_setup_email: existing complete-account setup via password_resets. Retain compatibility; invitations use separate purpose-bound credentials.
- migration and test seeds: no invitation email during migration; synthetic fixtures must explicitly represent completion.

users: unique NOT NULL username varchar(100), unique NOT NULL email varchar(190), NOT NULL full_name varchar(190), NOT NULL password_hash varchar(255), role enum, nullable ref_id. students: unique NOT NULL student_id varchar(100), unique NOT NULL email, NOT NULL full_name, nullable academic/research/group/assignment fields. advisers: same constraints with employee_id, nullable department and groups. Profile user_id FKs are nullable SET NULL; legacy ownership also uses email/ID/provenance. Preserve all unique indexes.

## Selected hybrid

Option A alone lacks token purpose separation. Option B alone preserves strict identity columns but requires a parallel lifecycle/bulk/report/authorization implementation for invitations. Hybrid is smallest compatible approach: persist users and role profile shells with explicit FK linkage, nullable username/name/role-specific ID, NULL profile_completed_at, and one account_invitations row per user. Never create fake IDs or names. Password hash remains NOT NULL, initially an unreachable random credential hash, never exposed or emailed.

Admin -> Student: normalized allowed email, optional complete active Adviser, transaction creates shell and invitation, commit, attempt sensitive setup email. Adviser -> Student: same service, lock/revalidate complete active Adviser and derive assignment; reject browser relationship/privilege fields. Admin -> Adviser: same service, no assignment. Tokens bind the immutable user/role/email/profile through one authoritative user_id. Secure setup -> login -> Complete Profile -> role dashboard. Central HTML/API guard allows only onboarding, password/security and logout before completion.

Students: full name, Student ID, academicUnitKey, programKey, yearLevel, academicYear, optional research title. Existing academic_catalog supplies all controlled values; graduate unit/year remain absent according to catalog. Title remains optional because existing upload/workflow accepts absent title. No fictitious research group: NULL until staff assignment. Advisers: full name, Employee ID, catalog department label. Staff controls email, role, assignments/groups, stage/status, protocol/IERB/PI/requirements, privileges and lifecycle.

IDs: trim, reject control characters, require nonempty <=100 characters (existing PRISM does not define a narrower institutional format); unique profile ID and users username/ref checks across all active/archived identities; database uniqueness arbitrates races. Completion is atomic and cannot be repeated to change an ID. Existing Admin edit retains correction authority. Student/Adviser profile_api never accepts ID changes. Email cannot change during setup/completion.

Duplicate email policy: query users and both profiles, including orphaned, pending, active and archived rows; reject and advise record review/resend/restore. No implicit activation or role/assignment transfer. users email uniqueness arbitrates concurrent creation. Complete-record paths must also refuse collisions.

Tokens: random_bytes(32), SHA-256 only at rest, invitation purpose distinct from password_resets, one-hour expiry, one-time consumption under profile -> user -> invitation locks. Resend uses same order, scope validation and 60-second persisted cooldown; rotate old hash; log no secret. Commit before sensitive email transport; failure retains Pending row and permits later resend. Pending restore uses fresh invitation credential if setup was never accepted, otherwise existing fresh password recovery; preserve completion state. Archive invalidates invitation and reset credentials. Purge explicitly deletes invitation/reset credentials before login deletion.

Pending purge confirmation: invited email from locked record; completed/legacy populated IDs continue exact-ID confirmation. Max confirmation length 190. Purge journal/audit for never-completed accounts keeps immutable role/profile reference, not target email/name. Bulk summaries display email; no bulk grace override. Adviser Archive includes pending assigned students in exact impact confirmation and sets adviser_id NULL. Completion locks current profile and never rewrites assignment, Hold or archive state.

## Backfill and schema

Narrow nullable changes: users.username/full_name; students.student_id/full_name; advisers.employee_id/full_name. Password hash/email remain strict. Add students/advisers.profile_completed_at DATETIME NULL; add profile-completion indexes. Add account_invitations: user_id primary/FK CASCADE users; invited_by_user_id nullable/FK SET NULL users; token_hash char(64) nullable unique; expires_at DATETIME NULL; accepted_at DATETIME NULL; last_sent_at DATETIME NOT NULL; created_at DATETIME NOT NULL default CURRENT_TIMESTAMP. Inviter role and target role come from users, assignment from profile; no duplicate role/email storage. No new triggers.

Backfill: nonblank name/ID/email, exactly one coherent same-role users match by email, username/ref_id match institutional ID, explicit user_id absent or correct, no cross-role profile claim and no competing ownership. Mark profile_completed_at=profile.created_at (deterministic), retain historical academic values under existing legacy preservation rule. Leave malformed/orphaned/ambiguous records NULL for Admin repair; do not create invitations or send email. Installed-record counts cannot be claimed from source or synthetic data.

Migration order: explicit CLI/phpMyAdmin target+backup guard -> prism_migrate lock + FK enforcement -> existing retention columns/journal/indexes -> narrow identity nullability + completion columns/indexes -> invitation table and FK/index validation -> deterministic transactional backfill -> exact final manifest inventory verification with projected schema_version=9 -> final stamp. MariaDB implicit DDL commits require safe retries; version never advances on failure. Existing intermediate v9 is not a production upgrade path. Manual SQL remains tools/schema-v9-retention-manual.sql and receives same final definitions/checks/backfill. Protected v8 SQL untouched.

Final manifest includes new table/columns/nullability/indexes/FKs with schema_version 9. Generate reviewed inventory only from disposable MariaDB. Old evidence hash fails. Preserve global/scoped verifier, runtime inventory, external-assurance limitation, database/server binding, expiry, FK enforcement and fail-closed checks.

Operational metrics, AI/report generation, IERB selectors, workload, notifications and deadline audiences require profile_completed_at IS NOT NULL. Administrative people screens and record exports may include explicit Pending labels and blank genuine IDs. Record filters and bulk scopes support Pending/Complete. Pending state is orthogonal to archive/Hold state.

## Planned files

Modify config.php; students_api.php; advisers_api.php; register_process.php; profile_api.php only as needed for security allowance; includes/account_retention.php; includes/account_retention_bulk.php; includes/record_filters.php; workflow.php; includes/calendar_deadlines.php; includes/notification_delivery.php; reporting/dashboard/export call sites; includes/account_lifecycle_schema.json; tools/schema-v9-retention-manual.sql; admin_people.php; assets/js/admin-management.js; assets/js/admin-retention.js; ACCOUNT_LIFECYCLE_OPERATOR_VERIFICATION.md; affected synthetic fixture support.

Add shared includes/account_onboarding.php, account_invitation_api.php, account_setup.php, complete_profile.php, onboarding/invitation UI JS/CSS as needed, isolated onboarding migration/service/HTTP/concurrency/UI tests, and a final implementation report.

## Verification and risks

Use existing verified private MariaDB runner; never invoke db() with installed configuration. Test genuine v8 -> final v9, exact manual script, retry, incompatible DDL/FK/index/backfill/verification/final-stamp failure; role matrix, scoped invitations, duplicate/orphan/archived identities, token expiry/tamper/reuse/rotation, atomic ID first-set, controlled fields, central direct API gate, lifecycle/bulk/purge integration, race categories at both isolation levels. Rerun retention suites including recovery/confirmation/Hostinger/global verification, existing regressions, HTTP and six viewport/two theme UI checks, PHP/JS syntax and diff check. Preserve superseded legacy policy tests and disclose their refusal.

Residual risks: installed legacy data counts unknown; malformed identities require Admin review. Email provider unavailable means pending delivery failure, not rollback/recreation. Shared-hosting manual procedure needs routine privileges. DDL failure leaves additive changes but no version stamp. Real deployment/evidence installation remains separately authorized. Do not edit the capstone paper; later align requirements/use cases, account-management activity/sequence diagrams, ERD/data dictionary, security/role matrix, UI and testing sections.
