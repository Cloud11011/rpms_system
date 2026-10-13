# PRISM Batch 11.5 — Frontend Design System

Date: 13 October 2026 (Asia/Manila). Foundation: verified local Batch 11, with Batch 10.75 compatibility patterns. This is a presentation consolidation; the frozen v10 schema and workflow state definitions are unchanged.

## Ownership and load order

Every production rendering shell calls `prism_frontend_styles()` from `includes/assets.php` at the end of its head. The helper renders `includes/frontend_styles.php`, which loads the existing Montserrat font, followed by three versioned local stylesheets and the calendar presentation script:

1. `assets/css/prism-tokens.css`: typography, spacing, colors, semantic tones, radii, elevation and compatibility variable names.
2. `assets/css/prism-components.css`: common button variants, form controls, toolbar alignment, public/account shell integration, focus and motion preferences.
3. `assets/css/prism-calendar.css`: shared calendar chrome and date states.
4. `assets/js/prism-calendar-presentation.js`: shared legends and accessible Today/selection attributes.

Existing page styles load first. `prism-workspace.css` still imports `batch1075.css`; `batch11.css` continues to own the approved compact record layouts, dashboard details and role-specific refinements. Their superseded token, button and calendar declarations have been consolidated rather than adding another batch override appendix. Specialized layouts remain in their existing stylesheets. Existing class names and event selectors remain compatible.

The shared loader is presentation-only and does not load application configuration or access a database. Its function boundary also works with existing isolated template tests that evaluate page source from another directory.

## Color tokens

| Purpose | Light | Dark | Token |
|---|---|---|---|
| Structural navy | `#0c0e3f` | Retained for branded artwork | `--prism-navy` |
| Brand pink | `#ee1280` | Retained for branded artwork | `--prism-pink` |
| Supporting purple | `#64175f` | Supporting text alias `#c998da` | `--prism-purple`, `--accent-purple` |
| Accessible accent/focus | `#bd0d65` / `#b80b62` | `#ff88bc` | `--prism-accent`, `--prism-focus` |
| Workspace primary action | `#a21368` | `#b52b79` | `--prism-primary` |
| Primary hover | `#861052` | `#9a2167` | `--prism-primary-hover` |
| Main text | `#0c0e3f` | `#f1f1fa` | `--prism-text` |
| Secondary text | `#666` | `#b5b7ca` | `--prism-muted` |
| Background | `#f5f7fb` | `#10111e` | `--prism-bg` |
| Card/surface | White | `#1b1d30` | `--prism-card`, `--prism-surface` |
| Border | `#e8eaf0` | `#34364e` | `--prism-border` |
| Deadline soft highlight | `#fbeaf3` | `#372135` | `--prism-pink-soft` |
| Supporting soft highlight | `#eee7f5` | `#30263d` | `--prism-purple-soft` |

Success, warning, danger, information, neutral and Admin Override retain separate `--prism-<tone>-bg`, `-fg` and `-bd` tokens from the existing UI kit. Dark semantic surfaces use translucent tones with readable foregrounds. Destructive filled buttons retain red; Admin Override retains purple.

Legacy names (`--bg-color`, `--card-bg`, `--text-primary`, `--workspace-primary`, `--legal-*`, `--public-*`, and onboarding `--surface`/`--text`) now resolve to the common palette. Decorative gradients, campus imagery, the institutional footer and approved hero treatments retain their specialized purposes.

## Typography

The font is Montserrat, then Arial/sans-serif. Font loading is centralized so public/legal pages no longer silently fall back to a different family when their own head omitted the font stylesheet.

| Role | Token | Size |
|---|---|---|
| Page title | `--prism-font-title` | 25.5–31.5px, fluid |
| Hero/workspace welcome | `--prism-font-hero` | 26–33px, fluid |
| Major section heading | `--prism-font-heading` | 19.5px |
| Card/section heading | `--prism-font-subheading` | 17.5px |
| Legal reading text | `--prism-font-reading` | 14.5px |
| Body/button/control | `--prism-font-body` | 13.5px |
| Navigation | `--prism-font-nav`, `--prism-font-nav-main` | 13.5px / 14.5px |
| Field/status label | `--prism-font-label`, `--prism-font-badge` | 12.5px |
| Secondary metadata | `--prism-font-meta` | 11.5px |
| Profile secondary label | `--prism-font-profile-meta` | 10.5px |
| Short eyebrow | `--prism-font-micro` | 10px |

Buttons use weight 600 and line-height 1.4. Page titles use a restrained negative tracking and approximately 1.3 line-height. Legal copy retains a larger reading size. Dashboard counts retain Batch 11's specialized numeric hierarchy rather than becoming ordinary section headings. Compact record metadata, full legal content and hero titles retain their intentional distinctions.

## Spacing, surfaces and elevation

The shared spacing scale is 4, 8, 12, 16, 20, 24 and 32px (`--prism-space-1` through `-8`, with the established step names). Toolbar/action gaps use 8px; section/card headings use 12px; ordinary panel padding uses 20px where the component needs a generic shell. Existing management cards with structured headers retain their padding ownership to avoid double padding.

| Component | Standard |
|---|---|
| Content panel | 14px radius, 1px token border, token surface, restrained shadow |
| Compact record | Existing Batch 11 10px radius and magenta edge; structured identity/status/actions/details retained |
| Input/button | 10px radius |
| Calendar date | 8px radius |
| Dialog | Token radius/border and stronger dialog shadow; existing responsive maximum sizes retained |
| Status/filter pill | Rounded pill geometry; existing semantic/count labels retained |
| Auth card | Approved Login family: 25px radius and campus background |

`--prism-shadow` is the existing restrained workspace shadow, with a darker equivalent in dark mode. `--prism-shadow-dialog` is reserved for elevated dialogs and authentication cards. No global hover transform is introduced.

## Buttons

| Variant | Class | Treatment |
|---|---|---|
| Primary | `.prism-btn.is-primary` / `.prism-btn-primary` | Approved magenta, white label |
| Secondary | `.prism-btn.is-secondary` / default `.prism-btn` | Soft pink/purple surface with readable tone and border |
| Outline/neutral | `.prism-btn.is-outline` / `.prism-btn-neutral` | Surface background, readable text, neutral border |
| Ghost/text | `.prism-btn.is-ghost` / `.prism-link-btn` | Transparent surface, accent text |
| Destructive | `.prism-btn.is-danger` | Filled semantic red |
| Destructive secondary | `.prism-btn.prism-btn-danger` | Soft semantic red |
| Admin Override | `.prism-btn.is-override` | Existing purple treatment |
| Compact | `.is-sm` / `.prism-btn-sm` | 36px minimum height, 6px × 12px padding |
| Icon | `.is-icon` and existing specialized utility controls | 36px compact target; preserved accessible names |

Ordinary form/toolbar buttons use a 44px minimum height and 10px × 16px padding. Authentication submissions retain the approved 52px size. Wrapping can increase height for long labels; labels are not truncated. Inline icons use flex alignment and an 8px gap, rather than extra icon margins.

Existing button classes (`management-primary`, `doc-primary`, `ierb-primary`, `adviser-button`, `save-task`, `cancel-edit`, report/upload controls and others explicitly listed in the shared stylesheet) are compatibility aliases. Navigation links, actionable dashboard cards, pills and calendar dates retain their specialized dimensions.

Disabled controls use the shared opacity and unavailable cursor. Loading uses the existing busy helper's labels/disabled state; the stylesheet recognizes `aria-busy` when callers provide it. No submission handler, route or authorization condition is changed. Keep Me Signed In remains intentionally unavailable.

## Forms, toolbars, menus and pagination

Text, password, search, select and textarea controls share token surfaces, borders, radius and a 44px minimum workspace height. Auth fields retain a 50px minimum. Checkbox, radio, hidden and file inputs are excluded from generic text-field sizing. Textareas retain vertical resizing. Validation uses semantic danger tokens without changing validation requirements.

Search wrappers own the border and icon; their inner input has no second border. Search/filter/action groups wrap with flex/grid, with no positional offsets. The document workflow pills occupy their own full-width toolbar row; the filter disclosure continues to own the same controls and query semantics.

Report-history headers wrap their title/count and export action into separate rows when space is limited. Recovery, registration and mandatory password fields have accessible names. Password visibility controls align with the field center. Auth cards retain the approved 430px width; onboarding retains its wider form layout.

Actions menus retain the existing fixed-position placement, keyboard traversal, authorized items and focus restoration from `PrismUI`. Their surfaces/radii use the shared tokens. Profile menu structure, notification counts, Quick Tour and unavailable-persistence control remain intact. Record pagination uses the same primary active-page color, neutral inactive controls, readable counts and existing bounded page-size choices.

The profile photo preview is now a native keyboard-focusable button that opens the existing file input. The existing change handler still renders a visit-only preview; it does not upload or persist a photo.

## Workflow status pills

`PrismUI.STATUS`, workflow predicates and state labels are unchanged. `.prism-badge.tone-*` still communicates state through icon, text and color. Approved, formally Submitted to RPMS, IERB stages, revisions and Admin Override remain independently labeled. No backend status or permission has been merged to obtain visual consistency. Long metadata and status labels keep the existing wrapping accommodations in compact records.

## Calendars

The Batch 11 Admin dashboard month view supplies the visual reference: restrained neutral dates, magenta month heading, compact rounded cells and the approved pink deadline accent. The presentation owner covers `calendarDays`, `adviserCalendarDays`, `dashboardCalendarGrid`, `monthGrid`, and the existing Admin year overview.

| State | Shared presentation |
|---|---|
| Ordinary date | Neutral surface, 8px radius, 13.5px text |
| Today | Primary accent with white text and accent underline |
| Official deadline | Soft pink surface, accent border, retained marker/count |
| Today with a deadline | Deadline surface with readable foreground **and** Today underline |
| Selected date | Distinct focus-colored inset outline |
| Outside month | Muted text and reduced opacity |
| Keyboard focus | Visible outer focus ring, separate from selection |

The legend explains the actual combined states. Standalone calendars now have the same legend as dashboard calendars. The presentation helper marks Today with `aria-current="date"` and selection with `aria-pressed`; it performs no API requests, storage operations or date calculations.

Month navigation uses the same 36px circular controls, 18px chevron SVGs and typography. Existing Today actions remain where already provided. The existing Admin Month/Year controls are preserved, with a legend visible in either mode. No new navigation or scheduling function is introduced.

Dashboard cells retain compact height. Standalone cells reserve 84px for the existing reminder previews, reducing to 58px on phones. The year overview remains deliberately compact. These density differences preserve content rather than introducing a different visual language. Admin, Adviser and Student continue using their own renderers, scoped APIs, storage keys and existing date/time handling.

## Responsive and accessibility conventions

Existing role-specific breakpoints and navigation behavior remain authoritative. Shared refinements use 600px for compact gutters/action wrapping; calendar gaps reduce from 8px to 4px. Existing compact records retain their 700px single-column transition, and role navigation retains its 1120px menu transition.

The Admin progress monitor's legacy 1300px table minimum now applies only to ordinary tables, preventing its Batch 11 compact records' Actions button from being pushed outside the viewport.

The obsolete forced mobile padding rule was removed from the legacy UI kit so each shell's responsive gutters can work. At 320px, the Admin calendar uses tighter gutters while preserving the sidebar rail and at least 24px-wide date targets. The Student dashboard uses its existing weekday header once, without a second generated weekday row. Authorized official-deadline cancellation controls use the shared destructive tone.

Focus rings use the shared theme-aware focus token. Icon controls retain their labels/titles. Native hidden attributes always remain hidden after flex/grid styling. Long labels wrap. Existing dialog containment, Escape and focus-return logic remain in their original owners. Reduced-motion preferences disable decorative animation, transitions and smooth scrolling throughout applicable shells, including pseudo-elements.

Browser coverage and limitations are recorded separately in [the test report](PRISM_FRONTEND_STANDARDIZATION_TEST_REPORT.md). This document describes ownership and intended component rules; executed evidence, rather than this specification alone, determines acceptance.
