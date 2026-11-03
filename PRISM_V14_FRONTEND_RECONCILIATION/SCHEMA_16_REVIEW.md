# Settings/schema-16 integration review

Status: design review; application and database changes not yet applied.

The ZIP proposes `academic_year_settings` as schema 16 and a separate three-table Settings extension (`prism_settings`, `prism_deadline_reminder_dispatches`, `academic_year_removals`). V14 independently retains the `research_onboarding_v1` extension on schema 15. A direct ZIP import would lose onboarding support and make schema verification fail when both extensions coexist.

Required nonvisual remediation before activation:

- Compose exact schema-11-through-16 contracts with the independently pinned onboarding extension. Every admitted extra table must be verified against its own contract, including foreign keys, indexes, checks, types, engine and collation. Do not weaken base comparisons to a list of column names.
- Bind the Settings extension to an explicit `schema_meta` marker and its own pinned DDL contract. Validate partial DDL during restart. Keep CLI-only opt-in, exact database binding, the shared migration mutex, foreign-key enforcement and transaction preconditions.
- Keep existing schema-11-through-15 paths operational. Add schema 16 only to services whose required underlying tables have been verified. Preserve all V14 resubmission, evidence, membership, protocol and retention guards.
- Maintain migration order 11 → 12 → 13 → 14 → 15 → 16; onboarding remains a separately opted-in extension. Rehearse both coexistence orders on synthetic fixtures. Block lower-version migration until later extensions are safely removed.
- Retain Student academic-year assignments and historical catalog rows. Registration availability, current-year choice and retirement change selection policy only. Enforce live Admin eligibility and audited, serialized writes.
- Keep notification-template `{message}` content mandatory. Preserve sanitized, system-generated workflow details and never template credentials or provider configuration.
- Move `notification_enqueue_deadline_reminders` out of `notification_worker_run`. In the ZIP it is declared inside the worker *after* its first call: first execution can report an undefined function, and repeated executions in one PHP process can redeclare it. Test repeated worker execution, dispatch uniqueness, cancellation, recipient eligibility and transaction rollback.
- Require retained-settings/reminder/removal history to block rollback. Disabling features while retaining their data is the post-activation recovery path. Invalidate deployment verification after contract changes.

Production migration, production access and delivery-provider use remain unauthorized. No production migration readiness claim can be made before the disposable tests and final audit.
