'use strict';
// Anonymous, file-only browser verification. Generate ROLES_HTML_PATH with
// roles_modal_render.php. No application bootstrap or network service is used.
const fs = require('fs');
const path = require('path');
const { pathToFileURL } = require('url');
let chromium;
for (const mod of [process.env.PW_PATH, 'playwright', 'playwright-core', '/opt/node22/lib/node_modules/playwright']) {
    if (!mod) continue;
    try { chromium = require(mod).chromium; break; } catch (_) { /* try next */ }
}
if (!chromium) { console.error('Playwright bulunamadı; tarayıcı kabulü çalıştırılamadı.'); process.exit(1); }
const root = path.dirname(__dirname);
const html = process.env.ROLES_HTML_PATH || path.join(root, '_test_roles.html');
if (!fs.existsSync(html)) { console.error('Anonim HTML yok: ROLES_HTML_PATH belirtin.'); process.exit(1); }
const proofDir = process.env.ROLES_QA_DIR;
if (proofDir) fs.mkdirSync(proofDir, {recursive:true});
const results = [];
function ok(name, pass, detail) { results.push({name,pass:!!pass,detail}); console.log((pass ? 'OK ' : 'FAIL ') + name + (pass || !detail ? '' : ' — ' + detail)); }
(async () => {
    const executablePath = process.env.PW_EXECUTABLE_PATH || [
        'C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',
        'C:/Program Files/Microsoft/Edge/Application/msedge.exe',
        '/opt/pw-browsers/chromium-1194/chrome-linux/chrome',
    ].find(p=>fs.existsSync(p));
    const browser = await chromium.launch(executablePath ? {executablePath} : (process.env.PW_CHANNEL ? {channel:process.env.PW_CHANNEL} : {}));
    try {
        for (const viewport of [{width:1440,height:1000},{width:820,height:1024},{width:390,height:844},{width:320,height:740}]) {
            const page = await browser.newPage({viewport});
            const errors = [];
            page.on('pageerror', e=>errors.push(e.message));
            await page.goto(pathToFileURL(html).href);
            await page.evaluate(()=>window.rolOpenCreate());
            const id = ' [' + viewport.width + ']';
            const modal = page.locator('#rolCreateModal');
            const editor = modal.locator('.rol-editor');
            const search = editor.locator('.rol-search');
            ok('Dört CRUD sütunu sabit' + id, JSON.stringify(await editor.locator('.rol-matrix-table').first().locator('thead th').allTextContents()) === JSON.stringify(['Sayfa / alt başlık','Gör','Ekle','Düzenle','Sil']));
            ok('Varsayılan ana sayfa izni açık' + id, await modal.locator('input[value="dashboard.read"]').isChecked());
            const initial = await modal.locator('form').evaluate(f=>new FormData(f).getAll('permissions[]'));
            await search.fill('Yükleme Raporları');
            ok('Arama alt satırı ve Raporlar atasını gösterir' + id, await editor.locator('tr[data-path="reports"]').isVisible() && await editor.locator('tr[data-label="Yükleme Raporları"]').isVisible());
            ok('Arama görünmeyen seçimleri korur' + id, JSON.stringify(initial) === JSON.stringify(await modal.locator('form').evaluate(f=>new FormData(f).getAll('permissions[]'))));
            await editor.locator('.rol-reset-filters').click();
            const reports = editor.locator('tr[data-path="reports"]');
            await reports.locator('.rol-row-expand').click();
            ok('Ok alt rapor yetkilerini açar' + id, await editor.locator('tr[data-label="Yükleme Raporları"]').isVisible());
            await modal.locator('input[value="reports.yukleme.read"]').check();
            await editor.locator('.rol-state-filter').selectOption('selected');
            ok('Seçili filtresi alt satırı görünür tutar' + id, await editor.locator('tr[data-label="Yükleme Raporları"]').isVisible());
            await editor.locator('.rol-reset-filters').click();
            await search.fill('Personel kartları');
            const employeeRead=modal.locator('input[value="attendance.employees.read"]');
            ok('Personel alt sayfasında dört bağımsız CRUD anahtarı var' + id, await employeeRead.isVisible() && await modal.locator('input[value="attendance.employees.create"]').isVisible() && await modal.locator('input[value="attendance.employees.edit"]').isVisible() && await modal.locator('input[value="attendance.employees.delete"]').isVisible());
            await employeeRead.check();
            ok('Personel Gör seçimi yazma izinlerini açmaz' + id, !await modal.locator('input[value="attendance.employees.create"]').isChecked() && !await modal.locator('input[value="attendance.employees.edit"]').isChecked() && !await modal.locator('input[value="attendance.employees.delete"]').isChecked());
            await employeeRead.uncheck();
            await editor.locator('.rol-reset-filters').click();
            await search.fill('Kullanıcı ve rol yönetimi');
            const shared = modal.locator('input[value="users.admin"]');
            ok('Paylaşılan izin iki yerde temsil edilir' + id, await shared.count() >= 2);
            await shared.first().check();
            ok('Aynı izin anahtarları birlikte değişir' + id, (await shared.evaluateAll(nodes=>nodes.every(n=>n.checked))));
            await shared.first().uncheck();
            ok('Aynı izin kapatılınca tümü kapanır' + id, (await shared.evaluateAll(nodes=>nodes.every(n=>!n.checked))));
            await editor.locator('.rol-reset-filters').click();
            await editor.locator('.rol-section-filter').selectOption('Yönetim');
            ok('Bölüm filtresi diğer bölümleri gizler' + id, await editor.locator('.rol-perm-group:visible').evaluateAll(nodes=>nodes.every(n=>n.dataset.group==='Yönetim')));
            await search.fill('eşleşmeyen-arama-999');
            ok('Boş filtre sonucu anlaşılır' + id, await editor.locator('.rol-empty').isVisible());
            await editor.locator('.rol-reset-filters').click();
            await editor.locator('.rol-data-scope summary').click();
            await editor.locator('.rol-scope-mode').selectOption('selected');
            await modal.locator('input[name="scope_depots[]"]').first().check();
            const scoped = await modal.locator('form').evaluate(f=>({mode:new FormData(f).get('scope_mode'),depots:new FormData(f).getAll('scope_depots[]')}));
            ok('Depo kapsamı forma aktarılır' + id, scoped.mode==='selected' && scoped.depots.length===1 && scoped.depots[0]==='Örnek Depo A');
            ok('Kapsamların kesişimi açıklanır' + id, (await editor.locator('.rol-data-scope').textContent()).includes('kesişir'));
            await editor.locator('.rol-data-scope summary').click();
            const layout = await modal.evaluate(m=>{
                const rect=el=>{const r=el.getBoundingClientRect();return {top:r.top,bottom:r.bottom,height:r.height}};
                const b=m.querySelector('.pm-body');
                return {dialog:rect(m.querySelector('.pm-dialog')),footer:rect(m.querySelector('.pm-footer')),bodyScroll:b.scrollHeight>b.clientHeight+4,vh:innerHeight,overflow:document.documentElement.scrollWidth>document.documentElement.clientWidth+1};
            });
            ok('Modal ve sabit alt düğmeler ekranda' + id, layout.dialog.bottom<=layout.vh+1 && layout.footer.bottom<=layout.vh+1 && layout.footer.top>=0 && layout.footer.height>0);
            ok('Gövde kaydırılabilir; sayfa yatay taşmaz' + id, layout.bodyScroll && !layout.overflow);
            await search.fill('Yükleme Raporları');
            const reportToggle=modal.locator('input[value="reports.yukleme.read"]');
            await reportToggle.focus();
            const before=await reportToggle.isChecked();
            await page.keyboard.press('Space');
            ok('Klavye Space anahtarı değiştirir' + id, await reportToggle.isChecked()!==before);
            await editor.locator('.rol-reset-filters').click();
            if(proofDir) {
                await modal.locator('.pm-body').evaluate(el=>el.scrollTop=0);
                await page.screenshot({path:path.join(proofDir,'roles-'+viewport.width+'.png')});
            }
            await page.keyboard.press('Escape');
            ok('Escape modali kapatır' + id, !await modal.isVisible());
            await page.evaluate(()=>{
                const b=Array.from(document.querySelectorAll('button[onclick]')).find(n=>n.getAttribute('onclick').includes('operator')&&n.getClientRects().length); b.focus(); b.click();
            });
            const edit=page.locator('#rolEditModal');
            ok('Eski rolün beyan alt izinleri korunur' + id, await edit.locator('input[value="beyan.contact.read"]').isChecked() && await edit.locator('input[value="beyan.contact.write"]').isChecked());
            ok('Eski rapor erişimi ayrıntılı izinlere açılır' + id, await edit.locator('input[value="reports.yukleme.read"]').isChecked());
            ok('Eski rol depo kapsamı yeniden açılır' + id, await edit.locator('.rol-scope-mode').inputValue()==='selected' && await edit.locator('input[name="scope_depots[]"]').first().isChecked());
            ok('Katalog dışı kayıtlı izin düzenlenebilir' + id, await edit.locator('input[value="extension.example"]').isChecked());
            const submitted=await edit.locator('form').evaluate(f=>new FormData(f).getAll('permissions[]'));
            ok('Ayrıntılı marker var, eski geniş beyan izni yok' + id, submitted.includes('role.permissions.detailed') && submitted.includes('beyan.permissions.detailed') && !submitted.includes('beyan.write'));
            await edit.locator('.pm-footer button').last().focus();
            await page.keyboard.press('Tab');
            ok('Tab odak döngüsü dialogda kalır' + id, await page.evaluate(()=>document.activeElement===document.querySelector('#rolEditModal .pm-close')));
            await page.keyboard.press('Escape');
            ok('Kapatınca odak açan düğmeye döner' + id, await page.evaluate(()=>document.activeElement.matches('button[onclick]') && document.activeElement.getAttribute('onclick').includes('operator')));
            ok('Tarayıcı JavaScript hatası yok' + id, errors.length===0,errors.join(';'));
            await page.close();
        }
    } finally { await browser.close(); }
    const proof={checks:results.length,passed:results.filter(r=>r.pass).length,failed:results.filter(r=>!r.pass),results};
    if(proofDir) fs.writeFileSync(path.join(proofDir,'proof.json'),JSON.stringify(proof,null,2));
    console.log(JSON.stringify({checks:proof.checks,passed:proof.passed,failed:proof.failed.length}));
    process.exit(proof.failed.length?1:0);
})().catch(e=>{console.error(e.stack);process.exit(1)});
