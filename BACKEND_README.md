# PRISM Backend

The working backend for **PRISM: A Web-Based AI System for IERB Progress
Monitoring and Automated Reporting** (Chan, Maniacop, Quiambao — CEU Malolos,
2026), built on top of the existing PRISM front end (`ashamikaila/rpms_system`).

Database: **MySQL/MariaDB** (matches Hostinger's hosting and the study's
System Architecture in Chapter 3, Figure 3/6).

## Roles

- **RPMS Administrator (`admin`)** — full access: manages student and
  research-adviser accounts, IERB records, document review, notifications,
  AI summaries, PDF reports.
- **Research Adviser (`adviser`)** — views and monitors their assigned
  students' IERB progress, and can **approve, deny, or comment** on
  submitted documents. Shares the same admin-adjacent tools (IERB Progress,
  Documents, Notifications, AI, Reports, Calendar), scoped to their own
  advisees where applicable.
- **Student (`student`)** — submits research/IERB documents and tracks their
  own status: Dashboard, IERB Progress, Document Submission, My Documents,
  Notifications, Calendar (personal reminders), and Profile.

There is no separate "Faculty" role — advisers are the only non-admin staff
role, matching how your institution's RPMS process actually works.

## Installing and running it (step by step)

### 1. Install XAMPP (or any PHP 8.1+ / MySQL stack)

Download from https://www.apachefriends.org and install it, keeping Apache
and MySQL checked.

### 2. Put the project in place

Extract the zip. Move the `rpms_system` folder into XAMPP's `htdocs`
directory (`C:\xampp\htdocs` on Windows, `/Applications/XAMPP/xamppfiles/htdocs`
on Mac) so you end up with `htdocs/rpms_system/config.php`.

### 3. Start Apache and MySQL

Open the XAMPP Control Panel and click **Start** next to both.

### 4. Create the database

Open `http://localhost/phpmyadmin`, click **New**, name it `prism`,
collation `utf8mb4_unicode_ci`, click **Create**. No need to create tables —
they're created automatically on first visit.

### 5. Point the app at your database

Create `rpms_system/config.local.php`:

```php
<?php
define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'prism');
define('DB_USER', 'root');
define('DB_PASS', '');
```

(XAMPP's default MySQL user is `root` with no password.)

### 6. Open it

Visit `http://localhost/rpms_system/login.php`. Tables and a default RPMS
admin account are created automatically on first load.

**Default administrator login**
- Portal: Admin
- Username: `rpms_admin`
- Password: `ChangeMe123!`

You'll be required to set a new password immediately after this first login —
PRISM forces a password change for any account still using its original
auto-generated/documented default (this applies to the seeded admin account,
and to every student/adviser account created afterward, since those also get
a predictable temporary password).

## Try the full workflow

1. Log in as admin → **Research Advisers** page → add an adviser (this
   auto-creates their login: username = Employee ID, password = `Ceu@` +
   Employee ID with punctuation stripped, e.g. `ADV-010` → `Ceu@ADV010`)
2. **Students** page → add a student, assigning them to that adviser (this
   also auto-creates their login the same way, using their Student ID)
3. Log out, log in as the student → **Document Submission** → upload a file
4. Log in as the adviser → **Documents** page → find the file → click
   **Approve** (✓), **Deny** (✕), or the comment icon to leave feedback
   without changing status. The student gets a notification either way.
5. Back in **Documents**, try the **Course** and **Year** filter dropdowns
   and the **Sort** dropdown, or switch to the "group by course" view using
   the layer-group icon next to the table/folder view buttons.

Without any further setup, AI summaries fall back to a simple local
extractive summarizer and emails get written to `storage/mail.log` instead
of actually sending — so you can test everything before connecting real
services.

## Turning on real AI and real email

Add to `config.local.php`:

```php
// AI summaries via OpenRouter (openrouter.ai)
define('OPENROUTER_API_KEY', 'sk-or-...');

// Real email via the Gmail API (OAuth2) — see the Gmail API section below
define('GMAIL_CLIENT_ID', '...');
define('GMAIL_CLIENT_SECRET', '...');
define('GMAIL_REFRESH_TOKEN', '...');
define('GMAIL_SENDER_EMAIL', 'your-rpms-account@gmail.com');
```

### Setting up the Gmail API

1. In [Google Cloud Console](https://console.cloud.google.com/), create a
   project and enable the **Gmail API**.
2. Configure the OAuth consent screen (External is fine for testing; add
   your sending Gmail account as a test user).
3. Create an **OAuth client ID** (Credentials → Create Credentials → OAuth
   client ID → Desktop app). Note the Client ID and Secret.
4. Get a refresh token via [OAuth 2.0 Playground](https://developers.google.com/oauthplayground):
   gear icon → use your own credentials → paste Client ID/Secret → in Step 1
   select **Gmail API v1 → gmail.send** → Authorize → sign in with the
   sending account → Step 2 → Exchange authorization code for tokens → copy
   the refresh token.
5. Put all four values in `config.local.php` as shown above.

`send_notification_email()` in `config.php` tries the Gmail API first, falls
back to `mail()`, then to `storage/mail.log` — nothing breaks if a token is
ever revoked.

## Deploying to Hostinger

1. **hPanel → Databases → MySQL Databases** — create a database and user,
   note the host/name/username/password.
2. Upload everything inside `rpms_system/` to `public_html` (or a subfolder)
   via File Manager or FTP.
3. Create `config.local.php` on the server with your real Hostinger values. At minimum configure:
   ```php
   define('DB_HOST', '...');
   define('DB_PORT', 3306);
   define('DB_NAME', '...');
   define('DB_USER', '...');
   define('DB_PASS', 'a-strong-database-password');
   define('APP_BASE_URL', 'https://yourdomain.com');
   ```
   Before the first request, also set a unique 12+ character
   `PRISM_INITIAL_ADMIN_PASSWORD` environment variable (and optionally
   `PRISM_INITIAL_ADMIN_EMAIL`). PRISM no longer ships a known administrator
   password.
4. Make sure `storage/`, `storage/documents/`, `storage/reports/` are
   writable (`chmod 775` via File Manager if uploads fail).
5. Visit `https://yourdomain.com/login.php` — tables are created
   automatically on first load and the initial administrator is bootstrapped
   from the deployment-only password above.
6. For scheduled notifications, add a Hostinger cron job such as:
   ```sh
   php /home/USER/domains/YOURDOMAIN/public_html/tools/process_scheduled_notifications.php
   ```
   Run it every 1–5 minutes. The worker atomically claims due rows so overlapping
   cron executions do not send the same notification twice.

## AI progress reports (Summarized / Full)

Both the **Reports** and **AI** pages offer exactly two AI actions, each
generated with a single click:

- **Generate Summarized Report** — a short (150–220 word) executive overview
  of all students: overall completion picture, on-track vs. delayed counts,
  and the top priority action for RPMS this week.
- **Generate Full Report** — a longer (400–700 word) narrative analysis plus
  a complete per-student detail listing (stage, status, adviser, pending
  requirements, last submission).

These are the *only* two AI prompts the system will ever send — the request
body is validated against a whitelist (`['summary', 'full']`) server-side in
`reports_api.php`, so there is no way to trigger free-form AI generation from
the UI or API.

**Automatic fallback:** if `OPENROUTER_API_KEY` isn't configured, or the
OpenRouter call fails for any reason, the report is generated instantly using
a deterministic local summarizer instead — the button never just fails. Every
report's PDF states its actual source ("OpenRouter AI (model)" or "Local
fallback summarizer") plus a human-review line, so RPMS staff always know
whether they're looking at an AI-written or locally-computed summary before
distributing it. Check `storage/api_errors.log` if you've configured
OpenRouter but reports keep falling back — it logs the exact HTTP
status/error from the API call.

## Security notes

- **RPMS staff self-registration (`register.php`) is disabled by default.**
  Set `ADMIN_REGISTRATION_CODE` in `config.local.php` to a private value if
  you want to let staff create their own admin accounts; share that code
  only with people who should get admin access. The initial `rpms_admin`
  account is created only when `PRISM_INITIAL_ADMIN_PASSWORD` is supplied
  by the deployment environment; there is no built-in default password.
- **On Apache hosting (including Hostinger), `.htaccess` files block direct
  web access to `storage/`** (uploaded documents, generated report PDFs,
  and `mail.log`/`api_errors.log`, which can contain sensitive content like
  password-reset links). Everything in that folder is only ever served
  through the authenticated PHP endpoints. If you deploy on nginx or another
  server that doesn't honor `.htaccess`, add an equivalent `location` block
  denying access to `/storage/`.
- Students can only view/download their own documents. Advisers are scoped
  to their assigned students for documents, aggregate reports, report history,
  and notifications; institution-wide report/notification access remains
  admin-only.
- New student/adviser accounts receive a one-time password-setup link when
  `APP_BASE_URL` is configured. Their internal temporary passwords are random
  and are never based on student/employee IDs.

## File map

- `config.php` — DB schema/migration (MySQL), session & role guards,
  OpenRouter client, Gmail API client (OAuth2).
- `login_process.php`, `register_process.php`, `logout.php`,
  `forgot_password_process.php`, `update_password.php` — auth flow.
- `students_api.php`, `advisers_api.php`, `ierb_api.php` — RPMS record
  management (student ↔ adviser assignment, IERB stage/status history).
- `documents_api.php`, `ai_helpers.php` — document repository: upload,
  review (Approve/Deny/comment), course/year metadata, AI summarization.
- `reports_api.php` — PDF report generation/history, including the
  one-click, guardrailed `ai_report` action (Summarized/Full only — see
  below) with automatic local fallback if AI is unavailable.
- `notifications_api.php`, `send_followup.php` — notification center
  (Gmail API delivery).
- `tools/process_scheduled_notifications.php` — CLI/cron worker that sends
  due scheduled notifications.
- `reports_api.php` — PDF report generation/history (dependency-free PDF
  writer, verified valid with `qpdf --check`).
- `profile_api.php` — self-service name/password changes for all roles.
- `dashboard.php`, `admin_people.php` (→ `admin_students.php` /
  `admin_advisers.php`), `ierbprog.php`, `documents.php`, `admin_ai.php`,
  `admin_notifications.php`, `reports.php`, `calendar.php` — admin/adviser
  pages, guarded by `require_login(['admin','adviser'])` or `'admin'` only
  where noted.
- `role_portal.php` (→ `student.php`) — the student portal: Dashboard, IERB
  Progress, Document Submission, My Documents, Notifications, Calendar,
  Profile.

## Known limitations

- The personal calendar/reminders feature is local `localStorage` state per
  account — a convenience feature, not part of the paper's centralized
  dataset.
- Profile photos are stored client-side only (not persisted server-side).
- The PDF writer supports plain text reports only (no tables/images) —
  sufficient for the reports described in the paper; swap in `dompdf` if you
  need richer layouts later.
