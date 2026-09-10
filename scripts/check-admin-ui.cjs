const { chromium } = require('../.test-env/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');

(async () => {
  const browser = await chromium.launch({ channel: process.platform === 'win32' ? 'msedge' : 'chromium', headless: true });
  try {
    for (const width of [320, 360, 390, 480, 768, 1024, 1440, 1920]) {
      const page = await browser.newPage({ viewport: { width, height: 1000 } });
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      // Fixture submissions never contact a site or provider.
      await page.route('**/*', route => route.abort());
      await page.setContent(fs.readFileSync(path.join(__dirname, '../.test-env/admin-qa.html'), 'utf8'));
      for (const tab of ['telegram', 'security', 'notifications', 'logging', 'linking', 'ai']) {
        const button = page.locator(`[data-tab="${tab}"]`);
        if (!await button.count()) throw new Error(`Missing ${tab} tab`);
        await button.click();
        if (await button.getAttribute('aria-selected') !== 'true') throw new Error('Tab selection failed');
        assert.equal(await page.locator('[role="tabpanel"]:visible').count(), 1);
        assert.equal(await page.locator('[name="telepilot_test_ai_connection"]').isVisible(), tab === 'ai');
        const controls = await page.locator('.telepilot-admin .button:visible').evaluateAll(es => es.map(e => {
          const s = getComputedStyle(e);
          return { height: e.getBoundingClientRect().height, radius: s.borderRadius, padding: s.padding,
            font: s.fontSize, weight: s.fontWeight, clipped: e.scrollWidth > e.clientWidth + 1 };
        }));
        for (const control of controls) {
          assert.ok(control.height >= 44, `Small touch target at ${width}px in ${tab}`);
          assert.equal(control.radius, '999px');
          assert.equal(control.padding, '11px 18px');
          assert.equal(control.font, '14px');
          assert.equal(control.weight, '700');
          assert.equal(control.clipped, false, `Clipped button text at ${width}px in ${tab}`);
        }
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
        if (overflow) {
          console.error(await page.evaluate(() => Array.from(document.querySelectorAll('body *')).filter(node => node.getBoundingClientRect().right > innerWidth + 1).slice(0, 12).map(node => ({ tag: node.tagName, class: node.className, width: node.getBoundingClientRect().width }))));
          throw new Error(`Horizontal overflow at ${width}px in ${tab}`);
        }
        if ([390, 1440].includes(width) && tab !== 'ai') {
          await page.locator(`[data-panel="${tab}"]`).screenshot({ path: path.join(__dirname, `../.test-env/admin-${width}-${tab}.png`) });
        }
      }
      const testButton = page.locator('[name="telepilot_test_ai_connection"]');
      const forms = await testButton.evaluate(button => {
        const save = document.querySelector('#submit');
        return { testForm: button.form.id, saveAction: save.form.getAttribute('action'),
          sameForm: button.form === save.form, testFields: Array.from(new FormData(button.form).keys()),
          saveFields: Array.from(new FormData(save.form).keys()), action: button.form.action };
      });
      assert.equal(forms.testForm, 'telepilot-ai-test-form');
      assert.equal(forms.sameForm, false);
      assert.equal(forms.saveAction, 'options.php');
      assert.ok(forms.testFields.includes('telepilot_tools_nonce'));
      assert.ok(!forms.testFields.some(name => name.startsWith('telepilot_settings[')));
      assert.ok(forms.saveFields.includes('telepilot_settings[ai_model]'));
      assert.ok(forms.action.endsWith('#telepilot-panel-ai'));
      await testButton.focus();
      assert.equal(await testButton.evaluate(e => getComputedStyle(e).outlineWidth), '3px');
      await testButton.evaluate(e => { e.disabled = true; });
      assert.equal(await testButton.evaluate(e => getComputedStyle(e).cursor), 'not-allowed');
      await testButton.evaluate(e => { e.disabled = false; });
      await page.evaluate(() => {
        window.qaSubmissions = [];
        document.addEventListener('submit', event => {
          event.preventDefault();
          window.qaSubmissions.push({ form: event.target.id, submitter: event.submitter.name });
        });
      });
      await testButton.click();
      assert.deepEqual(await page.evaluate(() => window.qaSubmissions), [
        { form: 'telepilot-ai-test-form', submitter: 'telepilot_test_ai_connection' },
      ]);
      await page.locator('#submit').click();
      assert.equal(await page.evaluate(() => window.qaSubmissions[1].submitter), 'submit');
      const tables = await page.locator('.telepilot-table-scroll').evaluateAll(es => es.map(e => ({
        focusable: e.tabIndex === 0, label: e.getAttribute('aria-label'), overflow: getComputedStyle(e).overflow,
        vertical: e.scrollHeight > e.clientHeight, horizontal: e.scrollWidth > e.clientWidth,
      })));
      assert.equal(tables.length, 2);
      for (const table of tables) {
        assert.ok(table.focusable && table.label && table.vertical);
        assert.equal(table.overflow, 'auto');
        if (width <= 480) assert.ok(table.horizontal);
      }
      if ([390, 1440].includes(width)) {
        await page.locator('.telepilot-actions .telepilot-button-row').screenshot({ path: path.join(__dirname, `../.test-env/admin-${width}-tools.png`) });
      }
      await page.locator('[data-tab="ai"]').focus();
      await page.keyboard.press('Home');
      if (await page.locator('.telepilot-tab').first().getAttribute('aria-selected') !== 'true') throw new Error('Keyboard tabs failed');
      await page.keyboard.press('End');
      assert.equal(await page.locator('[data-tab="linking"]').getAttribute('aria-selected'), 'true');
      await page.keyboard.press('ArrowRight');
      assert.equal(await page.locator('[data-tab="telegram"]').getAttribute('aria-selected'), 'true');
      await page.keyboard.press('ArrowLeft');
      assert.equal(await page.locator('[data-tab="linking"]').getAttribute('aria-selected'), 'true');
      await page.evaluate(() => { location.hash = 'telepilot-panel-ai'; });
      await page.setContent(fs.readFileSync(path.join(__dirname, '../.test-env/admin-qa.html'), 'utf8'));
      assert.equal(await page.locator('[data-tab="ai"]').getAttribute('aria-selected'), 'true');
      await page.locator('[name="telepilot_test_ai_connection"]').scrollIntoViewIfNeeded();
      await page.screenshot({ path: path.join(__dirname, `../.test-env/admin-${width}.png`) });
      if (errors.length) throw new Error(errors.join('\n'));
      console.log(`Admin ${width}px: six tabs, button wrapping/styles, isolated forms, keyboard/focus, disabled state, scrolling tables and overflow passed.`);
      await page.close();
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
