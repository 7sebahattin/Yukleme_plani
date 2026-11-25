/* =========================================================
   assets/doviz.js — Döviz & Günlük Kurlar istemci mantığı
   Saf Vanilla JS IIFE. innerHTML ile güvenilmez veri YOK, localStorage YOK.
   Veri kaynağı: #dvVeri (<script type="application/json">).
   ========================================================= */
(function () {
    'use strict';

    var kok = document.getElementById('doviz');
    if (!kok) return;

    // ── Veri okuma (#dvVeri) ────────────────────────────────────────────────
    var veri = {};
    var veriEl = document.getElementById('dvVeri');
    if (veriEl) {
        try {
            veri = JSON.parse(veriEl.textContent || '{}') || {};
        } catch (e) {
            veri = {};
        }
    }

    var csrfToken = typeof veri.csrf === 'string' ? veri.csrf : '';
    var geriUrl   = typeof veri.geri === 'string' ? veri.geri : 'gunluk_kurlar.php';

    // ── Toast bildirimi ─────────────────────────────────────────────────────
    var toastTimer = null;
    function toastGoster(mesaj, hata) {
        if (!mesaj) return;
        var mevcut = document.querySelector('.dv-toast');
        if (mevcut && mevcut.parentNode) mevcut.parentNode.removeChild(mevcut);
        if (toastTimer) clearTimeout(toastTimer);

        var el = document.createElement('div');
        el.className = 'dv-toast' + (hata ? ' dv-toast--hata' : '');
        el.setAttribute('role', hata ? 'alert' : 'status');
        el.textContent = String(mesaj);
        document.body.appendChild(el);

        toastTimer = setTimeout(function () {
            if (el.parentNode) el.parentNode.removeChild(el);
        }, hata ? 4200 : 2800);
    }

    // ── Ortak Fetch POST (JSON) ─────────────────────────────────────────────
    function postJson(formData) {
        if (!formData.has('csrf') && csrfToken) {
            formData.append('csrf', csrfToken);
        }
        if (!formData.has('geri') && geriUrl) {
            formData.append('geri', geriUrl);
        }
        return fetch('doviz_kaydet.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'fetch',
                'Accept': 'application/json'
            }
        }).then(function (resp) {
            return resp.text().then(function (txt) {
                var json = null;
                try {
                    json = JSON.parse(txt);
                } catch (e) {
                    json = null;
                }
                if (!json || typeof json !== 'object') {
                    return { ok: false, hata: 'Sunucudan beklenmeyen yanıt alındı (HTTP ' + resp.status + ').' };
                }
                return json;
            });
        }).catch(function () {
            return { ok: false, hata: 'Ağ bağlantısı kurulamadı. Lütfen tekrar deneyin.' };
        });
    }

    function yonlendirVeyaYenile(res) {
        var hedef = res && typeof res.yonlendir === 'string' && res.yonlendir ? res.yonlendir : '';
        setTimeout(function () {
            if (hedef && hedef !== (window.location.pathname.split('/').pop() + window.location.search)) {
                window.location.href = hedef;
            } else {
                window.location.reload();
            }
        }, 450);
    }

    function dugmeKilitle(btn, kilitli, metin) {
        if (!btn) return;
        if (kilitli) {
            if (!btn.dataset.eskiMetin) btn.dataset.eskiMetin = btn.textContent;
            btn.disabled = true;
            btn.classList.add('dv-yukleniyor');
            if (metin) btn.textContent = metin;
        } else {
            btn.disabled = false;
            btn.classList.remove('dv-yukleniyor');
            if (btn.dataset.eskiMetin) {
                btn.textContent = btn.dataset.eskiMetin;
                delete btn.dataset.eskiMetin;
            }
        }
    }

    // ── Modal yönetimi ──────────────────────────────────────────────────────
    function modalAc(modal) {
        if (!modal) return;
        var hataKutusu = modal.querySelector('.dv-form-hata');
        if (hataKutusu) {
            hataKutusu.textContent = '';
            hataKutusu.hidden = true;
        }
        modal.hidden = false;
        var ilk = modal.querySelector('input:not([type="hidden"]):not([disabled]), select:not([disabled])');
        if (ilk) {
            setTimeout(function () { ilk.focus(); }, 30);
        }
    }

    function modalKapat(modal) {
        if (!modal) return;
        modal.hidden = true;
    }

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;
        var acik = kok.querySelectorAll('.pm-overlay:not([hidden])');
        for (var i = 0; i < acik.length; i++) {
            modalKapat(acik[i]);
        }
    });

    kok.addEventListener('click', function (e) {
        var kapatBtn = e.target.closest('[data-dv-kapat]');
        if (kapatBtn) {
            modalKapat(kapatBtn.closest('.pm-overlay'));
            return;
        }
        if (e.target.classList && e.target.classList.contains('pm-overlay')) {
            modalKapat(e.target);
        }
    });

    // ── Tarih navigasyonu ───────────────────────────────────────────────────
    var tarihInput = kok.querySelector('[data-dv-tarih-input]');
    if (tarihInput) {
        tarihInput.addEventListener('change', function () {
            var form = tarihInput.closest('form');
            if (form && tarihInput.value) form.submit();
        });
    }

    var kayitliSec = kok.querySelector('[data-dv-tarih-sec]');
    if (kayitliSec) {
        kayitliSec.addEventListener('change', function () {
            if (!kayitliSec.value) return;
            var tumu = kayitliSec.getAttribute('data-tumu') === '1' ? '&tumu=1' : '';
            window.location.href = 'gunluk_kurlar.php?tarih=' + encodeURIComponent(kayitliSec.value) + tumu;
        });
    }

    // ── Hızlı Arama (Günlük Kurlar) ─────────────────────────────────────────
    var araInput = kok.querySelector('[data-dv-ara]');
    if (araInput) {
        var sayacEl   = kok.querySelector('[data-dv-sayac]');
        var bosArama  = kok.querySelector('[data-dv-arama-bos]');
        araInput.addEventListener('input', function () {
            var q = (araInput.value || '').trim().toLocaleLowerCase('tr-TR');
            var satirlar = kok.querySelectorAll('[data-dv-satir]');
            var tabloGorunen = 0;
            var kartGorunen  = 0;

            for (var i = 0; i < satirlar.length; i++) {
                var el = satirlar[i];
                var metin = (el.getAttribute('data-dv-filtre') || '').toLocaleLowerCase('tr-TR');
                var esles = q === '' || metin.indexOf(q) !== -1;
                el.hidden = !esles;
                if (esles) {
                    if (el.tagName === 'TR') tabloGorunen++;
                    else kartGorunen++;
                }
            }
            var gosterilen = tabloGorunen > 0 ? tabloGorunen : kartGorunen;
            if (sayacEl) sayacEl.textContent = gosterilen + ' döviz';
            if (bosArama) bosArama.hidden = gosterilen > 0;
        });
    }

    // ── Canlı Kur Çevirici ──────────────────────────────────────────────────
    var cevKutu = kok.querySelector('[data-dv-cevirici]');
    if (cevKutu && Array.isArray(veri.kurlar)) {
        var kurHarita = {};
        for (var ki = 0; ki < veri.kurlar.length; ki++) {
            kurHarita[veri.kurlar[ki].kod] = veri.kurlar[ki];
        }

        var tutarEl  = cevKutu.querySelector('[data-dv-cev-tutar]');
        var kaynakEl = cevKutu.querySelector('[data-dv-cev-kaynak]');
        var hedefEl  = cevKutu.querySelector('[data-dv-cev-hedef]');
        var tipEl    = cevKutu.querySelector('[data-dv-cev-tip]');
        var takasBtn = cevKutu.querySelector('[data-dv-cev-takas]');
        var sonucEl  = cevKutu.querySelector('[data-dv-cev-sonuc]');
        var birimEl  = cevKutu.querySelector('[data-dv-cev-birim]');

        function sayiCoz(str) {
            var s = String(str || '').trim().replace(/\s+/g, '');
            if (!s) return NaN;
            // Türkçe giriş: nokta binlik ayırıcı, virgül ondalık ayırıcı
            s = s.replace(/\./g, '').replace(',', '.');
            var num = Number(s);
            return Number.isFinite(num) && num >= 0 ? num : NaN;
        }

        function tutarCanliBicimle(ham) {
            // Yalnız rakam ve ilk virgülü koru (nokta binlik olarak otomatik eklenir)
            var temiz = String(ham || '').replace(/[^\d,]/g, '');
            var virgulIdx = temiz.indexOf(',');
            var tam = virgulIdx === -1 ? temiz : temiz.slice(0, virgulIdx);
            var ondalik = virgulIdx === -1 ? null : temiz.slice(virgulIdx + 1).replace(/,/g, '').slice(0, 4);
            // Baştaki fazla sıfırları sadeleştir ("0005" -> "5", "0" -> "0")
            if (tam.length > 1) {
                tam = tam.replace(/^0+/, '') || '0';
            }
            var bicimliTam = tam.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            return ondalik !== null ? (bicimliTam + ',' + ondalik) : bicimliTam;
        }

        function trBicim(num, hane) {
            return num.toLocaleString('tr-TR', {
                minimumFractionDigits: hane,
                maximumFractionDigits: hane
            });
        }

        function birimTryKur(kod, tip) {
            if (kod === 'TRY') return 1;
            var rec = kurHarita[kod];
            if (!rec) return null;
            var ham = rec[tip];
            if (typeof ham !== 'number' || !Number.isFinite(ham) || ham <= 0) {
                var yedek = ['doviz_satis', 'doviz_alis', 'efektif_satis', 'efektif_alis'];
                for (var yi = 0; yi < yedek.length; yi++) {
                    if (typeof rec[yedek[yi]] === 'number' && rec[yedek[yi]] > 0) {
                        ham = rec[yedek[yi]];
                        break;
                    }
                }
            }
            if (typeof ham !== 'number' || !Number.isFinite(ham) || ham <= 0) return null;
            var birim = rec.birim > 0 ? rec.birim : 1;
            return ham / birim;
        }

        function cevirHesapla() {
            var tutar  = sayiCoz(tutarEl ? tutarEl.value : '');
            var kaynak = kaynakEl ? kaynakEl.value : 'USD';
            var hedef  = hedefEl ? hedefEl.value : 'TRY';
            var tip    = tipEl ? tipEl.value : 'doviz_satis';

            if (!Number.isFinite(tutar)) {
                sonucEl.textContent = '—';
                birimEl.textContent = '';
                return;
            }

            var kKur = birimTryKur(kaynak, tip);
            var hKur = birimTryKur(hedef, tip);
            if (kKur === null || hKur === null) {
                sonucEl.textContent = 'Kur yok';
                birimEl.textContent = '';
                return;
            }

            var oran  = kKur / hKur;
            var sonuc = tutar * oran;
            var hane  = hedef === 'TRY' ? 2 : 4;
            var sembol = hedef === 'TRY' ? '₺' : (kurHarita[hedef] ? kurHarita[hedef].sembol : hedef);

            sonucEl.textContent = trBicim(sonuc, hane) + ' ' + sembol;
            birimEl.textContent = '1 ' + kaynak + ' = ' + trBicim(oran, 4) + ' ' + hedef;
        }

        if (tutarEl) {
            tutarEl.addEventListener('input', function () {
                var eski = tutarEl.value;
                var imlec = typeof tutarEl.selectionStart === 'number' ? tutarEl.selectionStart : eski.length;
                // İmleç öncesindeki anlamlı karakter (rakam/virgül) sayısını koru
                var oncekiAnlamli = eski.slice(0, imlec).replace(/[^\d,]/g, '').length;
                var yeni = tutarCanliBicimle(eski);
                if (yeni !== eski) {
                    tutarEl.value = yeni;
                    var sayac = 0;
                    var yeniPos = yeni.length;
                    for (var pi = 0; pi < yeni.length; pi++) {
                        if (sayac >= oncekiAnlamli) {
                            yeniPos = pi;
                            break;
                        }
                        if (/[\d,]/.test(yeni.charAt(pi))) sayac++;
                    }
                    if (typeof tutarEl.setSelectionRange === 'function') {
                        try { tutarEl.setSelectionRange(yeniPos, yeniPos); } catch (ex) {}
                    }
                }
                cevirHesapla();
            });
            tutarEl.value = tutarCanliBicimle(tutarEl.value);
        }
        if (kaynakEl) kaynakEl.addEventListener('change', cevirHesapla);
        if (hedefEl)  hedefEl.addEventListener('change', cevirHesapla);
        if (tipEl)    tipEl.addEventListener('change', cevirHesapla);
        if (takasBtn) {
            takasBtn.addEventListener('click', function () {
                var gecici = kaynakEl.value;
                kaynakEl.value = hedefEl.value;
                hedefEl.value = gecici;
                cevirHesapla();
            });
        }
        cevirHesapla();
    }

    // ── TCMB'den Çek / Son 7 İş Günü ────────────────────────────────────────
    kok.addEventListener('click', function (e) {
        var tcmbBtn = e.target.closest('[data-dv-tcmb-cek]');
        if (tcmbBtn) {
            var tarih = tcmbBtn.getAttribute('data-tarih') || veri.tarih || '';
            var fd = new FormData();
            fd.append('islem', 'tcmb_cek');
            if (tarih) fd.append('tarih', tarih);

            dugmeKilitle(tcmbBtn, true, 'TCMB\'den çekiliyor...');
            postJson(fd).then(function (res) {
                dugmeKilitle(tcmbBtn, false);
                if (res.ok) {
                    toastGoster(res.mesaj || 'TCMB kurları güncellendi.', false);
                    yonlendirVeyaYenile(res);
                } else {
                    toastGoster(res.hata || 'TCMB kurları çekilemedi.', true);
                }
            });
            return;
        }

        var aralikBtn = e.target.closest('[data-dv-aralik-cek]');
        if (aralikBtn) {
            var gun = aralikBtn.getAttribute('data-gun') || '7';
            var fdA = new FormData();
            fdA.append('islem', 'tcmb_aralik_cek');
            fdA.append('gun_sayisi', gun);

            dugmeKilitle(aralikBtn, true, 'Çekiliyor...');
            postJson(fdA).then(function (res) {
                dugmeKilitle(aralikBtn, false);
                if (res.ok) {
                    toastGoster(res.mesaj || 'Geçmiş kurlar güncellendi.', false);
                    yonlendirVeyaYenile(res);
                } else {
                    toastGoster(res.hata || 'Toplu senkron başarısız.', true);
                }
            });
            return;
        }

        // Manuel Kur Ekle modalı aç
        if (e.target.closest('[data-dv-kur-ekle]')) {
            var kurModal = document.getElementById('dvModalKur');
            if (!kurModal) return;
            var f = kurModal.querySelector('form');
            var baslik = document.getElementById('dvModalKurBaslik');
            if (baslik) baslik.textContent = 'Manuel Kur Ekle';
            if (f) {
                f.reset();
                if (f.elements.tarih && veri.tarih) f.elements.tarih.value = veri.tarih;
                var kodSel = f.elements.kod;
                if (kodSel && kodSel.selectedOptions && kodSel.selectedOptions[0] && f.elements.birim) {
                    f.elements.birim.value = kodSel.selectedOptions[0].getAttribute('data-birim') || '1';
                }
            }
            modalAc(kurModal);
            return;
        }

        // Manuel Kur Düzenle modalı aç
        var kurDuzBtn = e.target.closest('[data-dv-kur-duzenle]');
        if (kurDuzBtn) {
            var kurModalD = document.getElementById('dvModalKur');
            if (!kurModalD) return;
            var d = null;
            try { d = JSON.parse(kurDuzBtn.getAttribute('data-dv-kur-duzenle') || '{}'); } catch (err) { d = null; }
            if (!d) return;
            var fdForm = kurModalD.querySelector('form');
            var baslikD = document.getElementById('dvModalKurBaslik');
            if (baslikD) baslikD.textContent = (d.kod || '') + ' Kurunu Düzenle';
            if (fdForm) {
                fdForm.reset();
                if (fdForm.elements.tarih) fdForm.elements.tarih.value = d.tarih || veri.tarih || '';
                if (fdForm.elements.kod) {
                    var kodVar = false;
                    for (var oi = 0; oi < fdForm.elements.kod.options.length; oi++) {
                        if (fdForm.elements.kod.options[oi].value === d.kod) {
                            kodVar = true;
                            break;
                        }
                    }
                    if (!kodVar && d.kod) {
                        var opt = document.createElement('option');
                        opt.value = d.kod;
                        opt.textContent = d.kod;
                        fdForm.elements.kod.appendChild(opt);
                    }
                    fdForm.elements.kod.value = d.kod || '';
                }
                if (fdForm.elements.birim)         fdForm.elements.birim.value         = String(d.birim || 1);
                if (fdForm.elements.doviz_alis)    fdForm.elements.doviz_alis.value    = d.doviz_alis || '';
                if (fdForm.elements.doviz_satis)   fdForm.elements.doviz_satis.value   = d.doviz_satis || '';
                if (fdForm.elements.efektif_alis)  fdForm.elements.efektif_alis.value  = d.efektif_alis || '';
                if (fdForm.elements.efektif_satis) fdForm.elements.efektif_satis.value = d.efektif_satis || '';
            }
            modalAc(kurModalD);
            return;
        }

        // Kur Sil
        var kurSilBtn = e.target.closest('[data-dv-kur-sil]');
        if (kurSilBtn) {
            var kurId  = kurSilBtn.getAttribute('data-dv-kur-sil');
            var kurKod = kurSilBtn.getAttribute('data-dv-kod') || '';
            if (!window.confirm(kurKod + ' kur kaydı silinecek. Emin misiniz?')) return;
            var fdSil = new FormData();
            fdSil.append('islem', 'kur_sil');
            fdSil.append('id', kurId);

            dugmeKilitle(kurSilBtn, true);
            postJson(fdSil).then(function (res) {
                dugmeKilitle(kurSilBtn, false);
                if (res.ok) {
                    toastGoster(res.mesaj || 'Kur kaydı silindi.', false);
                    yonlendirVeyaYenile(res);
                } else {
                    toastGoster(res.hata || 'Kur kaydı silinemedi.', true);
                }
            });
            return;
        }

        // Yeni Döviz Tanımı modalı aç
        if (e.target.closest('[data-dv-tanim-ekle]')) {
            tanimModalAc(null);
            return;
        }

        // Döviz Tanımı Düzenle modalı aç
        var tanimDuzBtn = e.target.closest('[data-dv-tanim-duzenle]');
        if (tanimDuzBtn) {
            var tObj = null;
            try { tObj = JSON.parse(tanimDuzBtn.getAttribute('data-dv-tanim-duzenle') || '{}'); } catch (err) { tObj = null; }
            if (tObj) tanimModalAc(tObj);
            return;
        }

        // Döviz Tanımı Sil
        var tanimSilBtn = e.target.closest('[data-dv-tanim-sil]');
        if (tanimSilBtn) {
            var tid  = tanimSilBtn.getAttribute('data-dv-tanim-sil');
            var tkod = tanimSilBtn.getAttribute('data-dv-kod') || '';
            if (!window.confirm(tkod + ' para birimi tanımı silinecek. Emin misiniz?')) return;
            var fdTSil = new FormData();
            fdTSil.append('islem', 'tanim_sil');
            fdTSil.append('id', tid);

            dugmeKilitle(tanimSilBtn, true);
            postJson(fdTSil).then(function (res) {
                dugmeKilitle(tanimSilBtn, false);
                if (res.ok) {
                    toastGoster(res.mesaj || 'Para birimi silindi.', false);
                    yonlendirVeyaYenile(res);
                } else {
                    toastGoster(res.hata || 'Para birimi silinemedi.', true);
                }
            });
        }
    });

    // Manuel kur modalında döviz seçilince birimi otomatik doldur
    var kurKodSel = kok.querySelector('[data-dv-kur-kod]');
    if (kurKodSel) {
        kurKodSel.addEventListener('change', function () {
            var opt = kurKodSel.selectedOptions && kurKodSel.selectedOptions[0];
            var form = kurKodSel.closest('form');
            if (opt && form && form.elements.birim) {
                form.elements.birim.value = opt.getAttribute('data-birim') || '1';
            }
        });
    }

    // ── Döviz Tanımı Modal Doldurma & TCMB Kataloğu ─────────────────────────
    function tanimModalAc(tObj) {
        var modal = document.getElementById('dvModalTanim');
        if (!modal) return;
        var form   = modal.querySelector('form');
        var baslik = document.getElementById('dvModalTanimBaslik');
        if (!form) return;

        form.reset();
        var duzenleme = !!(tObj && tObj.id);
        var tryMi     = !!(tObj && tObj.kod === 'TRY');

        if (baslik) {
            baslik.textContent = duzenleme ? (tObj.kod + ' Tanımını Düzenle') : 'Yeni Döviz Ekle';
        }

        form.elements.id.value                  = duzenleme ? String(tObj.id) : '0';
        form.elements.kod.value                 = tObj && tObj.kod ? tObj.kod : '';
        form.elements.kod.readOnly              = tryMi;
        form.elements.ad.value                  = tObj && tObj.isim ? tObj.isim : '';
        form.elements.sembol.value              = tObj && tObj.sembol ? tObj.sembol : '';
        form.elements.birim.value               = String(tObj && tObj.birim ? tObj.birim : 1);
        form.elements.ondalik.value             = String(tObj && tObj.ondalik ? tObj.ondalik : 4);
        form.elements.varsayilan_kur_tipi.value = tObj && tObj.varsayilan_kur_tipi ? tObj.varsayilan_kur_tipi : 'doviz_satis';
        form.elements.sira.value                = String(tObj && typeof tObj.sira === 'number' ? tObj.sira : 90);

        form.elements.tcmb_aktif.checked  = tObj ? !!tObj.tcmb_aktif : true;
        form.elements.tcmb_aktif.disabled = tryMi;
        form.elements.aktif.checked       = tObj ? !!tObj.aktif : true;
        form.elements.aktif.disabled      = tryMi;

        modalAc(modal);
    }

    var katalogSec = kok.querySelector('[data-dv-katalog-sec]');
    if (katalogSec) {
        katalogSec.addEventListener('change', function () {
            var secKod = katalogSec.value;
            if (!secKod) return;
            katalogSec.value = '';
            var kat = veri.katalog && veri.katalog[secKod] ? veri.katalog[secKod] : null;
            if (!kat) return;
            tanimModalAc({
                id: 0,
                kod: kat.kod,
                isim: kat.isim,
                sembol: kat.sembol,
                birim: kat.birim,
                ondalik: 4,
                varsayilan_kur_tipi: 'doviz_satis',
                tcmb_aktif: 1,
                aktif: 1,
                sira: 90
            });
        });
    }

    // ── Switch (tanim_durum) ────────────────────────────────────────────────
    kok.addEventListener('change', function (e) {
        var sw = e.target.closest('input[data-dv-durum]');
        if (!sw) return;
        var alan  = sw.getAttribute('data-dv-durum');
        var id    = sw.getAttribute('data-id');
        var yeni  = sw.checked ? 1 : 0;

        var fd = new FormData();
        fd.append('islem', 'tanim_durum');
        fd.append('id', id);
        fd.append('alan', alan);
        fd.append('deger', String(yeni));

        sw.disabled = true;
        postJson(fd).then(function (res) {
            sw.disabled = false;
            if (res.ok) {
                toastGoster(res.mesaj || 'Durum güncellendi.', false);
                var satir = sw.closest('tr, .dv-kart');
                if (satir && alan === 'aktif') {
                    satir.classList.toggle('dv-satir--pasif', yeni === 0);
                }
            } else {
                sw.checked = !sw.checked;
                toastGoster(res.hata || 'Durum güncellenemedi.', true);
            }
        });
    });

    // ── Modal Form Gönderimleri (kur_kaydet / tanim_kaydet) ─────────────────
    kok.addEventListener('submit', function (e) {
        var form = e.target.closest('form[data-dv-form]');
        if (!form) return;
        e.preventDefault();

        var hataKutusu = form.querySelector('.dv-form-hata');
        if (hataKutusu) {
            hataKutusu.textContent = '';
            hataKutusu.hidden = true;
        }

        var fd = new FormData(form);
        // İşaretlenmemiş checkbox'lar için açıkça 0 gönder
        if (form.getAttribute('data-dv-form') === 'tanim_kaydet') {
            fd.set('tcmb_aktif', form.elements.tcmb_aktif && form.elements.tcmb_aktif.checked ? '1' : '0');
            fd.set('aktif', form.elements.aktif && form.elements.aktif.checked ? '1' : '0');
            if (form.elements.kod && form.elements.kod.value) {
                fd.set('kod', form.elements.kod.value.trim().toUpperCase());
            }
        }

        var gonderBtn = form.querySelector('button[type="submit"]');
        dugmeKilitle(gonderBtn, true, 'Kaydediliyor...');

        postJson(fd).then(function (res) {
            dugmeKilitle(gonderBtn, false);
            if (res.ok) {
                modalKapat(form.closest('.pm-overlay'));
                toastGoster(res.mesaj || 'Kaydedildi.', false);
                yonlendirVeyaYenile(res);
            } else if (hataKutusu) {
                hataKutusu.textContent = res.hata || 'Kayıt sırasında hata oluştu.';
                hataKutusu.hidden = false;
            } else {
                toastGoster(res.hata || 'Kayıt sırasında hata oluştu.', true);
            }
        });
    });
})();
