PRISM UI and account lifecycle implementation review

Branch: `prism-v2-final-v8`  
HEAD: `d4b9ac6fb3d1ef2f8d275ece9ea08fda84b5214c`  
No commit, push, merge, tag, deployment, staging, application migration, or schema-version change was performed.

**Files modified by this task (16)**

- `.gitignore`
- `account.php`
- `admin_people.php`
- `assets/css/admin-management.css`
- `assets/css/ierbprog.css`
- `assets/css/prism-workspace.css`
- `assets/css/research-resources.css`
- `assets/css/student-dashboard.css`
- `assets/js/account.js`
- `assets/js/admin-management.js`
- `assets/js/role-portal.js`
- `includes/ceu_footer.php`
- `includes/prism-navigation.php`
- `includes/research_resources.php`
- `role_portal.php`
- `tests/ui-audit.cjs`

**Files added to the reviewable change set (7)**

- `account_lifecycle_api.php`
- `includes/account_lifecycle.php`
- `includes/account_lifecycle_schema.json`
- `tests/account-lifecycle-runner.php`
- `tests/account-lifecycle-mysql.php`
- `tests/account-lifecycle-worker.php`
- `PRISM_UI_ACCOUNT_LIFECYCLE_REVIEW.md`

Files deleted: none. New test artifacts are under `tests/lifecycle-results/` and remain ignored. The pre-existing ignored MariaDB migration harness was restored after the dedicated lifecycle runner was created.

**Git diff --stat**

The following is the literal tracked-file diff. It includes the protected manual SQL file's pre-existing changes; untracked new files are listed above and are not represented by this command.

```text
 .gitignore                        |  3 ++
 account.php                       | 18 ++++++++++
 admin_people.php                  | 14 ++++++++
 assets/css/admin-management.css   | 13 +++++++
 assets/css/ierbprog.css           |  2 +-
 assets/css/prism-workspace.css    | 17 +++++++++
 assets/css/research-resources.css |  5 +++
 assets/css/student-dashboard.css  |  8 +++++
 assets/js/account.js              | 18 ++++++++++
 assets/js/admin-management.js     | 53 +++++++++++++++++++++++----
 assets/js/role-portal.js          | 12 ++++---
 includes/ceu_footer.php           |  2 +-
 includes/prism-navigation.php     |  4 +--
 includes/research_resources.php   |  6 +++-
 role_portal.php                   | 10 +++---
 tests/ui-audit.cjs                | 76 +++++++++++++++++++++++++++++++++++++--
 tools/schema-v8-manual.sql        |  4 +--
 17 files changed, 239 insertions(+), 26 deletions(-)

```

**Items 1-7**

| Item | Result |
|---|---|
| 1 | Actual `.mini-day.active-day` elements receive a 26x26px highlight, automatic margins, grid centering and the existing purple theme color. Current-date logic and event indicators remain. |
| 2 | Documents/calendar actions use accessible icons. Counts, review states, real remarks, filenames, errors and actual reminders remain. Redundant empty-state helper lines are hidden. |
| 3 | Shared footer heading is Contact Us; contact details, branding, social links and existing external-link attributes remain. |
| 4 | Student Calendar keeps its main title; the Research Calendar heading, inner paragraph and dynamic subtitle are removed. Today, navigation, dates, deadlines and reminders remain. |
| 5 | All roles show the exact full PRISM phrase. Captions wrap at word boundaries within bounded shells; mobile branding occupies a separate row. Collapsed Admin branding retains full alt/title text. |
| 6 | Chart top padding increases from 14px to 30px. Counts retain 12.5px typography and clear the scroll container. |
| 7 | Research Resources has a CEU IERB Portal resource pointing exactly to https://ceu-ierb.wixsite.com/ierb with target=_blank and rel=noopener noreferrer. SDG/Agenda resources remain. No portal fetch or embedding was added. |

**Database evidence and runtime gate**

A private MariaDB 10.4.32 instance was initialized with isolated data. Its resolved datadir was verified before access. Read-only INFORMATION_SCHEMA inspection verified 15 InnoDB tables, 150 columns, 37 index components, 10 foreign keys, and no triggers. Schema version is 8. Evidence: `tests/lifecycle-results/metadata.json`.

`includes/account_lifecycle_schema.json` contains the reviewed snapshot. Every hard-delete request compares actual tables, engines, columns, indexes, foreign keys, triggers and schema version to that snapshot. Unexpected or missing structures, including incoming foreign keys from another database, return an explicit 409. Metadata-access failure prevents deletion. Self-archive separately verifies that account, token and audit tables use InnoDB.

The installed application's original configured database login remains unavailable. No destructive test or application migration was run against that database. The disposable metadata inspection satisfies the approved prerequisite; runtime verification must also succeed on the actual connection before any hard delete can proceed.

**Lifecycle authorization**

| Actor | Archive Student | Delete Student | Archive Adviser | Delete Adviser | Archive Admin | Delete Admin |
|---|---|---|---|---|---|---|
| Student | No | No | No | No | No | No |
| Adviser | No | No | No | No | No | No |
| Admin | Yes | Eligible archived records only | Yes | Eligible previously archived records only | Self only | Self only |

The account lifecycle endpoint requires an authenticated Admin and a same-origin POST. It checks actor/target equality for Admin actions on the server. Archive Student retains the existing helper. Archive Adviser retains the existing deactivation operation, with archive wording and an archive icon in the UI. Existing legacy `action=delete` URLs still mean archive/deactivate, never permanent deletion.

**Permanent-delete rules**

Student deletion requires archived_at, a confirmed inactive login identity (or an unambiguously absent login), the Admin's current password, exact Student ID and explicit test/demo and destructive acknowledgements. It rejects protected progress, protocol/PI state, requirements/submission dates, documents of any version, unlinked legacy documents, frozen deadline recipients, notifications, substantive IERB history, protected audits, changed/ambiguous identities and unexpected dependencies. At most one recognized automatic creation-history row may be removed: Stage 1 / On Track, exact creation note, no requirements/submission date, a nonempty actor and matching creation timestamp.

Report snapshots have no membership table and legacy AI references are free-form. Any existing reports or ai_outputs therefore block Student and Adviser hard deletion. Unresolvable legacy Student-save identities and notifications also block Student deletion. This is intentionally restrictive; use Archive for collected institutional data.

Adviser deletion requires Inactive status plus recorded prior archive/deactivation evidence, current Admin password, exact Employee ID and test/demo/destructive acknowledgement. Current or archived Student assignments, document/IERB/notification/deadline attribution, identity changes, report/AI uncertainty and ambiguous login ownership return 409.

Admin self-archive requires current password and explicit confirmation. Admin self-delete requires current password and exact DELETE confirmation. An Admin account needed by an official deadline, or unexpectedly linked to another account record, cannot be deleted. Neither feature accepts another Admin as target.

**Transactions, locking and last-Admin protection**

Lifecycle writes lock Admin rows in ID order, revalidate the acting Admin and its current password, then lock target records, identities and relevant dependencies. This serializes competing lifecycle operations. Both self-archive and self-delete check that another active Admin remains under the same locks, before the action and again before commit. Concurrent attempts by the final two Admins produce one success and one conflict, leaving one active Admin.

Mandatory audit insertion, deterministic cleanup and account changes occur in one transaction. Any persistence/dependency failure rolls back all prior changes. Existing document/deadline/research workflows were not changed.

**Cleanup and historical attribution**

| Operation | Cleanup | Preserved |
|---|---|---|
| Student archive | Set archived_at; login Inactive | Student, documents/files, IERB, audit, notification/deadline history |
| Adviser archive | Adviser/login Inactive | Adviser, Student assignments and history |
| Eligible Student delete | Student; confirmed login; reset/setup rows; permitted creation-only IERB row | Existing audit evidence; protected dependencies prevent deletion |
| Eligible Adviser delete | Unused Adviser; confirmed login; reset/setup rows | Existing audit evidence; protected dependencies prevent deletion |
| Admin self-archive | Login Inactive; all outstanding tokens used | Authored deadlines/reports and every institutional record |
| Admin self-delete | Own login and reset/setup rows | Audit, report author ID and name, document/IERB/notification author snapshots |

No institutional records are nulled to make deletion succeed. In particular, report generated_by_user_id and generated_by remain unchanged after Admin deletion; the audit preserves the deleted account's identity. Authored deadlines block deletion instead of losing creator attribution/visibility.

**Filesystem behavior**

No uploaded file or report file is physically deleted by account lifecycle. The policy rejects records with documents before cleanup. No request-supplied filesystem path is accepted. Traversal-like stored names and shared historical-version files were tested and remained unchanged. Temporary test-server/browser directories are independently scoped and cleaned up.

**Audit and session behavior**

A mandatory INSERT on the same transaction/connection records actor ID, safe account identifier, role, action, entity ID and database timestamp. It does not use best-effort audit_log(). Passwords, password hashes, reset/setup tokens and private file contents are never included. The deletion event has no cascading account dependency and survives deletion. Audit persistence failure aborts deletion.

Successful Admin self-archive/delete clears session data, expires its cookie, destroys the session and redirects to Login. All reset/setup tokens become unusable. Existing per-request account/status/fingerprint checks reject other sessions after the account becomes inactive or is removed.

**Regression results**

| Suite | Result |
|---|---|
| Existing CLI regression | 27/27 suites; 5,105 assertions/checks passed |
| Actual MariaDB lifecycle integration | 318 assertions passed, including metadata, roles, tokens, sessions, rollback, files and concurrency |
| Existing disposable v8 integration | 221 assertions passed |
| HTTPS password reset/setup browser audit | 34 checks; zero failures |
| Focused UI at seven widths and both themes | 890 checks; zero failures |
| Every role page shell and typography | 2,640 checks; zero failures |
| Full isolated UI audit | 6,981 checks; zero failures |
| PHP syntax | 11 files passed |
| JavaScript syntax | 4 files passed |
| Git diff --check | Passed |

Total recorded assertions/browser checks: **16,189**, with zero failures in the final runs. Syntax checks are counted separately. These counts include overlapping regression coverage across suites.

Responsive matrix: 1920x1080, 1366x768, 1024x768, 768x1024, 390x844, 375x812, 320x568. Admin, Adviser and Student shells were verified in both themes. Checks cover header clearance, normal word wrapping, collapsed/open navigation, page-level overflow, a centered 26x26px date highlight, preserved current-day event indicators, icon labels/titles, populated review/reminder data, Calendar cleanup, footer text and exact IERB link attributes.

Chart fixtures rotate 0, 1, 5, 10, 15 and 16 through every stage at every requested width/theme. Counts retain 12.5px typography and remain within the scroll container. Screenshots are under tests/lifecycle-results/.

Lifecycle tests prove archive preservation; archived-first deletion; Student/Adviser denial; Admin self-only enforcement; current-password/typed-confirmation/test-record requirements; protected-history conflicts; token invalidation; durable audit survival; rollback on audit and dependency failure; no file removal; unexpected local/cross-database reference refusal; concurrent history-writer protection; and concurrent last-Admin protection. No destructive test touched installed application data.

Evidence: tests/lifecycle-results/final-results.json, integration-results.json, metadata.json, syntax-results.json, ui-results.json, lifecycle-integration.log, ui-focused.log, ui-full.log, and ui-shells.log. The broader shell measurements are in tests/release-candidate-results/typography-ui.json; existing CLI logs are in tests/final-regression-results/.

Reproduction commands:

```text
python tests/run-final-regression.py
C:\xampp\php\php.exe tests/account-lifecycle-runner.php --lifecycle
C:\xampp\php\php.exe tests/password-reset-migration-mysql.php --isolated-v8
node tests/password-reset-browser.cjs
node tests/ui-audit.cjs --lifecycle-ui-only
node tests/ui-audit.cjs --release-typography-only
node tests/ui-audit.cjs
git diff --check
```

The browser runs needed execution outside the sandbox because sandboxed Chrome/Edge stalled at Page.enable. The final runs used temporary profiles and mocked services and passed.

**Protected local files**

All three are unchanged by this task, unstaged and uncommitted. Final SHA-256 checks:

- `tools/schema-v8-manual.sql`: `DF99E2A0C8F99E2FBE74EAC08BF6AF5D50FBA270BE738F4D63077E1902AFD403`
- `PRISM_V8_BEFORE_RECONCILE.patch`: `B139C3C891D9B0BD5BA51731691FAC5D81045364F15014BCA00B258D903AD126`
- `PRISM_V8_BEFORE_RECONCILE_STATUS.txt`: `749E1542AE844A9C8CD887E3DBBDA4B6879617223BED1DC3FDDBB40F702BD399`

**Remaining limits**

- Automatic schema matching is deliberately strict, including index/column metadata. A structurally different installation refuses hard deletion until its dependencies are reviewed; no migration was added.
- Student/Adviser deletion may be unavailable across an installation containing historical reports or unresolved legacy references. Archive remains the recommended operation.
- Browser tests use real rendered templates with mocked APIs, synthetic data and blocked external fonts/icons. They do not establish live Gmail/OpenRouter delivery or installed-database operability.
- No production/deployment validation was performed. The working tree is left for review.
