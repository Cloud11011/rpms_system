# PRISM Admin document summary review

Implementation completed locally on 2026-10-08 for `prism-v2-final-v8`, HEAD
`9dec49dd3f94110690a1502618e15377112f0e1f`. No commit, push, merge or deployment was performed.

## Files changed

Existing tracked files changed by this task:

- `.gitignore`
- `.htaccess`
- `PRISM_DEPLOYMENT_V8.md`
- `assets/css/documents.css`
- `assets/js/documents.js`
- `config.php`
- `documents.php`
- `documents_api.php`
- `workflow.php`

New or newly exposed files (tests were previously ignored):

- `composer.json`
- `composer.lock`
- `includes/document_summary.php`
- `includes/summary_pdf_parser.php`
- `tests/alignment-audit.php`
- `tests/document-extraction-audit.php`
- `tests/document-summary-audit.php`
- `tests/document-summary-http-audit.py`
- `tests/document-summary-transport-audit.php`
- `tests/document-workflow-audit.php`
- `tests/fixtures/document-summary/academic-research.pdf`
- `tests/fixtures/document-summary/encrypted.pdf`
- `tests/fixtures/document-summary/external-entity.docx`
- `tests/fixtures/document-summary/font-budget.pdf`
- `tests/fixtures/document-summary/invalid-container.docx`
- `tests/fixtures/document-summary/malformed.pdf`
- `tests/fixtures/document-summary/research-protocol.docx`
- `tests/fixtures/document-summary/research-protocol.rtf`
- `tests/fixtures/document-summary/research-protocol.txt`
- `tests/fixtures/document-summary/scanned-image.pdf`
- `tests/fixtures/document-summary/sources.json`
- `tests/fixtures/document-summary/unsupported.doc`
- `tests/generate-document-summary-fixtures.py`
- `tests/run-final-regression.py`
- `tests/tonight-polish-audit.php`
- `tests/ui-audit.cjs`
- `tools/check-document-summary.php`
- `PRISM_DOCUMENT_SUMMARY_REVIEW.md`

The existing local change to `tools/schema-v8-manual.sql` and the two pre-existing
`PRISM_V8_BEFORE_RECONCILE*` files were left untouched. No schema column was added.
`ai_helpers.php`, `reports_api.php`, Student and Adviser page implementations were not changed.

## git diff --stat

This literal output covers tracked changes only. It includes the pre-existing schema SQL
change and excludes every new/untracked file listed above. No files were staged.

```text
 .gitignore                 | 17 +++++++++++++-
 .htaccess                  | 11 ++++++++++
 PRISM_DEPLOYMENT_V8.md     | 54 +++++++++++++++++++++++++++++++++++++++++++++
 assets/css/documents.css   |  8 +++++++
 assets/js/documents.js     | 50 ++++++++++++++++++++++++++++++++++++-----
 config.php                 | 22 +++++++++++++++----
 documents.php              |  9 ++++++++
 documents_api.php          | 55 ++++++++++++++++++++++++++++++++++++++++++----
 tools/schema-v8-manual.sql |  4 ++--
 workflow.php               |  2 ++
 10 files changed, 216 insertions(+), 16 deletions(-)

```

## Relevant diff

- Replaces retired HTTP 410 summarization with authenticated Admin-only generation; Student
  and Adviser calls receive 403 before any extraction/model call. Admin requires POST and the
  existing same-origin guard. Missing/malformed IDs receive 422; nonexistent records/files 404.
- Reads only a database-owned stored document. Basename and resolved-path checks prevent
  traversal/symlink escape. Actual file size, parser memory/time, decoded streams, objects,
  font expansion, text/page counts, outbound text, output tokens and response bytes are bounded.
- Full PDF parsing is lazy and exclusive to the Admin summary action. The upload-time local
  approval-date extractor is unchanged and never invokes OpenRouter.
- Uses pinned stable PDF parsing with a small application-side CMap adapter. The actual PLOS
  PDF uses valid compact CMap syntax that the unadapted 2.12.5 library misinterpreted as a huge
  range and exhausted 512 MB. The adapter normalizes end delimiters and bounds expansion.
  Vendor code is unmodified. DOCX uses existing Office validation and bounded non-network XML;
  TXT handles common encodings; RTF excludes non-text/embedded-object destinations.
- Redacts linked student/group/adviser identifiers, student IDs, email addresses, protocol codes,
  and obvious labeled identifying fields before transmission. No raw file, filename, client
  prompt or client path reaches OpenRouter. The prompt is fixed server-side; document text is
  treated as untrusted data. Large inputs retain purpose and selected later methods/ethics excerpts.
- Adds a backward-compatible sensitive-content option to the existing OpenRouter helper.
  Only document calls use body-free failure logging, 900 output tokens and a 64 KB response cap.
  Two-argument aggregate calls retain their model, provider, request and logging defaults.
- Stores summary text only in `documents.ai_summary`. Source/partial flags stay in the response
  and content-free structured audit metadata. Reopened stored summaries without the matching
  current-session generation response show `Source not recorded`. No source/timestamp markers
  or serialized metadata are placed in the summary field.
- Admin repository actions work in table, folder and course views. The modal has loading,
  regeneration, safe text rendering, AI/local source disclosure, partial-coverage disclosure,
  human-review notice, focus trapping and focus restoration. Adviser/Student controls remain absent.
- Saves under the existing document-write lock with an intervening-change check, after the model
  request completes. It updates only `ai_summary`; no review/submission/stage/override/version
  or approval-date operation is invoked. Typed failures preserve the prior summary.
- Protects `vendor/`, Composer manifests and existing private paths from direct HTTP access.
  Narrow ignore exceptions expose the relevant tests and lockfile for review; vendor/caches stay ignored.

## Dependencies actually installed

- `smalot/pdfparser v2.12.5`, source commit `2cfa0d92bd557875c9f52a75fde0e8392302a354`.
- `symfony/polyfill-mbstring v1.43.0` (transitive dependency).
- `composer.lock` generated and installed; no beta used, plugins disabled, scripts not run.
- Local PHP 8.2.12. Composer platform checks pass for PHP, zlib and iconv.
- Composer security audit completed: no advisories or abandoned packages reported.
- The deployment probe passes all 12 checks with local ZIP explicitly enabled.

## Actual extraction results

| Sample | Extracted UTF-8 bytes | Pages | Outcome |
|---|---:|---:|---|
| Real PLOS academic research PDF | 40,504 | 15 | Selectable research/methodology/ethics text recovered; about 0.4?0.8 seconds locally |
| Research protocol DOCX | 1,191 | ? | Body, table text and accents recovered |
| Research protocol TXT | 1,163 | ? | Research text and accents recovered |
| Research protocol RTF | 1,121 | ? | Research text and Unicode/hex accents recovered; hidden/object destinations excluded |

The PDF is Jarke et al. (2022), *Registered report: How open do you want your science?*
DOI `10.1371/journal.pone.0261260`, a real 1,567,228-byte publisher PDF, distributed under
Creative Commons Attribution. Its source, attribution and SHA-256 are in fixture `sources.json`.
The DOCX/TXT/RTF samples contain fictional identifiers and realistic protocol sections;
these are original format fixtures, not live PRISM documents.
Scanned/image-only, malformed, encrypted and excessive-font-map PDFs produce safe specific errors.
Invalid/XXE DOCX and legacy DOC are rejected. A 32 MB runtime rejects PDF parsing before processing.

## Authorization, generation and persistence

Admin passes PDF/DOCX/TXT/RTF generation in each of model-success, unavailable and error cases.
Student and Adviser POST/GET summary calls return 403, without model calls or writes.
Non-POST Admin calls return 405; missing/cross-site origin evidence returns 403.
Admin listing exposes stored summaries and the summary action; Adviser/Student listings do not.

The model-success, HTTP error, network error, unavailable, oversized, empty, identity-leaking
and thrown-error paths were tested with controlled provider/cURL doubles. Invalid responses
fall back deterministically. Source is explicit. **No live OpenRouter call was made**:
these results establish code-path behavior, not live provider connectivity or model-quality evaluation.

Endpoint fixtures execute the actual endpoint SQL on disposable in-memory SQLite with real extraction.
They compare every document column except `ai_summary` and every Student column before and after;
all are equal, including review status/remarks, formal RPMS state, stage, Adviser assignment,
override state, version/current/supersession state and approval-date fields. The only document SQL
write is `UPDATE documents SET ai_summary = :summary WHERE id = :id`. Persistence failures roll
back; intervening version/workflow changes reject the save. The tests do not exercise live Hostinger
or MariaDB concurrency; the implementation reuses the existing production lock mechanism.

## Regression/security/UI results

- **25/25 CLI suites pass; 4,903 reported checks/cases total.**
- **373** new extraction/privacy/endpoint/transport checks (37 + 295 + 41), included above.
- **6,809** full isolated browser UI checks, zero failures; includes 29 summary checks.
- **29** focused summary UI checks rerun after the final source-display change, zero failures.
- **21** shared session-expiry/network/debounce JavaScript checks, all pass.
- **19** actual isolated Apache GET/HEAD/private-path/public-control checks, all pass.
- **29** PHP/JavaScript syntax checks, zero failures; `git diff --check` passes.

Existing aggregate AI report generation/privacy continues to pass: alignment tests cover both
summary/full model-success and local modes; report-format audit passes 274 privacy/format checks;
sensitive-transport audit proves aggregate request defaults are unchanged. The full UI suite verifies
aggregate report controls/generation as well. Two retirement assertions were updated to the approved
Admin-only scope; absence/security assertions remain for Student, Adviser and unrelated report/dashboard pages.

| CLI suite | Reported result |
|---|---|
| `auth-audit.php` | PASS: Password change page stays accessible |
| `auth-flows.php` | 35 authentication flow checks passed. |
| `session-idle-audit.php` | PASS: 25 frozen-clock idle/session response checks. |
| `security-audit.php` | 46 security boundary cases passed. |
| `rate-limit-audit.php` | 45 rate-limit assertions passed; temporary files only. |
| `logout-audit.php` | 28 logout assertions passed across 4 cases. |
| `crud-audit.php` | 56 CRUD endpoint cases passed. |
| `document-workflow-audit.php` | 40 document workflow cases passed. |
| `notification-delivery-audit.php` | PASS: 290 notification delivery checks; in-memory database, no live services. |
| `research-group-audit.php` | PASS: 197 research-group and notification checks; no live services. |
| `academic-audit.php` | Academic catalog/validation: 591/591 checks passed. |
| `academic-api-audit.php` | PASS: 1343 isolated academic API checks. No live database, mail or application bootstrap. |
| `alignment-audit.php` | PASS: 228 pagination, adviser-group and report authorization checks; no live services or runtime writes. |
| `report-format-audit.php` | PASS: 274 report privacy/formatting assertions; no bootstrap or external services. |
| `backend-audit.php` | PASS: 87 backend regression assertions; no live database, config, mail, or AI services used. |
| `tonight-polish-audit.php` | PASS: 100 login and Admin-only document-summary boundary checks; no live services. |
| `readiness-audit.php` | PASS: 426 readiness pagination/scope/preview checks; isolated SQLite, no live services. |
| `email-format-audit.php` | PASS: 26 actual Gmail/mail/log MIME and HTML escaping checks; transports stubbed. |
| `cache-audit.php` | PASS: 139 rendered asset URLs match filemtime; fallback, query, fragment and working-directory checks passed. |
| `final-regression-audit.php` | PASS: 242 final scoped filter/sort/actionable/privacy compatibility assertions; isolated fixtures only. |
| `hardening-audit.php` | PASS: 114 token, password, Office container and AI-filter checks. |
| `calendar-deadlines-audit.php` | PASS: 182 official deadline endpoint/scope/security/delivery assertions; real isolated SQL, no live services. |
| `document-extraction-audit.php` | PASS: 37 extraction/privacy assertions. |
| `document-summary-audit.php` | PASS: 295 Admin/Adviser/Student endpoint, fallback, privacy, persistence and workflow assertions. |
| `document-summary-transport-audit.php` | PASS: 41 sensitive transport and aggregate compatibility assertions (mocked cURL, no network). |

Evidence files: `tests/final-regression-results/cli-results.json`, the associated suite logs,
`tests/document-summary-results/http-denial.json`, and `tests/post-gathering-results/syntax.json`.
Generated evidence/caches remain ignored. Full browser result was `6809 isolated UI checks; 0 failures`.

## Hostinger prerequisites and remaining limits

Hostinger was not connected, inspected, modified or deployed in this session. Before release:

1. Verify the hosting **web-runtime** PHP 8.1+, zlib, iconv, mbstring, XML/DOM, ZIP and cURL,
   plus readable Composer autoload. CLI settings alone do not prove web-runtime settings.
2. Ship the entire locked production `vendor/` bundle with its licenses. Do not assume Hostinger
   runs Composer. Run the supplied diagnostic using the hosting PHP configuration.
3. Verify memory/time/worker budgets and permission to apply finite PHP runtime limits.
   256 MB and at least 60 seconds request budget are recommended. Large documents can be
   rejected or summarized from bounded excerpts; the UI discloses omitted coverage.
4. Verify `.htaccess`/rewrite enforcement and direct vendor/Composer/storage/test 403/404 responses
   on hosting. The rules passed an actual isolated local Apache test with synthetic files only.
5. Confirm live OpenRouter connectivity with the existing configured provider/model when staging.
   The deterministic local fallback works if the service is absent or fails.

Arbitrary prose cannot be guaranteed anonymous: redaction is bounded best-effort minimization,
with known identifiers and obvious fields removed. Scanned/image-only and password-protected
PDFs require a readable text-based copy; no OCR exists. Complex RTF layout is not reproduced.
The mature PDF parser builds initial raw structures before some application guards, so finite PHP
memory/time limits remain the final resource boundary. Professional review of the original remains necessary.

Stop point: local implementation and tests complete, awaiting review. Nothing has been staged,
committed, pushed, merged or deployed.
