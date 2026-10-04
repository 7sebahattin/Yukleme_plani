<?php
// =========================================================
// _puantaj_toplu_duzelt.php — Kart Hareketleri: SEÇEREK toplu düzenle / toplu iptal (v302)
//
// gunluk_isci_puantaj_detay.php, Kart Hareketleri listesinin ALTINDA include eder
// (yalnız yönetici + Faz 8J hazır + mesai deposu = aktif depo). Partial fonksiyon
// TANIMLAMAZ, DB'ye dokunmaz. Seçim kutuları (input.td-sec) ve seçim çubuğu
// (#tdCubuk) sayfadadır; satır verisi kutunun data-td-* özniteliklerinden okunur
// (sunucu basar). Yazma işi pdks_faz8j_toplu_duzelt() / pdks_faz8j_toplu_iptal()'dedir
// (hep-ya-hiç; kurallar orada, UI yalnız sunar). Uçlar _puantaj_toplu_duzelt_ajax.php.
//
// Beklenen değişkenler:
//   $tdWorkDate  — 'Y-m-d' mesai günü (giriş günü HER ZAMAN bu gün)
//   $tdTipler    — [['id'=>,'name'=>], …] desteklenen tipler ($duzeltmeTipler)
//   $tdUrlDuzelt / $tdUrlIptal — ajax uç adresleri
// Kullanıcı verisi JS'te YALNIZ textContent ile basılır.
// =========================================================
if (!isset($tdWorkDate, $tdTipler, $tdUrlDuzelt, $tdUrlIptal)) { http_response_code(404); exit; }
$tdLimit  = defined('PDKS_FAZ8J_TOPLU_LIMIT') ? (int)PDKS_FAZ8J_TOPLU_LIMIT : 250;
$tdTrGun  = date('d.m.Y', strtotime($tdWorkDate));
$tdTipJson = json_encode(array_map(static fn($t) => ['id' => (int)$t['id'], 'ad' => (string)$t['name']], array_values($tdTipler)), JSON_UNESCAPED_UNICODE);
?>
<dialog id="tdDuzenle" class="pm-dialog isk-card-modal td-dlg" aria-labelledby="tdDuzenleBaslik"
        data-csrf="<?= h(csrf_token()) ?>" data-work-date="<?= h($tdWorkDate) ?>" data-limit="<?= (int)$tdLimit ?>"
        data-url="<?= h($tdUrlDuzelt) ?>" data-tipler="<?= h($tdTipJson) ?>">
<div class="pm-header td-head">
    <h2 class="pm-title" id="tdDuzenleBaslik">✏ Seçilenleri Düzenle <span class="td-baslik-sayi" id="tdDuzenleSayi"></span></h2>
    <button type="button" class="pm-close" aria-label="Kapat" onclick="this.closest('dialog').close()">✕</button>
</div>
<form class="td-form" onsubmit="return false" autocomplete="off">
    <div class="td-govde">
        <p class="td-bilgi">Mesai günü <strong><?= h($tdTrGun) ?></strong>. Giriş günü her zaman mesai günüdür; çıkış saati girişten küçükse çıkış <strong>ertesi gün</strong> sayılır (<span class="td-gun-ornek">+1 gün</span>). Kart değişmez. Değişmeyen satırlar atlanır. Tek satır bile hatalıysa hiçbir kayıt yazılmaz.</p>

        <fieldset class="td-hep">
            <legend>Hepsine uygula</legend>
            <div class="td-hep-izgara">
                <label class="td-alan"><span class="td-et">Tip</span>
                    <select id="tdHepTip"><option value="">— Değiştirme —</option><?php foreach ($tdTipler as $t): ?><option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?></select></label>
                <label class="td-alan"><span class="td-et">Giriş saati</span><input type="time" id="tdHepGiris"></label>
                <label class="td-alan"><span class="td-et">Çıkış saati</span><input type="time" id="tdHepCikis"></label>
                <div class="td-alan td-hep-btn"><span class="td-et" aria-hidden="true">&nbsp;</span><button type="button" class="btn" id="tdHepUygula">⤓ Uygula</button></div>
            </div>
            <p class="td-hep-not muted">Boş bırakılan alan değiştirilmez. <span id="tdHepSonuc" aria-live="polite"></span></p>
        </fieldset>

        <div class="td-liste-bas" aria-hidden="true"><span>Kart</span><span>Tip</span><span>Giriş</span><span>Çıkış</span><span></span></div>
        <div class="td-liste" id="tdListe" role="list" aria-label="Seçilen kart hareketleri"></div>

        <div class="pdks-form-grid td-neden">
            <label><span class="form-label">Düzeltme nedeni *</span><textarea id="tdNeden" maxlength="500" rows="2" required></textarea></label>
            <label><span class="form-label">Açıklama</span><textarea id="tdNot" maxlength="1000" rows="2"></textarea></label>
        </div>
        <div class="td-mesaj" id="tdMesaj" role="alert" hidden></div>
    </div>
    <div class="td-foot">
        <span class="td-ozet" id="tdOzet" aria-live="polite"></span>
        <button type="button" class="btn btn-primary" id="tdKaydet">Kaydet</button>
        <button type="button" class="btn" onclick="this.closest('dialog').close()">Vazgeç</button>
    </div>
</form>
</dialog>

<dialog id="tdIptal" class="pm-dialog isk-card-modal td-dlg td-dlg-iptal" aria-labelledby="tdIptalBaslik"
        data-csrf="<?= h(csrf_token()) ?>" data-url="<?= h($tdUrlIptal) ?>">
<div class="pm-header td-head">
    <h2 class="pm-title" id="tdIptalBaslik">🗑 Seçilenleri İptal Et <span class="td-baslik-sayi" id="tdIptalSayi"></span></h2>
    <button type="button" class="pm-close" aria-label="Kapat" onclick="this.closest('dialog').close()">✕</button>
</div>
<form class="td-form" onsubmit="return false" autocomplete="off">
    <div class="td-govde">
        <p class="td-bilgi">Aşağıdaki çalışma kayıtları puantajdan çıkarılır. Ham kart okutma geçmişi silinmez. Tek kayıt bile iptal edilemezse hiçbiri iptal edilmez.</p>
        <ul class="td-iptal-liste" id="tdIptalListe"></ul>
        <label class="td-iptal-neden"><span class="form-label">İptal nedeni *</span><textarea id="tdIptalNeden" maxlength="500" rows="3" required></textarea></label>
        <div class="td-mesaj" id="tdIptalMesaj" role="alert" hidden></div>
    </div>
    <div class="td-foot">
        <button type="button" class="btn btn-danger" id="tdIptalKaydet">Kaydı İptal Et</button>
        <button type="button" class="btn" onclick="this.closest('dialog').close()">Vazgeç</button>
    </div>
</form>
</dialog>
<script>
// v302 — Kart Hareketleri seçim + toplu düzenle / toplu iptal. Kurallar SUNUCUDA
// (pdks_faz8j_toplu_duzelt / _iptal, hep-ya-hiç); burada yalnız seçim, liste ve gönderim.
(function () {
    var dD = document.getElementById('tdDuzenle'), dI = document.getElementById('tdIptal');
    var cubuk = document.getElementById('tdCubuk');
    if (!dD || !dI || !cubuk) return;
    var gun = dD.getAttribute('data-work-date');
    var limit = parseInt(dD.getAttribute('data-limit'), 10) || 250;
    var tipler = []; try { tipler = JSON.parse(dD.getAttribute('data-tipler') || '[]'); } catch (e) { tipler = []; }
    var ertesi = (function () { var p = gun.split('-'); return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2] + 1)).toISOString().slice(0, 10); })();
    var elSayi = document.getElementById('tdSeciliSayi'), elLimit = document.getElementById('tdLimitNot');
    var bDuz = document.getElementById('tdDuzenleBtn'), bIpt = document.getElementById('tdIptalBtn'), bTem = document.getElementById('tdTemizleBtn');
    var liste = document.getElementById('tdListe'), elMesaj = document.getElementById('tdMesaj'), elOzet = document.getElementById('tdOzet');
    var bKay = document.getElementById('tdKaydet'), bIKay = document.getElementById('tdIptalKaydet');
    var gonderiyor = false, istekD = '', istekI = '', iptalIds = [];

    function istekId() {
        var a = new Uint8Array(16);
        (window.crypto || window.msCrypto).getRandomValues(a);
        return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    }
    function kutular() { return [].slice.call(document.querySelectorAll('input.td-sec')); }
    function gorunur(el) { return !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length); }
    // Seçili dönemler, GÖRÜNEN görünümün (masaüstü tablo / mobil kart) güncel sırasıyla, tekil.
    function seciliKutular() {
        var hepsi = kutular(), gor = hepsi.filter(gorunur), kaynak = gor.length ? gor : hepsi, gor_ = {}, sonuc = [];
        kaynak.forEach(function (c) { if (c.checked && !gor_[c.value]) { gor_[c.value] = 1; sonuc.push(c); } });
        return sonuc;
    }
    function tekilSayi() { var s = {}; kutular().forEach(function (c) { s[c.value] = 1; }); return Object.keys(s).length; }
    function cubukGuncelle() {
        var n = seciliKutular().length, top = tekilSayi(), asim = n > limit;
        elSayi.textContent = n;
        cubuk.classList.toggle('td-var', n > 0);
        bDuz.disabled = bIpt.disabled = n === 0 || asim;
        bTem.disabled = n === 0;
        if (elLimit) elLimit.hidden = !asim;
        document.querySelectorAll('input.td-tumu').forEach(function (t) {
            t.checked = n > 0 && n === top; t.indeterminate = n > 0 && n < top;
        });
    }
    function isaretle(deger, durum) {
        document.querySelectorAll('input.td-sec').forEach(function (c) {
            if (c.value !== deger) return;
            c.checked = durum;
            var kap = c.closest('tr, .pdks-card-item'); if (kap) kap.classList.toggle('td-secili', durum);
        });
    }
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (!t || !t.classList) return;
        if (t.classList.contains('td-sec')) { isaretle(t.value, t.checked); cubukGuncelle(); }
        else if (t.classList.contains('td-tumu')) { var d = t.checked; kutular().forEach(function (c) { isaretle(c.value, d); }); cubukGuncelle(); }
    });
    bTem.addEventListener('click', function () { kutular().forEach(function (c) { isaretle(c.value, false); }); cubukGuncelle(); });

    function veri(c) {
        return { pid: parseInt(c.value, 10), kart: c.getAttribute('data-td-kart') || '', tip: parseInt(c.getAttribute('data-td-tip'), 10) || 0,
                 tipAd: c.getAttribute('data-td-tip-ad') || '', girisTarih: c.getAttribute('data-td-giris-tarih') || '', giris: c.getAttribute('data-td-giris') || '',
                 cikisTarih: c.getAttribute('data-td-cikis-tarih') || '', cikis: c.getAttribute('data-td-cikis') || '', kartsiz: c.getAttribute('data-td-kartsiz') === '1' };
    }
    function el(tag, cls, metin) { var x = document.createElement(tag); if (cls) x.className = cls; if (metin !== undefined) x.textContent = metin; return x; }
    function cikisGunu(g, c) { if (!c) return ''; return (g && c < g) ? ertesi : gun; }
    function aralikMetni(v) { return (v.giris || '—') + '–' + (v.cikis ? v.cikis + (v.cikisTarih && v.cikisTarih !== gun ? ' (+1 gün)' : '') : 'çıkış yok'); }
    function ac(d) {
        document.querySelectorAll('dialog[open]').forEach(function (x) { x.close(); });
        d.showModal();
    }
    function mesajYaz(kutu, satirlar) {
        kutu.textContent = '';
        if (!satirlar.length) { kutu.hidden = true; return; }
        var ul = el('ul'); satirlar.forEach(function (s) { ul.appendChild(el('li', '', s)); });
        kutu.appendChild(ul); kutu.hidden = false;
    }
    function istek(url, govde) {
        return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(govde) })
            .then(function (r) { return r.json().catch(function () { return { ok: false, hata: 'Sunucu yanıtı okunamadı.' }; }); })
            .catch(function () { return { ok: false, hata: 'Sunucuya ulaşılamadı. Bağlantınızı kontrol edin.' }; });
    }
    // Yanıttaki hatalar: [{period_id, card_no, hata}] ya da düz metin → {satir: {pid: metin}, genel: [..]}
    function hatalariAyir(y) {
        var genel = [], satir = {};
        var ust = (y && (y.hata || y.error)) || '';
        if (ust) genel.push(String(ust));
        ((y && y.hatalar) || []).forEach(function (h) {
            if (h && typeof h === 'object') {
                var pid = parseInt(h.period_id, 10), m = String(h.hata || h.error || 'Hata');
                if (pid) satir[pid] = (satir[pid] ? satir[pid] + ' ' : '') + m;
                else genel.push((h.card_no ? h.card_no + ': ' : '') + m);
            } else if (h !== null && h !== undefined && String(h) !== '' && String(h) !== ust) genel.push(String(h));
        });
        return { genel: genel, satir: satir };
    }

    // ── Seçilenleri Düzenle ─────────────────────────────────────────────
    function satirlar() { return [].slice.call(liste.querySelectorAll('.td-satir')); }
    function satirDurum(s) {
        var tip = s.querySelector('.td-f-tip'), g = s.querySelector('.td-f-giris'), c = s.querySelector('.td-f-cikis');
        var cg = cikisGunu(g.value, c.value);
        var gunEl = s.querySelector('.td-gun'); gunEl.hidden = !(c.value && cg === ertesi);
        var o = s.__v;
        var degisti = parseInt(tip.value, 10) !== o.tip || g.value !== o.giris || (o.girisTarih !== '' && o.girisTarih !== gun)
            || c.value !== o.cikis || (c.value !== '' && cg !== o.cikisTarih);
        s.classList.toggle('td-degisti', degisti);
        s.querySelector('.td-isaret').hidden = !degisti;
        s.querySelector('.td-eski').hidden = !degisti;
        return degisti;
    }
    function ozetGuncelle() {
        var n = 0, ss = satirlar(); ss.forEach(function (s) { if (satirDurum(s)) n++; });
        elOzet.textContent = n + ' / ' + ss.length + ' satırda değişiklik';
    }
    function satirKur(v) {
        var s = el('div', 'td-satir'); s.setAttribute('role', 'listitem'); s.setAttribute('data-pid', String(v.pid)); s.__v = v;
        var k = el('div', 'td-hucre td-kart');
        k.appendChild(el('span', 'pdks-uid td-kart-no', v.kart));
        if (v.kartsiz) k.appendChild(el('span', 'pdks-badge pdks-badge-kartsiz', 'Kartsız'));
        var isr = el('span', 'td-isaret', '● Değişti'); isr.hidden = true; k.appendChild(isr);
        s.appendChild(k);

        var lt = el('label', 'td-alan td-hucre'); lt.appendChild(el('span', 'td-et', 'Tip'));
        var sel = el('select', 'td-f-tip'); sel.setAttribute('aria-label', v.kart + ' — işçi tipi');
        var var_ = tipler.some(function (t) { return t.id === v.tip; });
        if (!var_) { var om = el('option', '', (v.tipAd || 'Mevcut tip') + ' (değiştirmeden bırak)'); om.value = String(v.tip); sel.appendChild(om); }
        tipler.forEach(function (t) { var o = el('option', '', t.ad); o.value = String(t.id); sel.appendChild(o); });
        sel.value = String(v.tip);
        lt.appendChild(sel); s.appendChild(lt);

        var lg = el('label', 'td-alan td-hucre'); lg.appendChild(el('span', 'td-et', 'Giriş'));
        var gi = el('input', 'td-f-giris'); gi.type = 'time'; gi.value = v.giris; gi.required = true; gi.setAttribute('aria-label', v.kart + ' — giriş saati');
        lg.appendChild(gi); s.appendChild(lg);

        var lc = el('label', 'td-alan td-hucre'); lc.appendChild(el('span', 'td-et', 'Çıkış'));
        var ck = el('span', 'td-cikis-kap');
        var ci = el('input', 'td-f-cikis'); ci.type = 'time'; ci.value = v.cikis; ci.setAttribute('aria-label', v.kart + ' — çıkış saati' + (v.kartsiz ? ' (kartsızda zorunlu)' : ''));
        var gn = el('span', 'td-gun', '+1 gün'); gn.hidden = true; gn.title = 'Çıkış ertesi gün (' + ertesi.split('-').reverse().join('.') + ')';
        ck.appendChild(ci); ck.appendChild(gn); lc.appendChild(ck); s.appendChild(lc);

        var es = el('div', 'td-hucre td-eski-kap');
        var eski = el('span', 'td-eski muted', 'Önce: ' + (v.tipAd || '—') + ' · ' + aralikMetni(v)); eski.hidden = true;
        es.appendChild(eski); s.appendChild(es);

        var hm = el('div', 'td-satir-mesaj'); hm.hidden = true; hm.setAttribute('role', 'alert'); s.appendChild(hm);
        return s;
    }
    function satirHatalariTemizle() {
        satirlar().forEach(function (s) { s.classList.remove('td-satir-hata'); var m = s.querySelector('.td-satir-mesaj'); m.textContent = ''; m.hidden = true; });
    }
    function duzenleAc() {
        var sec = seciliKutular();
        if (!sec.length || sec.length > limit) return;
        liste.textContent = '';
        sec.forEach(function (c) { liste.appendChild(satirKur(veri(c))); });
        document.getElementById('tdDuzenleSayi').textContent = '(' + sec.length + ')';
        ['tdHepTip', 'tdHepGiris', 'tdHepCikis'].forEach(function (i) { document.getElementById(i).value = ''; });
        document.getElementById('tdHepSonuc').textContent = '';
        document.getElementById('tdNeden').value = ''; document.getElementById('tdNot').value = '';
        mesajYaz(elMesaj, []);
        istekD = istekId(); gonderiyor = false; bKay.disabled = false; bKay.textContent = 'Kaydet';
        ozetGuncelle();
        ac(dD);
        dD.querySelector('.td-govde').scrollTop = 0;
    }
    bDuz.addEventListener('click', duzenleAc);
    liste.addEventListener('input', ozetGuncelle);
    liste.addEventListener('change', ozetGuncelle);
    document.getElementById('tdHepUygula').addEventListener('click', function () {
        var t = document.getElementById('tdHepTip').value, g = document.getElementById('tdHepGiris').value, c = document.getElementById('tdHepCikis').value;
        if (!t && !g && !c) { document.getElementById('tdHepSonuc').textContent = 'Uygulanacak değer girin.'; return; }
        var ss = satirlar();
        ss.forEach(function (s) {
            if (t) s.querySelector('.td-f-tip').value = t;
            if (g) s.querySelector('.td-f-giris').value = g;
            if (c) s.querySelector('.td-f-cikis').value = c;
        });
        ozetGuncelle();
        document.getElementById('tdHepSonuc').textContent = ss.length + ' satıra uygulandı.';
    });
    bKay.addEventListener('click', function () {
        if (gonderiyor) return;
        satirHatalariTemizle();
        var h = [], ss = satirlar(), neden = document.getElementById('tdNeden').value.trim(), degisen = 0;
        if (!neden) h.push('Düzeltme nedeni zorunludur.');
        ss.forEach(function (s) {
            if (!s.querySelector('.td-f-giris').value) h.push(s.__v.kart + ': giriş saati zorunludur.');
            if (satirDurum(s)) degisen++;
        });
        if (!degisen) h.push('Hiçbir satırda değişiklik yok.');
        mesajYaz(elMesaj, h);
        if (h.length) { elMesaj.scrollIntoView({ block: 'nearest' }); return; }
        var govde = { csrf: dD.getAttribute('data-csrf'), istek_id: istekD, reason: neden, note: document.getElementById('tdNot').value.trim(),
            satirlar: ss.map(function (s) {
                var g = s.querySelector('.td-f-giris').value, c = s.querySelector('.td-f-cikis').value;
                return { period_id: s.__v.pid, worker_type_id: parseInt(s.querySelector('.td-f-tip').value, 10) || 0,
                         entry_clock: g, exit_date: cikisGunu(g, c), exit_clock: c };
            }) };
        gonderiyor = true; bKay.disabled = true; bKay.textContent = 'Kaydediliyor…';
        istek(dD.getAttribute('data-url'), govde).then(function (y) {
            if (y && y.ok === true) { if (y.redirect) location.href = y.redirect; else location.reload(); return; }
            gonderiyor = false; bKay.disabled = false; bKay.textContent = 'Kaydet';
            var a = hatalariAyir(y || {}), ilk = null;
            satirlar().forEach(function (s) {
                var m = a.satir[s.__v.pid]; if (!m) return;
                s.classList.add('td-satir-hata');
                var me = s.querySelector('.td-satir-mesaj'); me.textContent = m; me.hidden = false;
                if (!ilk) ilk = s;
            });
            if (!a.genel.length && !ilk) a.genel.push('Kayıt yapılamadı.');
            mesajYaz(elMesaj, a.genel);
            (ilk || elMesaj).scrollIntoView({ block: 'nearest' });
        });
    });

    // ── Seçilenleri İptal Et ────────────────────────────────────────────
    var iListe = document.getElementById('tdIptalListe'), iMesaj = document.getElementById('tdIptalMesaj');
    bIpt.addEventListener('click', function () {
        var sec = seciliKutular();
        if (!sec.length || sec.length > limit) return;
        iListe.textContent = ''; iptalIds = [];
        sec.forEach(function (c) {
            var v = veri(c), li = el('li', 'td-iptal-oge'); li.setAttribute('data-pid', String(v.pid));
            li.appendChild(el('span', 'pdks-uid', v.kart));
            li.appendChild(el('span', 'td-iptal-ayrinti', (v.tipAd || '—') + ' · ' + aralikMetni(v)));
            var m = el('span', 'td-satir-mesaj'); m.hidden = true; li.appendChild(m);
            iListe.appendChild(li); iptalIds.push(v.pid);
        });
        document.getElementById('tdIptalSayi').textContent = '(' + sec.length + ')';
        document.getElementById('tdIptalNeden').value = '';
        mesajYaz(iMesaj, []);
        istekI = istekId(); gonderiyor = false; bIKay.disabled = false; bIKay.textContent = 'Kaydı İptal Et';
        ac(dI);
        dI.querySelector('.td-govde').scrollTop = 0;
    });
    bIKay.addEventListener('click', function () {
        if (gonderiyor) return;
        [].forEach.call(iListe.children, function (li) { li.classList.remove('td-satir-hata'); var m = li.querySelector('.td-satir-mesaj'); m.textContent = ''; m.hidden = true; });
        var neden = document.getElementById('tdIptalNeden').value.trim();
        if (!neden) { mesajYaz(iMesaj, ['İptal nedeni zorunludur.']); return; }
        mesajYaz(iMesaj, []);
        gonderiyor = true; bIKay.disabled = true; bIKay.textContent = 'İptal ediliyor…';
        istek(dI.getAttribute('data-url'), { csrf: dI.getAttribute('data-csrf'), istek_id: istekI, reason: neden, period_ids: iptalIds.slice() }).then(function (y) {
            if (y && y.ok === true) { if (y.redirect) location.href = y.redirect; else location.reload(); return; }
            gonderiyor = false; bIKay.disabled = false; bIKay.textContent = 'Kaydı İptal Et';
            var a = hatalariAyir(y || {}), ilk = null;
            [].forEach.call(iListe.children, function (li) {
                var m = a.satir[parseInt(li.getAttribute('data-pid'), 10)]; if (!m) return;
                li.classList.add('td-satir-hata');
                var me = li.querySelector('.td-satir-mesaj'); me.textContent = m; me.hidden = false;
                if (!ilk) ilk = li;
            });
            if (!a.genel.length && !ilk) a.genel.push('İptal yapılamadı.');
            mesajYaz(iMesaj, a.genel);
            (ilk || iMesaj).scrollIntoView({ block: 'nearest' });
        });
    });

    // Tarayıcı form durumunu geri yüklediyse (geri tuşu) satır vurgusu ve eş kutular eşitlensin.
    kutular().forEach(function (c) { if (c.checked) isaretle(c.value, true); });
    cubukGuncelle();
})();
</script>
