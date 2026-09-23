# PRISM — 12 Feature Requests: What Changed & How to Apply

Everything here was built and tested against a real, running MySQL 
database (not just written blind) — see "How I tested this" below.

## How to apply this

Every file in this folder (except this README and `all_changes.diff`)
replaces the file of the same name/path in your repo. Overwrite in
place. One file was **deleted**: `role_login_template.php` — it's no
longer used now that there's a single login page, so remove it from
your repo. `stage_labels_api.php` is a brand-new file.

If you'd rather review line-by-line first, `all_changes.diff` is a
standard unified diff you can apply with `git apply all_changes.diff`
from your repo root, or just read through it.

**After applying:** the next page load runs `migrate()` automatically
(same self-migrating pattern the app already used), which creates the
new `stage_labels` table and adds the new columns to `students` and
`documents`. No manual SQL needed.

## Feature-by-feature summary

| # | Request | What changed |
|---|---|---|
| 1 | Single login page | `login.php` + `login_process.php` rewritten. `login_admin.php`, `login_adviser.php`, `login_students.php` now just redirect to `login.php` (old links keep working). `role_login_template.php` deleted (no longer used). |
| 2 | Email domain restriction | New `ALLOWED_EMAIL_DOMAINS` constant in `config.php` (defaults to `ceu.edu.ph`, `mls.edu.ph` — **verify these are your real domains**). Enforced in `register_process.php`, `advisers_api.php`, `students_api.php`. |
| 3 | AI approval-date detection | `ai_helpers.php`: new `ai_detect_approval_date()`, tries AI first, falls back to regex date-matching. Runs automatically on document upload and is backfilled during summarization. New `documents.detected_approval_date` / `approval_date_source` columns. |
| 4 | Hierarchical user management | `students_api.php`: advisers can now create/edit only their own students (server-enforced, not just hidden in the UI). `admin_people.php` access gate updated so advisers can reach student management but not adviser management. |
| 5 | Show/hide password | Added to `change_password_required.php` and the student portal's own change-password form (`role_portal.php`), reusing the existing `togglePassword()` helper from `assets/js/script.js`. |
| 6 | Automatic stage progression | `documents_api.php`: approving a document tied to a student's *current* stage auto-advances them to the next stage, logs it to `ierb_history`, and mentions it in the student's notification email. |
| 7 | Dynamic stage labels | New `stage_labels` table (seeded with real names like "Protocol Submission", "Certificate of Approval") + new `stage_labels_api.php` + a small admin-only editor built into `admin_ai.php`. Wired into `students_api.php`, `ierb_api.php`, `ierbprog.php`, `admin_people.php`, and `role_portal.php` — "Stage 1" etc. still exists as the underlying value everywhere, only the *displayed text* changes. |
| 8 | Admin override of progress | Verified already worked (admin-only `save` actions in `students_api.php`/`ierb_api.php` were already unrestricted). No code change needed. |
| 9 | Email-only login | Folded into item 1 — `login_process.php` now matches by `email` only. Student ID / Employee ID remain as *record* fields, just not login credentials. |
| 10 | Principal Investigator indicator | New `students.is_principal_investigator` column. Shown as a small clickable badge on the student portal (`role_portal.php` + `role-portal.js`) that reveals the Protocol Code on click. Admin-only to set. |
| 11 | Prefer Protocol Code | New `students.protocol_code` column. When set, a prominent card is shown above the document list on the student portal — the raw file table is still there below it, just no longer the first thing shown. |
| 12 | Full admin access | Audited every `require_login()`/`api_require_login()` call in the app (grep sweep) — admin was already included everywhere except the intentionally student-only portal. No gaps found. |

## How I tested this

I installed a real MariaDB instance and PHP's built-in server in my
own environment and ran the actual application against them — not just
read the code. Specifically verified, live:

- Fresh-database migration applies cleanly and is safe to re-run
- Full login flow: seeded admin → forced password change → real login
- Email domain restriction rejects a `@gmail.com` adviser, accepts `@ceu.edu.ph`
- An adviser creating a student: a spoofed `adviserId` in the request
  payload is correctly ignored and forced to the adviser's own ID
- Admin sees every student; the adviser sees only their own (confirmed
  via direct API responses, not just UI behavior)
- Document upload with a realistic IERB certificate body ("...approved
  this 3rd day of February 2026...") correctly extracts `2026-02-03` as
  the approval date, uploaded on a different date entirely
- Approving that document: student auto-advanced from Stage 1 to Stage
  2, `ierb_history` logged the reason and detected date, and the
  notification email text included the stage-advance line
- An adviser attempting to inject a fake Protocol Code / Principal
  Investigator flag while saving a student: both were silently
  discarded server-side, exactly as intended
- Stage label rename by admin succeeds; the same request from an
  adviser is correctly rejected with a 403-style authorization message
- Full PHP lint pass (38 files) and Node syntax check across every
  touched JS file

## Known limitation worth flagging

`ALLOWED_EMAIL_DOMAINS` defaults to `ceu.edu.ph` and `mls.edu.ph` — I
don't actually know your real domains, so please confirm/adjust this
one constant in `config.php` before relying on the restriction.
