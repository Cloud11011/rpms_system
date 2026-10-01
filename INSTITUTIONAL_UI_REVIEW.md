# PRISM optional institutional UI integration — 1 October 2026

Implemented for owner review on `prism-v2-academic-fields`. HEAD remains `d4d19b0`; academic checkpoint `b6323f5bce501f66faa9bdec15e512a48630ad16` is an ancestor. Changes are uncommitted and unstaged. No migration, live application/database bootstrap, email delivery, AI call, commit or push was performed.

## Scope and source

The only external visual/content reference was [ashamikaila/rpms_system/main at 66abc34b05bb221d89320f36697572f3866ae1bb](https://github.com/ashamikaila/rpms_system/tree/66abc34b05bb221d89320f36697572f3866ae1bb). Reads and downloads were pinned to that commit after resolving main.

- [ceu_footer.php](https://github.com/ashamikaila/rpms_system/blob/66abc34b05bb221d89320f36697572f3866ae1bb/ceu_footer.php) and [ceu-footer.css](https://github.com/ashamikaila/rpms_system/blob/66abc34b05bb221d89320f36697572f3866ae1bb/assets/css/ceu-footer.css): CEU identity, Malolos campus name, address, two telephone links, website, navy/pink visual direction and footer structure.
- [research_resources.php](https://github.com/ashamikaila/rpms_system/blob/66abc34b05bb221d89320f36697572f3866ae1bb/research_resources.php), [research-resources.css](https://github.com/ashamikaila/rpms_system/blob/66abc34b05bb221d89320f36697572f3866ae1bb/assets/css/research-resources.css), and only the SDG/Research Agenda presentation portions of [role_portal.php](https://github.com/ashamikaila/rpms_system/blob/66abc34b05bb221d89320f36697572f3866ae1bb/role_portal.php): reference titles, years, images and captions.
- The 17-goal text alternative and six research-cluster/SDG mappings were transcribed from the supplied artwork. They do not alter academic units/programs or impose new workflow requirements.

The accreditation collage, social-media content and unused source images were not imported. Reference session/login/navigation code, demo accounts, localStorage authentication, prototype APIs and backend behavior were not copied. Existing PRISM assets remain unchanged.

## Exact changed and new files

| File | Change |
| --- | --- |
| `dashboard.php` | Four presentation-only lines: two stylesheet links and two protected partial includes at the end of main. |
| `research_adviser.php` | Same four-line integration beneath the assigned-student workspace, outside its review dialog. |
| `role_portal.php` | Two stylesheet links; resources at the end of the existing dashboard panel; footer at the end of main, outside switched portal panels. |
| `tests/ui-audit.cjs` | Exact new partial fixtures, WebP MIME, additive protected-partial/browser assertions, optional temporary screenshots. |
| `includes/ceu_footer.php` (new) | Reusable compact CEU footer with reference-supplied contact information. |
| `includes/research_resources.php` (new) | Native expandable SDG/Research Agenda references, full-size image links and text alternatives. |
| `assets/css/ceu-footer.css` (new) | Scoped footer layout, wrapping, contrast and focus styles. |
| `assets/css/research-resources.css` (new) | Scoped responsive reference cards, native disclosure focus, proportional images and readable text. |
| `assets/images/ceu-logo.webp` (new) | Optimized CEU identity image used by the footer. |
| `assets/images/research-matrix.webp` (new) | Lossless full-resolution research agenda image used by the resource section. |
| `assets/images/sdg.webp` (new) | Full-resolution optimized SDG image used by the resource section. |
| `INSTITUTIONAL_UI_REVIEW.md` (new) | This provenance and verification record. |

The pre-existing `PRISM_TEST_CASES.md`, `PRISM_TEST_CASES.docx` and Word lock file were preserved. Private configuration was not opened, printed or modified.

## Image optimization

Original reference downloads were kept in a temporary folder, not added to the workspace. Their Git blob hashes were verified against the pinned repository before conversion.

| Reference asset | Original dimensions | Original bytes | Used output | Output dimensions | Output bytes |
| --- | --- | ---: | --- | --- | ---: |
| `CEU_LOGO_NEW.png` | 6250 × 6250 | 1,700,273 | `ceu-logo.webp` | 256 × 307 | 18,644 |
| `Research_Matrix.png` | 612 × 786 | 154,030 | `research-matrix.webp` | 612 × 786 | 51,024 |
| `SDG.jpg` | 2048 × 1448 | 267,994 | `sdg.webp` | 2048 × 1448 | 177,686 |
| **Total** | | **2,122,297** | | | **247,354** |

Total reduction: **88.34%**.

The logo has only fully transparent margins removed (source rectangle x=1538, y=1086, width=3154, height=3788), then is resampled for footer use and encoded as lossless WebP. No logo artwork was redrawn. The research matrix uses lossless WebP with every decoded pixel compared to the original; dimensions and pixels match exactly. SDG artwork retains its original dimensions and uses WebP quality 90. Original and optimized images were visually inspected for clarity.

Source Git blob hashes:

- CEU logo: `fffca303e3f29d02c3d4547b9ccdf88d6482633b`
- Research matrix: `4cac7939ccb4578d1fda4f95fd67ba08625b36bf`
- SDG image: `5e432405ed57c7cb8c9158dc62eaba85fe94482e`

## Preserved boundaries and accessibility

Both new partials reject direct execution, missing/invalid caller context and unknown roles. They depend on the existing page authentication and inherit the existing `includes/.htaccess` denial; that file was not changed. No new page route or API endpoint was introduced.

Authentication/session/security files, APIs, workflow helpers, database/schema/migrations, academic validation/catalog, existing navigation, and product JavaScript are unchanged. Every existing static ID and every script block/include in the three templates was compared with HEAD and retained unchanged. Template changes are additive presentation includes/styles only.

Resource disclosures use native `details/summary`; image links work by keyboard, include new-tab notices and use `noopener noreferrer`. Images have meaningful alt text and explicit dimensions, preserve aspect ratio, and link to their full-size versions. Text alternatives expose the 17 goals and six agenda mappings without requiring visual reading of the artwork.

The footer stays in normal main-content flow. Scoped grids stack at narrow widths; content wraps rather than clipping. Resource content is inside only the student's dashboard panel, so existing student progress/upload/document/calendar/profile switching remains unchanged. Both themes retain readable foreground/background colors.

## Test changes and results

No existing UI assertion was weakened, skipped or removed. A diff check confirmed the only replaced pre-existing test line was the asset MIME map, adding WebP support; other test changes were insertions.

Fixture expansion is limited to the exact strings for `includes/ceu_footer.php` and `includes/research_resources.php`, and the exact page allowlist `dashboard.php`, `research_adviser.php`, `role_portal.php`. Include counts and nested-include rejection are asserted. The final unexpected-include rejection, navigation fixtures and academic fixtures remain intact.

The new browser checks cover each of the three views at **375, 768, 1024, 1280 and 1600 px**, light and dark:
- One resource section/footer, valid hierarchy, no duplicate IDs and no new workflow controls/API writes.
- Native keyboard expansion/collapse, visible focus, Tab access and Enter activation of image links.
- Loaded image dimensions, alt text, proportional rendering and viewport/content bounds.
- Exact reference contact links, secure new tabs, complete text alternatives and agenda mappings.
- Student internal navigation keeps the footer visible and the resources separate.
- Direct/missing/invalid context rejection for each partial and accepted authenticated role contexts.

| Verification | Result |
| --- | --- |
| Full `node tests/ui-audit.cjs` | **3,403 checks; zero failures** |
| New institutional subset (included in full count) | **1,244 checks** |
| Existing browser checks retained | **2,159 checks** |
| `node tests/reset-ui-audit.cjs` | **5 passed** |
| `tests/auth-audit.php` | **12 passed** |
| `tests/auth-flows.php` | **31 passed** |
| `tests/security-audit.php` | **41 passed** |
| `tests/rate-limit-audit.php` | **45 passed** |
| `tests/logout-audit.php` | **28 assertions / 4 cases passed** |
| `tests/crud-audit.php` | **42 passed** |
| `tests/document-workflow-audit.php` | **24 passed** |
| `tests/backend-audit.php` with ZipArchive | **86 passed** |
| `tests/academic-audit.php` | **591 passed** |
| `tests/academic-migration-audit.php` | **155 passed; isolated fixtures only** |
| `tests/academic-api-audit.php` | **1,343 passed** |
| PHP syntax | **All five touched PHP files passed** |
| JS syntax | **Touched `tests/ui-audit.cjs` passed `node --check`** |
| Existing template IDs and scripts | **Preserved** |
| `git diff --check` | **Passed** |

Hardened/backend PHP suites total **309** checks/assertions. Academic suites total **2,089**. Browser fixtures render actual templates/styles/scripts with mocked APIs and block external fonts/icons. No runtime exception or CSP violation was recorded by the audit. Desktop/mobile screenshots were inspected, including the collapsed dashboard presentation and expanded informational content.

## Git diff --stat

Tracked files only; the eight new files listed above are not represented in this default Git summary.

```text
 dashboard.php        |   4 ++
 research_adviser.php |   4 ++
 role_portal.php      |   4 ++
 tests/ui-audit.cjs   | 185 ++++++++++++++++++++++++++++++++++++++++++++++++++-
 4 files changed, 196 insertions(+), 1 deletion(-)
```

No files were staged, committed or pushed.

## Remaining owner/manual checks

- Review the selected CEU identity/contact information and the dated 2023–2028 agenda as supplied by the approved reference before public presentation.
- Check real Chrome/Edge/mobile rendering, browser zoom and screen-reader announcements with the deployment's actual fonts/icons; fixture runs block external CDNs.
- Confirm CEU website links, telephone-app handoff and full-size WebP image opening in the intended browsers.
- Confirm the deployment server actually denies direct `/includes/` requests. Fixture/direct-PHP tests and the unchanged Apache rules do not replace deployment verification.
- Existing real-database, email, scheduler, AI and migration release gates remain outside this presentation task. No live migration was performed.
