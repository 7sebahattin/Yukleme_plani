// =========================================================
// scripts/siparis_zil_smoke.js — v529 tarayıcı testi: 🔔 bildirim zili, arka plan mail sorgusu, "ne değişti" baloncuğu (okununca kalkar,
// sistem title'ı binmez), OGL kesintisi penceresi (uzun modal: footer görünür + tıklanır).
//   SIPARIS_UI_OUT=<dir> php scripts/siparis_ui_render.php
//   SIPARIS_UI_OUT=<dir> node scripts/siparis_zil_smoke.js
// Sayfalar SENTETİK fixture'larla siparis_ui_render.php'den gelir (mail_son, liste, odemeler_kesinti). siparis_mail.php ağ isteği page.route() ile taklit edilir.
// =========================================================
const path = require('path');
const fs = require('fs');
const { chromium } = require('playwright');
const OUT = process.env.SIPARIS_UI_OUT || '/tmp/sip-ui';
let pass = 0, fail = 0;
const ok = (ad, kosul, ek) => { if (kosul) { pass++; console.log('PASS ' + ad); } else { fail++; console.log('FAIL ' + ad + (ek !== undefined ? ' :: ' + JSON.stringify(ek) : '')); } };
const EKRAN = [[1280, 900, 'masaüstü'], [820, 1100, 'tablet'], [390, 844, 'mobil']];

(async () => {
    // file:// sayfadaki fetch'i page.route() ile yakalamak TAM Chromium ister (headless-shell file:// fetch'ini route'a uğratmadan reddeder) — siparis_ui_smoke.js ile aynı
    const chromePath = ['/opt/pw-browsers/chromium-1194/chrome-linux/chrome', '/opt/pw-browsers/chromium/chrome-linux/chrome'].find(p => fs.existsSync(p));
    const browser = await chromium.launch(chromePath ? { executablePath: chromePath } : { channel: 'chromium' }).catch(() => chromium.launch({}));
    async function ac(sayfa, w, h, hazirla, opts) {
        const mobil = w < 768;
        const ctx = await browser.newContext({ viewport: { width: w, height: h }, hasTouch: mobil, isMobile: mobil });
        const page = await ctx.newPage();
        const hatalar = [];
        page.on('pageerror', e => hatalar.push('pageerror: ' + e.message));
        await page.addInitScript(o => {
            window.SIP_OTO_KAPAT_MS = 0; window.SIP_MAIL_OTO_MS = o.oto; window.SIP_BALON_MS = 0; window.SIP_DG_OKUNDU_MS = 300;
            try { if (o.zil !== undefined && o.zil !== null) localStorage.setItem('asya_sip_zil_1', String(o.zil)); } catch (e) {}
            try { if (o.gor) localStorage.setItem('asya_sip_gorulen_1', JSON.stringify(o.gor)); } catch (e) {}
        }, { oto: (opts && typeof opts.oto === 'number') ? opts.oto : -1, zil: opts ? opts.zil : undefined, gor: opts ? opts.gor : undefined });
        if (hazirla) await hazirla(page);
        await page.goto('file://' + path.join(OUT, sayfa + '.html'));
        await page.waitForTimeout(150);
        return { ctx, page, hatalar };
    }

    // ── 1) Zil: ilk ziyaret taban, okunmamış sayı, panel, Esc ──
    for (const [w, h, ad] of EKRAN) {
        let { ctx, page, hatalar } = await ac('mail_son', w, h);
        const z0 = await page.evaluate(() => ({ var: !!document.querySelector('[data-sip-zil]'), sayiGizli: document.querySelector('[data-sip-zil-sayi]').hidden,
            taban: localStorage.getItem('asya_sip_zil_1'), adet: JSON.parse(document.querySelector('[data-sip-zil-veri]').textContent).length }));
        ok(`[${ad}] zil çizildi, ilk ziyarette eski olaylar "okundu" sayılır (sayı gizli, taban yazıldı)`, z0.var && z0.sayiGizli && Number(z0.taban) > 0 && z0.adet > 0, z0);
        await ctx.close();
        ({ ctx, page, hatalar } = await ac('mail_son', w, h, null, { zil: 0 }));
        const s1 = await page.evaluate(() => ({ sayi: document.querySelector('[data-sip-zil-sayi]').textContent, gizli: document.querySelector('[data-sip-zil-sayi]').hidden,
            r: document.querySelector('[data-sip-zil-ac]').getBoundingClientRect().toJSON(), vw: document.documentElement.clientWidth }));
        ok(`[${ad}] okunmamış sayı rozeti görünür (${s1.sayi})`, !s1.gizli && Number(s1.sayi) > 0, s1);
        ok(`[${ad}] zil düğmesi ekran içinde (sağ üst bölge)`, s1.r.right <= s1.vw && s1.r.top < 400, s1.r);
        await page.click('[data-sip-zil-ac]'); await page.waitForTimeout(150);
        const p1 = await page.evaluate(() => {
            const p = document.querySelector('[data-sip-zil-panel]'), r = p.getBoundingClientRect();
            return { acik: !p.hidden, r: r.toJSON(), vw: document.documentElement.clientWidth, vh: window.innerHeight, li: p.querySelectorAll('li').length, yeni: p.querySelectorAll('li.sip-zil-yeni').length,
                ilk: (p.querySelector('li a') || {}).getAttribute ? p.querySelector('li a').getAttribute('href') : '', metin: (p.querySelector('.sip-zil-om') || {}).textContent || '',
                sayiGizli: document.querySelector('[data-sip-zil-sayi]').hidden, kayit: localStorage.getItem('asya_sip_zil_1'), tasma: document.documentElement.scrollWidth - window.innerWidth };
        });
        ok(`[${ad}] panel açıldı, öğeler listelendi ve okunmamışlar vurgulu`, p1.acik && p1.li > 0 && p1.yeni === p1.li, p1);
        ok(`[${ad}] panel ekran içinde, yatay taşma yok`, p1.r.left >= 0 && p1.r.right <= p1.vw + 1 && p1.r.top >= 0 && p1.tasma <= 0, p1);
        ok(`[${ad}] öğe bağlantısı sevkiyat/ödemeler sayfasına; metin dolu`, /^siparis_(view|odemeler|belge)\.php|^siparisler\.php/.test(p1.ilk) && p1.metin.length > 5, p1);
        ok(`[${ad}] panel açılınca okundu sayıldı (rozet gizli, kayıt güncel)`, p1.sayiGizli && Number(p1.kayit) > 0, p1);
        const ust = await page.evaluate(() => { const p = document.querySelector('[data-sip-zil-panel]'), r = p.getBoundingClientRect(); const e = document.elementFromPoint(r.left + r.width / 2, r.top + 30); return !!(e && p.contains(e)); });
        ok(`[${ad}] panel üstte (başka öğe örtmüyor)`, ust);
        await page.keyboard.press('Escape'); await page.waitForTimeout(80);
        ok(`[${ad}] Esc paneli kapatır`, await page.evaluate(() => document.querySelector('[data-sip-zil-panel]').hidden));
        ok(`[${ad}] sayfa hatası yok`, hatalar.length === 0, hatalar);
        await ctx.close();
    }

    // ── 2) Arka plan sorgusu: sırayla 3 tür, sayfa kaymaz, kart kapalı kalır, yeni gelen zile düşer ──
    {
        const istek = [];
        const hz = async pg => {
            await pg.route('**/siparis_mail.php', async r => {
                const g = JSON.parse(r.request().postData() || '{}'); istek.push(g.islem + ':' + (g.tur || ''));
                if (g.tur === 'lojistik') return r.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ ok: false, kod: 'mesgul', mesaj: 'Başka bir mail sorgusu şu an çalışıyor' }) });
                const yeni = [{ id: 999, t: '2026-11-20 10:00:00', ikon: '🆕', metin: 'Yeni sevkiyat: TC123456.10 · Sipariş (PO)', link: 'siparis_view.php?id=1' }];
                return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, bitti: true, tarama_id: 'a'.repeat(16), tur: g.tur, i: 1, toplam: 1, sayac: { alindi: 1 }, html: '<div data-test-sonuc="' + g.tur + '">sonuç</div>', bildirimler: yeni }) });
            });
        };
        const { ctx, page, hatalar } = await ac('mail_son', 1280, 900, hz, { oto: 50 });
        await page.waitForTimeout(1200);
        const d = await page.evaluate(() => ({ y: window.scrollY, kartAcik: document.querySelector('details[data-sip-yukle]').open, sayi: document.querySelector('[data-sip-zil-sayi]').textContent,
            gizli: document.querySelector('[data-sip-zil-sayi]').hidden, yenile: !document.querySelector('[data-sip-zil-yenile]').hidden, hata: (document.querySelector('[data-sip-mail-hata]') || {}).hidden,
            sonuc: !!document.querySelector('[data-test-sonuc="odeme"]'), mesgul: document.body.innerText.includes('Başka bir mail sorgusu'), busy: document.querySelector('[data-sip-mail-kap]').getAttribute('aria-busy'),
            dugme: Array.from(document.querySelectorAll('[data-sip-mail]')).every(b => !b.disabled) }));
        ok('oto: üç tür SIRAYLA sorgulandı (siparis → lojistik → odeme)', istek.join(',') === 'baslat:siparis,baslat:lojistik,baslat:odeme', istek);
        ok('oto: sayfa kaymadı, yükleme kartı kapalı kaldı', d.y === 0 && d.kartAcik === false, d);
        ok('oto: yeni gelen zile düştü (rozet 1) + "Listeyi yenile" önerisi', !d.gizli && d.sayi === '1' && d.yenile, d);
        ok('oto: meşgul hatası SESSİZ (hata kutusu gizli, metin yok), sıradaki türe geçildi', d.hata !== false && !d.mesgul && d.sonuc, d);
        ok('oto: bitince düğmeler yeniden etkin, meşgul değil', d.dugme && d.busy === 'false', d);
        ok('oto: sayfa hatası yok', hatalar.length === 0, hatalar);
        await ctx.close();
        const ist2 = [];
        const { ctx: c2, page: p2 } = await ac('mail_son', 1280, 900, async pg => { await pg.route('**/siparis_mail.php', r => { ist2.push(1); r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true,"bitti":true}' }); }); }, { oto: -1 });
        await p2.waitForTimeout(600);
        ok('oto kapalıyken (test kancası) istek gitmez', ist2.length === 0);
        await c2.close();
        const ist3 = [];
        const { ctx: c3, page: p3 } = await ac('mail_yarim', 1280, 900, async pg => { await pg.route('**/siparis_mail.php', r => { ist3.push(1); r.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true,"bitti":true}' }); }); }, { oto: 50 });
        await p3.waitForTimeout(600);
        ok('yarım tarama varken otomatik sorgu BAŞLAMAZ (Devam et şeridi kullanıcıya kalır)', ist3.length === 0);
        await c3.close();
    }

    // ── 3) "Ne değişti" baloncuğu: sistem title'ı yok; okununca nokta + çerçeve kalkar ve kalıcıdır ──
    {
        const gor = { t: '2000-01-01 00:00:00', s: {} };
        let { ctx, page } = await ac('liste', 1280, 900, null, { gor });
        const n = await page.evaluate(() => { const k = Array.from(document.querySelectorAll('.sip-dg-kap')).filter(x => !x.hidden && x.offsetParent !== null); const tr = k[0] && k[0].closest('tr');
            return { say: k.length, trTitle: tr ? tr.getAttribute('title') : 'yok', cerceve: tr ? tr.classList.contains('sip-degisti') : false, id: tr ? tr.querySelector('[data-sip-gor-id]').getAttribute('data-sip-gor-id') : '' }; });
        ok('baloncuk: nokta var, satırda native title YOK (sarı sistem baloncuğu binmez)', n.say > 0 && !n.trTitle && n.cerceve, n);
        const nokta = page.locator('.sip-dg-kap:not([hidden]) .sip-dg:visible').first();
        await nokta.scrollIntoViewIfNeeded(); await page.waitForTimeout(300);   // kaydırma baloncuğu kapatır: önce kaydır, sonra bekle
        await nokta.hover(); await page.waitForTimeout(500);
        const b = await page.evaluate(() => { const x = document.querySelector('.sip-balon'); return x && !x.hidden ? x.textContent : null; });
        ok('baloncuk: üzerine gelince özet açılır', !!b && b.includes('güncelleme'), b);
        await page.mouse.move(5, 880); await page.waitForTimeout(150);
        const s = await page.evaluate(id => { const tr = document.querySelector('[data-sip-gor-id="' + id + '"]').closest('tr'); const d = JSON.parse(localStorage.getItem('asya_sip_gorulen_1'));
            const bl = document.querySelector('.sip-balon');
            return { kap: tr.querySelector('.sip-dg-kap') ? tr.querySelector('.sip-dg-kap').hidden : true, cerceve: tr.classList.contains('sip-degisti'), g: d.g && d.g[id], balon: !bl || bl.hidden }; }, n.id);
        ok('baloncuk okunduktan sonra kapanınca nokta + çerçeve kalkar, kayıt cihazda (g)', s.kap && !s.cerceve && !!s.g && s.balon, s);
        await ctx.close();
        const kayit = { t: '2000-01-01 00:00:00', s: {}, g: { [n.id]: '2099-01-01 00:00:00' } };
        ({ ctx, page } = await ac('liste', 1280, 900, null, { gor: kayit }));
        const s2 = await page.evaluate(id => { const tr = document.querySelector('[data-sip-gor-id="' + id + '"]').closest('tr'); return { kap: tr.querySelector('.sip-dg-kap') ? tr.querySelector('.sip-dg-kap').hidden : true, cerceve: tr.classList.contains('sip-degisti') }; }, n.id);
        ok('yeniden açılışta okunan güncelleme noktası geri gelmez', s2.kap && !s2.cerceve, s2);
        await ctx.close();
        ({ ctx, page } = await ac('liste', 1280, 900, null, { gor }));
        const n3 = page.locator('.sip-dg-kap:not([hidden]) .sip-dg:visible').first();
        await n3.scrollIntoViewIfNeeded(); await page.waitForTimeout(300);
        await n3.hover(); await page.waitForTimeout(60);
        await page.mouse.move(5, 880); await page.waitForTimeout(100);
        ok('çok kısa üzerinden geçiş "okundu" sayılmaz (nokta kalır)', await page.evaluate(() => Array.from(document.querySelectorAll('.sip-dg-kap')).some(x => !x.hidden)));
        await ctx.close();
    }

    // ── 4) OGL kesintisi penceresi (uzun modal): footer ekranda, gövde kayar, Kaydet tıklanabilir; tablo eylemleri ──
    for (const [w, h, ad] of EKRAN) {
        const { ctx, page, hatalar } = await ac('odemeler_kesinti', w, h);
        const t = await page.evaluate(() => ({ pdf: document.querySelectorAll('#odDebit [data-od-kesinti]').length, elle: document.body.innerText.includes('✍ elle'), tasma: document.documentElement.scrollWidth - window.innerWidth }));
        ok(`[${ad}] kesinti tablosu: bağla/iptal düğmeleri + elle rozeti, yatay taşma yok`, t.pdf === 4 && t.elle && t.tasma <= 0, t);
        await page.click('.page-head [data-od-ac="odKesintiDlg"]'); await page.waitForTimeout(400);
        const m = await page.evaluate(() => {
            const d = document.getElementById('odKesintiDlg'), f = d.querySelector('.pm-footer'), b = d.querySelector('.pm-body'), fr = f.getBoundingClientRect();
            b.scrollTop = b.scrollHeight; const son = d.querySelector('input[name="kesilen"]').getBoundingClientRect();
            const kaydet = d.querySelector('.pm-footer .btn-primary').getBoundingClientRect(); const e = document.elementFromPoint(kaydet.left + kaydet.width / 2, kaydet.top + kaydet.height / 2);
            return { acik: d.open, footerIc: fr.bottom <= window.innerHeight + 1 && fr.top >= 0, sonGorunur: son.bottom <= fr.top + 1 && son.top >= 0, kaydetTik: !!(e && e.closest('.btn-primary')), enctype: d.querySelector('form').enctype };
        });
        ok(`[${ad}] kesinti penceresi açıldı, multipart form`, m.acik && m.enctype === 'multipart/form-data', m);
        ok(`[${ad}] uzun modal: footer ekran içinde, en alttaki alan kaydırınca görünür, Kaydet tıklanabilir`, m.footerIc && m.sonGorunur && m.kaydetTik, m);
        await page.click('#odKesintiDlg [data-od-kapat]');
        await page.click('#odDebit [data-od-kesinti="bagla"][data-no="2029-100500"]'); await page.waitForTimeout(250);
        const bg = await page.evaluate(() => { const d = document.getElementById('odKesBaglaDlg'); return { acik: d.open, no: d.querySelector('input[name="no"]').value, ozet: d.querySelector('[data-od-ozet-yazi]').textContent }; });
        ok(`[${ad}] "🔗 Sevkiyat" bağlama penceresi doğru belgeyle açılır`, bg.acik && bg.no === '2029-100500' && bg.ozet.includes('300,00'), bg);
        await page.click('#odKesBaglaDlg [data-od-kapat]');
        await page.click('#odDebit [data-od-kesinti="iptal"][data-no="2029-100501"]'); await page.waitForTimeout(250);
        const ip = await page.evaluate(() => { const d = document.getElementById('odKesIptalDlg'); return { acik: d.open, no: d.querySelector('input[name="no"]').value, req: d.querySelector('input[name="neden"]').required }; });
        ok(`[${ad}] iptal penceresi: belge no dolu, gerekçe zorunlu`, ip.acik && ip.no === '2029-100501' && ip.req, ip);
        ok(`[${ad}] sayfa hatası yok`, hatalar.length === 0, hatalar);
        if (w === 1280) await page.screenshot({ path: path.join(OUT, 'zil_odemeler.png'), fullPage: false });
        await ctx.close();
    }
    // Ekran görüntüsü (göz kontrolü): zil paneli açık
    {
        const { ctx, page } = await ac('mail_son', 1280, 900, null, { zil: 0 });
        await page.click('[data-sip-zil-ac]'); await page.waitForTimeout(200);
        await page.screenshot({ path: path.join(OUT, 'zil_panel.png') });
        await ctx.close();
        const { ctx: c2, page: p2 } = await ac('mail_son', 390, 844, null, { zil: 0 });
        await p2.click('[data-sip-zil-ac]'); await p2.waitForTimeout(200);
        await p2.screenshot({ path: path.join(OUT, 'zil_panel_mobil.png') });
        await c2.close();
    }
    await browser.close();
    console.log(`\n${pass} geçti, ${fail} kaldı`);
    process.exit(fail === 0 ? 0 : 1);
})().catch(e => { console.error(e); process.exit(2); });
