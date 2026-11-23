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
            page.on('dialog', d=>d.accept());          // "kaydedilmemiş değişiklik" ve kopyalama onayları
            await page.goto(pathToFileURL(html).href);
            const id = ' [' + viewport.width + ']';
            const genis = viewport.width >= 768;
            const modal = page.locator('#rolModal');
            const editor = modal.locator('.rolx');
            const formPerms = () => modal.locator('form').evaluate(f=>new FormData(f).getAll('permissions[]'));
            const git = async ad => { if (genis) await editor.locator('.rolx-nav-oge[data-git="'+ad+'"]').click(); else await editor.locator('.rolx-nav-sec').selectOption(ad); };
            const panel = ad => editor.locator('.rolx-panel[data-sayfa="'+ad+'"]');
            const kutu = v => modal.locator('input[name="permissions[]"][type="checkbox"][value="'+v+'"]');
            const durum = ad => panel(ad).locator('.rolx-panel-bas [data-durum]').textContent();

            // ── Yeni rol ──
            await page.evaluate(()=>window.rolOpenCreate());
            await page.waitForTimeout(400);                // açılış animasyonu (220ms) bitmeden ölçüm yanıltır
            ok('Pencere açılır, başlık "Yeni Rol"' + id, await modal.isVisible() && (await modal.locator('.pm-title').textContent()) === 'Yeni Rol');
            ok('Varsayılan: Ana Sayfa Gör + rozetler açık' + id, await kutu('dashboard.read').first().isChecked() && await kutu('ui.dashboard.rozetler').isChecked());
            const ilk = await formPerms();
            ok('İşaretleyiciler gönderilir (ayrıntılı + kart + katalog sürümü)' + id, ilk.includes('role.permissions.detailed') && ilk.includes('beyan.permissions.detailed') && ilk.includes('role.sections.detailed') && ilk.some(p=>/^role\.catalog\.\d+$/.test(p)));
            ok(genis ? 'Geniş ekranda sol sayfa listesi görünür, seçici gizli' + id : 'Dar ekranda sayfa seçici görünür, liste gizli' + id,
                genis ? (await editor.locator('.rolx-nav-liste').isVisible() && !await editor.locator('.rolx-nav-sec').isVisible())
                      : (await editor.locator('.rolx-nav-sec').isVisible() && !await editor.locator('.rolx-nav-liste').isVisible()));

            // ── Sayfa gezinme: tek panel görünür ──
            await git('siparis');
            ok('Seçilen sayfanın paneli görünür, diğerleri gizli' + id, await panel('siparis').isVisible() && await editor.locator('.rolx-panel:visible').count() === 1);
            ok('Panelde dört sabit sütun' + id, JSON.stringify(await panel('siparis').locator('.rolx-tablo thead th').allTextContents()) === JSON.stringify(['Sayfa / bölüm','Gör','Ekle','Düzenle','Sil']));
            ok('OGL: üç kart satırı + içerik grupları' + id, await panel('siparis').locator('tr[data-path="siparis/siparis_acik"]').count() === 1 && await panel('siparis').locator('tr[data-path="siparis/siparis_kapali"]').count() === 1 && await panel('siparis').locator('.rolx-oge[data-gerek]').count() >= 25);
            ok('Başlangıçta OGL "Kapalı"' + id, (await durum('siparis')).trim() === 'Kapalı');

            // ── Hazır ayarlar + durum rozeti ──
            await panel('siparis').locator('[data-hazir="salt"]').click();
            ok('"Yalnız görüntüleme": Gör ve salt içerikler açık, yazma kapalı' + id, await kutu('siparis.ogl.read').isChecked() && await kutu('siparis.ogl_acik.read').isChecked() && await kutu('ui.siparis.kpi').isChecked()
                && !await kutu('siparis.ogl_acik.create').isChecked() && !await kutu('siparis.ogl_kapali.delete').isChecked() && !await kutu('ui.siparis.mail_al').isChecked() && !await kutu('siparis.match').isChecked());
            ok('Rozet "Görüntüleme"' + id, /^Görüntüleme/.test((await durum('siparis')).trim()));
            await panel('siparis').locator('[data-hazir="tam"]').click();
            ok('"Tam yetki": panelin tüm anahtarları açık, rozet "Tam"' + id, await panel('siparis').locator('input[type="checkbox"]').evaluateAll(n=>n.every(x=>x.checked)) && (await durum('siparis')).trim() === 'Tam');
            if (genis) ok('Sol listedeki rozet panelle aynı' + id, (await editor.locator('.rolx-nav-oge[data-git="siparis"] [data-durum]').textContent()).trim() === 'Tam');
            else ok('Seçici etiketi durumu gösterir' + id, /Tam$/.test(await editor.locator('.rolx-nav-sec option[value="siparis"]').textContent()));
            await kutu('siparis.ogl_kapali.delete').uncheck({force:true});
            ok('Bir anahtar kapanınca rozet "Kısmi"' + id, /^Kısmi/.test((await durum('siparis')).trim()));
            await panel('siparis').locator('[data-hazir="kapat"]').click();
            ok('"Kapat": panel boş, rozet "Kapalı"' + id, await panel('siparis').locator('input[type="checkbox"]').evaluateAll(n=>n.every(x=>!x.checked)) && (await durum('siparis')).trim() === 'Kapalı');
            ok('Hazır ayar başka sayfayı etkilemez' + id, await kutu('dashboard.read').first().isChecked());

            // ── Ön koşul ve "sayfa kapalı" uyarıları ──
            await kutu('ui.siparis.mail_al').check({force:true});
            const mailOge = panel('siparis').locator('.rolx-oge', {has: page.locator('input[value="ui.siparis.mail_al"]')});
            ok('Ön koşul kapalıyken içerik soluk + not görünür' + id, await mailOge.evaluate(e=>e.classList.contains('rolx-gerek-yok')) && await mailOge.locator('.rolx-gerek-not').isVisible());
            ok('Sayfa Gör kapalıyken uyarı görünür' + id, await panel('siparis').locator('.rolx-kapali-not').isVisible());
            await kutu('siparis.ogl_acik.create').check({force:true});
            ok('Ön koşul açılınca not kalkar' + id, !await mailOge.evaluate(e=>e.classList.contains('rolx-gerek-yok')) && !await mailOge.locator('.rolx-gerek-not').isVisible());
            await kutu('siparis.ogl.read').check({force:true});
            ok('Sayfa Gör açılınca uyarı kalkar' + id, !await panel('siparis').locator('.rolx-kapali-not').isVisible());

            // ── Gizli panellerdeki seçimler korunur ──
            await git('reports');
            await kutu('reports.yukleme.read').check({force:true});
            await git('beyan');
            const sonra = await formPerms();
            ok('Görünmeyen panellerin seçimleri formda kalır' + id, sonra.includes('ui.siparis.mail_al') && sonra.includes('siparis.ogl_acik.create') && sonra.includes('reports.yukleme.read') && sonra.includes('dashboard.read'));
            ok('Beyan: kartlar ve iç içe Beyansız kartları' + id, await panel('beyan').locator('tr[data-path="beyan/beyan_bsz/beyan_bsz_bek"]').count() === 1 && await panel('beyan').locator('input[value="ui.beyan.wa_kopyala"]').count() === 1);
            ok('Değişiklik göstergesi görünür' + id, await editor.locator('.rolx-degisti').isVisible());

            // ── Aynı izin iki yerde (Kullanıcılar + Roller) birlikte değişir ──
            await git('users');
            const paylasilan = kutu('users.admin');
            ok('Paylaşılan izin iki yerde temsil edilir' + id, await paylasilan.count() >= 2);
            await paylasilan.first().check({force:true});
            ok('Aynı izin anahtarları birlikte değişir' + id, await paylasilan.evaluateAll(n=>n.every(x=>x.checked)));
            await paylasilan.first().uncheck({force:true});
            ok('Aynı izin kapatılınca tümü kapanır' + id, await paylasilan.evaluateAll(n=>n.every(x=>!x.checked)));

            // ── Personel alt sayfaları bağımsız ──
            await git('attendance');
            ok('Personel alt sayfasında dört bağımsız CRUD anahtarı var' + id, await kutu('attendance.employees.read').count() === 1 && await kutu('attendance.employees.create').count() === 1 && await kutu('attendance.employees.edit').count() === 1 && await kutu('attendance.employees.delete').count() === 1);
            await kutu('attendance.employees.read').check({force:true});
            ok('Personel Gör seçimi yazma izinlerini açmaz' + id, !await kutu('attendance.employees.create').isChecked() && !await kutu('attendance.employees.delete').isChecked());

            // ── Arama ──
            const ara = editor.locator('.rolx-ara-kutu');
            await ara.fill('Mailden al');
            if (genis) ok('Arama içerik adıyla sayfayı bulur ve açar' + id, await editor.locator('.rolx-nav-oge[data-git="siparis"]').isVisible() && !await editor.locator('.rolx-nav-oge[data-git="kantar"]').isVisible() && await panel('siparis').isVisible());
            else ok('Arama eşleşen sayfayı açar' + id, await panel('siparis').isVisible());
            ok('Eşleşen öğe vurgulanır' + id, await mailOge.evaluate(e=>e.classList.contains('rolx-bulundu')));
            ok('Arama seçimleri değiştirmez' + id, JSON.stringify((await formPerms()).sort()) === JSON.stringify([...sonra, 'attendance.employees.read'].sort()));
            await ara.fill('eşleşmeyen-arama-999');
            ok('Boş arama sonucu anlaşılır' + id, genis ? await editor.locator('.rolx-nav-bos').isVisible() : true);
            await ara.fill('');

            // ── Veri kapsamı ──
            await git('kapsam');
            await editor.locator('.rol-scope-mode').selectOption('selected');
            await modal.locator('input[name="scope_depots[]"]').first().check();
            const scoped = await modal.locator('form').evaluate(f=>({mode:new FormData(f).get('scope_mode'),depots:new FormData(f).getAll('scope_depots[]')}));
            ok('Depo kapsamı forma aktarılır' + id, scoped.mode==='selected' && scoped.depots.length===1 && scoped.depots[0]==='Örnek Depo A');
            ok('Kapsamların kesişimi açıklanır' + id, (await panel('kapsam').textContent()).includes('kesişir'));

            // ── Düzen: pencere ekranda, alt düğmeler erişilebilir, kaydırma çalışır ──
            await git('siparis');
            await page.waitForTimeout(150);
            const layout = await modal.evaluate((m, genis)=>{
                const rect=el=>{const r=el.getBoundingClientRect();return {top:r.top,bottom:r.bottom,height:r.height}};
                const b=m.querySelector('.pm-body'), g=m.querySelector('.rolx-govde'), n=m.querySelector('.rolx-nav');
                const kayan = genis ? g : b;
                kayan.scrollTop = kayan.scrollHeight;
                const ogeler = m.querySelectorAll('.rolx-panel[data-sayfa="siparis"] .rolx-oge');
                const son = ogeler[ogeler.length - 1].getBoundingClientRect();
                const f = rect(m.querySelector('.pm-footer'));
                return {dialog:rect(m.querySelector('.pm-dialog')),footer:f,kayar:kayan.scrollHeight>kayan.clientHeight+4,kaydi:kayan.scrollTop>0,sonGorunur:son.bottom<=f.top+1&&son.top>=0,
                    navKayar: genis ? n.scrollHeight>=n.clientHeight : true, vh:innerHeight,overflow:document.documentElement.scrollWidth>document.documentElement.clientWidth+1};
            }, genis);
            ok('Pencere ve sabit alt düğmeler ekranda' + id, layout.dialog.bottom<=layout.vh+1 && layout.footer.bottom<=layout.vh+1 && layout.footer.top>=0 && layout.footer.height>0);
            ok('İçerik gerçekten kaydırılır; son öğe düğmelerin üstünde görünür' + id, layout.kayar && layout.kaydi && layout.sonGorunur, JSON.stringify(layout));
            ok('Sayfa yatay taşmaz' + id, !layout.overflow);
            const sonKutu = panel('siparis').locator('.rolx-oge input[type="checkbox"]').last();
            const once = await sonKutu.isChecked();
            await panel('siparis').locator('.rolx-oge .rol-switch').last().click();
            ok('En alttaki anahtar tıklanabilir' + id, await sonKutu.isChecked() !== once);

            // ── Klavye ──
            await kutu('siparis.ogl_evrak.read').focus();
            const before = await kutu('siparis.ogl_evrak.read').isChecked();
            await page.keyboard.press('Space');
            ok('Klavye Space anahtarı değiştirir' + id, await kutu('siparis.ogl_evrak.read').isChecked() !== before);
            if(proofDir) await page.screenshot({path:path.join(proofDir,'roles-'+viewport.width+'.png')});
            await page.keyboard.press('Escape');
            ok('Escape (onaylı) pencereyi kapatır' + id, !await modal.isVisible());

            // ── Eski rolü düzenle ──
            await page.evaluate(()=>{ const b=Array.from(document.querySelectorAll('[data-rol-duzenle]')).filter(n=>n.getClientRects().length)[1]; b.focus(); b.click(); });
            await page.waitForTimeout(300);
            ok('Düzenleme kipinde başlık ve sistem kimliği' + id, (await modal.locator('.pm-title').textContent()) === 'Rolü Düzenle' && (await modal.locator('#rolSlug').textContent()) === 'operator' && await modal.locator('#rolAction').inputValue() === 'update_role');
            ok('Eski rolün beyan alt izinleri korunur' + id, await kutu('beyan.contact.read').isChecked() && await kutu('beyan.contact.write').isChecked());
            ok('Eski rapor erişimi ayrıntılı izinlere açılır' + id, await kutu('reports.yukleme.read').isChecked());
            ok('Eski rol: yeni içerik anahtarları ön koşula göre varsayılan açık' + id, await kutu('ui.beyan.wa_kopyala').isChecked() && await kutu('ui.beyan.taslak_olustur').isChecked() && !await kutu('ui.siparis.kpi').isChecked());
            ok('Eski rol depo kapsamı yeniden açılır' + id, await editor.locator('.rol-scope-mode').inputValue()==='selected' && await modal.locator('input[name="scope_depots[]"]').first().isChecked());
            ok('Katalog dışı kayıtlı izin "Diğer yetkiler"de düzenlenebilir' + id, await panel('diger').locator('input[value="extension.example"]').evaluate(n=>n.checked));
            ok('Açılışta değişiklik göstergesi yok' + id, !await editor.locator('.rolx-degisti').isVisible());
            const submitted = await formPerms();
            ok('Ayrıntılı işaretleyiciler var, eski geniş beyan izni yok' + id, submitted.includes('role.permissions.detailed') && submitted.includes('beyan.permissions.detailed') && submitted.includes('role.sections.detailed') && !submitted.includes('beyan.write'));
            await modal.locator('.pm-footer button').last().focus();
            await page.keyboard.press('Tab');
            ok('Tab odak döngüsü pencerede kalır' + id, await page.evaluate(()=>document.activeElement===document.querySelector('#rolModal .pm-close')));
            await page.keyboard.press('Escape');
            ok('Kapatınca odak açan düğmeye döner' + id, await page.evaluate(()=>document.activeElement.hasAttribute('data-rol-duzenle')));

            // ── Kopyala ──
            await page.evaluate(()=>{ Array.from(document.querySelectorAll('[data-rol-kopyala]')).filter(n=>n.getClientRects().length)[1].click(); });
            await page.waitForTimeout(300);
            ok('Kopyala: yeni rol olarak açılır, izinler taşınır' + id, (await modal.locator('.pm-title').textContent()) === 'Rolü Kopyala' && await modal.locator('#rolAction').inputValue() === 'create_role' && await modal.locator('#rolId').inputValue() === ''
                && (await modal.locator('#rolLabel').inputValue()) === 'Operatör (kopya)' && await kutu('beyan.contact.read').isChecked());
            await page.keyboard.press('Escape');
            ok('Tarayıcı JavaScript hatası yok' + id, errors.length===0,errors.join(';'));
            await page.close();
        }
    } finally { await browser.close(); }
    const proof={checks:results.length,passed:results.filter(r=>r.pass).length,failed:results.filter(r=>!r.pass),results};
    if(proofDir) fs.writeFileSync(path.join(proofDir,'proof.json'),JSON.stringify(proof,null,2));
    console.log(JSON.stringify({checks:proof.checks,passed:proof.passed,failed:proof.failed.length}));
    process.exit(proof.failed.length?1:0);
})().catch(e=>{console.error(e.stack);process.exit(1)});
