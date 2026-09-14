// =========================================================
// assets/pdks.js — PDKS (Personel / Kart Yönetimi) modül JS'i
// Yalnız personel_*.php sayfalarında yüklenir. app.js'e HİÇ dokunmaz.
//
// Kapsam:
//   - Genel modal aç/kapa (users.php'deki openModal/closeModal deseninin aynısı)
//   - USB HID kart okuyucu giriş kutusu: yalnız rakam kabul eder, Enter formu
//     GÖNDERMEZ (sunucudan önizleme ister), okuma sonrası kutuya odak geri döner
//   - Kart eylem modalı (Ata / Değiştir / İptal / Kayıp) — tek modal, JS ile
//     ihtiyaca göre alanları gösterir/gizler (hesap.js'teki data-hs-durum
//     desenine benzer, burada tek elle yazılmış küçük bir sürüm)
//
// ⚠ Kanonik UID KARARI HER ZAMAN SUNUCUDADIR. Buradaki önizleme yalnız
// kullanıcıya "algılanan UID budur" göstermek içindir; nihai doğrulama ve
// kayıt, form POST edildiğinde sunucuda (config/pdks.php) TEKRAR yapılır.
// =========================================================
(function () {
    'use strict';

    function openModal(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.removeAttribute('hidden');
        document.body.style.overflow = 'hidden';
    }
    function closeModal(id) {
        var el = document.getElementById(id);
        if (!el) return;
        el.setAttribute('hidden', '');
        document.body.style.overflow = '';
    }
    window.pdksOpenModal  = openModal;
    window.pdksCloseModal = closeModal;

    document.addEventListener('DOMContentLoaded', function () {
        // Overlay dışına tıklayınca kapat (tüm .pm-overlay'ler için genel kural)
        document.querySelectorAll('.pm-overlay[id]').forEach(function (el) {
            el.addEventListener('click', function (e) {
                if (e.target === el) closeModal(el.id);
            });
        });

        // ── USB tarama kutuları ──────────────────────────────
        // ⚠ Bu kutu SUNULAN DEĞERDİR: name="ham_uid" ile forma dahildir ve
        // sunucuya HAM (henüz normalize edilmemiş) ondalık olarak gider.
        // Aşağıdaki önizleme YALNIZ gösterim içindir — "kanonik" olarak
        // gösterilen değer hiçbir yerde forma geri yazılmaz/submit edilmez;
        // nihai kanonikleştirme HER ZAMAN sunucuda, submit anında yeniden
        // yapılır (config/pdks.php → pdks_kart_ata/pdks_kart_degistir).
        document.querySelectorAll('[data-pdks-scan]').forEach(function (input) {
            var previewEl = document.querySelector(input.getAttribute('data-pdks-preview') || '');
            var statusEl  = document.querySelector(input.getAttribute('data-pdks-status')  || '');
            // Bu kutuyla eşlenmiş gizli alan: son okumanın kaynağını taşır
            // ('usb_decimal' | 'web_nfc') — form bununla birlikte POST edilir.
            var kaynakEl  = document.querySelector(input.getAttribute('data-pdks-kaynak-field') || '');
            var timer = null;

            function kaynakDegeri() { return (kaynakEl && kaynakEl.value) ? kaynakEl.value : 'usb_decimal'; }

            function temizle() {
                if (previewEl) previewEl.textContent = '';
                if (statusEl) { statusEl.textContent = ''; statusEl.className = 'pdks-scan-status'; }
                if (kaynakEl) kaynakEl.value = 'usb_decimal';
            }

            function onizle() {
                var v = input.value.trim();
                if (v === '') { temizle(); return; }
                if (statusEl) { statusEl.textContent = 'Sorgulanıyor…'; statusEl.className = 'pdks-scan-status'; }
                var url = (input.getAttribute('data-pdks-onizle-url') || 'personel_kartlar.php')
                    + '?ajax=onizle&kaynak=' + encodeURIComponent(kaynakDegeri()) + '&uid=' + encodeURIComponent(v);
                fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function (r) { return r.json(); })
                    .then(function (d) {
                        if (!d || !d.ok) {
                            if (previewEl) previewEl.textContent = '—';
                            if (statusEl) { statusEl.textContent = (d && d.hata) || 'Geçersiz UID.'; statusEl.className = 'pdks-scan-status err'; }
                            return;
                        }
                        if (previewEl) previewEl.textContent = d.canonical;
                        if (d.exists) {
                            var kim = d.employee_name ? (' — ' + d.employee_name) : '';
                            if (statusEl) {
                                statusEl.textContent = '⚠ Bu kart zaten tanımlı' + kim;
                                statusEl.className = 'pdks-scan-status warn';
                            }
                        } else if (statusEl) {
                            statusEl.textContent = '✓ Boşta — tanımlanabilir';
                            statusEl.className = 'pdks-scan-status ok';
                        }
                    })
                    .catch(function () {
                        if (statusEl) { statusEl.textContent = 'Sorgu başarısız — bağlantıyı kontrol edin.'; statusEl.className = 'pdks-scan-status err'; }
                    });
            }

            // Yalnız rakam kabul et. Bu, GERÇEK bir klavye/USB-HID girdisidir
            // (programatik NFC yazımı BU olayı hiç TETİKLEMEZ — aşağıdaki
            // 'pdksnfcread' dinleyicisine bakın) — o yüzden burada kaynak
            // her zaman koşulsuz usb_decimal'e döner.
            input.addEventListener('input', function () {
                if (kaynakEl) kaynakEl.value = 'usb_decimal';
                var temiz = input.value.replace(/[^0-9]/g, '');
                if (temiz !== input.value) input.value = temiz;
                clearTimeout(timer);
                timer = setTimeout(onizle, 250);
            });

            // Enter: formu GÖNDERMEZ, yalnız önizlemeyi hemen tetikler.
            // Okuyucu Enter göndermese de yukarıdaki 'input' olayı zaten yeterlidir.
            input.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    clearTimeout(timer);
                    onizle();
                }
            });

            // ── Web NFC'den gelen ham okuma ─────────────────────
            // NFC butonu (aşağıdaki [data-pdks-nfc-target] bloğu) bu kutuya
            // bu özel olayı GÖNDERİR — normal 'input' dinleyicisini BİLEREK
            // atlar, çünkü ham NFC serialNumber'ı iki nokta/harf içerir ve
            // yukarıdaki rakam-ayıklayıcı onu bozardı. Bayt-tersi dönüşümü
            // BURADA YAPILMAZ — ham değer olduğu gibi sunucuya gider,
            // kanonikleştirme HER ZAMAN sunucuda (pdks_uid_from_web_nfc()).
            input.addEventListener('pdksnfcread', function (e) {
                if (kaynakEl) kaynakEl.value = 'web_nfc';
                input.value = (e.detail && e.detail.value) ? e.detail.value : '';
                clearTimeout(timer);
                onizle();
            });

            // Modal her açıldığında/formda odak kaybolduğunda tekrar odaklanabilsin diye dışa aç
            input.pdksTemizle = temizle;
        });

        // ── Web NFC "İLE OKU" butonları ──────────────────────────
        // Yalnız Chrome/Android + HTTPS'te desteklenir (window.isSecureContext).
        // Bir kez tıklanınca oturum boyunca DİNLEMEDE kalır — her yeni kart
        // taması için butona TEKRAR basmak GEREKMEZ (pdks_nfc_test.php'de
        // ölçülüp doğrulanmış davranışın aynısı).
        document.querySelectorAll('[data-pdks-nfc-target]').forEach(function (btn) {
            var target = document.querySelector(btn.getAttribute('data-pdks-nfc-target') || '');
            if (!target) return;
            var destekli = ('NDEFReader' in window) && !!window.isSecureContext;
            btn.hidden = !destekli;
            if (!destekli) return;

            var reader = null;
            var dinliyor = false;
            btn.addEventListener('click', function () {
                if (dinliyor) return;
                if (!reader) reader = new NDEFReader();
                reader.addEventListener('reading', function (ev) {
                    var ham = (ev.serialNumber != null) ? String(ev.serialNumber) : '';
                    if (ham === '') return;
                    target.dispatchEvent(new CustomEvent('pdksnfcread', { detail: { value: ham } }));
                });
                reader.addEventListener('readingerror', function () {
                    btn.title = 'NFC okuma hatası — kartı tekrar yaklaştırın.';
                });
                reader.scan().then(function () {
                    dinliyor = true;
                    btn.textContent = '📡 NFC DİNLENİYOR…';
                    btn.disabled = true;
                }).catch(function (err) {
                    btn.title = 'NFC başlatılamadı: ' + (err && err.message ? err.message : String(err));
                });
            });
        });

        // Görünür scan kutusuna otomatik odak (ör. modal açılışında)
        document.querySelectorAll('[data-pdks-autofocus]').forEach(function (el) {
            try { el.focus(); } catch (e) {}
        });

        // ── Kart eylem modalı — tek modal, action'a göre alan göster/gizle ──
        var kartModal = document.getElementById('pdksKartModal');
        if (kartModal) {
            window.pdksKartModalAc = function (action, cardId, employeeId, kartUid) {
                document.getElementById('pdksKartAction').value = action;
                var cardIdEl = document.getElementById('pdksKartCardId');
                var empIdEl  = document.getElementById('pdksKartEmployeeId');
                if (cardIdEl) cardIdEl.value = cardId || '';
                if (empIdEl)  empIdEl.value  = employeeId || '';

                var baslik = {
                    ata:      'Kart Ata',
                    degistir: 'Kartı Değiştir',
                    iptal:    'Kartı İptal Et',
                    kayip:    'Kayıp Bildir'
                }[action] || 'Kart İşlemi';
                document.getElementById('pdksKartBaslik').textContent = baslik;

                var scanGerekli = (action === 'ata' || action === 'degistir');
                var gerekceGerekli = (action !== 'ata');
                document.getElementById('pdksKartScanBlok').hidden = !scanGerekli;
                document.getElementById('pdksKartGerekceBlok').hidden = !gerekceGerekli;
                document.getElementById('pdksKartGerekce').required = gerekceGerekli;

                var uidInput = document.getElementById('pdksKartUidInput');
                if (uidInput) {
                    uidInput.value = '';
                    if (uidInput.pdksTemizle) uidInput.pdksTemizle();
                }
                var gerekceInput = document.getElementById('pdksKartGerekce');
                if (gerekceInput) gerekceInput.value = '';

                var mevcutUid = document.getElementById('pdksKartMevcutUid');
                if (mevcutUid) mevcutUid.textContent = kartUid || '';

                openModal('pdksKartModal');
                if (scanGerekli && uidInput) {
                    setTimeout(function () { uidInput.focus(); }, 80);
                }
            };
            window.pdksKartModalKapat = function () { closeModal('pdksKartModal'); };
        }
    });
})();
