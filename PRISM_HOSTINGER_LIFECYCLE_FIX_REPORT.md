Implemented locally against `prism-v2-final-v8` / `390bedb06ffe31ac2a5a8c8aa57d9ded4d5d47cf`. No commit, push, merge, tag, deployment, production DB connection, or installed configuration change was performed.

1. The original verifier requires direct global SELECT and TRIGGER grants. Their purpose is complete metadata visibility, including incoming foreign keys from other databases and PRISM triggers. Hostinger's schema-scoped account cannot satisfy that global grant check. The existing global CLI mode remains available.

2. The trash icon was faint because the shared row-action CSS forced a pale background while the lifecycle danger style supplied white text. Active rows omitted the delete button intentionally to enforce Archive first; there was no frontend dependency/provenance eligibility predicate hiding archived accounts. Actions now use the same 40px square dimensions, 6px spacing, centered icons, borders/radii, accessible names/titles, hover styles, and visible keyboard focus. Disabled delete controls remain focusable with `aria-disabled` so their explanation is discoverable; guarded handlers prevent opening the flow.

3. Student controls now follow these states. Advisers viewing Students retain only their authorized Edit action.

| Student state | Edit | Archive | Permanent Delete |
| --- | --- | --- | --- |
| Active normal or unused test Student | Visible | Available | Visible, disabled; explains Archive first |
| Archived Student with valid deployment evidence | Visible | Disabled; already archived | Available to open confirmation |
| Archived Student with protected history and valid evidence | Visible | Disabled | Confirmation opens; backend refuses deletion with 409 |
| Archived Student with missing/invalid/expired schema evidence | Visible | Disabled | Visible, disabled; explains verification failure |

4. Files changed for this task:

| Files | Purpose |
| --- | --- |
| `includes/account_lifecycle_schema.php` | Explicit mode evidence, scoped grants/schema/FK/trigger inventory, server/manifest binding, expiry, runtime validation, read-only availability |
| `tools/verify-account-lifecycle-schema.php` | Explicit scoped CLI flags, read-only generation, limited-assurance output |
| `includes/account_lifecycle.php` | Roll back unexpected FK RESTRICT refusal and return lifecycle conflict / 409 |
| `account_lifecycle_api.php` | Authenticated Admin-only GET availability; existing POST same-origin protections remain |
| `admin_people.php`, `assets/js/admin-management.js` | Student/Adviser action states, availability reason, accessible confirmation fields |
| `account.php`, `assets/js/account.js` | Reuse lifecycle form/toggle layout and schema availability for Admin self-delete |
| `assets/css/admin-management.css` | Action contrast/alignment, reusable form groups, bounded modal, password eye/focus styles |
| `assets/js/lifecycle-forms.js` | Shared lifecycle initialization/reset around existing Login/Register toggle function |
| `tests/account-lifecycle-runner.php`, `tests/account-lifecycle-mysql.php`, `tests/account-lifecycle-worker.php` | Integrate scoped suite and isolated worker scenarios |
| `tests/account-lifecycle-shared-hosting.php` | Actual restricted-user positive/negative schema and dependency regressions |
| `tests/account-lifecycle-http.php` | Actual scoped HTTP Student creation, archive, delete, audit and availability regression |
| `tests/ui-audit.cjs` | Responsive lifecycle fixtures/checks; align existing navigation assertion with current accessible logo layout |
| `.gitignore` | Make the new regression test reviewable; generated results remain ignored |
| `ACCOUNT_LIFECYCLE_OPERATOR_VERIFICATION.md`, this report | No-SSH operator workflow, assurance limits, change/validation record |

Existing local changes to `assets/css/prism-workspace.css`, `includes/prism-navigation.php`, `role_portal.php`, `tools/schema-v8-manual.sql`, and the pre-existing untracked reports/patches were preserved. `includes/account_lifecycle_schema.json` and `config.local.php` were not changed. Nothing was staged.

5. The reusable `.lifecycle-form` and `.lifecycle-form-group` rules provide stacked associated labels, 8px label/control gaps, 18px form gaps, equal full-width inputs and aligned 18px checkboxes. The Student/Adviser modal is bounded to 760px on desktop, fluid on smaller screens, and vertically scrollable within the viewport. Destructive submit buttons remain red. Long explanatory text and identities wrap. Admin lifecycle forms use the same spacing.

6. Password visibility reuses the existing `togglePassword()` from `assets/js/script.js` and its Font Awesome eye/eye-slash pattern. Lifecycle eye buttons are `type="button"`, keyboard accessible, and update Show/Hide password plus `aria-pressed`. Reset/cancellation/rejection restores the hidden state. Toggling preserves the password value; no password persistence or logging was added. Admin archive/self-delete and Adviser delete share this behavior. Admin lifecycle remains self-only.

7. Hostinger-compatible verification uses:

```text
php tools/verify-account-lifecycle-schema.php --verify-shared-hosting --schema-changes-excluded --external-dependencies-excluded
```

This does not silently replace global verification. It requires direct schema-wide SELECT and TRIGGER for every visible application database, separately reads visible FK components/rules, rejects visible cross-schema PRISM references and unexpected triggers, and compares the unchanged manifest. Generated evidence explicitly records `schema_scoped_shared_hosting` and `complete_visibility=false`. Both modes bind the manifest, database/server identity/version, mode and DDL exclusion, and expire after 24 hours. Scoped mode additionally binds visible metadata and the external-dependency exclusion policy. Runtime repeats those checks. A boolean alone, altered mode, stale evidence, disabled FK enforcement or mismatch fails closed.

Hidden cross-schema dependencies cannot be disproved by scoped metadata. This mode requires the operator/provider to establish that hidden references/definer writes are excluded while deletion is enabled. FK enforcement blocks hidden RESTRICT/NO ACTION and rolls back the transaction, but cannot stop hidden CASCADE/SET NULL effects if that assurance is false. If the exclusion cannot be established, Archive remains the safe result. Actual Hostinger production verification was not performed. The exact local-XAMPP-to-Remote-MySQL and File-Manager configuration procedure is in [the operator guide](ACCOUNT_LIFECYCLE_OPERATOR_VERIFICATION.md).

8. Final validation passed:

- `C:\xampp\php\php.exe tests/account-lifecycle-runner.php --lifecycle`: 1,288 assertions, including 460 existing remediation assertions and 510 scoped-verification assertions.
- `node tests/ui-audit.cjs --lifecycle-ui-only --visual-only`: 1,176 checks, zero failures. This includes the focused Student/Adviser/Admin lifecycle checks and the existing broader lifecycle UI checks.
- PHP syntax checks on all 11 changed/new PHP files: passed.
- JS syntax checks on the three lifecycle scripts, the reused authentication toggle script, and browser harness: passed.
- `git diff --check`: passed. HEAD remains the audited baseline and the staging area is empty.

Backend coverage includes real schema-scoped grants, the actual HTTP creation/archive/delete workflow, protected dependencies, wrong/missing/stale evidence, schema version/column/default/nullability/index/FK/trigger deviations, visible external FKs, hidden RESTRICT rollback, password/ID/acknowledgement requirements, Admin-only/self-only/last-Admin rules, same-origin protection, audit failures and concurrent writers under READ COMMITTED and REPEATABLE READ.

Responsive coverage uses 1920x1080, 1366x768, 1024x768, 768x1024, 390x844 and 375x812 in both light/dark themes, with normal/test/archived/protected Students, a 180-character name and a 100-character ID. Checks cover action geometry/contrast/hover/focus, disabled explanations, modal bounds/scrolling, field/checkbox spacing, password toggle/cancel/reset, exact payloads, destructive confirmations and backend-409 presentation. The actual font/icon assets were loaded for visual verification. Desktop and mobile screenshots were inspected.

Generated evidence: `tests/lifecycle-results/integration-results.json`, `student-delete-ui-results.json`, and `student-delete-{width}x{height}.png`. These local test artifacts are ignored by Git and contain only synthetic fixtures.
