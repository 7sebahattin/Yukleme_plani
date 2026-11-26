/* =========================================================
 * assets/muhasebe.js — Genel Muhasebe (TDHP), e-Dönüşüm & Mutabakat
 * Kurallar: IIFE · istemci depolaması yok · güvenli DOM şablonları
 * ========================================================= */
(function () {
  'use strict';

  function parseTutar(v) {
    var s = String(v || '').trim();
    if (!s) return 0;
    if (s.indexOf(',') !== -1 && s.indexOf('.') !== -1) {
      s = s.replace(/\./g, '').replace(',', '.');
    } else {
      s = s.replace(',', '.');
    }
    var n = parseFloat(s);
    return isFinite(n) && n > 0 ? Math.round(n * 100) / 100 : 0;
  }

  function fmt(n) {
    return (Math.round(n * 100) / 100).toLocaleString('tr-TR', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }

  // 1) Yevmiye fişi dinamik satır yönetimi + anlık Borç = Alacak doğrulaması
  var fisForm = document.getElementById('muhFisForm');
  var satirBody = document.getElementById('muhSatirlarBody');
  var satirTpl = document.getElementById('muhSatirTpl');
  var btnEkle = document.getElementById('muhSatirEkle');
  var dengeBar = document.getElementById('muhDengeBar');
  var elTopBorc = document.getElementById('muhTopBorc');
  var elTopAlacak = document.getElementById('muhTopAlacak');
  var elFark = document.getElementById('muhTopFark');
  var btnKaydet = document.getElementById('muhFisKaydetBtn');

  function dengeHesapla() {
    if (!satirBody) return { borc: 0, alacak: 0, fark: 0, satirSayisi: 0 };
    var borc = 0;
    var alacak = 0;
    var dolu = 0;
    var rows = satirBody.querySelectorAll('tr.muh-fis-satir');
    rows.forEach(function (tr) {
      var bEl = tr.querySelector('input[data-alan="borc"]');
      var aEl = tr.querySelector('input[data-alan="alacak"]');
      var hEl = tr.querySelector('select[data-alan="hesap"]');
      var b = parseTutar(bEl ? bEl.value : 0);
      var a = parseTutar(aEl ? aEl.value : 0);
      borc = Math.round((borc + b) * 100) / 100;
      alacak = Math.round((alacak + a) * 100) / 100;
      if (hEl && hEl.value && (b > 0 || a > 0)) dolu++;
    });
    var fark = Math.round(Math.abs(borc - alacak) * 100) / 100;
    if (elTopBorc) elTopBorc.textContent = fmt(borc) + ' TL';
    if (elTopAlacak) elTopAlacak.textContent = fmt(alacak) + ' TL';
    if (elFark) elFark.textContent = fmt(fark) + ' TL';
    var dengeli = dolu >= 2 && borc > 0 && fark < 0.01;
    if (dengeBar) {
      dengeBar.setAttribute('data-durum', dengeli ? 'ok' : (borc > 0 || alacak > 0 ? 'hata' : 'bos'));
    }
    if (btnKaydet) {
      btnKaydet.disabled = !dengeli;
    }
    return { borc: borc, alacak: alacak, fark: fark, satirSayisi: dolu, dengeli: dengeli };
  }

  function satirEkle() {
    if (!satirBody || !satirTpl || !satirTpl.content) return;
    var frag = document.importNode(satirTpl.content, true);
    satirBody.appendChild(frag);
    dengeHesapla();
  }

  if (satirBody) {
    if (satirBody.querySelectorAll('tr.muh-fis-satir').length < 2) {
      satirEkle();
      satirEkle();
    }
    satirBody.addEventListener('input', function (e) {
      var t = e.target;
      if (!t || !t.getAttribute) return;
      var alan = t.getAttribute('data-alan');
      if (alan === 'borc' || alan === 'alacak') {
        var tr = t.closest('tr.muh-fis-satir');
        if (tr && parseTutar(t.value) > 0) {
          var diger = tr.querySelector('input[data-alan="' + (alan === 'borc' ? 'alacak' : 'borc') + '"]');
          if (diger && parseTutar(diger.value) > 0) diger.value = '';
        }
        dengeHesapla();
      }
    });
    satirBody.addEventListener('change', dengeHesapla);
    satirBody.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('button[data-satir-sil]') : null;
      if (!btn) return;
      var rows = satirBody.querySelectorAll('tr.muh-fis-satir');
      if (rows.length <= 2) return;
      var tr = btn.closest('tr.muh-fis-satir');
      if (tr) tr.remove();
      dengeHesapla();
    });
    dengeHesapla();
  }

  if (btnEkle) {
    btnEkle.addEventListener('click', satirEkle);
  }

  if (fisForm) {
    fisForm.addEventListener('submit', function (e) {
      var d = dengeHesapla();
      if (!d.dengeli) {
        e.preventDefault();
        window.alert('Yevmiye fişi kaydedilemez: En az 2 satır girilmeli ve Borç toplamı Alacak toplamına eşit olmalıdır (Fark: ' + fmt(d.fark) + ' TL).');
      }
    });
  }

  // 2) TDHP Hesap Planı hızlı istemci filtresi
  var tdhpAra = document.getElementById('muhTdhpCanliAra');
  var tdhpTablo = document.getElementById('muhTdhpTablo');
  if (tdhpAra && tdhpTablo) {
    tdhpAra.addEventListener('input', function () {
      var q = String(tdhpAra.value || '').toLocaleLowerCase('tr-TR').trim();
      var trs = tdhpTablo.querySelectorAll('tbody tr');
      trs.forEach(function (tr) {
        var txt = (tr.textContent || '').toLocaleLowerCase('tr-TR');
        tr.style.display = (!q || txt.indexOf(q) !== -1) ? '' : 'none';
      });
    });
  }

  // 3) e-Dönüşüm gelen fatura red nedeni doğrulaması
  document.querySelectorAll('form[data-edonusum-red]').forEach(function (f) {
    f.addEventListener('submit', function (e) {
      var inp = f.querySelector('input[name="neden"]');
      if (inp && !String(inp.value || '').trim()) {
        e.preventDefault();
        window.alert('Red yanıtı göndermek için gerekçe yazmalısınız.');
        inp.focus();
      }
    });
  });
})();
