# PRISM Login/Register visual refinement

Completed locally on `prism-v2-final-v8`, with HEAD still
`d4b9ac6fb3d1ef2f8d275ece9ea08fda84b5214c`. Existing uncommitted work,
including Item 8 remediation, was preserved. No commit, push, merge, tag, or
deployment was performed.

The supplied attachment contained the written brief only. Its palette and
proportions, plus the actual `assets/images/prismlogo1.png`, were used as the
visual reference. Screenshot-to-screenshot matching could not be assessed
without the reference screenshot files.

## Files changed by this task

| File | Change |
| --- | --- |
| `login.php` | Scoped body class, separated account note, button wording, accessible password-eye button. |
| `register.php` | Scoped body class, 12+ password placeholder, full-name placeholder, accessible password buttons, removed Back action, disabled-state Login wording. |
| `assets/css/style.css` | One final scoped Login/Register design block; legacy styles retained. |
| `assets/js/script.js` | Password toggle responds to the whole control and updates button accessibility state; existing span/icon controls remain supported. |
| `tools/verify-auth-visual.cjs` | Repeatable isolated browser verification of real PHP templates, responsive layout, controls, POST contracts, and shared-style preservation. |
| `PRISM_AUTH_VISUAL_REFINEMENT_REPORT.md` | This report. |

Generated verification artifacts: `tests/auth-visual-results/` contains 23
screenshots and five JSON files. The existing reset browser suite also refreshed
`tests/release-candidate-results/password-reset-browser.json`.

## Final visual values

| Requirement | Final value |
| --- | --- |
| Login card width | `width:100%; max-width:430px`, with safe page padding. |
| Register card width | `width:100%; max-width:430px`, with safe page padding and vertical scrolling. |
| Card surface | `rgba(255,255,255,.96)` — 96% opaque white. |
| Card radius / blur / shadow | `25px`; `blur(10px)`; `0 15px 40px rgba(0,0,0,.25)`. |
| Card padding | Desktop: `30px 30px 35px`; narrow screens: `30px 25px`. |
| Overlay | `linear-gradient(rgba(12,14,63,.45),rgba(238,18,128,.20))`. |
| Background image | Original `assets/images/ceu_bg.png`; covers the scrolling page. |
| Logo | Original `assets/images/prismlogo1.png`, centered, 220px wide, proportional height. Original transparent canvas spacing is compensated in CSS. |
| PRISM text gradient | `linear-gradient(90deg,#DE4B9E 0%,#BC65B3 50%,#8585C7 100%)`. |
| Gradient direction | Visually inspected: pink → purple → lavender. The original navy → pink gradient is overridden on Login only. |
| Gradient scope / fallback | Only the word PRISM; both background-clip declarations retained. Unsupported browsers receive readable `#DE4B9E` text. |
| Heading / punctuation | `#222222`, including “Welcome to” and `!`. |
| System subtitle | `#0C0E3F`, font weight 600. |
| University line | Neutral `#666666`. |
| Primary buttons | Default `#0C0E3F`; hover `#EE1280`; text `#FFFFFF`; height 52px; radius 50px. |
| Inputs | Background `#F3F3F3`; height 50px; radius 50px; default 1px transparent border. |
| Input hover / focus / focus-visible | Border `#EE1280`, constant border width; focus outline `2px solid rgba(238,18,128,.25)`, offset 2px. |
| Input text / placeholder | `#222222` / `#777777`. |
| Input icons | `#888888`. Password controls have 44×44px hit areas. |
| Links | Register, Login, and Forgot Password use `#EE1280`; account-navigation links have weight 700. Forgot Password is right aligned. |
| RPMS helper | `#555555`. Only its clickable Register word is pink. |
| Adviser/student note | Own `.login-account-note` rule: `#888888`, 12.5px, line-height 1.5, centered. |

## Navigation and authentication preservation

- Register's normal Back action was removed. Normal account navigation retains
  `Login` → `login.php`.
- `ADMIN_REGISTRATION_CODE === ''` still hides registration and supplies a simple
  `Login` → `login.php` link; neither state displays Back wording.
- The password placeholder reads `Password (12+ characters)`. Both registration
  password fields retain `minlength="12"`, `maxlength="200"`, `required`, and
  `autocomplete="new-password"`.
- Login remains POST → `login_process.php`; Register remains POST →
  `register_process.php`.
- Browser comparisons against HEAD confirmed every existing input attribute
  other than presentation-only placeholder wording was preserved. Field names,
  including all six registration names, are unchanged.
- PHP bootstrap/session/redirect code in Login and Register is unchanged after
  normalizing Windows line endings. Authentication handlers, password hashing,
  domain validation, private registration-code validation, password policy,
  password-reset handlers, and session logic were not edited.
- Escaped server error rendering and session flash behavior are preserved.
  The Forgot Password link still targets `forgot_password.php`.
- No prototype authentication, demo accounts, browser-storage account logic, or
  GET authentication was introduced.
- The added CSS is scoped to `body.unified-login` and its descendants. Only Login
  and Register receive that body class.

## Responsive results

All sizes passed for Login, enabled Register, and disabled Register. Card widths
below account for Windows' vertical scrollbar when the page scrolls.

| Viewport | Login width | Register width | Register scroll needed |
| --- | ---: | ---: | --- |
| 1920×1080 | 430px | 430px | No |
| 1366×768 | 430px | 430px | Yes |
| 1024×768 | 430px | 430px | Yes |
| 768×1024 | 430px | 430px | No |
| 390×844 | 358px | 358px | No |
| 375×812 | 343px | 343px | No |
| 320×568 | 273px | 273px | Yes |

Verified no horizontal overflow, fully reachable content, horizontal centering,
centered original logo, no visible logo/title collision, unclipped Login heading
and punctuation, correct gradient and colors, aligned inputs, full-width primary
buttons, wrapping helper/error text, constant dimensions on input hover/focus,
and mouse/keyboard password toggles. Login also scrolls at 320×568. Disabled
Register retains reachable Login navigation at every size.

Montserrat loaded on all pages. Font Awesome loaded on all pages using icons;
the disabled-registration state does not use icon elements.

## Verification results

| Check | Result |
| --- | --- |
| `node tools/verify-auth-visual.cjs` | 418 checks passed; no browser/PHP fixture errors. |
| `php tests/auth-flows.php` | 48 authentication/registration/reset checks passed. |
| `php tests/auth-audit.php` | All 16 session/role/password-change guard checks passed on rerun. Initial run hit the existing clock-sensitive exactly-1800-second boundary. |
| `node tests/password-reset-browser.cjs` | 34 HTTPS reset/setup browser checks; zero failures. |
| `php -d extension=zip tests/hardening-audit.php` | 114 checks passed, including the 12-character minimum, token rules, and throttling. ZIP was enabled for this command only; default invocation stopped at its missing-ZIP prerequisite. |
| Shared auth style regression | Forgot Password, Reset Password, and Change Password Required computed styles match the original stylesheet. Legacy reset password-eye control still works. |
| PHP syntax | Passed for Login, Register, their process handlers, Forgot Password, Reset Password, Update Password, and Change Password Required. |
| JavaScript syntax | Passed for `assets/js/script.js` and `tools/verify-auth-visual.cjs`. |
| `git diff --check` | Passed. |

Browser checks render the real PHP templates with isolated configuration/session
fixtures and use the real local CSS, JS, images, and external fonts/icons. POST
contract checks use an isolated receiver. Authentication/reset regression suites
exercise real endpoint source with fixture database/session/mail operations;
they do not create live accounts or send email.

Screenshots and full measurements are available under `tests/auth-visual-results/`.
The work is ready for local review.
