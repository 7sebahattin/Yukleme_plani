'use strict';
// Real PHP view + SQLite fixture, real browser behavior; no live DB/HKS requests.
// PW_PATH=/path/to/playwright PW_CHANNEL=msedge node scripts/beyan_status_menu_smoke.js
const fs = require('fs'), os = require('os'), path = require('path');
const {spawnSync} = require('child_process');
const {chromium} = require(process.env.PW_PATH || 'playwright');
const root = path.resolve(__dirname, '..');
const scratch = fs.mkdtempSync(path.join(os.tmpdir(), 'beyan-status-'));
const css = fs.readFileSync(path.join(root, 'assets/style.css'), 'utf8');
const app = fs.readFileSync(path.join(root, 'assets/app.js'), 'utf8');
let count = 0;
function ok(value, label) { if (!value) throw Error(label); count++; console.log('OK ' + label); }
function render(status = 'beyan_acildi', profile = '') {
  const file = path.join(scratch, 'view.html');
  const result = spawnSync('php', ['scripts/beyan_ui_smoke.php', '--render-status', file, status, profile], {cwd:root, encoding:'utf8'});
  if (result.status !== 0) throw Error(result.stderr || result.stdout);
  return fs.readFileSync(file, 'utf8').replace(/<link[^>]*>/g, '').replace(/<script\b[^>]*src=[^>]*><\/script>/g, '').replace('</head>', '<style>'+css+'</style></head>');
}
(async () => {
  const browser = await chromium.launch(process.env.PW_CHANNEL ? {channel:process.env.PW_CHANNEL} : {});
  try {
    const page = await browser.newPage({viewport:{width:1440,height:1000}});
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    let html = render();
    await page.route('**/*', route => route.fulfill({contentType:'text/html',body:html}));
    async function setup(status, profile) {
      html = render(status, profile);
      await page.goto('https://beyan.test/view');
      await page.addScriptTag({content:app});
      await page.evaluate(() => {
        window.statusPosts = []; window.copiedText = '';
        Object.defineProperty(navigator, 'clipboard', {configurable:true,value:{writeText:async text => {window.copiedText = text;}}});
        const form = document.querySelector('.beyan-durum-form');
        if (form) form.addEventListener('submit', event => {event.preventDefault(); window.statusPosts.push(Array.from(new FormData(form, event.submitter)));});
      });
    }
    await setup();
    const trigger = page.locator('.beyan-durum-toggle'), menu = page.locator('#beyanDurumSecenekleri');
    const expanded = async () => await trigger.getAttribute('aria-expanded') === 'true';
    ok((await trigger.textContent()).includes('AÇILDI') && !await expanded(), 'desktop shows current state and starts collapsed');
    await trigger.hover();
    ok(await menu.isVisible() && await trigger.getAttribute('aria-expanded') === 'true', 'hover expands alternatives and accessibility state');
    ok(await menu.locator('button').first().evaluate(el => {
      const style = getComputedStyle(el);
      return style.transitionProperty.includes('transform') && style.transitionDuration.split(',').some(duration => parseFloat(duration) > 0);
    }), 'cards animate their transforms instead of appearing instantly');
    await page.waitForTimeout(800);
    const boxes = await menu.locator('button[name=status]').evaluateAll(buttons => buttons.map(button => {const r=button.getBoundingClientRect();return {x:r.x,y:r.y};}));
    const triggerBox = await trigger.boundingBox();
    ok(boxes.length === 8 && boxes.every((box,i) => box.x >= triggerBox.x + triggerBox.width - 2 && Math.abs(box.y-boxes[0].y)<3 && (!i || box.x > boxes[i-1].x)), 'all eight cards spread rightward on one horizontal row');
    await menu.locator('button').last().hover();
    ok(await expanded(), 'pointer can reach the last spread card');
    await page.mouse.move(0,0); await page.waitForTimeout(2200);
    ok(await expanded() && await menu.isVisible(), 'leaving keeps cards open during the three-second grace period');
    await trigger.hover(); await page.waitForTimeout(1100);
    ok(await expanded(), 'reentering cancels the pending close');
    await page.mouse.move(0,0); await page.waitForTimeout(2800);
    ok(await expanded(), 'a fresh leave restarts the full three-second delay');
    await page.waitForTimeout(350);
    ok(!await expanded() && await menu.isVisible(), 'closing animation remains visible briefly after the delay expires');
    await page.waitForTimeout(800);
    ok(!await expanded() && await menu.isHidden(), 'cards animate closed after the three-second delay');
    await trigger.focus();
    ok(await menu.isVisible(), 'keyboard focus exposes options');
    await page.keyboard.press('Escape');
    await menu.waitFor({state:'hidden'});
    ok(await menu.isHidden() && await trigger.evaluate(el => el === document.activeElement), 'Escape collapses and returns focus to current state');
    await page.keyboard.press('ArrowDown');
    ok(await menu.isVisible() && await menu.locator('button').first().evaluate(el => el === document.activeElement), 'ArrowDown moves to first alternative');
    await page.locator('h1').click();
    await menu.waitFor({state:'hidden'});
    ok(await menu.isHidden(), 'outside click collapses menu');
    await trigger.click();
    ok(await menu.isVisible(), 'click supports desktop users without hover');
    await page.evaluate(() => {window.promptCalls = 0; window.prompt = () => {window.promptCalls++; return null;};});
    await menu.locator('[value=red]').press('Enter');
    ok(await page.evaluate(() => promptCalls === 1 && statusPosts.length === 0) && (await trigger.textContent()).includes('AÇILDI'), 'cancelled Red note neither submits nor changes visible current state');
    await trigger.hover(); await menu.locator('[value=numune_bekliyor]').click();
    const post = await page.evaluate(() => statusPosts[0]);
    ok(post && post.filter(([key]) => key === 'status').length === 1 && post.find(([key]) => key === 'status')[1] === 'numune_bekliyor', 'clicked state submits exactly once with chosen value');
    const payload = Object.fromEntries(post);
    ok(Object.keys(payload).sort().join(',') === 'csrf,return_to,status,status_only' && payload.csrf && payload.status_only === '1' && payload.return_to === 'view', 'status request retains CSRF and narrow existing status-only contract');
    ok(await page.locator('.beyan-durum-form').getAttribute('action') === 'beyan_edit.php?id=3', 'request uses existing server update action');
    await setup(payload.status);
    ok((await trigger.textContent()).includes('NUMUNE') && await menu.isHidden() && await menu.locator('[value=beyan_acildi]').count() === 1, 'server re-render promotes selected state and restores old state as alternative');
    await trigger.hover();
    await page.evaluate(() => {window.promptCalls = 0; window.prompt = () => {window.promptCalls++; return '  Uygun olmayan numune  ';};});
    await menu.locator('[value=red]').click();
    await page.waitForFunction(() => statusPosts.length === 1);
    ok(await page.evaluate(() => promptCalls === 1 && Object.fromEntries(statusPosts[0]).analysis_note === 'Uygun olmayan numune'), 'mouse click on Red card submits mandatory trimmed note');
    await setup();
    ok(await page.locator('#waKopyalaBtn').count() === 1 && await page.locator('#waMetin').count() === 1, 'one copy control and one text source');
    ok(await page.locator('.beyan-page-heading .beyan-badge').count() === 0 && !/#\d+/.test(await page.locator('.beyan-page-heading').textContent()), 'header no longer duplicates current status or numeric record ID');
    const heading = await page.locator('h1').boundingBox(), copy = await page.locator('#waKopyalaBtn').boundingBox();
    ok(copy.y >= heading.y + heading.height && Math.abs(copy.x-heading.x) < 3, 'copy control sits left immediately below party heading');
    ok(await page.locator('#waKopyalaBtn').evaluate(el => getComputedStyle(el).backgroundColor === 'rgb(37, 211, 102)'), 'copy control uses WhatsApp green');
    await page.locator('#waKopyalaBtn').click();
    ok(await page.evaluate(() => copiedText === JSON.parse(document.getElementById('waMetin').textContent)) && (await page.locator('#waKopyalaBtn').textContent()).includes('Kopyalandı'), 'copy writes complete server-provided text and reports success');
    for (const width of [360,390,900,1024]) {
      await page.setViewportSize({width,height:1000});
      if (width >= 1024) { await trigger.hover(); await page.waitForTimeout(800); }
      ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth+1), 'no horizontal page overflow at '+width);
      if (width < 1024) ok(await trigger.isHidden() && await menu.isVisible() && await menu.locator('button[value=temiz]').isVisible(), 'mobile/tablet keeps direct status strip at '+width);
    }
    await page.setViewportSize({width:1440,height:1000});
    for (const profile of ['restricted','deleted']) {
      await setup('beyan_acildi',profile);
      ok(await page.locator('.beyan-durum-form').count() === 0, profile+' cannot display status actions');
      if (profile === 'deleted') ok((await page.locator('.beyan-page-heading').textContent()).includes('ARŞİVLENDİ') && await page.locator('.flash-error').count() > 0, 'archived record retains its warning and archive indication');
    }
    const fallback = await browser.newPage({javaScriptEnabled:false,viewport:{width:1440,height:1000}});
    html = render(); await fallback.route('**/*', route => route.fulfill({contentType:'text/html',body:html}));
    await fallback.goto('https://beyan.test/fallback');
    ok(await fallback.locator('.beyan-durum-toggle').isHidden() && await fallback.locator('button[name=status]').count() === 8 && await fallback.locator('[value=temiz]').isVisible(), 'without JavaScript all direct status actions remain accessible');
    await fallback.close();
    ok(errors.length === 0, 'no browser script errors: '+errors.join('; '));
    console.log(count+' status/copy/layout checks passed.');
  } finally {
    await browser.close();
    for (const file of fs.readdirSync(scratch)) fs.unlinkSync(path.join(scratch,file)); fs.rmdirSync(scratch);
  }
})().catch(error => {console.error(error);process.exitCode=1;});
