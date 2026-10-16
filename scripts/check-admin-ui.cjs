const { chromium } = require('../.test-env/node_modules/playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const baselineInventory = process.env.TELEPILOT_UI_BASELINE_INVENTORY
  ? JSON.parse(fs.readFileSync(path.resolve(process.env.TELEPILOT_UI_BASELINE_INVENTORY), 'utf8'))
  : null;

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
      const layout = await page.evaluate(() => {
        const hero = document.querySelector('.telepilot-hero');
        const content = document.querySelector('.telepilot-content');
        const resources = document.querySelector('.telepilot-utility-links');
        const overview = document.querySelector('.telepilot-overview-grid');
        const section = title => [...document.querySelectorAll('.telepilot-panel h2')].find(e => e.textContent.trim() === title).closest('section');
        const cardRects = [...overview.children].map(e => e.getBoundingClientRect());
        return {
          heroHeight: hero.getBoundingClientRect().height,
          firstInputTop: document.querySelector('[name="telepilot_settings[bot_token]"]').getBoundingClientRect().top,
          contentWidth: content.getBoundingClientRect().width,
          contentOffset: content.getBoundingClientRect().left - content.parentElement.getBoundingClientRect().left,
          shellPadding: parseFloat(getComputedStyle(content.parentElement).paddingLeft),
          headingSize: getComputedStyle(hero.querySelector('h1')).fontSize,
          logoWidth: hero.querySelector('img').getBoundingClientRect().width,
          versionBadges: hero.querySelectorAll('.telepilot-status-pill').length,
          resourcesFollowHero: hero.nextElementSibling === resources && !hero.contains(resources),
          overviewHeadings: [...overview.querySelectorAll('h2')].map(e => e.textContent.trim()),
          overviewColumns: getComputedStyle(overview).gridTemplateColumns.split(' ').length,
          overviewTops: cardRects.map(r => r.top),
          dashboardWidth: cardRects[2].width,
          overviewWidth: overview.getBoundingClientRect().width,
          setupTop: section('First-Time Setup').getBoundingClientRect().top,
          commandsTop: section('Primary Telegram Commands').getBoundingClientRect().top,
        };
      });
      assert.ok(layout.contentWidth <= 1200, `Content is stretched at ${width}px`);
      assert.ok(Math.abs(layout.contentOffset - layout.shellPadding) < 1, 'Content must align left');
      assert.equal(layout.headingSize, width <= 782 ? '24px' : '26px');
      assert.equal(layout.logoWidth, 40);
      assert.equal(layout.versionBadges, 1);
      assert.ok(layout.resourcesFollowHero);
      assert.deepEqual(layout.overviewHeadings, ['System Status', 'Hardening Readiness', 'Dashboard Preview']);
      assert.equal(layout.overviewColumns, width >= 1200 ? 3 : width >= 1000 ? 2 : 1);
      if (width >= 1200) assert.equal(new Set(layout.overviewTops).size, 1);
      if (width >= 1000 && width < 1200) assert.ok(Math.abs(layout.dashboardWidth - layout.overviewWidth) < 1);
      if (width >= 1000) assert.ok(Math.abs(layout.setupTop - layout.commandsTop) < 1);
      else assert.ok(layout.commandsTop > layout.setupTop);
      if (width === 1440) {
        assert.ok(layout.heroHeight <= 180, `Hero is ${layout.heroHeight}px tall`);
        assert.ok(layout.firstInputTop < 420, `First input starts at ${layout.firstInputTop}px`);
        fs.writeFileSync(path.join(__dirname, '../.test-env/admin-compact-after.json'), JSON.stringify(layout, null, 2));
      }
      const expectedLinks = [
        ['Alef Digital Solutions', 'https://alefdigitalsolutions.com'],
        ['Product Page', 'https://alefdigitalsolutions.com/solutions/wp-telepilot'],
        ['Report Bugs', 'https://alefdigitalsolutions.com/solutions/wp-telepilot'],
        ['GPLv2 or later', 'https://www.gnu.org/licenses/gpl-2.0.html'],
      ];
      assert.equal(await page.locator('.telepilot-utility-links a').count(), 4);
      assert.equal(await page.locator('.telepilot-hero a, .telepilot-hero-card, .telepilot-resource-links, .telepilot-section-label').count(), 0);
      for (const [label, href] of expectedLinks) {
        const link = page.getByRole('link', { name: label, exact: true });
        assert.equal(await link.count(), 1, `Duplicate resource: ${label}`);
        assert.equal(await link.getAttribute('href'), href);
        assert.equal(await link.getAttribute('target'), '_blank');
        assert.deepEqual((await link.getAttribute('rel')).split(' ').sort(), ['noopener', 'noreferrer']);
        const style = await link.evaluate(e => {
          const s = getComputedStyle(e);
          return { border: s.borderTopWidth, background: s.backgroundColor, underline: s.textDecorationLine, padding: s.padding };
        });
        assert.deepEqual(style, { border: '0px', background: 'rgba(0, 0, 0, 0)', underline: 'underline', padding: '0px' });
        await link.focus();
        assert.equal(await link.evaluate(e => getComputedStyle(e).outlineWidth), '3px');
      }
      if (baselineInventory) {
        const inventory = await page.evaluate(() => ({
          headings: [...document.querySelectorAll('.telepilot-admin h2')].map(e => e.textContent.trim()),
          fields: [...document.querySelectorAll('.telepilot-admin [name]')].map(e => e.name).sort(),
          tabs: [...document.querySelectorAll('.telepilot-tab')].map(e => e.dataset.tab),
        }));
        assert.deepEqual(inventory, baselineInventory, 'Existing headings, fields, actions and tabs must be preserved');
      }
      await page.evaluate(() => { document.activeElement.blur(); window.scrollTo(0, 0); });
      await page.screenshot({ path: path.join(__dirname, `../.test-env/admin-${width}-compact.png`) });
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
          assert.equal(control.radius, '8px');
          assert.equal(control.padding, '8px 14px');
          assert.equal(control.font, '14px');
          assert.equal(control.weight, '700');
          assert.equal(control.clipped, false, `Clipped button text at ${width}px in ${tab}`);
        }
        const fieldControls = await page.locator('.telepilot-field input:not([type="checkbox"]):visible, .telepilot-field select:visible, .telepilot-field textarea:visible, .telepilot-check:visible, .telepilot-tab:visible').evaluateAll(es => es.map(e => ({
          height: e.getBoundingClientRect().height,
          font: getComputedStyle(e).fontSize,
          field: e.matches('input,select,textarea'),
        })));
        for (const control of fieldControls) {
          assert.ok(control.height >= 44, `Small field, checkbox label or tab at ${width}px in ${tab}`);
          if (control.field) assert.equal(control.font, width <= 782 ? '16px' : '14px');
        }
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
        if (overflow) {
          console.error(await page.evaluate(() => Array.from(document.querySelectorAll('body *')).filter(node => node.getBoundingClientRect().right > innerWidth + 1).slice(0, 12).map(node => ({ tag: node.tagName, class: node.className, width: node.getBoundingClientRect().width }))));
          throw new Error(`Horizontal overflow at ${width}px in ${tab}`);
        }
        if ([390, 1440].includes(width)) {
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
      const repairButton = page.locator('[name="telepilot_retry_database_repair"]');
      assert.equal(await repairButton.count(), 1);
      assert.equal(await repairButton.evaluate(e => e.form.method), 'post');
      assert.ok(await repairButton.evaluate(e => new FormData(e.form).has('telepilot_tools_nonce')));
      await repairButton.click();
      assert.equal(await page.evaluate(() => window.qaSubmissions[2].submitter), 'telepilot_retry_database_repair');
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
      assert.deepEqual(await page.locator('.telepilot-table th').first().evaluate(e => ({
        paddingTop: getComputedStyle(e).paddingTop,
        paddingBottom: getComputedStyle(e).paddingBottom,
        position: getComputedStyle(e).position,
      })), { paddingTop: '8px', paddingBottom: '8px', position: 'sticky' });
      if ([390, 1440].includes(width)) {
        await page.locator('.telepilot-overview-grid').screenshot({ path: path.join(__dirname, `../.test-env/admin-${width}-overview.png`) });
      }
      if (process.env.TELEPILOT_TEST_QUEUE_REPAIR_FAILURE === '1') {
        const warning = page.locator('p.telepilot-inline-note').filter({ hasText: 'Queue repair could not change update_id to nullable.' });
        assert.equal(await warning.count(), 1);
        assert.ok(await warning.isVisible(), 'Queue repair warning must remain visible');
        assert.ok(await warning.evaluate(e => e.scrollHeight <= e.clientHeight + 1 && e.scrollWidth <= e.clientWidth + 1), 'Queue repair warning must not be clipped');
        const references = page.locator('p.telepilot-inline-note').filter({ hasText: 'ebf0b0d0-42f3-4f2e-a84e-37356fc68f29' });
        assert.ok(await references.count() > 0, 'Safe repair reference must remain available');
        if ([390, 1440].includes(width)) {
          await warning.screenshot({ path: path.join(__dirname, `../.test-env/admin-${width}-repair-warning.png`) });
        }
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
      console.log(`Admin ${width}px: compact header/resources/layout, six tabs, 44px controls, isolated forms, keyboard/focus, disabled state, scrolling tables and overflow passed.`);
      await page.close();
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
