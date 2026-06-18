<?php
// =========================================================
// beyan_view.php — Beyan detay ekranı (Sprint Beyan-01)
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!can_beyan('read')) forbidden();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { set_flash('error', 'Geçersiz beyan.'); header('Location: beyanlar.php'); exit; }

$st = db()->prepare("SELECT * FROM customs_declarations WHERE id = ?");
$st->execute([$id]);
$beyan = $st->fetch();
if (!$beyan) { set_flash('error', 'Beyan bulunamadı.'); header('Location: beyanlar.php'); exit; }

$is_deleted = !empty($beyan['deleted_at']);
$statuses   = beyan_statuses();
$cur_status = (string)($beyan['status'] ?? 'beyan_acildi');

// Yükleme planı bağlantısı
$linked_record = null;
if (!empty($beyan['loading_record_id'])) {
    $slr = db()->prepare("SELECT id, firma, parti_no, tarih FROM loading_records WHERE id = ?");
    $slr->execute([(int)$beyan['loading_record_id']]);
    $linked_record = $slr->fetch() ?: null;
}

// ── Eşleştirme: yüklendi olmayan yükleme planları (modal için) ──
// Buton yalnızca düzenleme yetkisi olan, silinmemiş ve henüz yüklenmemiş
// beyanlarda aktif olur.
$can_match     = !$is_deleted && can_beyan('write') && $cur_status !== 'yuklendi'
                 && empty($beyan['loading_record_id']);
$match_records = [];
if ($can_match) {
    // Son 90 gün + tarihi olmayanlar; yalnızca yuklendi olmayan ve kilitsiz kayıtlar.
    $since = date('Y-m-d', strtotime('-90 days'));
    $ms = db()->prepare(
        "SELECT r.id, r.tarih, r.firma, r.parti_no, r.alici, r.ulasim, r.gumruk,
                r.urun, r.durum, us.name AS urun_sahibi_adi
         FROM loading_records r
         LEFT JOIN material_definitions us ON us.id = r.urun_sahibi_id AND us.type = 'firma'
         WHERE r.type = 'yukleme'
           AND COALESCE(r.durum, '') <> 'yuklendi'
           AND r.locked_at IS NULL
           AND (r.tarih IS NULL OR r.tarih >= ?)
         ORDER BY COALESCE(r.tarih, '0000-00-00') DESC, r.id DESC
         LIMIT 200"
    );
    $ms->execute([$since]);
    $match_records = $ms->fetchAll();
    // Veri azsa (90 günde hiç yoksa) tüm yüklendi olmayanları getir
    if (empty($match_records)) {
        $ms2 = db()->prepare(
            "SELECT r.id, r.tarih, r.firma, r.parti_no, r.alici, r.ulasim, r.gumruk,
                    r.urun, r.durum, us.name AS urun_sahibi_adi
             FROM loading_records r
             LEFT JOIN material_definitions us ON us.id = r.urun_sahibi_id AND us.type = 'firma'
             WHERE r.type = 'yukleme'
               AND COALESCE(r.durum, '') <> 'yuklendi'
               AND r.locked_at IS NULL
             ORDER BY COALESCE(r.tarih, '0000-00-00') DESC, r.id DESC
             LIMIT 200"
        );
        $ms2->execute();
        $match_records = $ms2->fetchAll();
    }
}

// Beyan tarafındaki (aktarılacak) değerler — önizleme + JS için
// Not: Beyandaki "Şirket Adı" (company_name) → yükleme planı "Gümrük" (gumruk)
$beyan_match = [
    'parti_no' => trim((string)($beyan['party_no']       ?? '')),
    'alici'    => trim((string)($beyan['buyer_name']      ?? '')),
    'ulasim'   => trim((string)($beyan['transport_type']  ?? '')),
    'gumruk'   => trim((string)($beyan['company_name']    ?? '')),
];

render_header('Beyan Detayı');
render_flash();
?>

<div class="page-head">
    <div>
        <h1>🧾 <?= h($beyan['party_no'] ?: 'Beyan #' . $id) ?></h1>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:4px">
            <?= beyan_badge_html($cur_status) ?>
            <?php if ($is_deleted): ?>
            <span class="beyan-badge" style="background:#fef3c7;color:#92400e">ARŞİVLENDİ</span>
            <?php endif; ?>
            <span class="muted" style="font-size:.82rem">#<?= $id ?></span>
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="beyanlar.php" class="btn btn-ghost">← Beyanlar</a>
        <?php if ($can_match): ?>
        <button type="button" class="btn" id="beyanMatchOpenBtn"
                style="background:#0ea5e9;color:#fff;border-color:#0ea5e9">
            🔗 Yükleme Planı ile Eşleştir
        </button>
        <?php elseif (!$is_deleted && can_beyan('write') && $cur_status === 'yuklendi'): ?>
        <span class="muted" style="font-size:.82rem;align-self:center">Bu beyan zaten yüklendi.</span>
        <?php endif; ?>
        <?php if (!$is_deleted && can_beyan('write')): ?>
        <a href="beyan_edit.php?id=<?= $id ?>" class="btn btn-primary">Düzenle</a>
        <?php endif; ?>
        <?php if (!$is_deleted && can_beyan('delete')): ?>
        <form method="post" action="beyan_delete.php" style="display:inline"
              onsubmit="return confirm('Bu beyan arşivden gizlenecek. Devam edilsin mi?')">
            <input type="hidden" name="id"   value="<?= $id ?>">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <button type="submit" class="btn btn-ghost" style="color:var(--danger)">Sil</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($is_deleted): ?>
<div class="flash flash-error">
    Bu beyan <?= h(fmt_datetime($beyan['deleted_at'])) ?> tarihinde arşivlendi (silinmiş).
</div>
<?php endif; ?>

<!-- ── Özet üst satır ── -->
<div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:12px">
    <?php if ($beyan['product_name']): ?>
    <div class="beyan-section" style="flex:1;min-width:200px;margin-bottom:0">
        <div class="beyan-section-title">Ürün</div>
        <div style="font-size:1.1rem;font-weight:700"><?= h($beyan['product_name']) ?><?= $beyan['product_variety'] ? ' · ' . h($beyan['product_variety']) : '' ?></div>
    </div>
    <?php endif; ?>
    <?php if ($beyan['net_kg'] !== null): ?>
    <div class="beyan-section" style="flex:1;min-width:120px;margin-bottom:0;text-align:center">
        <div class="beyan-section-title">Net KG</div>
        <div style="font-size:1.3rem;font-weight:700;color:var(--primary)"><?= fmt_kg($beyan['net_kg']) ?></div>
    </div>
    <?php endif; ?>
    <?php if ($beyan['pallet_count'] !== null): ?>
    <div class="beyan-section" style="flex:1;min-width:100px;margin-bottom:0;text-align:center">
        <div class="beyan-section-title">Palet</div>
        <div style="font-size:1.3rem;font-weight:700"><?= (int)$beyan['pallet_count'] ?></div>
    </div>
    <?php endif; ?>
</div>

<!-- 1. Temel Bilgiler -->
<div class="beyan-section">
    <div class="beyan-section-title">📋 Temel Bilgiler</div>
    <div class="beyan-view-grid">
        <div class="beyan-view-row">
            <span class="lbl">Parti No</span>
            <span class="val"><?= h($beyan['party_no'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Başlık / Beyan Tipi</span>
            <span class="val"><?= h($beyan['declaration_title'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Nakliye Türü</span>
            <span class="val"><?= h($beyan['transport_type'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Hat / Güzergah</span>
            <span class="val"><?= h($beyan['line_type'] ?: '—') ?></span>
        </div>
        <?php if ($beyan['company_name']): ?>
        <div class="beyan-view-row" style="grid-column:1/-1">
            <span class="lbl">Şirket Adı</span>
            <span class="val"><?= h($beyan['company_name']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($beyan['company_address']): ?>
        <div class="beyan-view-row" style="grid-column:1/-1">
            <span class="lbl">Şirket Adresi</span>
            <span class="val" style="white-space:pre-wrap"><?= h($beyan['company_address']) ?></span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 2. Ürün Bilgileri -->
<div class="beyan-section">
    <div class="beyan-section-title">🍎 Ürün Bilgileri</div>
    <div class="beyan-view-grid">
        <div class="beyan-view-row">
            <span class="lbl">Ürün Adı</span>
            <span class="val"><?= h($beyan['product_name'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Ürün Çeşidi</span>
            <span class="val"><?= h($beyan['product_variety'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Palet Adedi</span>
            <span class="val"><?= $beyan['pallet_count'] !== null ? (int)$beyan['pallet_count'] : '—' ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Brüt KG</span>
            <span class="val"><?= $beyan['gross_kg'] !== null ? fmt_kg($beyan['gross_kg']) : '—' ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Net KG</span>
            <span class="val strong"><?= $beyan['net_kg'] !== null ? fmt_kg($beyan['net_kg']) : '—' ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Kasa Adedi</span>
            <span class="val"><?= $beyan['crate_count'] !== null ? number_format((int)$beyan['crate_count'], 0, ',', '.') : '—' ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Kasa Cinsi</span>
            <span class="val"><?= h($beyan['crate_type'] ?: '—') ?></span>
        </div>
    </div>
</div>

<!-- 3. Lojistik / Alıcı -->
<div class="beyan-section">
    <div class="beyan-section-title">🚚 Lojistik / Alıcı</div>
    <div class="beyan-view-grid">
        <div class="beyan-view-row">
            <span class="lbl">Çıkış Depo</span>
            <span class="val"><?= h($beyan['exit_depot'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Alıcı</span>
            <span class="val"><?= h($beyan['buyer_name'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">İlgili Kişi</span>
            <span class="val"><?= h($beyan['contact_person'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Marka</span>
            <span class="val"><?= h($beyan['brand'] ?: '—') ?></span>
        </div>
    </div>
</div>

<!-- 4. Analiz / Durum -->
<div class="beyan-section">
    <div class="beyan-section-title">📊 Analiz / Durum</div>
    <div class="beyan-view-grid">
        <div class="beyan-view-row">
            <span class="lbl">Durum</span>
            <span class="val"><?= beyan_badge_html($cur_status) ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Numune Alındı</span>
            <span class="val"><?= $beyan['sample_taken_at'] ? h(fmt_datetime($beyan['sample_taken_at'])) : '—' ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Analiz Sonuç Tarihi</span>
            <span class="val"><?= $beyan['analysis_result_at'] ? h(fmt_datetime($beyan['analysis_result_at'])) : '—' ?></span>
        </div>
    </div>
    <?php if ($beyan['analysis_note']): ?>
    <div style="margin-top:10px;padding:10px;background:#f8fafc;border:1px solid var(--border);border-radius:var(--radius-sm)">
        <div class="muted" style="font-size:.78rem;margin-bottom:4px">Analiz Notu</div>
        <div style="white-space:pre-wrap"><?= h($beyan['analysis_note']) ?></div>
    </div>
    <?php endif; ?>

    <?php
    $next_states = beyan_next_statuses($cur_status);
    if (!empty($next_states) && !$is_deleted && can_beyan('write')):
    ?>
    <div style="margin-top:10px">
        <div class="muted" style="font-size:.82rem;margin-bottom:6px">Hızlı Durum Geçişi:</div>
        <div class="beyan-next-status-wrap">
            <?php foreach ($next_states as $ns):
                $sv = $statuses[$ns] ?? ['label' => $ns, 'css' => 'taslak'];
            ?>
            <form method="post" action="beyan_edit.php?id=<?= $id ?>" style="display:inline">
                <input type="hidden" name="csrf"   value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="status" value="<?= h($ns) ?>">
                <?php
                // Durum değiştirilirken tüm mevcut alanları hidden olarak gönder
                foreach (['raw_text','unmatched_text','declaration_title','company_name','company_address',
                          'transport_type','line_type','party_no','pallet_count','product_name','product_variety',
                          'gross_kg','net_kg','crate_count','crate_type','exit_depot','contact_person',
                          'buyer_name','brand','analysis_note','sample_taken_at','analysis_result_at'] as $hf):
                    $hval = '';
                    if ($hf === 'pallet_count') $hval = $beyan['pallet_count'] !== null ? (string)(int)$beyan['pallet_count'] : '';
                    elseif ($hf === 'crate_count') $hval = $beyan['crate_count'] !== null ? (string)(int)$beyan['crate_count'] : '';
                    elseif ($hf === 'gross_kg') $hval = fmt_edit_num($beyan['gross_kg'], 0);
                    elseif ($hf === 'net_kg')   $hval = fmt_edit_num($beyan['net_kg'],   0);
                    elseif ($hf === 'sample_taken_at')    $hval = $beyan['sample_taken_at']    ? date('Y-m-d\TH:i', strtotime($beyan['sample_taken_at']))    : '';
                    elseif ($hf === 'analysis_result_at') $hval = $beyan['analysis_result_at'] ? date('Y-m-d\TH:i', strtotime($beyan['analysis_result_at'])) : '';
                    else   $hval = (string)($beyan[$hf] ?? '');
                ?>
                <input type="hidden" name="<?= h($hf) ?>" value="<?= h($hval) ?>">
                <?php endforeach; ?>
                <button type="submit"
                        class="btn btn-sm beyan-badge beyan-badge-<?= h($sv['css']) ?>"
                        style="border:none;cursor:pointer;padding:4px 12px;font-size:.75rem"
                        <?= ($ns === 'red') ? 'onclick="return prompt_note(this)"' : '' ?>>
                    → <?= h($sv['label']) ?>
                </button>
            </form>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- 5. WhatsApp Ham Metni (collapsible) -->
<?php if ($beyan['raw_text']): ?>
<div class="beyan-section">
    <button type="button" class="beyan-collapsible-toggle" onclick="toggleCollapsible(this)">
        📱 WhatsApp Ham Metni <span>▶</span>
    </button>
    <div class="beyan-collapsible-body" style="display:none">
<?= nl2br(h($beyan['raw_text'])) ?>
    </div>
</div>
<?php endif; ?>

<!-- 6. Eşleşmeyen Satırlar (collapsible) -->
<?php if ($beyan['unmatched_text']): ?>
<div class="beyan-section">
    <button type="button" class="beyan-collapsible-toggle" onclick="toggleCollapsible(this)">
        ⚠️ Eşleşmeyen / Kontrol Edilecek Satırlar <span>▶</span>
    </button>
    <div class="beyan-collapsible-body" style="display:none">
<?= nl2br(h($beyan['unmatched_text'])) ?>
        <button type="button" class="btn btn-sm btn-ghost" style="margin-top:8px"
                onclick="copyText(this.previousElementSibling)">📋 Kopyala</button>
    </div>
</div>
<?php endif; ?>

<!-- 7. Yükleme Planı Bağlantısı -->
<div class="beyan-section">
    <div class="beyan-section-title">🔗 Yükleme Planı Bağlantısı</div>
    <?php if ($linked_record): ?>
    <p>
        <a href="record_view.php?id=<?= (int)$linked_record['id'] ?>" class="btn btn-sm btn-ghost">
            → Yükleme Planını Aç: <?= h($linked_record['parti_no'] ?: '#' . $linked_record['id']) ?>
        </a>
    </p>
    <?php elseif (!$is_deleted && $cur_status === 'temiz'): ?>
    <p class="muted" style="font-size:.88rem">
        Yükleme planı bağlanmadı.
        <button type="button" class="btn btn-sm" disabled title="Sonraki sprintte aktif olacak">
            Yükleme Planı Oluştur (yakında)
        </button>
    </p>
    <?php else: ?>
    <p class="muted" style="font-size:.88rem">Henüz yükleme planına bağlanmadı.</p>
    <?php endif; ?>
</div>

<!-- Sistem bilgisi -->
<div class="muted" style="font-size:.8rem;margin:12px 0 24px;text-align:right">
    Oluşturma: <?= h(fmt_datetime($beyan['created_at'])) ?>
    <?php if ($beyan['updated_at']): ?>
    · Güncelleme: <?= h(fmt_datetime($beyan['updated_at'])) ?>
    <?php endif; ?>
</div>

<script>
function toggleCollapsible(btn) {
    var body = btn.nextElementSibling;
    var arrow = btn.querySelector('span');
    if (!body) return;
    var open = body.style.display !== 'none';
    body.style.display = open ? 'none' : 'block';
    if (arrow) arrow.textContent = open ? '▶' : '▼';
}

function copyText(el) {
    var text = el ? el.textContent : '';
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
            el.style.background = '#d1fae5';
            setTimeout(function () { el.style.background = ''; }, 1500);
        });
    }
}

function prompt_note(btn) {
    var note = prompt('Red için analiz notu giriniz (zorunlu):');
    if (!note || !note.trim()) return false;
    // Formun içindeki analysis_note hidden input'unu güncelle
    var form = btn.closest('form');
    if (!form) return true;
    var noteInput = form.querySelector('input[name="analysis_note"]');
    if (noteInput) noteInput.value = note.trim();
    return true;
}
</script>

<?php if ($can_match): ?>
<!-- ============================================================
     YÜKLEME PLANI EŞLEŞTİRME MODALI (Sprint Beyan-Yukleme-Eslesme-01)
     ============================================================ -->
<div id="beyanMatchOverlay" class="beyan-match-overlay" hidden role="dialog" aria-modal="true"
     aria-labelledby="beyanMatchTitle">
  <div class="beyan-match-dialog">

    <div class="beyan-match-head">
      <h2 id="beyanMatchTitle">Yükleme Planı ile Eşleştir</h2>
      <button type="button" class="beyan-match-close" id="beyanMatchClose" aria-label="Kapat">✕</button>
    </div>

    <!-- ── 1. ADIM: Yükleme planı seçimi ── -->
    <div class="beyan-match-body" id="beyanMatchStep1">
      <p class="beyan-match-desc">
        Yüklendi olmayan yükleme planlarından birini seçin. Beyan bilgileri seçilen
        yükleme planına aktarılacaktır.
      </p>
      <div class="beyan-match-search">
        <input type="text" id="beyanMatchSearch" class="form-control"
               placeholder="Firma, ürün, parti no, alıcı ara…">
      </div>

      <div class="beyan-match-table-wrap">
        <table class="beyan-match-table">
          <thead>
            <tr>
              <th></th>
              <th>No</th>
              <th>Tarih</th>
              <th>Firma</th>
              <th>Ürün Sahibi</th>
              <th>Ürün</th>
              <th>Parti No</th>
              <th>Alıcı</th>
              <th>Ulaşım</th>
              <th>Gümrük</th>
              <th>Durum</th>
            </tr>
          </thead>
          <tbody id="beyanMatchRows">
            <?php if (empty($match_records)): ?>
            <tr><td colspan="11" class="muted center" style="padding:16px">
              Yüklendi olmayan yükleme planı bulunamadı.
            </td></tr>
            <?php else: foreach ($match_records as $mr):
                $durum_lbl = ($mr['durum'] ?? '') === 'islendi' ? 'İşlendi' : 'Yeni';
                $hay = mb_strtolower(trim(
                    ($mr['firma'] ?? '') . ' ' . ($mr['urun'] ?? '') . ' ' . ($mr['parti_no'] ?? '') . ' ' .
                    ($mr['alici'] ?? '') . ' ' . ($mr['urun_sahibi_adi'] ?? '')
                ), 'UTF-8');
            ?>
            <tr class="beyan-match-row" data-search="<?= h($hay) ?>"
                data-id="<?= (int)$mr['id'] ?>"
                data-parti="<?= h((string)($mr['parti_no'] ?? '')) ?>"
                data-alici="<?= h((string)($mr['alici'] ?? '')) ?>"
                data-ulasim="<?= h((string)($mr['ulasim'] ?? '')) ?>"
                data-gumruk="<?= h((string)($mr['gumruk'] ?? '')) ?>">
              <td><input type="radio" name="beyanMatchPick" value="<?= (int)$mr['id'] ?>" class="beyan-match-radio"></td>
              <td>#<?= (int)$mr['id'] ?></td>
              <td><?= h(fmt_date($mr['tarih'] ?? '')) ?: '—' ?></td>
              <td><?= h($mr['firma'] ?? '') ?: '—' ?></td>
              <td><?= h($mr['urun_sahibi_adi'] ?? '') ?: '—' ?></td>
              <td><?= h($mr['urun'] ?? '') ?: '—' ?></td>
              <td><?= h($mr['parti_no'] ?? '') ?: '—' ?></td>
              <td><?= h($mr['alici'] ?? '') ?: '—' ?></td>
              <td><?= h($mr['ulasim'] ?? '') ?: '—' ?></td>
              <td><?= h($mr['gumruk'] ?? '') ?: '—' ?></td>
              <td><span class="beyan-match-durum"><?= h($durum_lbl) ?></span></td>
            </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <div class="beyan-match-footer">
        <button type="button" class="btn btn-ghost" id="beyanMatchCancel1">Vazgeç</button>
        <button type="button" class="btn btn-primary" id="beyanMatchCompare" disabled>Eşleşme Yap</button>
      </div>
    </div>

    <!-- ── 2. ADIM: Önizleme ── -->
    <div class="beyan-match-body" id="beyanMatchStep2" hidden>
      <p class="beyan-match-desc">
        <strong>Eşleşme Önizlemesi.</strong> Beyanda değer varsa yükleme planına aktarılır;
        beyan boşsa yükleme planındaki mevcut değer korunur.
      </p>
      <div class="beyan-match-table-wrap">
        <table class="beyan-match-table beyan-match-preview">
          <thead>
            <tr>
              <th>Alan</th>
              <th>Beyan Verisi</th>
              <th>Yükleme Planındaki Mevcut</th>
              <th>İşlem Sonrası</th>
            </tr>
          </thead>
          <tbody id="beyanMatchPreviewRows"></tbody>
        </table>
      </div>

      <form method="post" action="beyan_eslestir.php" id="beyanMatchForm"
            onsubmit="return beyanMatchConfirm();">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="match_loading_record">
        <input type="hidden" name="beyan_id" value="<?= $id ?>">
        <input type="hidden" name="loading_record_id" id="beyanMatchLoadingId" value="">
        <p class="beyan-match-warn">
          Bu işlem seçilen yükleme planını beyan bilgileriyle günceller ve yükleme
          durumunu <strong>Yüklendi</strong> yapar. Beyan durumu da <strong>Yüklendi</strong> olur.
        </p>
        <div class="beyan-match-footer">
          <button type="button" class="btn btn-ghost" id="beyanMatchBack">← Geri</button>
          <button type="submit" class="btn btn-primary">Her Şeyi Eşleştir ve Güncelle</button>
        </div>
      </form>
    </div>

  </div><!-- .beyan-match-dialog -->
</div><!-- #beyanMatchOverlay -->

<script>
(function () {
    var BEYAN = <?= json_encode($beyan_match, JSON_UNESCAPED_UNICODE) ?>;

    var overlay  = document.getElementById('beyanMatchOverlay');
    var openBtn  = document.getElementById('beyanMatchOpenBtn');
    var closeBtn = document.getElementById('beyanMatchClose');
    var cancel1  = document.getElementById('beyanMatchCancel1');
    var search   = document.getElementById('beyanMatchSearch');
    var rows     = Array.prototype.slice.call(document.querySelectorAll('.beyan-match-row'));
    var radios   = Array.prototype.slice.call(document.querySelectorAll('.beyan-match-radio'));
    var compareBtn = document.getElementById('beyanMatchCompare');
    var step1    = document.getElementById('beyanMatchStep1');
    var step2    = document.getElementById('beyanMatchStep2');
    var backBtn  = document.getElementById('beyanMatchBack');
    var previewBody = document.getElementById('beyanMatchPreviewRows');
    var loadingIdInput = document.getElementById('beyanMatchLoadingId');

    if (!overlay || !openBtn) return;

    function openModal()  { overlay.hidden = false; document.body.style.overflow = 'hidden'; }
    function closeModal() {
        overlay.hidden = true;
        document.body.style.overflow = '';
        showStep1();
    }
    function showStep1() { step1.hidden = false; step2.hidden = true; }
    function showStep2() { step1.hidden = true;  step2.hidden = false; }

    openBtn.addEventListener('click', openModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancel1)  cancel1.addEventListener('click', closeModal);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !overlay.hidden) closeModal();
    });

    // Arama filtresi
    if (search) {
        search.addEventListener('input', function () {
            var q = this.value.trim().toLowerCase();
            rows.forEach(function (tr) {
                var hay = tr.getAttribute('data-search') || '';
                tr.style.display = (q === '' || hay.indexOf(q) !== -1) ? '' : 'none';
            });
        });
    }

    // Radyo seçimi
    radios.forEach(function (rb) {
        rb.addEventListener('change', function () {
            compareBtn.disabled = !document.querySelector('.beyan-match-radio:checked');
        });
    });
    rows.forEach(function (tr) {
        tr.addEventListener('click', function (e) {
            if (e.target.tagName === 'INPUT') return;
            var rb = tr.querySelector('.beyan-match-radio');
            if (rb) { rb.checked = true; rb.dispatchEvent(new Event('change')); }
        });
    });

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;' }[c];
        });
    }

    function buildPreview(row) {
        // Alanlar: [etiket, beyan değeri, mevcut yükleme değeri, ek not]
        var defs = [
            ['Parti No', BEYAN.parti_no, row.getAttribute('data-parti') || '', ''],
            ['Alıcı',    BEYAN.alici,    row.getAttribute('data-alici') || '', ''],
            ['Ulaşım',   BEYAN.ulasim,   row.getAttribute('data-ulasim') || '', ''],
            ['Gümrük',   BEYAN.gumruk,   row.getAttribute('data-gumruk') || '',
             'Beyandaki Şirket Adı, yükleme planında Gümrük alanına aktarılır.']
        ];
        var html = '';
        defs.forEach(function (d) {
            var label = d[0], beyanVal = (d[1] || '').trim(), curVal = (d[2] || '').trim(), hint = d[3] || '';
            var result, note = '';
            if (beyanVal === '') {
                result = curVal;
                note = '<div class="beyan-match-note">Beyan verisi boş olduğu için mevcut değer korunacak.</div>';
            } else {
                result = beyanVal;
                if (hint) note = '<div class="beyan-match-note">' + esc(hint) + '</div>';
            }
            var changed = beyanVal !== '' && beyanVal !== curVal;
            html += '<tr' + (changed ? ' class="beyan-match-changed"' : '') + '>'
                  + '<th>' + esc(label) + '</th>'
                  + '<td>' + (beyanVal !== '' ? esc(beyanVal) : '<span class="muted">—</span>') + '</td>'
                  + '<td>' + (curVal   !== '' ? esc(curVal)   : '<span class="muted">boş</span>') + '</td>'
                  + '<td><strong>' + (result !== '' ? esc(result) : '<span class="muted">—</span>') + '</strong>' + note + '</td>'
                  + '</tr>';
        });
        previewBody.innerHTML = html;
    }

    compareBtn.addEventListener('click', function () {
        var rb = document.querySelector('.beyan-match-radio:checked');
        if (!rb) return;
        var row = rb.closest('.beyan-match-row');
        loadingIdInput.value = rb.value;
        buildPreview(row);
        showStep2();
    });

    if (backBtn) backBtn.addEventListener('click', showStep1);

    window.beyanMatchConfirm = function () {
        return confirm('Seçilen yükleme planı beyan bilgileriyle güncellenecek ve Yüklendi yapılacak. Onaylıyor musunuz?');
    };
})();
</script>
<?php endif; ?>

<?php render_footer(); ?>
