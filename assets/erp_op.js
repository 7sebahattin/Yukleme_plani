/* =========================================================
 * assets/erp_op.js — ERP Faz 2 Dinamik Satır ve Palet/Dara/Net Hesaplayıcı
 * Kurallar: IIFE · istemci depolaması yok · güvenli DOM şablonları
 * ========================================================= */
(function () {
  'use strict';

  function num(v) {
    var s = String(v || '').trim();
    if (!s) return 0;
    if (s.indexOf(',') !== -1 && s.indexOf('.') !== -1) {
      s = s.replace(/\./g, '').replace(',', '.');
    } else {
      s = s.replace(',', '.');
    }
    var n = parseFloat(s);
    return isFinite(n) && n > 0 ? n : 0;
  }

  function fmt(n, d) {
    return (Math.round(n * Math.pow(10, d)) / Math.pow(10, d)).toLocaleString('tr-TR', {
      minimumFractionDigits: d,
      maximumFractionDigits: d
    });
  }

  // 1) Yeni Araç Yükleme Fişleri (v2) — dinamik palet satırı & anlık Dara/Net hesabı
  var paletBody = document.getElementById('eopPaletBody');
  var paletTpl = document.getElementById('eopPaletTpl');
  var paletEkleBtn = document.getElementById('eopPaletEkle');
  var elTopBrut = document.getElementById('eopTopBrut');
  var elTopDara = document.getElementById('eopTopDara');
  var elTopNet = document.getElementById('eopTopNet');
  var elTopKasa = document.getElementById('eopTopKasa');

  function paletHesapla() {
    if (!paletBody) return;
    var tBrut = 0, tDara = 0, tNet = 0, tKasa = 0;
    paletBody.querySelectorAll('tr.eop-palet-satir').forEach(function (tr) {
      var brut = num((tr.querySelector('[data-p="brut"]') || {}).value);
      var kasa = Math.floor(num((tr.querySelector('[data-p="kasa"]') || {}).value));
      var kDara = num((tr.querySelector('[data-p="kdara"]') || {}).value);
      var pDara = num((tr.querySelector('[data-p="pdara"]') || {}).value);
      var eDara = num((tr.querySelector('[data-p="edara"]') || {}).value);
      var dara = Math.round((kasa * kDara + pDara + eDara) * 1000) / 1000;
      var net = Math.max(0, Math.round((brut - dara) * 1000) / 1000);
      var outD = tr.querySelector('[data-out="dara"]');
      var outN = tr.querySelector('[data-out="net"]');
      if (outD) outD.textContent = fmt(dara, 2);
      if (outN) outN.textContent = fmt(net, 2);
      tBrut += brut;
      tDara += dara;
      tNet += net;
      tKasa += kasa;
    });
    if (elTopBrut) elTopBrut.textContent = fmt(tBrut, 2) + ' kg';
    if (elTopDara) elTopDara.textContent = fmt(tDara, 2) + ' kg';
    if (elTopNet) elTopNet.textContent = fmt(tNet, 2) + ' kg';
    if (elTopKasa) elTopKasa.textContent = String(tKasa);
  }

  function paletSatirEkle() {
    if (!paletBody || !paletTpl || !paletTpl.content) return;
    var frag = document.importNode(paletTpl.content, true);
    var pNo = frag.querySelector('input[name="palet_no[]"]');
    if (pNo && !pNo.value) {
      pNo.value = 'P-' + (paletBody.querySelectorAll('tr.eop-palet-satir').length + 1);
    }
    paletBody.appendChild(frag);
    paletHesapla();
  }

  if (paletBody) {
    if (paletBody.querySelectorAll('tr.eop-palet-satir').length === 0) {
      paletSatirEkle();
    }
    paletBody.addEventListener('input', paletHesapla);
    paletBody.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('button[data-satir-sil]') : null;
      if (!btn) return;
      if (paletBody.querySelectorAll('tr.eop-palet-satir').length <= 1) return;
      var tr = btn.closest('tr.eop-palet-satir');
      if (tr) tr.remove();
      paletHesapla();
    });
    paletHesapla();
  }
  if (paletEkleBtn) paletEkleBtn.addEventListener('click', paletSatirEkle);

  // 2) Genel dinamik satır ekleyici (BOM bileşeni / Satın Alma talep satırı)
  document.querySelectorAll('button[data-eop-ekle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var targetId = btn.getAttribute('data-eop-ekle');
      var tplId = btn.getAttribute('data-eop-tpl');
      var body = targetId ? document.getElementById(targetId) : null;
      var tpl = tplId ? document.getElementById(tplId) : null;
      if (body && tpl && tpl.content) {
        body.appendChild(document.importNode(tpl.content, true));
      }
    });
  });

  document.addEventListener('click', function (e) {
    var btn = e.target && e.target.closest ? e.target.closest('button[data-eop-sil]') : null;
    if (!btn) return;
    var tr = btn.closest('tr');
    var tbody = tr ? tr.parentElement : null;
    if (tbody && tbody.querySelectorAll('tr').length > 1 && tr) {
      tr.remove();
    }
  });
})();
