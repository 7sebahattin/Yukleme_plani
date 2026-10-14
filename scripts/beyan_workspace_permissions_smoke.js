'use strict';
// Real server form render; local SQLite only. Tests disabled and absent fields in browser.
const fs = require('fs'), path = require('path'), os = require('os'), {spawnSync} = require('child_process');
const {chromium} = require(process.env.PW_PATH || 'playwright');
const root = path.resolve(__dirname, '..');
const css = fs.readFileSync(path.join(root, 'assets/style.css'), 'utf8');
const js = fs.readFileSync(path.join(root, 'assets/app.js'), 'utf8');
let count = 0;
function ok(value, label) { if (!value) throw Error(label); count++; console.log('OK ' + label); }
(async () => {
  const browser = await chromium.launch(process.env.PW_CHANNEL ? {channel: process.env.PW_CHANNEL} : {});
  const scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'beyan-permission-'));
  try {
    for (const profile of ['restricted', 'readonly', 'full']) {
      const file = path.join(scratch, profile + '.html');
      const result = spawnSync('php', ['scripts/beyan_workspace_render.php', file, 'edit'], {cwd:root, env:{...process.env, BEYAN_TEST_PROFILE:profile}, encoding:'utf8'});
      ok(result.status === 0, profile + ' PHP render: ' + result.stderr);
      let html = fs.readFileSync(file, 'utf8').replace(/<link[^>]*>/g, '').replace(/<script\b[^>]*src=[^>]*><\/script>/g, '');
      const page = await browser.newPage();
      const errors = []; page.on('pageerror', error => errors.push(error.message));
      await page.setContent(html); await page.addStyleTag({content:css}); await page.addScriptTag({content:js});
      ok(await page.locator('[name=vehicle_plate]').count() === 1, profile + ' exactly one plate field');
      ok(await page.locator('[name=vehicle_plate]').isEnabled(), profile + ' base plate editable independently of HKS');
      ok(await page.evaluate(() => new FormData(document.querySelector('#beyanForm')).get('vehicle_plate') === '34ABC123'), profile + ' plate retained in submission');
      if (profile === 'restricted') {
        ok(await page.locator('[name=buyer_name], [name=driver_name], [name=driver_phone], [name=analysis_note], [name=raw_text], .beyan-workspace-hks').count() === 0, 'restricted controls omitted from DOM');
        ok(!html.includes('ÖRNEK ALICI') && !html.includes('ÖRNEK ADRES') && !html.includes('Kontrol edilecek örnek satır'), 'restricted values absent from HTML and JSON');
        ok(await page.locator('[data-beyan-tab]').count() === 1, 'only authorized tab rendered');
      }
      if (profile === 'readonly') {
        for (const field of ['buyer_name', 'driver_name', 'driver_phone', 'company_address', 'analysis_note', 'raw_text', 'sample_crate_count', 'sample_crate_type', 'sample_gross_kg', 'sample_net_kg']) {
          ok(await page.locator('[name="'+field+'"]').isDisabled(), field + ' read-only');
        }
        ok(await page.evaluate(() => !new FormData(document.querySelector('#beyanForm')).has('buyer_name')), 'disabled values not submitted');
      }
      for (const width of [360, 390, 768, 1440]) {
        await page.setViewportSize({width, height:900});
        ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), profile + ' no page overflow at '+width);
        ok(await page.locator('.bf-kaydet').isVisible(), profile + ' save accessible at '+width);
        if (profile === 'restricted' && width === 1440) {
          ok(await page.locator('.beyan-workspace-main').evaluate(el => el.clientWidth >= el.parentElement.clientWidth - 2), 'hidden HKS column releases desktop space');
        }
      }
      ok(errors.length === 0, profile + ' JS errors: ' + errors.join('; '));
      await page.close();
    }
    console.log(count + ' permission/layout checks passed.');
  } finally { await browser.close(); for (const file of fs.readdirSync(scratch)) fs.unlinkSync(path.join(scratch, file)); fs.rmdirSync(scratch); }
})().catch(error => { console.error(error); process.exitCode=1; });
