const fs = require('node:fs'), path = require('node:path'), crypto = require('node:crypto');
module.exports = async h => {
  const { check, playwright, sessions, review, base, sql, group } = h;
  const browser = await playwright.chromium.launch({ executablePath: process.env.PRISM_TEST_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
  const screenshots = path.join(review, 'screenshots'); fs.mkdirSync(screenshots, { recursive: true });
  const errors = [], matrix = [];
  async function prepareContext(ctx, role, theme = 'light') {
    const identity = crypto.createHash('sha256').update(`${role}|${role === 'admin' ? 1 : 2}`).digest('hex');
    await ctx.addInitScript(({ role, theme, identity }) => { localStorage.setItem('prismTheme', theme); localStorage.setItem(`prismTour.${role}.${identity}.v1`, 'seen'); }, { role, theme, identity });
    await ctx.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)\//, route => route.fulfill({ contentType: 'text/css', body: '' }));
  }
  try {
    for (const theme of ['light', 'dark']) for (const width of [390,768,1024,1440,1920]) {
      group.reset([4,5,6]);
      const ctx = await browser.newContext({ viewport: { width, height: 1000 }, storageState: await sessions.admin.storageState(), reducedMotion: 'reduce' });
      await prepareContext(ctx, 'admin', theme);
      const page = await ctx.newPage(); page.on('pageerror', e => errors.push(String(e)));
      await page.goto(base + '/ierbprog.php'); await page.locator('#ierbGroupSelect:not([disabled])').waitFor();
      await page.mouse.move(width - 20, 60); await page.keyboard.press('Escape');
      await page.selectOption('#ierbGroupSelect', await group.key());
      await page.locator('#ierbGroupReview:not([disabled])').waitFor();
      await page.locator('#ierbTableBody[aria-busy="false"]').waitFor();
      await page.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
      const geometry = await page.evaluate(() => {
        const section = document.querySelector('#ierbGroupAdvancement'), footer = document.querySelector('.ceu-footer'), detailed = document.querySelector('.ierb-directory');
        return { next: section.nextElementSibling === footer, order: detailed.getBoundingClientRect().bottom <= section.getBoundingClientRect().top, overflow: document.documentElement.scrollWidth > innerWidth, memberOverflow: section.scrollWidth > section.clientWidth, dark: document.documentElement.classList.contains('dark-theme'), width: innerWidth, scroll: document.documentElement.scrollWidth, outside: [...document.querySelectorAll('body *')].filter(e => e.getBoundingClientRect().right > innerWidth + 1).slice(0,12).map(e=>({tag:e.tagName,id:e.id,class:e.className,right:e.getBoundingClientRect().right})) };
      });
      check(geometry.next && geometry.order, width + ' ' + theme + ': section follows Detailed Progress immediately before CEU footer');
      if (geometry.overflow || geometry.memberOverflow) await page.screenshot({ path: path.join(screenshots, `${theme}-${width}-overflow.png`), fullPage: true });
      check(!geometry.overflow && !geometry.memberOverflow && geometry.dark === (theme === 'dark'), width + ' ' + theme + ': no page/member overflow and correct theme ' + JSON.stringify(geometry));
      check(await page.locator('#ierbGroupPreview .ierb-group-members li').count() === 3, width + ' ' + theme + ': readable authoritative three-member preview');
      await page.locator('#ierbGroupAdvancement').screenshot({ path: path.join(screenshots, `${theme}-${width}-section.png`) });
      await page.click('#ierbGroupReview');
      const dialog = page.getByRole('dialog', { name: 'Advance Research Group to Next IERB Stage?' }); await dialog.waitFor();
      check(await dialog.locator('li').count() === 3 && await dialog.locator('#prismReason').evaluate(e => e === document.activeElement), width + ' ' + theme + ': accessible confirmation names every member and focuses reason');
      await dialog.screenshot({ path: path.join(screenshots, `${theme}-${width}-confirmation.png`) });
      const modalSize = await dialog.evaluate(e => ({ width: e.getBoundingClientRect().width, overflow: e.scrollWidth > e.clientWidth }));
      check(modalSize.width <= width && !modalSize.overflow, width + ' ' + theme + ': confirmation remains within narrow viewport');
      // The scrollable member list is keyboard accessible; Shift-Tab wraps at its beginning.
      await dialog.locator('.ierb-group-members').focus();
      await page.keyboard.press('Shift+Tab');
      check(await dialog.locator('[data-act="ok"]').evaluate(e => e === document.activeElement), width + ' ' + theme + ': keyboard focus trapped inside confirmation');
      await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
      await page.getByText('Group advancement canceled.', { exact: false }).waitFor();
      check(await page.locator('#ierbGroupReview').isDisabled() && await page.locator('#ierbGroupRefresh').evaluate(e => e === document.activeElement), width + ' ' + theme + ': cancel invalidates preview and restores focus to refresh');
      check(sql("SELECT id FROM students WHERE id IN (4,5,6) AND stage='Stage 1'").length === 3, width + ' ' + theme + ': cancel changes no official record');
      matrix.push({ width, theme, section: 'PASS', modal: 'PASS', overflow: false, cancel: 'PASS', keyboard: 'PASS' });
      await ctx.close();
    }
    group.reset([4,5,6]);
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 }, storageState: await sessions.admin.storageState() });
    await prepareContext(ctx, 'admin');
    const page = await ctx.newPage(); page.on('pageerror', e => errors.push(String(e)));
    await page.goto(base + '/ierbprog.php'); await page.locator('#ierbGroupSelect:not([disabled])').waitFor();
    await page.mouse.move(1400, 60); await page.keyboard.press('Escape');
    await page.selectOption('#ierbGroupSelect', await group.key()); await page.locator('#ierbGroupReview:not([disabled])').waitFor();
    await page.click('#ierbGroupReview');
    let dialog = page.getByRole('dialog', { name: 'Advance Research Group to Next IERB Stage?' });
    await dialog.getByRole('button', { name: 'Confirm Group Advancement' }).click();
    check(await dialog.locator('.prism-field-error').innerText() !== '', 'Browser rejects empty mandatory reason without closing confirmation');
    await dialog.locator('#prismReason').fill('All group members reviewed by RPMS for the next stage.');
    let posts = 0; page.on('request', r => { if (r.url().endsWith('ierb_api.php?action=group_advance')) posts++; });
    await page.route('**/ierb_api.php?action=group_advance', async route => { await h.sleep(400); await route.continue(); });
    await dialog.getByRole('button', { name: 'Confirm Group Advancement' }).click();
    check(await page.locator('#ierbGroupReview').isDisabled() && await page.locator('#ierbGroupSelect').isDisabled(), 'Browser disables decision and group controls during confirmed submission');
    await page.getByText('3 Students advanced to Stage 2', { exact: false }).waitFor();
    check(posts === 1 && sql("SELECT id FROM students WHERE id IN (4,5,6) AND stage='Stage 2'").length === 3, 'Browser submits exactly one confirmed group decision and all Students advance');
    check(await page.locator('#manageIerbStages').count() === 1, 'Manage IERB Stages remains in the page header');
    await page.click('#manageIerbStages'); await page.getByRole('dialog', { name: 'Manage IERB Stages' }).waitFor();
    check(await page.locator('#prismStageGuidance').count() === 1, 'Existing Stage Guidance editor opens after group operation');
    await page.getByRole('button', { name: 'Close', exact: true }).click();
    await ctx.close();
    const adviser = await browser.newContext({ viewport: { width: 390, height: 900 }, storageState: await sessions.adviser.storageState() });
    await prepareContext(adviser, 'adviser');
    const adviserPage = await adviser.newPage(); await adviserPage.goto(base + '/ierbprog.php');
    check(await adviserPage.locator('#ierbGroupAdvancement,#ierbGroupReview,#ierbGroupSelect').count() === 0, 'Adviser HTML contains no administrative group advancement controls');
    check(await adviserPage.locator('#ierbTableBody').count() === 1, 'Adviser individual progress directory remains available');
    await adviser.close();
    check(errors.length === 0, 'All browser runs complete without JavaScript page errors: ' + errors.join('; '));
    fs.writeFileSync(path.join(review, 'responsive-results.json'), JSON.stringify(matrix, null, 2));
  } finally { await browser.close(); }
};
