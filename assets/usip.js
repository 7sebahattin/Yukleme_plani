/* usip.js — Ürün Siparişleri (urun_siparis.php): satıra tıkla → düzelt, yeni sipariş, kalibre ekle/çıkar,
 * yazarak arama + "+ ekle" kutuları, canlı özet, kaydet/sil. Hesabın tek otoritesi sunucudur (config/urun_siparis.php
 * usip_hesap); buradaki özet yalnız önizlemedir. Yazdır / PDF / WP resmi assets/siparis_yazdir.js'tedir. */
(function () {
  'use strict';
  var kok = document.getElementById('usip');
  if (!kok) return;
  var $ = function (id) { return document.getElementById(id); };
  var CSRF = kok.getAttribute('data-csrf') || '';
  var YAZMA = kok.getAttribute('data-yazma') === '1';
  var SILME = kok.getAttribute('data-silme') === '1';
  var UC = 'urun_siparis_islem.php';
  var REF = { firma: [], urun: [], palet: [], kasa: [] };
  try { REF = JSON.parse($('usipRef').textContent) || REF; } catch (e) { /* öneriler boş kalır */ }

  var nf0 = new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 0 });
  var nf2 = new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  var TAG = { hks: 'HKS', kayit: 'Kayıtlı', tanim: 'Tanımlı', yeni: 'Yeni' };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function norm(x) { return String(x || '').replace(/İ|I|ı/g, 'i').toLocaleLowerCase('tr').trim(); }
  // usip_num() aynası: virgül varsa TR biçimi; yalnız 1.234.567 deseni binlik; aksi halde nokta ondalık
  function sayi(v) {
    var s = String(v == null ? '' : v).replace(/\s+/g, '');
    if (s === '') return null;
    if (s.indexOf(',') >= 0) s = s.replace(/\./g, '').replace(',', '.');
    else if (/^\d{1,3}(\.\d{3})+$/.test(s)) s = s.replace(/\./g, '');
    return /^\d+(\.\d+)?$/.test(s) ? parseFloat(s) : null;
  }
  function toast(m) {
    var t = document.createElement('div'); t.className = 'usip-toast'; t.setAttribute('role', 'status'); t.textContent = m;
    document.body.appendChild(t); setTimeout(function () { t.remove(); }, 2600);
  }
  try { var bekleyen = sessionStorage.getItem('usip_toast'); if (bekleyen) { sessionStorage.removeItem('usip_toast'); toast(bekleyen); } } catch (e) { /* depolama kapalı */ }

  /* ---- yazarak ara + "+ ekle" ---- */
  function combo(id, items, ekle) {
    var inp = $(id), list = inp.parentNode.querySelector('.usip-list'), sec = -1, gor = [];
    function ac() {
      var q = norm(inp.value), h = ''; gor = [];
      items.forEach(function (it) { if (!q || norm(it.ad).indexOf(q) >= 0) gor.push(it); });
      gor.forEach(function (it, i) { h += '<div class="usip-opt" role="option" data-i="' + i + '"><span>' + esc(it.ad) + '</span><span class="usip-tag usip-tag-' + it.t + '">' + TAG[it.t] + '</span></div>'; });
      if (q && !items.some(function (it) { return norm(it.ad) === q; })) h += '<div class="usip-opt usip-opt-ekle" data-ekle="1">' + ekle(esc(inp.value.trim())) + '</div>';
      list.innerHTML = h || '<div class="usip-opt-bos">Kayıt yok</div>';
      list.hidden = false; inp.setAttribute('aria-expanded', 'true'); sec = -1;
    }
    function kapat() { list.hidden = true; inp.setAttribute('aria-expanded', 'false'); }
    function sec_(i) { inp.value = gor[i].ad; kapat(); inp.dispatchEvent(new Event('input', { bubbles: true })); }
    function yeni() { var ad = inp.value.trim(); if (!ad) return; inp.value = ad; kapat(); inp.dispatchEvent(new Event('input', { bubbles: true })); }
    inp.addEventListener('click', ac);   // odaklanınca değil, tıklayınca/yazınca açılır (pencere açılışında liste kendiliğinden belirmesin)
    inp.addEventListener('input', function () { if (document.activeElement === inp) ac(); });
    list.addEventListener('mousedown', function (e) {
      e.preventDefault(); var o = e.target.closest('.usip-opt'); if (!o) return;
      if (o.getAttribute('data-ekle')) yeni(); else if (o.getAttribute('data-i') != null) sec_(+o.getAttribute('data-i'));
    });
    inp.addEventListener('keydown', function (e) {
      var os = list.querySelectorAll('.usip-opt[data-i],.usip-opt[data-ekle]');
      if (e.key === 'Escape' && !list.hidden) { kapat(); e.stopPropagation(); return; }
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault(); if (list.hidden) ac();
        os = list.querySelectorAll('.usip-opt[data-i],.usip-opt[data-ekle]');
        sec = Math.max(0, Math.min(os.length - 1, sec + (e.key === 'ArrowDown' ? 1 : -1)));
        Array.prototype.forEach.call(os, function (o, i) { o.classList.toggle('on', i === sec); });
        if (os[sec]) os[sec].scrollIntoView({ block: 'nearest' });
      }
      if (e.key === 'Enter' && !list.hidden) { e.preventDefault(); var o = os[sec]; if (o) { if (o.getAttribute('data-ekle')) yeni(); else sec_(+o.getAttribute('data-i')); } else kapat(); }
    });
    document.addEventListener('mousedown', function (e) { if (!inp.parentNode.contains(e.target)) kapat(); });
    inp.addEventListener('blur', function () { setTimeout(kapat, 120); });
  }
  combo('f_firma', REF.firma, function (a) { return '+ “' + a + '” yeni firma olarak kaydet'; });
  combo('f_urun', REF.urun, function (a) { return '+ “' + a + '” ürün olarak ekle'; });
  combo('f_pcins', REF.palet, function (a) { return '+ “' + a + '” palet cinsi olarak ekle'; });
  combo('f_kcins', REF.kasa, function (a) { return '+ “' + a + '” kasa cinsi olarak ekle'; });
  Array.prototype.forEach.call(document.querySelectorAll('[data-plaka]'), function (i) {
    i.addEventListener('input', function () { i.value = i.value.replace(/\s/g, '').toLocaleUpperCase('tr'); });
  });

  /* ---- kalibre satırları ---- */
  function kalSatir(no, palet) {
    var d = document.createElement('div'); d.className = 'usip-kal-satir';
    d.innerHTML = '<div class="usip-f"><label>Kalibre (No)</label><input class="k-no" maxlength="20" inputmode="numeric" placeholder="örn. 7" autocomplete="off"></div>' +
      '<div class="usip-f"><label>Palet <span class="u">(adet)</span></label><input class="k-palet" inputmode="numeric" placeholder="0" autocomplete="off"></div>' +
      '<button type="button" class="rm" aria-label="Kalibreyi kaldır">×</button>';
    d.querySelector('.k-no').value = no || ''; d.querySelector('.k-palet').value = palet || '';
    $('usipKal').appendChild(d); rmDurum(); return d;
  }
  function rmDurum() { var r = $('usipKal').children; for (var i = 0; i < r.length; i++) r[i].querySelector('.rm').disabled = (r.length === 1 || !YAZMA); }

  /* ---- form <-> veri ---- */
  function oku() {
    return {
      firma: $('f_firma').value.trim(), urun: $('f_urun').value.trim(), teslimat_tarihi: $('f_tarih').value,
      kalibreler: Array.prototype.map.call($('usipKal').children, function (r) { return { kalibre: r.querySelector('.k-no').value.trim(), palet: r.querySelector('.k-palet').value.trim() }; }),
      palet_cinsi: $('f_pcins').value.trim(), kasa_cinsi: $('f_kcins').value.trim(),
      palet_kasa: $('f_pkasa').value.trim(), birim_agirlik: $('f_birim').value.trim(), birim_fiyat: $('f_fiyat').value.trim(),
      nakliyeci: $('l_nakliyeci').value.trim(), cekici_plaka: $('l_cekici').value, dorse_plaka: $('l_dorse').value,
      sofor_adi: $('l_sofor').value.trim(), sofor_telefon: $('l_tel').value.trim(), yurtici_gumruk: $('l_gumruk').value.trim(), navlun_no: $('l_navlun').value.trim(),
      aciklama: $('f_aciklama').value.trim()
    };
  }
  function doldur(s) {
    s = s || {};
    $('f_firma').value = s.firma || ''; $('f_urun').value = s.urun || ''; $('f_tarih').value = s.teslimat_tarihi || '';
    $('f_pcins').value = s.palet_cinsi || ''; $('f_kcins').value = s.kasa_cinsi || '';
    $('f_pkasa').value = s.palet_kasa ? String(s.palet_kasa) : '';
    $('f_birim').value = s.birim_agirlik && +s.birim_agirlik ? String(+s.birim_agirlik).replace('.', ',') : '';
    $('f_fiyat').value = s.birim_fiyat !== null && s.birim_fiyat !== undefined && s.birim_fiyat !== '' ? String(+s.birim_fiyat).replace('.', ',') : '';
    $('l_nakliyeci').value = s.nakliyeci || ''; $('l_cekici').value = s.cekici_plaka || ''; $('l_dorse').value = s.dorse_plaka || '';
    $('l_sofor').value = s.sofor_adi || ''; $('l_tel').value = s.sofor_telefon || ''; $('l_gumruk').value = s.yurtici_gumruk || ''; $('l_navlun').value = s.navlun_no || '';
    $('f_aciklama').value = s.aciklama || '';
    $('usipKal').innerHTML = '';
    (s.kalibreler && s.kalibreler.length ? s.kalibreler : [{ kalibre: '', palet: '' }]).forEach(function (k) { kalSatir(k.kalibre, k.palet); });
    Array.prototype.forEach.call(document.querySelectorAll('#usipOvl .bad'), function (e) { e.classList.remove('bad'); });
    ozet();
  }
  function ozet() {
    var o = oku(), palet = 0;
    o.kalibreler.forEach(function (k) { palet += sayi(k.palet) || 0; });
    var pk = sayi(o.palet_kasa) || 0, ba = sayi(o.birim_agirlik) || 0, bf = sayi(o.birim_fiyat);
    var kasa = palet * pk, kg = kasa * ba, tutar = bf !== null ? Math.round(kg * bf * 100) / 100 : 0;
    $('s_palet').textContent = nf0.format(palet); $('s_kasa').textContent = nf0.format(kasa);
    $('s_kg').textContent = nf0.format(Math.round(kg)) + ' kg'; $('s_tutar').textContent = nf2.format(tutar) + ' TL';
    $('f_kasa').value = nf0.format(kasa); $('f_tutar').value = bf !== null ? nf2.format(tutar) : '';
    var a = [];
    if (o.palet_cinsi) a.push(o.palet_cinsi); if (o.kasa_cinsi) a.push(o.kasa_cinsi);
    if (pk) a.push(nf0.format(pk) + ' kasa/palet'); if (ba) a.push(String(ba).replace('.', ',') + ' kg');
    oz('o_amb', a.join(' · '));
    var l = [o.nakliyeci, o.cekici_plaka, o.dorse_plaka, o.sofor_adi].filter(Boolean); oz('o_loj', l.join(' · '));
    oz('o_fiyat', bf !== null ? nf2.format(bf) + ' TL/kg · ' + nf2.format(tutar) + ' TL' : '');
    var fk = o.firma && REF.firma.some(function (x) { return norm(x.ad) === norm(o.firma); });
    var nf = $('n_firma'); nf.textContent = !o.firma ? '' : (fk ? '✓ Kayıtlı firma' : 'Kayıtta yok: tam adıyla yeni firma olarak kaydedilir'); nf.className = 'usip-not ' + (!o.firma ? '' : fk ? 'ok' : 'warn');
    var uk = o.urun && REF.urun.filter(function (x) { return norm(x.ad) === norm(o.urun); })[0];
    var nu = $('n_urun'); nu.textContent = !o.urun ? '' : (uk ? (uk.t === 'hks' ? '✓ HKS ürünü' : '✓ Kayıtlı ürün') : 'Listede yok: yeni ürün olarak kaydedilir'); nu.className = 'usip-not ' + (!o.urun ? '' : uk ? 'ok' : 'warn');
  }
  function oz(id, m) { var e = $(id); e.textContent = m || 'Girilmedi'; e.classList.toggle('bos', !m); }

  /* ---- pencere ---- */
  var duzenlenen = null;
  function hata(m) { var e = $('usipHata'); e.textContent = m || ''; e.hidden = !m; if (m) e.scrollIntoView({ block: 'nearest' }); }
  function silOnayKapat() { $('usipFoot').hidden = false; $('usipSilOnay').hidden = true; }
  function ac(s) {
    duzenlenen = s ? s.id : null;
    $('usipBaslik').textContent = s ? (YAZMA ? 'Siparişi Düzenle' : 'Sipariş') : 'Yeni Sipariş';
    doldur(s); hata('');
    $('usipAlanlar').disabled = !YAZMA; $('usipKaydet').hidden = !YAZMA; $('usipKalEkle').hidden = !YAZMA; rmDurum();
    $('usipSil').hidden = !(s && SILME); silOnayKapat();
    $('usipOvl').hidden = false;
    if (!s) { $('accAmb').open = true; $('accFiyat').open = true; }
    if (YAZMA && !s) $('f_firma').focus();
  }
  function kapat() { $('usipOvl').hidden = true; }
  function getir(id) {
    return fetch(UC + '?id=' + encodeURIComponent(id), { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok || !j.ok) throw new Error(j.hata || 'Sipariş okunamadı.'); return j.siparis; }); });
  }
  function ac_id(id) { getir(id).then(ac).catch(function (e) { toast(e.message || 'Sipariş okunamadı.'); }); }
  function gonder(govde) {
    govde.csrf = CSRF;
    return fetch(UC, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(govde) })
      .then(function (r) { return r.json().catch(function () { throw new Error('Sunucu beklenmeyen bir yanıt verdi — sayfayı yenileyip tekrar deneyin.'); }).then(function (j) { if (!r.ok || !j.ok) throw new Error(j.hata || 'İşlem yapılamadı.'); return j; }); });
  }
  function bitti(m) { try { sessionStorage.setItem('usip_toast', m); } catch (e) { /* yok say */ } location.reload(); }

  function kaydet() {
    var o = oku(), eksik = [];
    function isaret(id, kosul) { $(id).classList.toggle('bad', kosul); if (kosul) eksik.push(id); }
    isaret('f_firma', !o.firma); isaret('f_urun', !o.urun); isaret('f_tarih', !o.teslimat_tarihi);
    isaret('f_pkasa', !(sayi(o.palet_kasa) > 0)); isaret('f_birim', !(sayi(o.birim_agirlik) > 0));
    var kalHata = false;
    Array.prototype.forEach.call($('usipKal').children, function (r) {
      var no = r.querySelector('.k-no'), p = r.querySelector('.k-palet'), a = !no.value.trim(), b = !(sayi(p.value) >= 1);
      no.classList.toggle('bad', a); p.classList.toggle('bad', b); if (a || b) kalHata = true;
    });
    if (eksik.indexOf('f_pkasa') >= 0 || eksik.indexOf('f_birim') >= 0) $('accAmb').open = true;
    if (eksik.length || kalHata) { hata('Kırmızı alanları doldurun.'); return; }
    hata('');
    var b = $('usipKaydet'); b.disabled = true;
    o.islem = 'kaydet'; if (duzenlenen) o.id = duzenlenen;
    gonder(o).then(function (j) { bitti(j.mesaj || 'Sipariş kaydedildi.'); })
      .catch(function (e) { hata(e.message); b.disabled = false; });
  }

  $('usipKalEkle').onclick = function () { kalSatir().querySelector('.k-no').focus(); ozet(); };
  $('usipKal').addEventListener('click', function (e) { var b = e.target.closest('.rm'); if (b && !b.disabled) { b.parentNode.remove(); rmDurum(); ozet(); } });
  $('usipOvl').addEventListener('input', ozet);
  $('usipKaydet').onclick = kaydet;
  $('usipKapat').onclick = kapat; $('usipX').onclick = kapat;
  $('usipOvl').addEventListener('mousedown', function (e) { if (e.target === $('usipOvl')) kapat(); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !$('usipOvl').hidden) kapat(); });
  $('usipSil').onclick = function () { $('usipFoot').hidden = true; $('usipSilOnay').hidden = false; $('usipSilVazgec').focus(); };
  $('usipSilVazgec').onclick = silOnayKapat;
  $('usipSilEvet').onclick = function () {
    var b = $('usipSilEvet'); b.disabled = true;
    gonder({ islem: 'sil', id: duzenlenen }).then(function (j) { bitti(j.mesaj || 'Sipariş silindi.'); })
      .catch(function (e) { silOnayKapat(); hata(e.message); b.disabled = false; });
  };
  if ($('usipYeni')) $('usipYeni').onclick = function () { ac(null); };

  /* satırlar / kartlar tıklanabilir (Enter + Boşluk da açar; Ctrl/Cmd ile seçim engellenmez) */
  function satirAc(e) {
    var el = e.target.closest ? e.target.closest('[data-id]') : null;
    if (!el || !kok.contains(el)) return;
    if (e.type === 'keydown' && e.key !== 'Enter' && e.key !== ' ') return;
    if (e.type === 'keydown') e.preventDefault();
    ac_id(el.getAttribute('data-id'));
  }
  kok.addEventListener('click', function (e) { if (e.target.closest('.usip-satir,.usip-kt[data-id]')) satirAc(e); });
  kok.addEventListener('keydown', function (e) { if (e.target.matches && e.target.matches('.usip-satir,.usip-kt[data-id]')) satirAc(e); });
})();
