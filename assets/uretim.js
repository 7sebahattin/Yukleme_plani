/* Günlük Üretim — grafikler (Chart.js, yerel dosya) + boş gün filtresi. Veri: #uretimVeri (JSON). */
(function () {
  'use strict';
  var el = document.getElementById('uretimVeri');
  if (!el) return;
  var V = {};
  try { V = JSON.parse(el.textContent || '{}'); } catch (e) { return; }

  // Boş günleri gizle (JS yoksa tüm günler görünür kalır)
  var cb = document.getElementById('uretimBosGizle');
  function bosUygula() {
    var gizle = cb && cb.checked;
    document.querySelectorAll('.uretim-tablo tbody tr[data-bos="1"]').forEach(function (tr) { tr.hidden = !!gizle; });
  }
  if (cb) { cb.addEventListener('change', bosUygula); bosUygula(); }

  if (typeof Chart === 'undefined') return;   // kütüphane yüklenemediyse tablo yeterli
  var cs = getComputedStyle(document.body);
  var metin = cs.color || '#333';
  var izgara = 'rgba(128,128,128,.18)';
  Chart.defaults.color = metin;
  Chart.defaults.font.family = cs.fontFamily;
  var nf = new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 1 });

  var METRIKLER = {
    kg_isci:   { ad: 'İşçi başı kg',        birim: ' kg' },
    kg_saat:   { ad: 'İşçi-saat başı kg',   birim: ' kg' },
    kasa_isci: { ad: 'İşçi başı kasa',      birim: ' kasa' },
    isci:      { ad: 'Çalışan işçi',        birim: ' kişi' },
    saat:      { ad: 'Çalışma saati',       birim: ' sa' }
  };
  var ORT = { kg_isci: V.ort && V.ort.kg_isci, kg_saat: V.ort && V.ort.kg_saat, kasa_isci: V.ort && V.ort.kasa_isci };

  var RENK_BAR = '#4f8bd6', RENK_CIZGI = '#e07b00', RENK_ORT = '#7a8a99';

  function ortSeri(n, deger) { var a = []; for (var i = 0; i < n; i++) a.push(deger == null ? null : deger); return a; }

  // ── Günlük grafik ──
  var g = V.gunluk, cvG = document.getElementById('uretimGunluk'), sel = document.getElementById('uretimMetrik');
  if (g && cvG) {
    var grafikG = new Chart(cvG, {
      data: {
        labels: g.etiket,
        datasets: [
          { type: 'bar', label: 'Net kg', data: g.kg, backgroundColor: RENK_BAR, borderRadius: 3, yAxisID: 'y', order: 3 },
          { type: 'line', label: METRIKLER.kg_isci.ad, data: g.kg_isci, borderColor: RENK_CIZGI, backgroundColor: RENK_CIZGI, yAxisID: 'y1', tension: .25, pointRadius: 3, spanGaps: true, order: 1 },
          { type: 'line', label: 'Ay ortalaması', data: ortSeri(g.etiket.length, ORT.kg_isci), borderColor: RENK_ORT, borderDash: [6, 4], pointRadius: 0, borderWidth: 1.5, yAxisID: 'y1', order: 2 }
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        scales: {
          x: { grid: { display: false }, ticks: { maxRotation: 60, autoSkip: true } },
          y: { position: 'left', beginAtZero: true, grid: { color: izgara }, title: { display: true, text: 'Net kg' }, ticks: { callback: function (v) { return nf.format(v); } } },
          y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: METRIKLER.kg_isci.ad } }
        },
        plugins: {
          legend: { position: 'bottom' },
          tooltip: { callbacks: { label: function (c) {
            if (c.parsed.y == null) return null;
            var m = c.dataset.type === 'bar' ? ' kg' : (METRIKLER[sel ? sel.value : 'kg_isci'].birim);
            return c.dataset.label + ': ' + nf.format(c.parsed.y) + m;
          } } }
        }
      }
    });
    if (sel) sel.addEventListener('change', function () {
      var k = sel.value, m = METRIKLER[k];
      grafikG.data.datasets[1].label = m.ad;
      grafikG.data.datasets[1].data = g[k];
      grafikG.data.datasets[2].data = ortSeri(g.etiket.length, ORT[k]);
      grafikG.data.datasets[2].hidden = ORT[k] == null;
      grafikG.options.scales.y1.title.text = m.ad;
      grafikG.update();
    });
  }

  // ── Aylık grafik ──
  var a = V.aylik, cvA = document.getElementById('uretimAylik');
  if (a && cvA) {
    new Chart(cvA, {
      data: {
        labels: a.etiket,
        datasets: [
          { type: 'bar', label: 'Net kg', data: a.kg, backgroundColor: RENK_BAR, borderRadius: 3, yAxisID: 'y', order: 2 },
          { type: 'line', label: 'İşçi başı kg', data: a.kg_isci, borderColor: RENK_CIZGI, backgroundColor: RENK_CIZGI, yAxisID: 'y1', tension: .25, pointRadius: 3, spanGaps: true, order: 1 }
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        scales: {
          x: { grid: { display: false } },
          y: { position: 'left', beginAtZero: true, grid: { color: izgara }, title: { display: true, text: 'Net kg' }, ticks: { callback: function (v) { return nf.format(v); } } },
          y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: 'İşçi başı kg' } }
        },
        plugins: {
          legend: { position: 'bottom' },
          tooltip: { callbacks: {
            label: function (c) { return c.parsed.y == null ? null : c.dataset.label + ': ' + nf.format(c.parsed.y) + ' kg'; },
            afterBody: function (it) { var i = it[0].dataIndex; return a.gun[i] ? a.gun[i] + ' gün veri' : ''; }
          } }
        }
      }
    });
  }
})();
