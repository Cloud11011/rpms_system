// F02 investigation: repeat the exact Batch 6 responsive-matrix interaction that failed once in 7R
// (maintenance/ierb-ui-tests/integration.cjs:385: Admin opens ierbprog.php, clicks #addIerbEntry, waits for #ierbEntryModal).
// Same context options, init script, font routing, networkidle wait and "close the hover sidebar" step as the test's open().
// On any miss it captures DOM, sidebar, focus, click log, reload count, listener state (CDP) and a screenshot, then retries the click.
// usage: node b6-entry-stress.cjs <iterations> <out-dir> [variant]
//   variant "test" (default) reproduces open() exactly; "nomove" skips the mouse.move/Escape step.
// Env: PRISM_TEST_REVIEW_DIR, PRISM_TEST_DB_PORT, PRISM_TEST_APP_PORT, PRISM_TEST_DB_COLLATION, PRISM_TEST_PLAYWRIGHT (as the runner).
const fs = require('node:fs'), path = require('node:path'), cp = require('node:child_process'), crypto = require('node:crypto');
const root = 'C:/PRISM_Workspaces/prism-v13-release-clean/.batch7r-worktree';
const review = process.env.PRISM_TEST_REVIEW_DIR, app = path.join(review, 'sandbox/app'), appPort = process.env.PRISM_TEST_APP_PORT, base = 'http://127.0.0.1:' + appPort;
const php = 'C:/xampp/php/php.exe', playwright = require(process.env.PRISM_TEST_PLAYWRIGHT);
const [iterations = '100', out = '.', variant = 'test'] = process.argv.slice(2);
fs.mkdirSync(out, { recursive: true });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const guide = value => { const dot = value.indexOf('.'); return `prismTour.${value.slice(0, dot)}.${crypto.createHash('sha256').update(value.slice(dot + 1)).digest('hex')}.v1`; };
const guideKeys = ['admin.admin|1', 'adviser.adviser|2', 'student.Student|S4'].map(guide);
(async () => {
  const lock = path.join(review, 'sandbox/run.lock'); const fd = fs.openSync(lock, 'wx'); fs.writeSync(fd, String(process.pid));
  const server = cp.spawn(php, ['-d', 'extension=zip', '-d', 'auto_prepend_file=' + path.join(root, 'maintenance/ierb-group-tests/runtime.php'), '-d', 'disable_functions=mail,curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_errno,curl_error,curl_close', '-d', 'session.save_path=' + path.join(app, 'sessions'), '-d', 'upload_tmp_dir=' + path.join(app, 'uploads'), '-S', '127.0.0.1:' + appPort, '-t', app], { windowsHide: true });
  let serverLog = ''; server.stdout.on('data', d => serverLog += d); server.stderr.on('data', d => serverLog += d);
  let browser; const results = { variant, iterations: Number(iterations), attempts: 0, misses: [], loadStalls: [], recoveredOnRetry: 0, durationsMs: [] };
  try {
    for (let n = 0; n < 60; n++) { try { if ((await fetch(base + '/login.php')).ok) break; } catch (_) {} await sleep(100); }
    const admin = await playwright.request.newContext({ baseURL: base });
    const r = await admin.post('/login_process.php', { form: { email: 'admin@example.test', password: 'SyntheticPass123!' }, headers: { origin: base }, maxRedirects: 0 });
    if (r.status() !== 302) throw Error('Admin login failed ' + r.status());
    const storageState = await admin.storageState();
    browser = await playwright.chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const widths = [390, 768, 1024, 1440, 1920];
    for (let i = 0; i < Number(iterations); i++) {
      const width = widths[i % widths.length], theme = Math.floor(i / widths.length) % 2 ? 'dark' : 'light';
      const ctx = await browser.newContext({ viewport: { width, height: 1000 }, storageState, reducedMotion: 'reduce', hasTouch: width === 390 });
      await ctx.addInitScript(({ theme, keys }) => {
        localStorage.setItem('prismTheme', theme); keys.forEach(k => localStorage.setItem(k, 'seen'));
        try { sessionStorage.setItem('__diagLoads', String(Number(sessionStorage.getItem('__diagLoads') || 0) + 1)); } catch (_) {}
        window.__diag = { clicks: [], errors: [], keys: [], start: Date.now() };
        addEventListener('click', e => __diag.clicks.push({ t: Date.now() - __diag.start, id: e.target.id, tag: e.target.tagName, cls: String(e.target.className).slice(0, 80), trusted: e.isTrusted, x: e.clientX, y: e.clientY }), true);
        addEventListener('keydown', e => __diag.keys.push({ t: Date.now() - __diag.start, key: e.key, target: e.target.id || e.target.tagName }), true);
        addEventListener('error', e => __diag.errors.push(String(e.message)));
      }, { theme, keys: guideKeys });
      await ctx.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com)\//, route => route.fulfill({ contentType: 'text/css', body: '' }));
      const page = await ctx.newPage(); const errors = [], consoleLines = [], requests = [];
      page.on('pageerror', e => errors.push(String(e))); page.on('console', m => consoleLines.push(m.type() + ': ' + m.text()));
      page.on('requestfinished', q => requests.push({ t: Date.now(), url: q.url().replace(base, ''), method: q.method() }));
      page.on('framenavigated', f => { if (f === page.mainFrame()) requests.push({ t: Date.now(), navigated: f.url().replace(base, '') }); });
      const pending = new Map(); page.on('request', q => pending.set(q, Date.now())); page.on('requestfinished', q => pending.delete(q)); page.on('requestfailed', q => pending.delete(q));
      const t0 = Date.now(), logStart = serverLog.length;
      try { await page.goto(base + '/ierbprog.php'); await page.waitForLoadState('networkidle'); }
      catch (error) {
        // A page that never loads is a separate failure mode: record what was still in flight and what the server logged.
        results.loadStalls.push({ iteration: i, width, theme, error: String(error).split('\n')[0], unfinished: [...pending].map(([q, t]) => ({ url: q.url().replace(base, ''), method: q.method(), type: q.resourceType(), sinceMs: t - t0 })),
          serverLog: serverLog.slice(logStart).split('\n').slice(-40) });
        console.log('LOAD STALL ' + JSON.stringify(results.loadStalls.at(-1)).slice(0, 2500));
        await ctx.close(); continue;
      }
      if (variant === 'test') { await page.mouse.move(width - 20, 60); await page.keyboard.press('Escape'); }
      const clickAt = Date.now();
      await page.click('#addIerbEntry');
      results.attempts++;
      try { await page.locator('#ierbEntryModal').waitFor({ state: 'visible', timeout: 8000 }); results.durationsMs.push(Date.now() - t0); }
      catch (error) {
        const cdp = await ctx.newCDPSession(page);
        const { result } = await cdp.send('Runtime.evaluate', { expression: "document.getElementById('addIerbEntry')" });
        const listeners = result.objectId ? (await cdp.send('DOMDebugger.getEventListeners', { objectId: result.objectId })).listeners.map(l => ({ type: l.type, line: l.lineNumber, script: l.scriptId })) : null;
        const state = await page.evaluate(() => {
          const b = document.getElementById('addIerbEntry'), m = document.getElementById('ierbEntryModal'), sb = document.querySelector('.prism-sidebar'), r = b?.getBoundingClientRect();
          const hit = r ? document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2) : null;
          return { url: location.pathname, readyState: document.readyState, loads: sessionStorage.getItem('__diagLoads'), nav: performance.getEntriesByType('navigation').map(e => e.type),
            modal: m && { display: m.style.display, computed: getComputedStyle(m).display, ariaHidden: m.getAttribute('aria-hidden') }, button: r && { rect: r.toJSON(), disabled: b.disabled, hit: hit && (hit.id || hit.className || hit.tagName) },
            sidebar: sb && { cls: sb.className, backdropHidden: document.querySelector('.prism-sidebar-backdrop')?.hidden }, active: document.activeElement?.id || document.activeElement?.tagName,
            openDialogs: [...document.querySelectorAll('dialog[open],[aria-modal="true"]')].map(d => d.className || d.id), diag: window.__diag, scroll: [scrollX, scrollY] };
        });
        const shot = path.join(out, `miss-${i}-${width}-${theme}.png`); await page.screenshot({ path: shot });
        await page.click('#addIerbEntry');
        let recovered = false; try { await page.locator('#ierbEntryModal').waitFor({ state: 'visible', timeout: 5000 }); recovered = true; results.recoveredOnRetry++; } catch (_) {}
        results.misses.push({ iteration: i, width, theme, clickAfterLoadMs: clickAt - t0, listeners, state, errors, console: consoleLines.slice(-20), requests: requests.map(q => ({ ...q, t: q.t - t0 })), recovered, screenshot: path.basename(shot) });
        console.log('MISS ' + JSON.stringify(results.misses.at(-1)).slice(0, 1500));
      }
      if (errors.length) (results.pageErrors ||= []).push({ iteration: i, width, theme, errors });
      await ctx.close();
      if ((i + 1) % 25 === 0) console.log(`${i + 1}/${iterations} attempts=${results.attempts} misses=${results.misses.length}`);
    }
  } finally {
    if (browser) await browser.close(); server.kill(); fs.closeSync(fd); fs.unlinkSync(lock);
    const d = results.durationsMs.sort((a, b) => a - b); results.durationP50 = d[Math.floor(d.length / 2)]; results.durationMax = d.at(-1); delete results.durationsMs;
    fs.writeFileSync(path.join(out, `stress-${variant}.json`), JSON.stringify(results, null, 2));
    fs.writeFileSync(path.join(out, `server-${variant}.log`), serverLog);
    console.log(JSON.stringify({ variant, attempts: results.attempts, misses: results.misses.length, loadStalls: results.loadStalls.length, recoveredOnRetry: results.recoveredOnRetry, pageErrors: (results.pageErrors || []).length }));
  }
})().catch(e => { console.error(e); process.exitCode = 1; });
