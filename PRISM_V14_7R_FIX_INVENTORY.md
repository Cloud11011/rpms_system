# PRISM V14 7R: Fix inventory

**For:** auditors and reviewers. Each Batch 7 finding (plus the new finding found in 7R) with its 7R status:

- **FIXED:** code changed, and an executed test or rehearsal demonstrates it.
- **PARTIAL:** improved, with a stated remaining gap.
- **BLOCKED:** cannot progress without an external action.
- **NOT VERIFIED:** no executed evidence.

Run numbers refer to [PRISM_V14_7R_SECURITY_AND_REGRESSION.md](PRISM_V14_7R_SECURITY_AND_REGRESSION.md) §2. "DB" refers to [PRISM_V14_7R_DATABASE_COMPATIBILITY.md](PRISM_V14_7R_DATABASE_COMPATIBILITY.md).

| ID | Sev. | Finding | 7R status | Evidence |
| --- | --- | --- | --- | --- |
| **B7R-N01** | **High (new)** | Mixed-collation runtime failure. The IERB monitor's COALESCE expressions on a general_ci base fail with errors 1267/1270 for filters and search once schema 12 applies. Batch 7 could not observe it. | **FIXED** | Reproduced with the f944220 runners on 12.3.2 (DB §3). Probes pass after the fix. Monitor search over canonical and legacy titles tested over HTTP (run 10). |
| B7-F01 | High | No path to enroll, reconcile, or open/resolve correction cases | **FIXED** (tooling); activation is decision D1 | CLI `v14-operations.php`; `reconcile-research-project.php designate-pi`; gated HTTP endpoints; requirement-panel UI (run 10) |
| B7-F02 | High | Schema 13/14 FK failure on non-unicode legacy collation | **FIXED** | 11→15 PASS on the real 12.3.2 export and a 10-row matrix (DB §5) |
| B7-F03 | Medium | Hostinger version unrehearsed | **FIXED** for 12.3.2 | DB §5 (MySQL 8 not applicable to Hostinger) |
| B7-F04 | High | No restored production-shaped rehearsal | **PARTIAL** | The real export was rehearsed. The export dropped one CHECK constraint, so a faithful (hPanel) backup restore on Hostinger staging is **pending** (runbook §3–6). |
| B7-F05 | High | No proven no-SSH method | **PARTIAL** | `hostinger-cron-runner.php` exercised locally 11→15; TLS option for Remote MySQL. **Not yet proven on Hostinger** (runbook §5). |
| B7-F06 | Medium | Preflight rejects 12–15 | **FIXED** | Contract PASS at 11 and 15 on the production-shaped database (cron receipts); HTTP-free check in run 10 |
| B7-F07 | Medium | Summarization unavailable at schema 11 | **BLOCKED** on migration to 14 | Unchanged by design (DDL is never applied by HTTP) |
| B7-F08 | Medium | AI reports lack the title; members counted individually | **FIXED** | Research Title column and table; canonical projects counted once; parsed PDF text (run 10) |
| B7-F09 | Medium | Research Title edit mismatch and erasure | **FIXED**; legacy blank policy is D2 | Erase → 422; < 3 chars → 422; canonical change → 409; before/after audited (run 10) |
| B7-F10 | Medium | PDF ASCII transliteration | **FIXED** for Windows-1252 scripts; **PARTIAL** for non-Latin scripts | Exact round-trip of Peñaflor / Batà / São José / Ñuñoa – “quotes” €. Non-Latin characters are shown as `?` with an on-page notice ([pdf/unicode-roundtrip.json](PRISM_V14_7R_EVIDENCE/pdf/unicode-roundtrip.json)). Embedding a Unicode font remains optional. |
| B7-F11 | Medium | Test-harness isolation | **FIXED** | `finally` restore; exclusive run lock (it refused an overlapping run in this batch); collation-variant fixtures (12.3.2 general_ci) |
| B7-F12 | Low | PI, co-investigators and category not recorded | **PARTIAL** | PI recorded by RPMS and displayed (checklist, track sheet, panel); co-investigators and category are decision D6 |
| B7-F13 | Medium | Sorting gaps | **FIXED**; acceptance is D3 | Student Records newest updated (DB-verified order), Reports allowlisted sort with newest default, Deadlines upcoming/overdue view with nearest first (run 10) |
| B7-F14 | Low | Inline-error validation | Decision D4 (unchanged) | — |
| B7-F15 | Low | Historical alphanumeric IDs | Count established: **17** non-numeric, 6 blank; decision D5 | Cleanup preview summary |
| B7-F16 | Low | Incomplete historical timestamps | Accepted with disclosure (unchanged) | — |
| B7-F17 | Medium | AI accuracy and privacy | **NOT VERIFIED** (accuracy). Provider permission is received; titles are never sent in progress-report payloads. | Decision D9 |
| B7-F18 | Low | Browser/AT coverage | **PARTIAL** | New dialog tested in Chromium at 390/1440 px (keyboard Escape, no page errors). No screen reader. |
| B7-F19 | Low | Student-range fence | Unchanged (monitor) | — |
| B7-F20 | Low | Deletion verification invalidated by migration | **PARTIAL** | Schema-11 hash inputs untouched (evidence stays valid at 11). After migration, regeneration is still out-of-band. |
| B7-F21 | Low | Test files lacking SAPI guards | **FIXED** | Every `maintenance/ierb-*-tests/*.php` has a guard |
| B7-F22 | Low | Identifier trailing hyphen | **FIXED**; also made platform-independent (libiconv vs glibc transliteration) | Run 10 |
| B7-F23 | Low | Admin deletion without a reason | Decision D7 (unchanged) | — |
| B7-F24 | Low | Generic runner errors | **FIXED** | Error class, SQLSTATE and errno; credential-free (DB §6) |
| B7-F25 | Low | "Other records" versioning scope | Decision D8 | — |

## Decision A–D items from the brief

| Item | Status |
| --- | --- |
| A. Every verified member uploads, revises, formally submits Adviser-approved documents, views shared progress; PI not exclusive; no duplicate submissions; once-per-project satisfaction | **FIXED** (runs 03/10) |
| B. Enrollment, reconciliation, title/Adviser correction, PI designation, collision-safe identifiers, no inference | **FIXED** (tooling + gated HTTP); activation is D1 |
| C. Adviser request pending until RPMS decides; approve/reject with reason; RPMS-initiated; per-case Amendment; concurrency and duplicates; immutable history; notices | **FIXED** (run 10); staging walkthrough pending |
| D. Fixed stage rules | Preserved (runs 02/03/06) |
| Test-data cleanup | Preview and procedure delivered; **execution not performed** (by instruction) |
