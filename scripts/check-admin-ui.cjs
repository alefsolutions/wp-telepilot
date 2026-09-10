const { chromium } = require('../.test-env/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');

(async () => {
  const browser = await chromium.launch({ channel: process.platform === 'win32' ? 'msedge' : 'chromium', headless: true });
  try {
    for (const width of [1440, 390]) {
      const page = await browser.newPage({ viewport: { width, height: 1000 } });
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.route('**/*', route => route.abort());
      await page.setContent(fs.readFileSync(path.join(__dirname, '../.test-env/admin-qa.html'), 'utf8'));
      for (const tab of ['telegram', 'security', 'notifications', 'logging', 'linking', 'ai']) {
        const button = page.locator(`[data-tab="${tab}"]`);
        if (!await button.count()) throw new Error(`Missing ${tab} tab`);
        await button.click();
        if (await button.getAttribute('aria-selected') !== 'true') throw new Error('Tab selection failed');
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
        if (overflow) {
          console.error(await page.evaluate(() => Array.from(document.querySelectorAll('body *')).filter(node => node.getBoundingClientRect().right > innerWidth + 1).slice(0, 12).map(node => ({ tag: node.tagName, class: node.className, width: node.getBoundingClientRect().width }))));
          throw new Error(`Horizontal overflow at ${width}px in ${tab}`);
        }
      }
      await page.locator('[data-panel="ai"]').scrollIntoViewIfNeeded();
      await page.evaluate(() => window.scrollTo(0, document.querySelector('[data-panel="ai"]').getBoundingClientRect().top + window.scrollY - 30));
      await page.screenshot({ path: path.join(__dirname, `../.test-env/admin-${width}.png`) });
      await page.locator('[data-tab="ai"]').focus();
      await page.keyboard.press('Home');
      if (await page.locator('.telepilot-tab').first().getAttribute('aria-selected') !== 'true') throw new Error('Keyboard tabs failed');
      if (errors.length) throw new Error(errors.join('\n'));
      console.log(`Admin ${width}px: tabs, keyboard, overflow and JavaScript checks passed.`);
      await page.close();
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
