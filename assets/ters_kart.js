/* =========================================================
 * assets/ters_kart.js — v354 TERS KART TAKİP istemcisi
 * Yalnız ters_kart*.php yükler (style.css / app.js / pdks.js'e dokunmaz).
 *
 * İstemci YALNIZ ham okuma + kaynak gönderir (usb_decimal | web_nfc); kart,
 * çavuş, sayım ve tüm kararlar SUNUCUDA verilir. UID normalizasyonu sunucuda
 * (pdks_gunluk_uid_kanonik) — burada ikinci bir UID mantığı YOK. Web NFC için
 * config/pdks.php'nin bastığı paylaşılan window.PdksNfcOku kullanılır.
 * Kullanıcı verisi DOM'a yalnız textContent ile yazılır.
 * ========================================================= */
(function () {
    'use strict';
    var TK = window.TK = {};

    TK.csrf = function () {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.getAttribute('content') || '' : '';
    };

    /** JSON POST. Ağ hatası / 403 / bozuk yanıt → {ok:false, hata}. Asla reddetmez. */
    TK.post = function (url, govde) {
        govde = govde || {};
        govde.csrf = TK.csrf();
        return fetch(url, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(govde)
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, kod: 'yanit', hata: 'Sunucu yanıtı okunamadı (' + r.status + ').' }; });
        }).then(function (d) {
            if (d && !d.ok && !d.hata) d.hata = d.error || 'İşlem yapılamadı.';
            return d;
        }).catch(function () {
            return { ok: false, kod: 'ag', hata: 'Bağlantı hatası — okutma kaydedilmedi. İnternet bağlantısını kontrol edip kartı tekrar okutun.' };
        });
    };

    TK.istekId = function () {
        var a = new Uint8Array(16);
        (window.crypto || window.msCrypto).getRandomValues(a);
        return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
    };

    TK.el = function (tag, sinif, metin) {
        var e = document.createElement(tag);
        if (sinif) e.className = sinif;
        if (metin != null) e.textContent = String(metin);
        return e;
    };

    TK.veri = function (id) {
        var e = document.getElementById(id);
        if (!e) return null;
        try { return JSON.parse(e.textContent || 'null'); } catch (x) { return null; }
    };

    // ── Ses (ilk dokunuşta açılır) ──
    var ac = null;
    function sesHazirla() {
        if (ac) return;
        try { ac = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) { ac = null; }
    }
    document.addEventListener('pointerdown', sesHazirla, { once: true });
    document.addEventListener('keydown', sesHazirla, { once: true });
    TK.ses = function (tur) {
        if (!ac) return;
        try {
            var o = ac.createOscillator(), g = ac.createGain();
            o.type = 'sine';
            o.frequency.value = tur === 'ok' ? 880 : (tur === 'bilgi' ? 660 : 220);
            g.gain.value = 0.08;
            o.connect(g); g.connect(ac.destination);
            o.start(); o.stop(ac.currentTime + (tur === 'hata' ? 0.35 : 0.12));
        } catch (e) { /* ses yoksa sessiz */ }
    };

    /**
     * Sonuç bandı: tur ok (yeşil) | hata (kırmızı) | uyari (turuncu) | bilgi (mavi).
     * satirlar: [[etiket, değer], …] (textContent).
     */
    TK.sonuc = function (kutu, tur, baslik, satirlar, ms) {
        if (!kutu) return;
        kutu.className = 'tk-sonuc tk-sonuc-' + tur;
        kutu.textContent = '';
        kutu.appendChild(TK.el('div', 'tk-sonuc-baslik', baslik));
        (satirlar || []).forEach(function (s) {
            if (s == null) return;
            var r = TK.el('div', 'tk-sonuc-satir');
            if (Array.isArray(s)) { r.appendChild(TK.el('span', 'tk-sonuc-et', s[0])); r.appendChild(TK.el('strong', null, s[1])); }
            else r.textContent = s;
            kutu.appendChild(r);
        });
        kutu.hidden = false;
        TK.ses(tur === 'ok' ? 'ok' : (tur === 'hata' ? 'hata' : 'bilgi'));
        clearTimeout(kutu._t);
        if (ms) kutu._t = setTimeout(function () { kutu.hidden = true; }, ms);
    };

    /**
     * Kart okuyucu: USB HID (klavye gibi rakam + Enter yazar) gizli input ile,
     * Web NFC (Android Chrome) paylaşılan PdksNfcOku ile. Okumalar SIRAYLA işlenir
     * (hızlı art arda okutmada hiçbiri kaybolmaz); aynı kart tekrarMs içinde
     * yeniden okunursa yok sayılır (okuyucunun çift tetiklemesi).
     * opts: {input, nfcBtn, nfcDurum, isle(ham, kaynak) → Promise, tekrarMs}
     */
    TK.okuyucu = function (opts) {
        var input = opts.input, tekrarMs = opts.tekrarMs || 1500;
        var kuyruk = [], calisiyor = false, son = { k: '', t: 0 }, zam = null, aktif = true;
        var ince = window.matchMedia && window.matchMedia('(any-pointer: fine)').matches;

        function odak() { if (aktif && ince && input && document.activeElement !== input) { try { input.focus({ preventScroll: true }); } catch (e) { input.focus(); } } }
        function isle() {
            if (calisiyor || !kuyruk.length) return;
            calisiyor = true;
            var o = kuyruk.shift();
            Promise.resolve(opts.isle(o.ham, o.kaynak)).catch(function () {}).then(function () { calisiyor = false; isle(); });
        }
        function ekle(ham, kaynak) {
            ham = String(ham || '').trim();
            if (!ham || !aktif) return;
            var k = kaynak + ':' + ham, simdi = Date.now();
            if (son.k === k && simdi - son.t < tekrarMs) return;
            son = { k: k, t: simdi };
            kuyruk.push({ ham: ham, kaynak: kaynak });
            isle();
        }
        if (input) {
            input.addEventListener('input', function () {
                input.value = input.value.replace(/\D+/g, '');
                clearTimeout(zam);
                zam = setTimeout(function () { var v = input.value; input.value = ''; ekle(v, 'usb_decimal'); }, 350);
            });
            input.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                e.preventDefault(); clearTimeout(zam);
                var v = input.value; input.value = ''; ekle(v, 'usb_decimal');
            });
            document.addEventListener('click', function (e) {
                if (e.target.closest('input, select, textarea, button, a, dialog, label, summary')) return;
                odak();
            });
            setTimeout(odak, 50);
        }
        var nfcBtn = opts.nfcBtn, nfcDinlemede = false;
        if (nfcBtn) {
            if (window.PdksNfcOku && window.PdksNfcOku.destekli()) {
                nfcBtn.hidden = false;
                nfcBtn.addEventListener('click', function () {
                    sesHazirla();
                    if (nfcDinlemede) return;
                    window.PdksNfcOku.baslat({
                        onOkuma: function (ev) {
                            var ham = (ev && ev.serialNumber != null) ? String(ev.serialNumber) : '';
                            if (ham) ekle(ham, 'web_nfc');
                            else if (opts.nfcHata) opts.nfcHata('Kart algılandı ama seri numarası boş geldi — kartı tekrar yaklaştırın.');
                        },
                        onOkumaHatasi: function () { if (opts.nfcHata) opts.nfcHata('NFC okuma hatası — kartı tekrar yaklaştırın.'); },
                        onBasladi: function () {
                            nfcDinlemede = true; nfcBtn.disabled = true; nfcBtn.textContent = '📶 NFC dinleniyor';
                            if (opts.nfcDurum) opts.nfcDurum.textContent = 'NFC açık — kartı telefona yaklaştırın.';
                        },
                        onHata: function () { if (opts.nfcHata) opts.nfcHata('NFC başlatılamadı — düğmeye tekrar dokunun.'); }
                    });
                });
            } else {
                nfcBtn.hidden = true;   // iOS / masaüstü: Web NFC yok — varmış gibi gösterme
            }
        }
        return {
            durdur: function () { aktif = false; },
            baslat: function () { aktif = true; odak(); },
            odak: odak,
            ekle: ekle
        };
    };

    TK.saatMetni = function (s) { return s ? String(s).slice(11, 16) : '—'; };

    /** Onaylı işlem dialogu (native <dialog>): başlık, metin, gerekçe alanı (ops.). */
    TK.sor = function (dlg, ayar) {
        return new Promise(function (coz) {
            if (!dlg || typeof dlg.showModal !== 'function') { coz(window.confirm(ayar.metin || ayar.baslik) ? { gerekce: '' } : null); return; }
            dlg.querySelector('[data-tk-dlg-baslik]').textContent = ayar.baslik || '';
            dlg.querySelector('[data-tk-dlg-metin]').textContent = ayar.metin || '';
            var alan = dlg.querySelector('[data-tk-dlg-alan]'), ta = dlg.querySelector('[data-tk-dlg-gerekce]');
            var hata = dlg.querySelector('[data-tk-dlg-hata]');
            alan.hidden = !ayar.gerekce; ta.value = ''; hata.hidden = true;
            dlg.querySelector('[data-tk-dlg-et]').textContent = ayar.gerekceEtiket || 'Gerekçe';
            var tamam = dlg.querySelector('[data-tk-dlg-tamam]');
            tamam.textContent = ayar.tamam || 'Onayla';
            tamam.className = 'btn ' + (ayar.tehlike ? 'btn-danger' : 'btn-primary');
            function bitir(sonuc) { tamam.onclick = null; dlg.querySelector('[data-tk-dlg-vazgec]').onclick = null; dlg.onclose = null; if (dlg.open) dlg.close(); coz(sonuc); }
            tamam.onclick = function () {
                var g = ta.value.trim();
                if (ayar.gerekce === 'zorunlu' && !g) { hata.textContent = 'Gerekçe zorunludur.'; hata.hidden = false; ta.focus(); return; }
                bitir({ gerekce: g });
            };
            dlg.querySelector('[data-tk-dlg-vazgec]').onclick = function () { bitir(null); };
            dlg.onclose = function () { bitir(null); };
            dlg.showModal();
            (ayar.gerekce ? ta : tamam).focus();
        });
    };

    // =========================================================
    // SAYFA: SABAH SAYIMI (ters_kart_sayim.php)
    // =========================================================
    function sayimSayfasi(kok) {
        var v = TK.veri('tkSayimVeri');
        if (!v || !v.sayim_id) return;
        var url = 'ters_kart_sayim.php?ajax=';
        var sonucKutu = document.getElementById('tkSonuc');
        var kalanListe = document.getElementById('tkKalanListe');
        var saatIn = document.getElementById('tkGirisSaati'), nedenIn = document.getElementById('tkNeden'), nedenAlan = document.getElementById('tkNedenAlan');
        var beyanIn = document.getElementById('tkBeyan'), onayBtn = document.getElementById('tkOnayBtn');
        var onizKutu = document.getElementById('tkOnizleme'), dlg = document.getElementById('tkDlg');
        var istek = TK.istekId(), onizNo = 0, onizZam = null;

        function sayac(s) {
            if (!s) return;
            ['havuz_aktif', 'kalan', 'dagitilacak', 'haric'].forEach(function (k) {
                var e = kok.querySelector('[data-tk-sayac="' + k + '"]');
                if (e && s[k] != null) e.textContent = s[k];
            });
        }
        function kalanCip(kartId, cardNo, tip) {
            if (!kalanListe || kalanListe.querySelector('[data-kart="' + kartId + '"]')) return;
            var c = TK.el('span', 'tk-cip'); c.dataset.kart = kartId;
            c.appendChild(TK.el('span', null, cardNo));
            if (tip) c.appendChild(TK.el('small', null, tip));
            var x = TK.el('button', 'tk-cip-x', '×'); x.type = 'button'; x.title = 'Listeden çıkar'; x.setAttribute('aria-label', cardNo + ' kartını kalan listesinden çıkar');
            x.addEventListener('click', function () { karar(kartId, 'kalan_sil', cardNo); });
            c.appendChild(x);
            kalanListe.insertBefore(c, kalanListe.firstChild);
            var bos = document.getElementById('tkKalanBos'); if (bos) bos.hidden = true;
        }
        function karar(kartId, k, cardNo) {
            return TK.post(url + 'karar', { sayim_id: v.sayim_id, kart_id: kartId, karar: k }).then(function (d) {
                if (!d.ok) { TK.sonuc(sonucKutu, 'hata', '⛔ ' + d.hata, [], 6000); return; }
                if (k === 'kalan_sil') { var c = kalanListe.querySelector('[data-kart="' + kartId + '"]'); if (c) c.remove(); }
                sayac(d.sayac);
                if (k === 'kayip' || k === 'dagitildi' || k === 'teyit_sil') location.reload();
                else onizle();
            });
        }
        kok.addEventListener('click', function (e) {
            var b = e.target.closest('[data-tk-karar]');
            if (!b) return;
            var k = b.getAttribute('data-tk-karar'), kid = +b.getAttribute('data-kart'), no = b.getAttribute('data-no') || '';
            if (k === 'kayip') {
                TK.sor(dlg, { baslik: no + ' — KAYIP işaretle', metin: 'Kart kayıp sayılır: havuzda kalır ama bugünkü sayıma girmez ve kioskta girişe kapanır. Bulununca Kart Havuzu ekranından Kullanıma Al.', gerekce: 'istege', gerekceEtiket: 'Not (isteğe bağlı)', tamam: 'Kayıp işaretle', tehlike: true })
                    .then(function (r) { if (r) TK.post(url + 'karar', { sayim_id: v.sayim_id, kart_id: kid, karar: 'kayip', not: r.gerekce }).then(function (d) { if (!d.ok) TK.sonuc(sonucKutu, 'hata', '⛔ ' + d.hata, [], 6000); else location.reload(); }); });
            } else karar(kid, k, no);
        });

        function okut(ham, kaynak) {
            return TK.post(url + 'okut', { sayim_id: v.sayim_id, ham_uid: ham, kaynak: kaynak }).then(function (d) {
                if (d.ok && d.mukerrer) {
                    TK.sonuc(sonucKutu, 'bilgi', 'ℹ ' + d.card_no + ' zaten okutuldu — tekrar sayılmadı', [], 2500);
                } else if (d.ok) {
                    TK.sonuc(sonucKutu, 'ok', '✅ ' + d.card_no + ' kalan olarak sayıldı', [['Tip', d.tip_adi || '—']], 2500);
                    kalanCip(d.kart_id, d.card_no, d.tip_adi);
                } else {
                    TK.sonuc(sonucKutu, 'hata', '⛔ ' + d.hata, [], 6000);
                }
                sayac(d.sayac);
                if (d.ok) onizleGecikmeli();
            });
        }
        if (v.durum === 'taslak') {
            TK.okuyucu({
                input: document.getElementById('tkScanInput'), nfcBtn: document.getElementById('tkNfcBtn'),
                nfcDurum: document.getElementById('tkNfcDurum'),
                nfcHata: function (m) { TK.sonuc(sonucKutu, 'hata', '⛔ ' + m, [], 5000); },
                isle: okut
            });
        }

        // ── Önizleme (salt okunur) + onay ──
        function nedenGoster() {
            if (!nedenAlan || !saatIn) return;
            nedenAlan.hidden = !(!v.varsayilan_saat || (saatIn.value && saatIn.value !== v.varsayilan_saat));
        }
        function onizleGecikmeli() { clearTimeout(onizZam); onizZam = setTimeout(onizle, 400); }
        function onizle() {
            if (v.durum !== 'taslak' || !onizKutu) return;
            var no = ++onizNo;
            nedenGoster();
            if (onayBtn) onayBtn.disabled = true;
            return TK.post(url + 'onizle', { sayim_id: v.sayim_id, giris_saati: saatIn ? saatIn.value : '', neden: nedenIn ? nedenIn.value : '', beyan: beyanIn ? beyanIn.value : '' }).then(function (r) {
                if (no !== onizNo) return;   // eski yanıt
                onizKutu.textContent = '';
                if (!r.ok) { onizKutu.appendChild(TK.el('div', 'tk-kutu tk-kutu-hata', r.hata)); return; }
                var d = r.degerlendirme, o = d.ozet;
                sayac({ havuz_aktif: o.havuz_aktif, kalan: o.kalan, dagitilacak: o.calisan, haric: o.haric });
                var oz = TK.el('div', 'tk-oniz-ozet');
                var tipler = Object.keys(o.tipler || {}).map(function (t) { return t + ' ' + o.tipler[t]; }).join(' · ');
                oz.appendChild(TK.el('div', 'tk-oniz-buyuk', o.calisan + ' işçi'));
                oz.appendChild(TK.el('div', 'tk-oniz-alt', (tipler || '—') + (o.zaten_iceride ? ' · ' + o.zaten_iceride + ' kişi zaten içeride (normal okutma — yeni kayıt yok)' : '')));
                onizKutu.appendChild(oz);
                (d.genel || []).forEach(function (m) { onizKutu.appendChild(TK.el('div', 'tk-kutu tk-kutu-hata', '⛔ ' + m)); });
                if ((d.hatalar || []).length) {
                    var hk = TK.el('div', 'tk-kutu tk-kutu-hata');
                    hk.appendChild(TK.el('strong', null, '⛔ ' + d.hatalar.length + ' kartta sorun var — düzeltilmeden onay yapılamaz:'));
                    var ul = TK.el('ul', 'tk-hata-liste');
                    d.hatalar.forEach(function (h) {
                        var li = TK.el('li', null);
                        li.appendChild(TK.el('strong', null, h.card_no + ' '));
                        li.appendChild(document.createTextNode(h.sebep || ''));
                        if (h.donmemis) {
                            var b1 = TK.el('button', 'btn btn-sm btn-danger', 'Kayıp'); b1.type = 'button';
                            b1.setAttribute('data-tk-karar', 'kayip'); b1.setAttribute('data-kart', h.kart_id); b1.setAttribute('data-no', h.card_no);
                            var b2 = TK.el('button', 'btn btn-sm', 'Bugün dağıtıldı'); b2.type = 'button';
                            b2.setAttribute('data-tk-karar', 'dagitildi'); b2.setAttribute('data-kart', h.kart_id); b2.setAttribute('data-no', h.card_no);
                            var bw = TK.el('span', 'tk-hata-eylem'); bw.appendChild(b1); bw.appendChild(b2); li.appendChild(bw);
                        }
                        if (h.havuz_disi && h.rol === 'kalan') {
                            var b3 = TK.el('button', 'btn btn-sm', 'Listeden çıkar'); b3.type = 'button';
                            b3.setAttribute('data-tk-karar', 'kalan_sil'); b3.setAttribute('data-kart', h.kart_id); b3.setAttribute('data-no', h.card_no);
                            li.appendChild(b3);
                        }
                        ul.appendChild(li);
                    });
                    hk.appendChild(ul);
                    onizKutu.appendChild(hk);
                }
                (d.uyarilar || []).forEach(function (m) { onizKutu.appendChild(TK.el('div', 'tk-kutu tk-kutu-uyari', '⚠ ' + m)); });
                if (onayBtn) {
                    onayBtn.disabled = !d.ok || !o.calisan || !(saatIn && saatIn.value);
                    onayBtn.textContent = '✅ ' + o.calisan + ' kişinin girişini onayla' + (saatIn && saatIn.value ? ' (' + saatIn.value + ')' : '');
                }
            });
        }
        [saatIn, nedenIn, beyanIn].forEach(function (e) { if (e) e.addEventListener('input', onizleGecikmeli); });
        if (v.durum === 'taslak') onizle();

        if (onayBtn) onayBtn.addEventListener('click', function () {
            onayBtn.disabled = true;
            var eski = onayBtn.textContent; onayBtn.textContent = '⏳ Kaydediliyor…';
            TK.post(url + 'onayla', { sayim_id: v.sayim_id, giris_saati: saatIn.value, neden: nedenIn ? nedenIn.value : '', beyan: beyanIn ? beyanIn.value : '', istek_id: istek }).then(function (d) {
                if (d.ok) { location.href = 'ters_kart_sayim.php?cavus=' + encodeURIComponent(v.foreman_id) + '&onay=1'; return; }
                onayBtn.textContent = eski;
                TK.sonuc(sonucKutu, 'hata', '⛔ ' + d.hata, [], 8000);
                onizle();
            });
        });

        var geriBtn = document.getElementById('tkGeriAlBtn');
        if (geriBtn) geriBtn.addEventListener('click', function () {
            TK.sor(dlg, { baslik: 'Sayımı Geri Al', metin: 'Bu sayımla yazılan girişler İPTAL edilir (silinmez) ve okutmalarınız korunarak yeni bir taslak açılır. Çıkış okutulmaya başlandıysa geri alınamaz.', gerekce: 'zorunlu', tamam: 'Geri Al', tehlike: true })
                .then(function (r) {
                    if (!r) return;
                    TK.post(url + 'geri_al', { sayim_id: v.sayim_id, gerekce: r.gerekce }).then(function (d) {
                        if (d.ok) location.href = 'ters_kart_sayim.php?cavus=' + encodeURIComponent(v.foreman_id);
                        else TK.sonuc(sonucKutu, 'hata', '⛔ ' + d.hata, [], 9000);
                    });
                });
        });
    }

    // =========================================================
    // SAYFA: KART HAVUZU (ters_kart_havuz.php)
    // =========================================================
    function havuzSayfasi(kok) {
        var v = TK.veri('tkHavuzVeri');
        if (!v || !v.foreman_id) return;
        var sonucKutu = document.getElementById('tkSonuc'), log = document.getElementById('tkEkleLog'), dlg = document.getElementById('tkDlg');
        var tipSec = kok.querySelectorAll('[data-tk-tip]'), tipId = 0;
        var degisti = false;
        function tipSecildi(b) {
            tipSec.forEach(function (x) { x.classList.toggle('aktif', x === b); x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); });
            tipId = +b.getAttribute('data-tk-tip');
            var d = document.getElementById('tkTipDurum'); if (d) d.textContent = 'Okutulan kartlar ' + b.textContent.trim() + ' olarak eklenir.';
        }
        tipSec.forEach(function (b) { b.addEventListener('click', function () { tipSecildi(b); okuyucu && okuyucu.odak(); }); });
        if (tipSec.length) tipSecildi(tipSec[0]);
        function sayac(o) {
            if (!o) return;
            ['toplam', 'aktif', 'kayip', 'bozuk', 'kullanim_disi'].forEach(function (k) {
                var e = kok.querySelector('[data-tk-sayac="' + k + '"]'); if (e) e.textContent = o[k];
            });
            kok.querySelectorAll('[data-tk-tip-sayac]').forEach(function (e) {
                var t = (o.tipler || {})[e.getAttribute('data-tk-tip-sayac')]; e.textContent = t || 0;
            });
        }
        var okuyucu = null;
        var input = document.getElementById('tkScanInput');
        if (input) okuyucu = TK.okuyucu({
            input: input, nfcBtn: document.getElementById('tkNfcBtn'), nfcDurum: document.getElementById('tkNfcDurum'),
            nfcHata: function (m) { TK.sonuc(sonucKutu, 'hata', '⛔ ' + m, [], 5000); },
            isle: function (ham, kaynak) {
                return TK.post('ters_kart_havuz.php?ajax=ekle', { foreman_id: v.foreman_id, worker_type_id: tipId, ham_uid: ham, kaynak: kaynak }).then(function (d) {
                    var tur, msj;
                    if (d.ok && d.sonuc === 'zaten') { tur = 'bilgi'; msj = 'ℹ ' + d.card_no + ' zaten havuzda (' + d.tip_adi + ')'; }
                    else if (d.ok) { tur = d.uyari ? 'uyari' : 'ok'; msj = '✅ ' + d.card_no + (d.sonuc === 'tip_guncellendi' ? ' tipi güncellendi → ' : (d.yeni_kart ? ' yeni kart eklendi — ' : ' havuza eklendi — ')) + d.tip_adi; degisti = true; }
                    else { tur = 'hata'; msj = '⛔ ' + d.hata; }
                    TK.sonuc(sonucKutu, tur, msj, d.uyari ? [d.uyari] : [], tur === 'hata' ? 6000 : 2500);
                    if (log) { var li = TK.el('li', 'tk-log-' + tur, msj); log.insertBefore(li, log.firstChild); while (log.children.length > 30) log.lastChild.remove(); }
                    sayac(d.ozet);
                    var yb = document.getElementById('tkYenileBtn'); if (yb && degisti) yb.hidden = false;
                });
            }
        });

        kok.addEventListener('click', function (e) {
            var b = e.target.closest('[data-tk-islem]');
            if (!b) return;
            var islem = b.getAttribute('data-tk-islem'), kid = +b.getAttribute('data-kart'), no = b.getAttribute('data-no') || '';
            var ayar = {
                kayip: { baslik: no + ' — Kayıp', metin: 'Kart kayıp işaretlenir; sayıma girmez ve kioskta girişe kapanır. İçerideyse çıkışı yine yapılabilir.', gerekce: 'istege', gerekceEtiket: 'Not (isteğe bağlı)', tamam: 'Kayıp işaretle', tehlike: true },
                bozuk: { baslik: no + ' — Bozuk', metin: 'Kart bozuk işaretlenir (devre dışı); sayıma girmez.', gerekce: 'istege', gerekceEtiket: 'Not (isteğe bağlı)', tamam: 'Bozuk işaretle', tehlike: true },
                kullanim_disi: { baslik: no + ' — Kullanım dışı', metin: 'Kart pasifleştirilir; sayıma girmez.', gerekce: 'istege', gerekceEtiket: 'Not (isteğe bağlı)', tamam: 'Pasifleştir', tehlike: true },
                kullanima_al: { baslik: no + ' — Kullanıma Al', metin: 'Kart yeniden kullanılabilir olur ve sayıma girer.', gerekce: false, tamam: 'Kullanıma Al' },
                cikar: { baslik: no + ' — Havuzdan Çıkar', metin: 'Kart bu çavuşun havuzundan çıkar. Geçmiş sayımlar etkilenmez.', gerekce: 'zorunlu', tamam: 'Havuzdan çıkar', tehlike: true },
                aktar: { baslik: no + ' — Başka Çavuşa Aktar', metin: 'Kart aynı depoda seçilen çavuşun havuzuna geçer (tip korunur).', gerekce: 'zorunlu', tamam: 'Aktar' }
            }[islem];
            if (!ayar) return;
            var hedefSel = document.getElementById('tkAktarHedef'), hedefAlan = document.getElementById('tkAktarAlan');
            if (hedefAlan) hedefAlan.hidden = islem !== 'aktar';
            TK.sor(dlg, ayar).then(function (r) {
                if (hedefAlan) hedefAlan.hidden = true;
                if (!r) return;
                TK.post('ters_kart_havuz.php?ajax=islem', { foreman_id: v.foreman_id, kart_id: kid, islem: islem, gerekce: r.gerekce, hedef_foreman_id: hedefSel ? +hedefSel.value : 0 })
                    .then(function (d) { if (d.ok) location.reload(); else TK.sonuc(sonucKutu, 'hata', '⛔ ' + d.hata, [], 7000); });
            });
        });
        var yb = document.getElementById('tkYenileBtn');
        if (yb) yb.addEventListener('click', function () { location.reload(); });
        var sif = document.getElementById('tkSifirlaBtn');
        if (sif) sif.addEventListener('click', function () {
            TK.sor(dlg, { baslik: 'Havuzu Sıfırla', metin: 'Bu çavuşun havuzundaki TÜM kartların üyeliği kapanır (kartlar silinmez, geçmiş sayımlar korunur). Ardından kartları yeniden okutarak havuzu oluşturursunuz.', gerekce: 'zorunlu', tamam: 'Havuzu sıfırla', tehlike: true })
                .then(function (r) { if (r) TK.post('ters_kart_havuz.php?ajax=sifirla', { foreman_id: v.foreman_id, gerekce: r.gerekce }).then(function (d) { if (d.ok) location.reload(); else TK.sonuc(sonucKutu, 'hata', '⛔ ' + d.hata, [], 7000); }); });
        });
    }

    // Çavuş seçim kutuları (GET formu) seçimde kendiliğinden gönderilir.
    document.querySelectorAll('select[data-tk-oto]').forEach(function (s) {
        s.addEventListener('change', function () { if (s.form) s.form.submit(); });
    });

    var kok = document.querySelector('[data-tk-sayfa]');
    if (!kok) return;
    var sayfa = kok.getAttribute('data-tk-sayfa');
    if (sayfa === 'sayim') sayimSayfasi(kok);
    else if (sayfa === 'havuz') havuzSayfasi(kok);
})();
