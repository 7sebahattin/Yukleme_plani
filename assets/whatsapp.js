/* assets/whatsapp.js — WhatsApp Yükleme Planı yönetim sayfası (vanilla JS)
   Tüm istekler aynı sayfaya POST + CSRF (form-urlencoded, X-Requested-With). Metinler textContent ile yazılır (XSS yok);
   günlük tablosu sunucuda h() ile üretilen HTML'dir. */
(function () {
    'use strict';
    var kok = document.getElementById('waSayfa');
    if (!kok) return;
    var csrf = kok.getAttribute('data-csrf') || '';
    var $ = function (id) { return document.getElementById(id); };

    function post(alanlar) {
        var govde = new URLSearchParams();
        govde.set('csrf', csrf);
        Object.keys(alanlar).forEach(function (k) { govde.set(k, alanlar[k]); });
        return fetch('whatsapp_yonetim.php', {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            body: govde.toString()
        }).then(function (r) {
            return r.json().catch(function () { return { ok: false, hata: 'Sunucudan geçersiz yanıt (HTTP ' + r.status + ').' }; });
        }).catch(function () { return { ok: false, hata: 'Sunucuya ulaşılamadı.' }; });
    }

    /* ── 0) Hızlı Kurulum Kodu & Rastgele Webhook Secret Token ── */
    var tokBtn = $('waTokenUret'), tokInput = $('waWhSecret'), whOniz = $('waWebhookOnizleme');
    var whTaban = kok.getAttribute('data-webhook-url') || '';
    function whOnizle(v) {
        if (!whOniz || !whTaban) return;
        whOniz.textContent = 'Evolution .env › WEBHOOK_URL=' + whTaban + '?token=' + (v || '<TOKEN>');
    }
    if (tokBtn && tokInput) {
        tokBtn.addEventListener('click', function () {
            var arr = new Uint8Array(24);
            if (window.crypto && window.crypto.getRandomValues) window.crypto.getRandomValues(arr);
            else for (var i = 0; i < arr.length; i++) arr[i] = Math.floor(Math.random() * 256);
            var hex = Array.prototype.map.call(arr, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
            tokInput.type = 'text';
            tokInput.value = hex;
            whOnizle(hex);
            tokInput.focus();
            tokInput.select();
        });
        tokInput.addEventListener('input', function () { whOnizle(tokInput.value.trim()); });
    }

    var kurKod = $('waKurulumKod'), kurBtn = $('waKurulumUygula'), kurNot = $('waKurulumNot');
    function kurulumKoduUygula() {
        if (!kurKod) return;
        var raw = kurKod.value.trim();
        if (!raw) { if (kurNot) kurNot.textContent = 'Önce PC betiğinin kopyaladığı kodu yapıştırın.'; return; }
        try {
            var jsonStr = raw.indexOf('WA-KURULUM:') === 0 ? atob(raw.slice(11).trim()) : raw;
            var obj = JSON.parse(jsonStr);
            if (!obj || typeof obj !== 'object') throw new Error('Geçersiz biçim');
            if (obj.evolution_url && $('waEvoUrl')) $('waEvoUrl').value = String(obj.evolution_url);
            if (obj.instance_adi && $('waInstance')) $('waInstance').value = String(obj.instance_adi);
            if (obj.evolution_api_key && $('waEvoKey')) $('waEvoKey').value = String(obj.evolution_api_key);
            if (obj.webhook_secret && tokInput) { tokInput.value = String(obj.webhook_secret); whOnizle(tokInput.value); }
            if (kurNot) kurNot.textContent = 'Alanlar dolduruldu! Şimdi AI API Key (kayıtlı değilse) girip "Bağlantı ayarlarını kaydet"e basın.';
            kurKod.value = '';
        } catch (err) {
            if (kurNot) kurNot.textContent = 'Kurulum kodu okunamadı. PC penceresindeki WA-KURULUM:… kodunun tamamını yapıştırın.';
        }
    }
    if (kurBtn) kurBtn.addEventListener('click', kurulumKoduUygula);
    if (kurKod) kurKod.addEventListener('paste', function () { setTimeout(kurulumKoduUygula, 30); });

    /* ── 1) Bağlantı: 5 sn'de bir durum; QR yalnız gerektiğinde ── */
    var rozet = $('waDurumRozet'), not = $('waDurumNot'), qrKutu = $('waQrKutu'), qrImg = $('waQrImg');
    var qrZaman = 0, mesgul = false, sonDurum = '', sohbetIlkYuklendi = false;
    var ETIKET = { open: 'Bağlı', connecting: 'Bağlanıyor…', close: 'Bağlantı yok' };

    function durumYaz(d) {
        sonDurum = d;
        rozet.className = 'wa-rozet wa-' + (ETIKET[d] ? d : 'pasif');
        rozet.textContent = ETIKET[d] || d;
        if (d === 'open') {
            qrKutu.hidden = true; qrImg.removeAttribute('src'); qrZaman = 0;
            if (!sohbetIlkYuklendi && typeof sohbetGruplariYukle === 'function') {
                sohbetIlkYuklendi = true;
                sohbetGruplariYukle();
            }
        }
    }
    function qrYaz(qr) {
        if (qr) { qrImg.src = qr; qrKutu.hidden = false; qrZaman = Date.now(); }
    }
    function durumSor(qrIste, kur) {
        if (mesgul) return Promise.resolve();
        mesgul = true;
        return post({ ajax: kur ? 'kur' : 'durum', qr: qrIste ? '1' : '' }).then(function (r) {
            mesgul = false;
            if (!r.ok) {
                rozet.className = 'wa-rozet wa-hata'; rozet.textContent = 'Ulaşılamıyor';
                not.textContent = r.hata || 'Durum alınamadı.';
                return;
            }
            not.textContent = '';
            durumYaz(r.durum);
            qrYaz(r.qr);
        });
    }
    function dongu() {
        var qrGerek = sonDurum !== 'open' && sonDurum !== '' && (Date.now() - qrZaman > 45000) && !qrKutu.hidden;
        durumSor(qrGerek, false);
    }
    $('waQrIste').addEventListener('click', function () { durumSor(true, true); });
    $('waDurumYenile').addEventListener('click', function () { durumSor(false, false); });
    if ($('waCikisYap')) $('waCikisYap').addEventListener('click', function () {
        if (!window.confirm('WhatsApp oturumu kapatılacak ve yeni QR gösterilecek. Telefonla QR\'ı yeniden okuttuğunuzda son mesaj geçmişi tekrar yüklenir. Devam edilsin mi?')) return;
        var b = this; b.disabled = true;
        post({ ajax: 'cikis' }).then(function (r) {
            b.disabled = false;
            if (!r.ok) { window.alert(r.hata || 'Oturum kapatılamadı.'); return; }
            sohbetIlkYuklendi = false;
            durumSor(true, true);
        });
    });

    /* ── 2) Canlı Sohbet & Seçmeli AI Tarama (WhatsApp Web görünümü) ── */
    var sohbetGrupListe = $('waSohbetGrupListe'), sohbetDurum = $('waSohbetDurum'), grupAra = $('waGrupAra');
    var mesajAkis = $('waMesajAkis'), mesajAra = $('waMesajAra'), tarihBas = $('waTarihBas'), tarihBit = $('waTarihBit');
    var mesajLimit = $('waMesajLimit'), mesajYenile = $('waMesajYenile');
    var sadeceAday = $('waSadeceAday'), aktarilanGizle = $('waAktarilanGizle'), taslakOtomatik = $('waTaslakOtomatik');
    var tumunuSec = $('waTumunuSec'), secimBilgi = $('waSecimBilgi'), seciliTaratBtn = $('waSeciliTarat');
    var tumGruplar = [], aktifGrup = null, aktifMesajlar = [], seciliIdler = {}, aktifTur = '';
    var DURUM_ROZET = {
        aktarildi: ['wa-tamam', 'Plana aktarıldı'],
        hazir: ['wa-hazir', 'Hazır'],
        eksik_bilgi: ['wa-eksik', 'Eksik bilgi'],
        alakasiz: ['wa-pasif', 'Alakasız'],
        ai_hata: ['wa-hata', 'AI hatası'],
        isleniyor: ['wa-bekliyor', 'İşleniyor…']
    };

    function sonEtkinlik(ts) {
        if (!ts) return '';
        var d = new Date(ts * 1000), simdi = new Date();
        if (d.toDateString() === simdi.toDateString()) return d.toLocaleTimeString('tr-TR', { hour: '2-digit', minute: '2-digit' });
        return d.toLocaleDateString('tr-TR', { day: '2-digit', month: '2-digit' });
    }

    function sohbetGruplariCiz() {
        if (!sohbetGrupListe) return;
        var q = (grupAra ? grupAra.value.trim().toLocaleLowerCase('tr-TR') : '');
        sohbetGrupListe.textContent = '';
        var bulunan = tumGruplar.filter(function (g) {
            if (aktifTur && g.tur !== aktifTur) return false;
            return !q || g.ad.toLocaleLowerCase('tr-TR').indexOf(q) !== -1 || g.jid.indexOf(q) !== -1 || (g.telefon || '').indexOf(q) !== -1;
        });
        if (!bulunan.length) {
            var bos = document.createElement('li'); bos.className = 'wa-bos';
            bos.textContent = !tumGruplar.length ? 'Sohbet bulunamadı.'
                : (q ? 'Aramayla eşleşen sohbet yok.'
                    : (aktifTur === 'kisi' ? 'Kişisel sohbet yok. Evolution güncellendikten sonra "Oturumu kapat & yeniden bağlan" ile QR\'ı yeniden okutun.' : 'Bu sekmede sohbet yok.'));
            sohbetGrupListe.appendChild(bos);
            return;
        }
        bulunan.forEach(function (g) {
            var li = document.createElement('li');
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'wa-sohbet-grup-btn' + (aktifGrup && aktifGrup.jid === g.jid ? ' wa-secili' : '');
            var title = document.createElement('div'); title.className = 'wa-sohbet-isim';
            var adSp = document.createElement('span'); adSp.textContent = (g.tur === 'kisi' ? '👤 ' : '👥 ') + g.ad;
            title.appendChild(adSp);
            if (g.son) {
                var zt = document.createElement('span'); zt.className = 'wa-ikincil'; zt.textContent = sonEtkinlik(g.son);
                title.appendChild(zt);
            }
            var sm = document.createElement('small');
            sm.textContent = g.tur === 'kisi' ? (g.telefon ? '+' + g.telefon : 'Kişisel sohbet') : (g.uye ? g.uye + ' üye' : 'WhatsApp grubu');
            btn.appendChild(title); btn.appendChild(sm);
            btn.addEventListener('click', function () {
                aktifGrup = g;
                seciliIdler = {};
                sohbetGruplariCiz();
                grupMesajlariniGetir();
            });
            li.appendChild(btn);
            sohbetGrupListe.appendChild(li);
        });
    }

    function sohbetGruplariYukle() {
        if (!sohbetGrupListe) return;
        var btn = $('waSohbetGrupYukle');
        if (btn) btn.disabled = true;
        if (sohbetDurum) sohbetDurum.textContent = 'Sohbetler yükleniyor…';
        post({ ajax: 'sohbetler' }).then(function (r) {
            if (btn) btn.disabled = false;
            if (!r.ok) {
                if (sohbetDurum) sohbetDurum.textContent = r.hata || 'Sohbetler alınamadı.';
                return;
            }
            tumGruplar = r.sohbetler || [];
            var grupSay = tumGruplar.filter(function (g) { return g.tur === 'grup'; }).length;
            if (sohbetDurum) sohbetDurum.textContent = grupSay + ' grup, ' + (tumGruplar.length - grupSay) + ' kişi yüklendi.';
            sohbetGruplariCiz();
        });
    }

    if ($('waSohbetSekme')) {
        Array.prototype.forEach.call($('waSohbetSekme').querySelectorAll('.wa-sekme'), function (b) {
            b.addEventListener('click', function () {
                aktifTur = b.getAttribute('data-tur') || '';
                Array.prototype.forEach.call($('waSohbetSekme').querySelectorAll('.wa-sekme'), function (x) { x.classList.toggle('wa-secili', x === b); });
                sohbetGruplariCiz();
            });
        });
    }

    function gorunurMesajlar() {
        var q = mesajAra ? mesajAra.value.trim().toLocaleLowerCase('tr-TR') : '';
        var t1 = tarihBas ? tarihBas.value : '';
        var t2 = tarihBit ? tarihBit.value : '';
        var aday = sadeceAday ? sadeceAday.checked : false;
        var gizleAkt = aktarilanGizle ? aktarilanGizle.checked : false;
        return aktifMesajlar.filter(function (m) {
            if (aday && m.is_noise) return false;
            if (gizleAkt && m.record_id) return false;
            if (t1 && m.date < t1) return false;
            if (t2 && m.date > t2) return false;
            if (q) {
                var havuz = ((m.text || '') + ' ' + (m.sender_name || '') + ' ' + (m.sender_phone || '')).toLocaleLowerCase('tr-TR');
                if (havuz.indexOf(q) === -1) return false;
            }
            return true;
        });
    }

    function secimSayaciniGuncelle() {
        var seciliSayi = Object.keys(seciliIdler).filter(function (k) { return seciliIdler[k]; }).length;
        if (secimBilgi) secimBilgi.textContent = seciliSayi + ' mesaj seçili (tek seferde en çok 20)';
        if (seciliTaratBtn) seciliTaratBtn.disabled = seciliSayi === 0;
        if (tumunuSec) {
            var gor = gorunurMesajlar();
            tumunuSec.checked = gor.length > 0 && gor.every(function (m) { return !!seciliIdler[m.id]; });
        }
    }

    function mesajlariCiz() {
        if (!mesajAkis) return;
        mesajAkis.textContent = '';
        if (!aktifGrup) {
            var d0 = document.createElement('div'); d0.className = 'wa-bos';
            d0.textContent = 'Soldan bir grup ya da kişi seçin.';
            mesajAkis.appendChild(d0);
            secimSayaciniGuncelle();
            return;
        }
        var listeM = gorunurMesajlar();
        if (!listeM.length) {
            var d1 = document.createElement('div'); d1.className = 'wa-bos';
            d1.textContent = aktifMesajlar.length ? 'Filtrelere uyan mesaj yok.' : 'Bu sohbette kayıtlı metin mesajı yok. Yeni mesajlar geldikçe burada görünür; eski mesajlar için PC paketini güncelleyip yukarıdan "Oturumu kapat & yeniden bağlan" ile QR\'ı yeniden okutun.';
            mesajAkis.appendChild(d1);
            secimSayaciniGuncelle();
            return;
        }
        listeM.forEach(function (m) {
            var lab = document.createElement('label');
            lab.className = 'wa-balon' + (seciliIdler[m.id] ? ' wa-isaretli' : '') + (m.is_noise ? ' wa-gurultu' : '');
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.checked = !!seciliIdler[m.id];
            cb.addEventListener('change', function () {
                if (cb.checked) seciliIdler[m.id] = true;
                else delete seciliIdler[m.id];
                lab.classList.toggle('wa-isaretli', cb.checked);
                secimSayaciniGuncelle();
            });
            var govde = document.createElement('div'); govde.className = 'wa-balon-govde';
            var ust = document.createElement('div'); ust.className = 'wa-balon-ust';
            var sol = document.createElement('span');
            var strong = document.createElement('strong');
            strong.textContent = m.sender_name || (m.from_me ? 'Siz' : (m.sender_phone || 'Bilinmeyen'));
            sol.appendChild(strong);
            if (m.sender_phone) {
                var telSp = document.createElement('span'); telSp.className = 'wa-ikincil';
                telSp.textContent = ' · ' + m.sender_phone;
                sol.appendChild(telSp);
            }
            var sag = document.createElement('span');
            sag.style.display = 'inline-flex'; sag.style.gap = '6px'; sag.style.alignItems = 'center';
            if (m.log_durum && DURUM_ROZET[m.log_durum]) {
                var rz = document.createElement('span');
                rz.className = 'wa-rozet ' + DURUM_ROZET[m.log_durum][0];
                rz.textContent = DURUM_ROZET[m.log_durum][1];
                sag.appendChild(rz);
            }
            if (m.record_id) {
                var lnk = document.createElement('a');
                lnk.href = 'record_view.php?id=' + encodeURIComponent(String(m.record_id));
                lnk.className = 'btn btn-sm btn-ghost';
                lnk.textContent = 'Plan #' + m.record_id;
                lnk.addEventListener('click', function (ev) { ev.stopPropagation(); });
                sag.appendChild(lnk);
            }
            var saat = document.createElement('span'); saat.className = 'wa-ikincil'; saat.textContent = m.time || m.date;
            sag.appendChild(saat);
            ust.appendChild(sol); ust.appendChild(sag);

            var metinEl = document.createElement('div'); metinEl.className = 'wa-balon-metin';
            metinEl.textContent = m.text;
            govde.appendChild(ust); govde.appendChild(metinEl);
            lab.appendChild(cb); lab.appendChild(govde);
            mesajAkis.appendChild(lab);
        });
        secimSayaciniGuncelle();
    }

    function grupMesajlariniGetir() {
        if (!aktifGrup) return;
        if (mesajYenile) mesajYenile.disabled = true;
        if (sohbetDurum) sohbetDurum.textContent = '"' + aktifGrup.ad + '" mesajları yükleniyor…';
        post({ ajax: 'mesajlar', jid: aktifGrup.jid, limit: mesajLimit ? mesajLimit.value : '100' }).then(function (r) {
            if (mesajYenile) mesajYenile.disabled = false;
            if (!r.ok) {
                if (sohbetDurum) sohbetDurum.textContent = r.hata || 'Mesajlar alınamadı.';
                return;
            }
            aktifMesajlar = r.mesajlar || [];
            if (sohbetDurum) sohbetDurum.textContent = aktifGrup.ad + ' · ' + aktifMesajlar.length + ' mesaj';
            mesajlariCiz();
        });
    }

    if ($('waSohbetGrupYukle')) $('waSohbetGrupYukle').addEventListener('click', sohbetGruplariYukle);
    if (grupAra) grupAra.addEventListener('input', sohbetGruplariCiz);
    if (mesajYenile) mesajYenile.addEventListener('click', grupMesajlariniGetir);
    if (mesajLimit) mesajLimit.addEventListener('change', grupMesajlariniGetir);
    [mesajAra, tarihBas, tarihBit, sadeceAday, aktarilanGizle].forEach(function (el) {
        if (el) el.addEventListener(el.type === 'search' ? 'input' : 'change', mesajlariCiz);
    });
    if (tumunuSec) {
        tumunuSec.addEventListener('change', function () {
            gorunurMesajlar().forEach(function (m) {
                if (tumunuSec.checked) seciliIdler[m.id] = true;
                else delete seciliIdler[m.id];
            });
            mesajlariCiz();
        });
    }
    if (seciliTaratBtn) {
        seciliTaratBtn.addEventListener('click', function () {
            var paket = aktifMesajlar.filter(function (m) { return !!seciliIdler[m.id]; }).slice(0, 20);
            if (!paket.length) return;
            seciliTaratBtn.disabled = true;
            if (sohbetDurum) sohbetDurum.textContent = paket.length + ' mesaj AI ile taranıyor…';
            post({
                ajax: 'secili_tarat',
                taslak_ac: (taslakOtomatik && !taslakOtomatik.checked) ? '0' : '1',
                mesajlar: JSON.stringify(paket)
            }).then(function (r) {
                seciliTaratBtn.disabled = false;
                if (!r.ok) {
                    if (sohbetDurum) sohbetDurum.textContent = r.hata || 'Tarama başarısız.';
                    return;
                }
                var map = {};
                (r.satirlar || []).forEach(function (s) { map[s.id] = s; });
                aktifMesajlar.forEach(function (m) {
                    if (map[m.id]) {
                        m.log_durum = map[m.id].durum;
                        if (map[m.id].record_id) m.record_id = map[m.id].record_id;
                        delete seciliIdler[m.id];
                    }
                });
                if (r.html && $('waLogKutu')) $('waLogKutu').innerHTML = r.html;
                var msg = 'Tarandı: ' + r.toplam + ' mesaj · Taslak eklendi: ' + r.aktarildi + ' · Hazır: ' + r.hazir + ' · Eksik: ' + r.eksik_bilgi + (r.ai_hata ? ' · AI hata: ' + r.ai_hata : '');
                if (sohbetDurum) sohbetDurum.textContent = msg;
                mesajlariCiz();
            });
        });
    }

    durumSor(false, false);
    setInterval(function () { if (!document.hidden) dongu(); }, 5000);

    /* ── 3) Otomatik dinleme grupları ── */
    var liste = $('waGrupListe'), grupNot = $('waGrupNot');
    function secili() {
        var s = {};
        liste.querySelectorAll('input[type=checkbox]').forEach(function (c) { s[c.value] = c.checked; });
        return s;
    }
    $('waGrupGetir').addEventListener('click', function () {
        var btn = this; btn.disabled = true; grupNot.textContent = 'Gruplar getiriliyor…';
        post({ ajax: 'gruplar' }).then(function (r) {
            btn.disabled = false;
            if (!r.ok) { grupNot.textContent = r.hata || 'Gruplar alınamadı.'; return; }
            var onceki = secili();
            liste.textContent = '';
            r.gruplar.forEach(function (g) {
                var li = document.createElement('li');
                var lab = document.createElement('label'); lab.className = 'wa-onay';
                var cb = document.createElement('input'); cb.type = 'checkbox'; cb.name = 'gruplar[]'; cb.value = g.jid; cb.checked = !!onceki[g.jid];
                var sp = document.createElement('span'); sp.textContent = g.ad + (g.uye ? ' · ' + g.uye + ' üye' : '');
                var sm = document.createElement('small'); sm.textContent = g.jid;
                lab.appendChild(cb); lab.appendChild(sp); lab.appendChild(sm);
                var gz = document.createElement('input'); gz.type = 'hidden'; gz.name = 'grup_ad[' + g.jid + ']'; gz.value = g.ad;
                li.appendChild(lab); li.appendChild(gz); liste.appendChild(li);
            });
            // Önceden seçili ama artık listede olmayan gruplar da korunur (hat gruptan çıkmış olabilir)
            Object.keys(onceki).forEach(function (jid) {
                if (!onceki[jid] || r.gruplar.some(function (g) { return g.jid === jid; })) return;
                var li = document.createElement('li');
                var lab = document.createElement('label'); lab.className = 'wa-onay';
                var cb = document.createElement('input'); cb.type = 'checkbox'; cb.name = 'gruplar[]'; cb.value = jid; cb.checked = true;
                var sp = document.createElement('span'); sp.textContent = jid + ' (listede yok)';
                lab.appendChild(cb); lab.appendChild(sp); li.appendChild(lab); liste.appendChild(li);
            });
            grupNot.textContent = r.gruplar.length + ' grup bulundu. Dinlemek istediklerinizi işaretleyip "Ayarları kaydet"e basın. Hiçbiri işaretli değilse TÜM gruplar dinlenir.';
        });
    });

    /* ── 3) AI test ── */
    var testDurum = $('waTestDurum');
    $('waTestGonder').addEventListener('click', function () {
        var metin = $('waTestMetin').value.trim();
        if (!metin) { testDurum.textContent = 'Önce bir mesaj yazın.'; return; }
        var btn = this; btn.disabled = true; testDurum.textContent = 'Ayrıştırılıyor…';
        post({ ajax: 'test', metin: metin }).then(function (r) {
            btn.disabled = false;
            if (!r.ok) { testDurum.textContent = r.hata || 'Hata.'; return; }
            testDurum.textContent = '';
            var ozet = $('waTestOzet'); ozet.textContent = '';
            var ROZET = { hazir: ['wa-hazir', 'Karar: Hazır (otomatik taslak olurdu)'], eksik_bilgi: ['wa-eksik', 'Karar: Eksik bilgi'], alakasiz: ['wa-pasif', 'Karar: Alakasız'] };
            var k = ROZET[r.karar] || ['wa-pasif', r.karar];
            [[k[0], k[1]], ['wa-pasif', r.ai + ' · ' + r.model], ['wa-pasif', 'Güven: ' + r.sonuc.guven_skoru + ' (eşik ' + r.esik + ')']]
                .concat(r.gurultu ? [['wa-eksik', 'Gürültü süzgeci AI\'a göndermezdi']] : [])
                .forEach(function (x) { var s = document.createElement('span'); s.className = 'wa-rozet ' + x[0]; s.textContent = x[1]; ozet.appendChild(s); });
            $('waTestJson').textContent = JSON.stringify(r.sonuc, null, 2);
            $('waTestSonuc').hidden = false;
        });
    });

    /* ── 4) Günlük ── */
    var logKutu = $('waLogKutu');
    function loglariYenile() {
        return post({ ajax: 'loglar' }).then(function (r) { if (r.ok) logKutu.innerHTML = r.html; });
    }
    $('waLogYenile').addEventListener('click', loglariYenile);
    setInterval(function () { if (!document.hidden) loglariYenile(); }, 20000);

    logKutu.addEventListener('click', function (e) {
        var b = e.target.closest('[data-wa-eylem]');
        if (!b) return;
        var eylem = b.getAttribute('data-wa-eylem');
        var onay = { aktar: 'Bu mesaj yükleme planına TASLAK olarak aktarılsın mı?', onayla: 'Taslak onaylansın mı? Plan normal kayıt olur.' }[eylem];
        if (onay && !window.confirm(onay)) return;
        b.disabled = true;
        post({ ajax: eylem, id: b.getAttribute('data-id') }).then(function (r) {
            window.alert(r.ok ? (r.mesaj || 'Tamam.') : (r.hata || 'Hata oluştu.'));
            return loglariYenile();
        });
    });
})();
