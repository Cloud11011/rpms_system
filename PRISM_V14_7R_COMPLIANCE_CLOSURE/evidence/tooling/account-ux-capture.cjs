// F06: Admin account page review (Student and Adviser pages are covered by the Batch 6 suite in this pass).
// Checks labels, read-only presentation, password controls, Save gating, overflow and theme at 390/1440 px; writes screenshots.
// usage: node account-ux-capture.cjs <out-dir> [email]   Env: PRISM_TEST_REVIEW_DIR, PRISM_TEST_APP_PORT, PRISM_TEST_DB_PORT, PRISM_TEST_DB_COLLATION, PRISM_TEST_PLAYWRIGHT
const fs = require('node:fs'), path = require('node:path'), cp = require('node:child_process'), crypto = require('node:crypto');
const root = 'C:/PRISM_Workspaces/prism-v13-release-clean/.batch7r-worktree';
const review = process.env.PRISM_TEST_REVIEW_DIR, app = path.join(review, 'sandbox/app'), base = 'http://127.0.0.1:' + process.env.PRISM_TEST_APP_PORT;
const playwright = require(process.env.PRISM_TEST_PLAYWRIGHT);
const [out, email = 'admin@example.test'] = process.argv.slice(2);
fs.mkdirSync(out, { recursive: true });
const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => {
  const server = cp.spawn('C:/xampp/php/php.exe', ['-d', 'extension=zip', '-d', 'auto_prepend_file=' + path.join(root, 'maintenance/ierb-group-tests/runtime.php'), '-d', 'disable_functions=mail,curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_errno,curl_error,curl_close', '-d', 'session.save_path=' + path.join(app, 'sessions'), '-S', '127.0.0.1:' + process.env.PRISM_TEST_APP_PORT, '-t', app], { windowsHide: true });
  let browser; const results = { email, pages: [] };
  try {
    for (let n = 0; n < 60; n++) { try { if ((await fetch(base + '/login.php')).ok) break; } catch (_) {} await sleep(100); }
    const api = await playwright.request.newContext({ baseURL: base });
    const r = await api.post('/login_process.php', { form: { email, password: 'SyntheticPass123!' }, headers: { origin: base }, maxRedirects: 0 });
    if (r.status() !== 302) throw Error('login failed ' + r.status());
    const user = await (await api.get('/profile_api.php?action=me')).json().catch(() => ({}));
    const tour = 'prismTour.admin.' + crypto.createHash('sha256').update('admin|1').digest('hex') + '.v1';
    browser = await playwright.chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    for (const theme of ['light', 'dark']) for (const width of [390, 1440]) {
      const ctx = await browser.newContext({ viewport: { width, height: 1000 }, storageState: await api.storageState(), reducedMotion: 'reduce', hasTouch: width === 390 });
      await ctx.addInitScript(({ theme, tour }) => { localStorage.setItem('prismTheme', theme); localStorage.setItem(tour, 'seen'); }, { theme, tour });
      await ctx.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com)\//, route => route.fulfill({ contentType: 'text/css', body: '' }));
      const page = await ctx.newPage(); const errors = []; page.on('pageerror', e => errors.push(String(e)));
      await page.goto(base + '/account.php'); await page.waitForLoadState('networkidle');
      await page.waitForFunction(() => document.getElementById('accountName')?.value !== '');
      const facts = await page.evaluate(() => {
        const controls = [...document.querySelectorAll('main input:not([type=hidden]), main select, main textarea')].filter(e => e.getClientRects().length);
        const labelled = e => !!(e.labels?.length || e.getAttribute('aria-label') || e.getAttribute('aria-labelledby'));
        const ro = controls.filter(e => e.readOnly);
        return {
          controls: controls.length, unlabelled: controls.filter(e => !labelled(e)).map(e => e.id || e.name),
          readOnly: ro.map(e => ({ id: e.id, dashed: getComputedStyle(e).borderStyle === 'dashed', note: !!(e.getAttribute('aria-describedby') && document.getElementById(e.getAttribute('aria-describedby').split(' ')[0])) })),
          saveDisabled: document.querySelector('#accountProfileForm [type=submit]')?.disabled,
          passwordToggles: [...document.querySelectorAll('button[aria-controls^=account][aria-pressed]')].map(b => ({ label: b.getAttribute('aria-label'), pressed: b.getAttribute('aria-pressed') })),
          requiredMarked: [...document.querySelectorAll('.prism-required-mark[data-prism-mark]')].filter(m => m.getClientRects().length).length,
          overflow: document.documentElement.scrollWidth > innerWidth + 1, dark: document.documentElement.classList.contains('dark-theme'),
          heading: document.querySelector('main h1')?.textContent.trim(), roleChip: document.querySelector('.prism-role-chip')?.innerText.trim() || null,
        };
      });
      const shot = `account-admin-${width}-${theme}.png`; await page.screenshot({ path: path.join(out, shot), fullPage: width === 390 });
      results.pages.push({ width, theme, ...facts, errors, screenshot: shot });
      await ctx.close();
    }
    const all = results.pages;
    results.checks = {
      everyVisibleControlLabelled: all.every(p => !p.unlabelled.length),
      readOnlyFieldsDashedWithNote: all.every(p => p.readOnly.length > 0 && p.readOnly.every(f => f.dashed && f.note)),
      saveDisabledUntilChange: all.every(p => p.saveDisabled === true),
      passwordTogglesNamedWithState: all.every(p => p.passwordToggles.length >= 2 && p.passwordToggles.every(t => /^Show /.test(t.label) && t.pressed === 'false')),
      requiredMarkersPresent: all.every(p => p.requiredMarked > 0), noHorizontalOverflow: all.every(p => !p.overflow),
      themeApplied: all.every(p => p.dark === (p.theme === 'dark')), noPageErrors: all.every(p => !p.errors.length),
    };
    results.status = Object.values(results.checks).every(Boolean) ? 'PASS' : 'FAIL';
  } finally {
    if (browser) await browser.close(); server.kill();
    fs.writeFileSync(path.join(out, 'account-admin-review.json'), JSON.stringify(results, null, 2));
    console.log(JSON.stringify({ status: results.status, checks: results.checks }));
  }
})().catch(e => { console.error(e); process.exitCode = 1; });
