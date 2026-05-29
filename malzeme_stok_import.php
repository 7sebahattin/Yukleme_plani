<?php
// =========================================================
// malzeme_stok_import.php — Excel stok aktarım sihirbazı
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_perm('stok.write');

$pdo = db();

function msi_types(): array {
    $skip = ['firma', 'depo', 'bolge', 'urun'];
    return array_filter(definition_types(), fn($k) => !in_array($k, $skip), ARRAY_FILTER_USE_KEY);
}
$ms_types = msi_types();
$ms_units = ['adet', 'kg', 'm', 'm²', 'paket', 'rulo', 'top', 'çift', 'set'];

// ── POST: İçe aktarımı işle ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ms_import') {
    csrf_check($_POST['csrf'] ?? null);

    $import_data = json_decode($_POST['import_data'] ?? '[]', true);
    if (!is_array($import_data)) $import_data = [];

    $mat_lookup = [];
    foreach ($pdo->query(
        "SELECT id, type, name, unit_dara_kg FROM material_definitions WHERE is_active=1"
    )->fetchAll() as $m) {
        $mat_lookup[mb_strtolower(trim($m['name']))] = $m;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO material_stock_movements
         (movement_date, movement_type, material_id, material_name, material_type,
          depo, quantity, unit, unit_dara_kg, total_dara_kg, belge_no, firma, note)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );

    $inserted     = 0;
    $cnt_giris    = 0;
    $cnt_sevk     = 0;
    $total_qty    = 0.0;
    foreach ($import_data as $row) {
        $mat_name = trim((string)($row['mat_name'] ?? ''));
        $mat_type = trim((string)($row['mat_type'] ?? ''));
        $unit     = trim((string)($row['unit'] ?? 'adet'));
        $qty      = (float)($row['qty'] ?? 0);
        $mv_type  = in_array($row['movement_type'] ?? '', ['giris', 'sevk']) ? $row['movement_type'] : 'giris';
        $date     = trim((string)($row['date'] ?? ''));
        $belge    = trim((string)($row['belge_no'] ?? ''));
        $firma    = trim((string)($row['firma'] ?? ''));
        $depo     = trim((string)($row['depo'] ?? ''));

        if ($mat_name === '' || $qty <= 0) continue;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;

        $m         = $mat_lookup[mb_strtolower($mat_name)] ?? null;
        $mat_id    = $m ? (int)$m['id'] : null;
        $unit_dara = $m ? (float)$m['unit_dara_kg'] : 0.0;

        $stmt->execute([
            $date, $mv_type, $mat_id, $mat_name, $mat_type,
            $depo ?: null, $qty, $unit, $unit_dara, round($qty * $unit_dara, 3),
            $belge ?: null, $firma ?: null, null,
        ]);
        $inserted++;
        $total_qty += $qty;
        if ($mv_type === 'giris') $cnt_giris++; else $cnt_sevk++;
    }

    audit_log_event('import', 'malzeme_stok', null, null, [
        'total_rows' => count($import_data),
        'inserted'   => $inserted,
        'skipped'    => count($import_data) - $inserted,
        'giris'      => $cnt_giris,
        'sevk'       => $cnt_sevk,
        'total_qty'  => round($total_qty, 3),
    ]);

    set_flash('success', "$inserted hareket başarıyla aktarıldı.");
    header('Location: malzeme_stok.php');
    exit;
}

// ── JS için malzeme adları ────────────────────────────────
$mat_names_by_type = [];
try {
    foreach ($pdo->query(
        "SELECT type, name FROM material_definitions WHERE is_active=1 ORDER BY type, name"
    )->fetchAll() as $d) {
        if (isset($ms_types[$d['type']])) {
            $mat_names_by_type[$d['type']][] = $d['name'];
        }
    }
} catch (PDOException $e) {}

render_header('Excel Stok Aktarımı');
render_flash();
?>

<div class="page-head">
    <h1 class="page-title">Excel Stok Aktarımı</h1>
    <a href="malzeme_stok.php" class="btn btn-ghost btn-sm">← Geri</a>
</div>

<!-- Adım göstergesi -->
<div class="imp-steps-bar">
    <div class="imp-step-ind active" id="ind1">1 · Dosya</div>
    <div class="imp-step-ind" id="ind2">2 · Eşleştirme</div>
    <div class="imp-step-ind" id="ind3">3 · Onay</div>
</div>

<!-- ── ADIM 1: Dosya Yükle ── -->
<div class="imp-step" id="step1">
    <div class="imp-dropzone" id="impDropzone">
        <div class="imp-dz-icon">📂</div>
        <div class="imp-dz-text">Excel dosyasını sürükleyip bırakın</div>
        <div class="imp-dz-sub">— ya da —</div>
        <label class="btn btn-primary" for="impFile">Dosya Seç (.xlsx / .xls)</label>
        <input type="file" id="impFile" accept=".xlsx,.xls,.csv" style="display:none">
    </div>
    <div id="step1Msg" class="imp-msg" hidden></div>

    <!-- Sheet seçimi: dosya yüklendikten sonra görünür -->
    <div id="sheetPickWrap" hidden style="margin-top:14px">
        <div class="form-group" style="max-width:400px">
            <label class="form-label">Hangi sayfayı aktaracaksınız?</label>
            <select id="sheetPick" class="form-control"></select>
        </div>
        <button type="button" class="btn btn-primary" id="sheetPickBtn" style="margin-top:8px">Bu Sayfayı Kullan →</button>
    </div>
</div>

<!-- ── ADIM 2: Sütun Eşleştirme ── -->
<div class="imp-step" id="step2" hidden>
    <div class="form-grid" style="margin-bottom:16px">
        <div class="form-group">
            <label class="form-label">Malzeme Türü <span class="req">*</span></label>
            <select id="impMatType" class="form-control">
                <option value="">— seçiniz —</option>
                <?php foreach ($ms_types as $k => $label): ?>
                    <option value="<?= h($k) ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label">Birim</label>
            <select id="impUnit" class="form-control">
                <?php foreach ($ms_units as $u): ?>
                    <option value="<?= h($u) ?>"><?= h($u) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <h3 style="margin:0 0 6px">Sütun Eşleştirme</h3>
    <p class="imp-hint">Her Excel sütunu için sistem alanını seçin. Giriş ve çıkış ayrı sütunlardaysa ikisini de eşleştirin.</p>
    <div id="colMapContainer"></div>

    <div class="imp-nav">
        <button type="button" class="btn btn-ghost" onclick="goStep(1)">← Geri</button>
        <button type="button" class="btn btn-primary" id="step2NextBtn">İleri →</button>
    </div>
</div>

<!-- ── ADIM 3: Ürün Eşleştirme + Önizleme ── -->
<div class="imp-step" id="step3" hidden>
    <div id="urunMatchSection">
        <h3 style="margin:0 0 6px">Ürün Eşleştirme</h3>
        <p class="imp-hint">Excel'deki ürün adlarını sistemdeki tanımlı isimlerle eşleştirin. Boş bırakırsanız Excel'deki ad kullanılır.</p>
        <div id="urunMatchContainer"></div>
    </div>

    <h3 style="margin:16px 0 6px">Önizleme <span id="prevCount" style="font-weight:normal;font-size:.9em;color:#666"></span></h3>
    <div class="table-wrap">
        <table class="data-table" id="previewTable">
            <thead>
                <tr>
                    <th>Tarih</th>
                    <th>Ürün</th>
                    <th>Tür</th>
                    <th>Miktar</th>
                    <th>Birim</th>
                    <th>Fiş No</th>
                    <th>Firma</th>
                    <th>Depo</th>
                </tr>
            </thead>
            <tbody id="previewBody"></tbody>
        </table>
    </div>

    <div id="step3Warn" class="imp-warn" hidden></div>

    <form method="post" id="importForm">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="ms_import">
        <input type="hidden" name="import_data" id="importDataInput" value="">
        <div class="imp-nav">
            <button type="button" class="btn btn-ghost" onclick="goStep(2)">← Geri</button>
            <button type="button" class="btn btn-secondary" id="refreshPreviewBtn">🔄 Güncelle</button>
            <button type="submit" class="btn btn-primary" id="importSubmitBtn">✅ Aktar</button>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<script>
(function () {
    var excelRows = [];
    var colMap    = {};   // col_idx (string) -> field_name
    var urunMap   = {};   // excel_name -> system_name

    var impNamesData = <?= json_encode($mat_names_by_type, JSON_UNESCAPED_UNICODE) ?>;

    // ── Adım geçişi ─────────────────────────────────────────
    function goStep(n) {
        [1, 2, 3].forEach(function (i) {
            document.getElementById('step' + i).hidden = (i !== n);
            var ind = document.getElementById('ind' + i);
            ind.classList.toggle('active', i === n);
            ind.classList.toggle('done',   i < n);
        });
        if (n === 3) buildStep3();
    }
    window.goStep = goStep;

    // ── Yardımcı ────────────────────────────────────────────
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function parseDate(val) {
        if (val === '' || val === null || val === undefined) return '';
        if (typeof val === 'number' && val > 1) {
            var d = new Date((val - 25569) * 86400 * 1000);
            if (!isNaN(d.getTime())) {
                return d.getUTCFullYear() + '-' +
                    String(d.getUTCMonth() + 1).padStart(2, '0') + '-' +
                    String(d.getUTCDate()).padStart(2, '0');
            }
        }
        var s = String(val).trim();
        var m = s.match(/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/);
        if (m) return m[3] + '-' + m[2].padStart(2, '0') + '-' + m[1].padStart(2, '0');
        if (/^\d{4}-\d{2}-\d{2}$/.test(s)) return s;
        return '';
    }

    function parseNum(val) {
        if (typeof val === 'number') return val;
        var s = String(val || '').trim().replace(/\s/g, '');
        if (!s) return 0;
        if (s.indexOf(',') !== -1) {
            s = s.replace(/\./g, '').replace(',', '.');
        } else {
            s = s.replace(/\./g, '');
        }
        return parseFloat(s) || 0;
    }

    // ── Adım 1: Dosya ───────────────────────────────────────
    var fileInput = document.getElementById('impFile');
    var dropzone  = document.getElementById('impDropzone');

    fileInput.addEventListener('change', function () {
        if (this.files[0]) processFile(this.files[0]);
    });
    dropzone.addEventListener('dragover', function (e) {
        e.preventDefault(); this.classList.add('imp-drag-over');
    });
    dropzone.addEventListener('dragleave', function () {
        this.classList.remove('imp-drag-over');
    });
    dropzone.addEventListener('drop', function (e) {
        e.preventDefault(); this.classList.remove('imp-drag-over');
        if (e.dataTransfer.files[0]) processFile(e.dataTransfer.files[0]);
    });

    var loadedWb = null; // workbook object kept for sheet switching

    function processFile(f) {
        var msg = document.getElementById('step1Msg');
        msg.hidden = false;
        msg.textContent = 'Okunuyor: ' + f.name + '…';
        var reader = new FileReader();
        reader.onload = function (ev) {
            try {
                loadedWb = XLSX.read(ev.target.result, { type: 'array', cellDates: false });

                var names  = loadedWb.SheetNames;
                var pick   = document.getElementById('sheetPick');
                var wrap   = document.getElementById('sheetPickWrap');

                pick.innerHTML = '';
                names.forEach(function (n, i) {
                    var opt = document.createElement('option');
                    opt.value = i;
                    opt.textContent = (i + 1) + '. ' + n;
                    // Auto-select sheet whose name contains "giriş", "çıkış", "stok"
                    var nl = n.toLowerCase()
                        .replace(/ğ/g,'g').replace(/ü/g,'u').replace(/ş/g,'s')
                        .replace(/ı/g,'i').replace(/ö/g,'o').replace(/ç/g,'c');
                    if (/giris|cikis|stok/.test(nl)) opt.selected = true;
                    pick.appendChild(opt);
                });

                if (names.length === 1) {
                    // Tek sayfa — doğrudan devam et
                    msg.textContent = '✓ Tek sayfa okundu — ' + f.name;
                    loadSheet(0);
                } else {
                    msg.textContent = '✓ ' + names.length + ' sayfa bulundu — ' + f.name;
                    wrap.hidden = false;
                }
            } catch (e) {
                msg.textContent = '⚠ Dosya okunamadı: ' + e.message;
            }
        };
        reader.readAsArrayBuffer(f);
    }

    function loadSheet(sheetIdx) {
        var msg = document.getElementById('step1Msg');
        if (!loadedWb) return;
        var ws  = loadedWb.Sheets[loadedWb.SheetNames[sheetIdx]];
        var raw = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '' });
        excelRows = raw.filter(function (r) {
            return r.some(function (c) { return c !== '' && c !== null; });
        });
        if (excelRows.length < 2) {
            msg.textContent = '⚠ Seçilen sayfada yeterli veri yok (en az 1 başlık + 1 satır gerekli).';
            return;
        }
        msg.textContent = '✓ "' + loadedWb.SheetNames[sheetIdx] + '" sayfasından ' + (excelRows.length - 1) + ' satır okundu.';
        document.getElementById('sheetPickWrap').hidden = true;
        buildColMap();
        renderStep2();
        goStep(2);
    }

    document.getElementById('sheetPickBtn').addEventListener('click', function () {
        var idx = parseInt(document.getElementById('sheetPick').value) || 0;
        loadSheet(idx);
    });

    // ── Adım 2: Sütun eşleştirme ────────────────────────────
    var FIELDS = [
        ['atla',          '— Atla —'],
        ['tarih',         'Tarih'],
        ['urun_adi',      'Ürün Adı'],
        ['giris_miktari', 'Giriş Miktarı'],
        ['cikis_miktari', 'Çıkış Miktarı'],
        ['fis_no',        'Fiş No'],
        ['firma',         'Firma'],
        ['depo',          'Depo'],
    ];

    function norm(s) {
        return String(s).toLowerCase()
            .replace(/ğ/g,'g').replace(/ü/g,'u').replace(/ş/g,'s')
            .replace(/ı/g,'i').replace(/ö/g,'o').replace(/ç/g,'c')
            .normalize('NFD').replace(/[̀-ͯ]/g,'').trim();
    }

    function buildColMap() {
        colMap = {};
        var headers = excelRows[0];
        headers.forEach(function (h, i) {
            var hh = norm(h);
            if (/tarih/.test(hh))                      colMap[i] = 'tarih';
            else if (/urun|mal|cins|cesit/.test(hh))   colMap[i] = 'urun_adi';
            else if (/giris|giriş/.test(hh))            colMap[i] = 'giris_miktari';
            else if (/cikis|cikiş|sevk/.test(hh))       colMap[i] = 'cikis_miktari';
            else if (/fis|belge/.test(hh))              colMap[i] = 'fis_no';
            else if (/firma/.test(hh))                  colMap[i] = 'firma';
            else if (/depo/.test(hh))                   colMap[i] = 'depo';
            else                                         colMap[i] = 'atla';
        });
    }

    function renderStep2() {
        var headers   = excelRows[0];
        var sampleRow = excelRows.length > 1 ? excelRows[1] : [];

        var html = '<div class="table-wrap"><table class="data-table imp-col-map-tbl">';
        html += '<thead><tr><th>#</th><th>Excel Başlığı</th><th>Sistem Alanı</th><th>Örnek Değer</th></tr></thead><tbody>';
        headers.forEach(function (h, i) {
            var sample = esc(String(sampleRow[i] == null ? '' : sampleRow[i]).substring(0, 60));
            html += '<tr><td>' + (i + 1) + '</td><td><strong>' + esc(h) + '</strong></td>';
            html += '<td><select class="form-control col-map-sel" data-col="' + i + '">';
            FIELDS.forEach(function (f) {
                html += '<option value="' + f[0] + '"' + (colMap[i] === f[0] ? ' selected' : '') + '>' + esc(f[1]) + '</option>';
            });
            html += '</select></td><td class="imp-sample">' + sample + '</td></tr>';
        });
        html += '</tbody></table></div>';
        document.getElementById('colMapContainer').innerHTML = html;

        document.querySelectorAll('.col-map-sel').forEach(function (sel) {
            sel.addEventListener('change', function () { colMap[parseInt(this.dataset.col)] = this.value; });
        });
    }

    document.getElementById('step2NextBtn').addEventListener('click', function () {
        if (!document.getElementById('impMatType').value) {
            alert('Lütfen Malzeme Türü seçin.'); return;
        }
        var hasQty = Object.values(colMap).some(function (v) {
            return v === 'giris_miktari' || v === 'cikis_miktari';
        });
        if (!hasQty && !confirm('Giriş veya Çıkış Miktarı sütunu seçilmedi. Devam edilsin mi?')) return;
        goStep(3);
    });

    // ── Adım 3: Ürün eşleştirme + önizleme ─────────────────
    function buildStep3() {
        buildUrunMatch();
        renderPreview();
    }

    function getColIdx(field) {
        var idx = -1;
        Object.keys(colMap).forEach(function (i) {
            if (colMap[i] === field) idx = parseInt(i);
        });
        return idx;
    }

    function buildUrunMatch() {
        var urunIdx = getColIdx('urun_adi');
        var section = document.getElementById('urunMatchSection');
        if (urunIdx < 0) { section.hidden = true; return; }
        section.hidden = false;

        var matType  = document.getElementById('impMatType').value;
        var sysNames = impNamesData[matType] || [];

        var uniq = {};
        excelRows.slice(1).forEach(function (row) {
            var n = String(row[urunIdx] || '').trim();
            if (n) uniq[n] = true;
        });
        var names = Object.keys(uniq).sort();
        if (!names.length) { section.hidden = true; return; }

        var html = '<div class="table-wrap"><table class="data-table imp-urun-tbl">';
        html += '<thead><tr><th>Excel\'deki Ad</th><th>Sistemdeki Karşılığı</th></tr></thead><tbody>';
        names.forEach(function (n) {
            var defVal = urunMap[n] || '';
            if (!defVal) {
                var nl = norm(n);
                for (var j = 0; j < sysNames.length; j++) {
                    if (norm(sysNames[j]) === nl) { defVal = sysNames[j]; break; }
                }
            }
            html += '<tr><td>' + esc(n) + '</td>';
            html += '<td><select class="form-control urun-map-sel" data-excel="' + esc(n) + '">';
            html += '<option value="">[Aynen kullan]</option>';
            sysNames.forEach(function (sn) {
                html += '<option value="' + esc(sn) + '"' + (defVal === sn ? ' selected' : '') + '>' + esc(sn) + '</option>';
            });
            html += '</select></td></tr>';
        });
        html += '</tbody></table></div>';
        document.getElementById('urunMatchContainer').innerHTML = html;

        document.querySelectorAll('.urun-map-sel').forEach(function (sel) {
            urunMap[sel.dataset.excel] = sel.value;
            sel.addEventListener('change', function () {
                urunMap[this.dataset.excel] = this.value;
                renderPreview();
            });
        });
    }

    function buildImportRows() {
        var matType  = document.getElementById('impMatType').value;
        var unit     = document.getElementById('impUnit').value;
        var tarihIdx = getColIdx('tarih');
        var urunIdx  = getColIdx('urun_adi');
        var girisIdx = getColIdx('giris_miktari');
        var cikisIdx = getColIdx('cikis_miktari');
        var fisIdx   = getColIdx('fis_no');
        var firmaIdx = getColIdx('firma');
        var depoIdx  = getColIdx('depo');

        var rows = [];
        excelRows.slice(1).forEach(function (row) {
            var tarih = tarihIdx >= 0  ? parseDate(row[tarihIdx]) : '';
            var urun  = urunIdx  >= 0  ? String(row[urunIdx]  || '').trim() : '';
            var giris = girisIdx >= 0  ? parseNum(row[girisIdx]) : 0;
            var cikis = cikisIdx >= 0  ? parseNum(row[cikisIdx]) : 0;
            var fisNo = fisIdx   >= 0  ? String(row[fisIdx]   || '').trim() : '';
            var firma = firmaIdx >= 0  ? String(row[firmaIdx] || '').trim() : '';
            var depo  = depoIdx  >= 0  ? String(row[depoIdx]  || '').trim() : '';

            var sysUrun = (urun && urunMap[urun]) ? urunMap[urun] : urun;

            if (giris > 0) rows.push({ date: tarih, mat_name: sysUrun, mat_type: matType, unit: unit, qty: giris, movement_type: 'giris', belge_no: fisNo, firma: firma, depo: depo });
            if (cikis > 0) rows.push({ date: tarih, mat_name: sysUrun, mat_type: matType, unit: unit, qty: cikis, movement_type: 'sevk',  belge_no: fisNo, firma: firma, depo: depo });
        });
        return rows;
    }

    function renderPreview() {
        var rows    = buildImportRows();
        var tbody   = document.getElementById('previewBody');
        var countEl = document.getElementById('prevCount');
        var warnEl  = document.getElementById('step3Warn');

        countEl.textContent = '(' + rows.length + ' satır)';

        var warns = {};
        var html  = '';
        rows.forEach(function (r) {
            var dateOk = /^\d{4}-\d{2}-\d{2}$/.test(r.date || '');
            var nameOk = r.mat_name !== '';
            if (!dateOk) warns['Geçersiz/boş tarih içeren satırlar var (Excel tarih serisi veya GG.AA.YYYY bekleniyor).'] = 1;
            if (!nameOk) warns['Ürün adı boş satırlar var — bunlar aktarılmaz.'] = 1;
            html += '<tr' + ((!dateOk || !nameOk) ? ' class="imp-row-warn"' : '') + '>';
            html += '<td>' + esc(r.date || '—') + '</td>';
            html += '<td>' + esc(r.mat_name || '—') + '</td>';
            html += '<td>' + (r.movement_type === 'giris' ? '<span style="color:#1a7a3c">● Giriş</span>' : '<span style="color:#c0392b">● Sevk</span>') + '</td>';
            html += '<td style="text-align:right">' + r.qty + '</td>';
            html += '<td>' + esc(r.unit) + '</td>';
            html += '<td>' + esc(r.belge_no) + '</td>';
            html += '<td>' + esc(r.firma) + '</td>';
            html += '<td>' + esc(r.depo) + '</td>';
            html += '</tr>';
        });

        tbody.innerHTML = html || '<tr><td colspan="8" style="text-align:center;padding:20px;color:#888">Aktarılacak satır bulunamadı. Lütfen sütun eşleştirmesini kontrol edin.</td></tr>';

        var warnKeys = Object.keys(warns);
        if (warnKeys.length) {
            warnEl.hidden = false;
            warnEl.innerHTML = '⚠ ' + warnKeys.map(function (w) { return esc(w); }).join('<br>⚠ ');
        } else {
            warnEl.hidden = true;
        }

        document.getElementById('importDataInput').value = JSON.stringify(rows);
    }

    document.getElementById('refreshPreviewBtn').addEventListener('click', function () {
        buildUrunMatch();
        renderPreview();
    });

    document.getElementById('importForm').addEventListener('submit', function (e) {
        var rows = buildImportRows();
        if (!rows.length) {
            e.preventDefault();
            alert('Aktarılacak satır bulunamadı. Lütfen sütun eşleştirmesini kontrol edin.');
            return;
        }
        document.getElementById('importDataInput').value = JSON.stringify(rows);
        if (!confirm(rows.length + ' hareket aktarılsın mı?')) e.preventDefault();
    });
})();
</script>

<?php render_footer(); ?>
