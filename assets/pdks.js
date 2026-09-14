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
            var timer = null;

            function temizle() {
                if (previewEl) previewEl.textContent = '';
                if (statusEl) { statusEl.textContent = ''; statusEl.className = 'pdks-scan-status'; }
            }

            function onizle() {
                var v = input.value.trim();
                if (v === '') { temizle(); return; }
                if (statusEl) { statusEl.textContent = 'Sorgulanıyor…'; statusEl.className = 'pdks-scan-status'; }
                var url = (input.getAttribute('data-pdks-onizle-url') || 'personel_kartlar.php')
                    + '?ajax=onizle&kaynak=usb_decimal&uid=' + encodeURIComponent(v);
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

            // Yalnız rakam kabul et (kaynak daima usb_decimal — otomatik tespit YOK)
            input.addEventListener('input', function () {
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

            // Modal her açıldığında/formda odak kaybolduğunda tekrar odaklanabilsin diye dışa aç
            input.pdksTemizle = temizle;
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
