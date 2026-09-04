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

// ── Hal Kayıt (HKS) bildirimi — buton kapısı (Sprint Beyan-Bildirim-01) ──
// Buton yalnızca PLAKA girilmişse açılır (kullanıcı kuralı). Ayrıca durum
// uygun olmalı, beyan silinmemiş olmalı ve yetki çift kapıdan geçmeli:
// beyan.write TEK BAŞINA yetmez — HKS'te rüsum doğuran zincirin ilk halkası
// olduğu için Hal Kayıt panelinin kapısı olan records.write de aranır.
$hks_plaka     = trim((string)($beyan['vehicle_plate'] ?? ''));
$hks_yetki     = can_beyan('write') && (can('records.write') || is_admin());
$hks_durum_ok  = in_array($cur_status, beyan_hks_uygun_durumlar(), true);
$hks_net_kg    = (float)($beyan['net_kg'] ?? 0);
$hks_aktif     = beyan_hks_aktif($id);
$hks_gecmis    = beyan_hks_gecmis($id);

// Buton neden kapalı? Tek tek söylenir — "pasif buton" sessiz kalmaz.
$hks_engel = null;
if (!$hks_yetki)                 $hks_engel = 'Bildirim için beyan.write + records.write yetkisi gerekir.';
elseif ($is_deleted)             $hks_engel = 'Arşivlenmiş beyan için bildirim yapılamaz.';
elseif (!$hks_durum_ok)          $hks_engel = 'Bu beyan durumunda bildirim yapılamaz.';
elseif ($hks_plaka === '')       $hks_engel = 'Araç plakası girilmeden bildirim yapılamaz — beyanı düzenleyip plakayı girin.';
elseif ($hks_net_kg <= 0)        $hks_engel = 'Net KG girilmeden bildirim yapılamaz.';
// Plaka DOLU olsa bile eşleştirme eksikse geçilmez: hangi HKS firması, hangi
// katalog ürünü ve hangi ülke olduğu beyanda tanımlı olmadan bildirim
// kurulamaz. Bu alanlar beyan formundaki "🏛 Hal Bildirim Bilgileri"
// bölümünde girilir.
elseif (!beyan_hks_eslesme_tam($beyan))
                                 $hks_engel = 'Hal Bildirim bilgileri eksik (HKS firması / ürünü / ülke). '
                                            . 'Beyanı düzenleyip "🏛 Hal Bildirim Bilgileri" bölümünü doldurun.';
elseif ($hks_aktif)              $hks_engel = $hks_aktif['durum'] === 'gonderildi'
                                    ? 'Bu beyan için bildirim zaten gönderildi.'
                                    : 'Bu beyan için bekleyen bir HKS taslağı var.';
$hks_acik = ($hks_engel === null);

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
        <?php if ($hks_acik): ?>
        <button type="button" class="btn" id="hksOpenBtn"
                style="background:#7c3aed;color:#fff;border-color:#7c3aed">
            🏛 Bildirim Yap
        </button>
        <?php elseif ($hks_yetki && !$is_deleted): ?>
        <button type="button" class="btn" disabled title="<?= h((string)$hks_engel) ?>"
                style="opacity:.55;cursor:not-allowed">🏛 Bildirim Yap</button>
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
            <span class="lbl">Araç Plakası</span>
            <span class="val"><?= h(($beyan['vehicle_plate'] ?? '') ?: '—') ?></span>
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
                          'transport_type','vehicle_plate','line_type','party_no','pallet_count','product_name','product_variety',
                          'gross_kg','net_kg','crate_count','crate_type','exit_depot','contact_person',
                          'buyer_name','brand','analysis_note','sample_taken_at','analysis_result_at',
                          'hks_firma_id','hks_urun_id','hks_ulke_id'] as $hf):
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

<!-- 6b. Hal Kayıt (HKS) Bildirimi -->
<div class="beyan-section">
    <div class="beyan-section-title">🏛 Hal Kayıt Bildirimi</div>
    <div class="beyan-view-grid" style="margin-bottom:10px">
        <div class="beyan-view-row">
            <span class="lbl">HKS Ürünü</span>
            <span class="val"><?= h((string)($beyan['hks_urun_ad'] ?? '')) ?: '—' ?></span>
        </div>
        <div class="beyan-view-row">
            <span class="lbl">Ülke</span>
            <span class="val"><?= h((string)($beyan['hks_ulke_ad'] ?? '')) ?: '—' ?></span>
        </div>
    </div>
    <?php if (empty($hks_gecmis)): ?>
    <p class="muted" style="font-size:.88rem">
        Bu beyan için henüz bildirim oluşturulmadı.
        <?php if (!$hks_acik && $hks_engel): ?>
        <br><span style="color:var(--danger)"><?= h((string)$hks_engel) ?></span>
        <?php endif; ?>
    </p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="beyan-match-table">
            <thead>
                <tr><th>Durum</th><th>Firma</th><th>Ürün</th><th>Ülke</th>
                    <th>Plaka</th><th>KG</th><th>Fiyat</th><th>Tarih</th></tr>
            </thead>
            <tbody>
            <?php foreach ($hks_gecmis as $hg): ?>
                <tr>
                    <td><span class="beyan-badge"><?= h(beyan_hks_durum_etiket($hg['durum'])) ?></span></td>
                    <td><?= h((string)($hg['hks_firma_ad'] ?? '')) ?: '—' ?></td>
                    <td><?= h((string)($hg['urun_ad'] ?? '')) ?: '—' ?></td>
                    <td><?= h((string)($hg['ulke_ad'] ?? '')) ?: '—' ?></td>
                    <td><?= h((string)($hg['plaka'] ?? '')) ?: '—' ?></td>
                    <td><?= h(fmt_kg($hg['kg'] ?? 0)) ?></td>
                    <td><?= h(number_format((float)($hg['fiyat'] ?? 0), 2, ',', '.')) ?></td>
                    <td><?= h(fmt_datetime($hg['created_at'])) ?></td>
                </tr>
                <?php if (!empty($hg['hata_metni'])): ?>
                <tr><td colspan="8" class="muted" style="font-size:.8rem"><?= h((string)$hg['hata_metni']) ?></td></tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="muted" style="font-size:.82rem;margin-top:8px">
        Taslaklar <a href="halkayit/index.php?ekran=taslaklar">Hal Kayıt panelinden</a> gönderilir —
        bu ekran HKS'e bildirim <strong>göndermez</strong>.
    </p>
    <?php endif; ?>
</div>

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

<?php if ($hks_acik): ?>
<!-- ══ HAL KAYIT BİLDİRİM MODALİ (Sprint Beyan-Bildirim-01) ══════════════ -->
<!-- z-index 600 (.beyan-hks-overlay) — CLAUDE.md modal katman kuralı.      -->
<div id="hksOverlay" class="beyan-hks-overlay" hidden role="dialog" aria-modal="true"
     aria-labelledby="hksTitle">
  <div class="beyan-hks-dialog">
    <div class="beyan-hks-head">
      <strong id="hksTitle">🏛 Hal Kayıt Bildirimi</strong>
      <button type="button" class="beyan-hks-x" id="hksClose" aria-label="Kapat">✕</button>
    </div>

    <div class="beyan-hks-body">
      <div id="hksLoading" class="muted" style="padding:20px;text-align:center">Bilgiler hazırlanıyor…</div>
      <div id="hksError" class="flash flash-error" hidden></div>

      <div id="hksContent" hidden>
        <!-- Beyandan gelen — salt okunur -->
        <div class="beyan-hks-sub">Beyandan gelen bilgiler</div>
        <dl class="beyan-hks-list" id="hksInfo"></dl>

        <!-- Beyandaki kalıcı eşleştirme — SALT OKUNUR.
             Değiştirmek için beyan düzenlenir; böylece aynı beyandan yapılan
             her bildirim aynı firma/ürün/ülke ile gider. -->
        <div class="beyan-hks-sub">HKS eşleştirmesi <span class="beyan-hks-rozet">beyandan</span></div>
        <dl class="beyan-hks-list" id="hksEslesme"></dl>

        <!-- İşlem anına ait alanlar -->
        <div class="beyan-hks-sub">Bildirim ayarları</div>
        <div class="beyan-hks-fields">
          <label>Bildirimci Sıfatı <span id="hksSifatRozet" class="beyan-hks-rozet"></span>
            <select id="hksSifat" class="form-control"></select>
          </label>
          <label>Bildirim Türü <span id="hksTurRozet" class="beyan-hks-rozet"></span>
            <select id="hksTur" class="form-control"></select>
          </label>
          <label style="grid-column:1/-1">Birim Fiyat <strong style="color:var(--danger)">*</strong>
            <input type="text" id="hksFiyat" class="form-control" inputmode="decimal"
                   placeholder="örn. 12,50" autocomplete="off">
            <!-- Öneriler tıklanınca alana yazılır; HİÇBİRİ otomatik dolmaz.
                 HKS'in fiyat alanında para birimi yok ve rüsum bu sayı
                 üzerinden hesaplanıyor — rakamın nereden geldiği görünmeli. -->
            <span id="hksFiyatOneri" class="beyan-hks-oneri"></span>
          </label>
        </div>

        <p class="beyan-hks-warn">
          Bu işlem yalnızca <strong>TASLAK</strong> oluşturur. HKS'e bildirim
          <strong>GÖNDERİLMEZ</strong> — gönderim geri alınamaz ve rüsum doğurur,
          o adım Hal Kayıt ekranında yapılır. Künyeler gönderim anında canlı
          stoktan çözülür.
        </p>
      </div>
    </div>

    <div class="beyan-hks-foot">
      <button type="button" class="btn btn-ghost" id="hksCancel">Vazgeç</button>
      <button type="button" class="btn btn-primary" id="hksSave" disabled>Taslağa Kaydet</button>
    </div>
  </div>
</div>

<script>
(function () {
    var BEYAN_ID = <?= (int)$id ?>;
    var CSRF     = <?= json_encode(csrf_token()) ?>;

    var ov   = document.getElementById('hksOverlay');
    var el   = function (i) { return document.getElementById(i); };
    var veri = null;

    function hata(msg) {
        el('hksLoading').hidden = true;
        el('hksError').textContent = msg;
        el('hksError').hidden = false;
    }

    function satir(k, v) {
        return '<dt>' + k + '</dt><dd>' + (v === '' || v === null || v === undefined ? '—' : v) + '</dd>';
    }

    // Seçenekleri doldurur; seciliId varsa onu işaretler.
    function doldur(sel, liste, seciliId) {
        sel.innerHTML = '<option value="">— seçiniz —</option>' +
            liste.map(function (x) {
                return '<option value="' + x.id + '"' +
                       (String(x.id) === String(seciliId || '') ? ' selected' : '') + '>' +
                       x.ad + '</option>';
            }).join('');
    }

    // Ön-seçim rozeti: seçim GERÇEKTEN yapıldıysa etiketlenir (varsayılan
    // katalogda bulunamadıysa select boş kalır ve rozet de yazılmaz).
    function onSecimRozet(span, sel, metin) {
        if (!sel.value) { span.textContent = ''; return; }
        span.textContent = metin;
        span.className = 'beyan-hks-rozet beyan-hks-rozet-ok';
    }

    // Birim fiyat önerileri. TIKLANINCA yazar — asla kendiliğinden doldurmaz:
    // HKS'in fiyat alanında para birimi yok, rüsum bu sayıdan hesaplanıyor ve
    // gönderim geri alınamaz; rakamın nereden geldiği kullanıcıya görünmeli.
    function fiyatOnerileriCiz(liste) {
        var kap = el('hksFiyatOneri');
        if (!liste.length) { kap.innerHTML = ''; return; }
        kap.innerHTML = '<span class="beyan-hks-oneri-baslik">Öneriler:</span>' +
            liste.map(function (o, i) {
                var g = o.deger.toLocaleString('tr-TR', { maximumFractionDigits: 4 });
                return '<button type="button" class="beyan-hks-oneri-btn" data-i="' + i + '" title="' +
                       o.aciklama.replace(/"/g, '&quot;') + '">' +
                       o.etiket + ': <strong>' + g + '</strong></button>';
            }).join('');
        Array.prototype.forEach.call(kap.querySelectorAll('button'), function (b) {
            b.addEventListener('click', function () {
                var o = liste[Number(b.dataset.i)];
                el('hksFiyat').value = o.deger.toLocaleString('tr-TR', { maximumFractionDigits: 4 });
                kontrol();
            });
        });
    }

    function kontrol() {
        // Firma/ürün/ülke beyandan gelir ve buton kapısında zaten doğrulandı;
        // burada yalnız bu ekranda girilen alanlar denetlenir.
        var tam = el('hksSifat').value && el('hksTur').value && el('hksFiyat').value.trim() !== '';
        el('hksSave').disabled = !tam;
    }

    function ac() {
        ov.hidden = false;
        document.body.style.overflow = 'hidden';
        if (veri) return;                       // bir kez yüklenir
        fetch('api_beyan_bildirim.php?action=hazirla&beyan_id=' + BEYAN_ID, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ beyan_id: BEYAN_ID })
        })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
            if (!res.ok) { hata(res.j.hata || 'Bilgiler alınamadı.'); return; }
            veri = res.j;
            if (veri.onKosul) { hata(veri.onKosul); return; }

            var b = veri.beyan;
            el('hksInfo').innerHTML =
                satir('Parti No', b.partiNo) +
                satir('Ürün', b.urunAdi + (b.cesit ? ' / ' + b.cesit : '')) +
                satir('Net KG', b.netKg.toLocaleString('tr-TR')) +
                satir('Araç Plakası', b.plaka) +
                satir('Alıcı', b.alici) +
                satir('Çıkış Deposu', b.depo) +
                satir('Palet / Kasa', (b.palet === null ? '—' : b.palet) + ' / ' + (b.kasa === null ? '—' : b.kasa));

            var es = veri.eslesme || {};
            el('hksEslesme').innerHTML =
                satir('HKS Firması', es.firmaAd) +
                satir('HKS Ürünü',   es.urunAd) +
                satir('Ülke',        es.ulkeAd);

            var vs = veri.varsayilan || {};
            doldur(el('hksSifat'), veri.katalog.sifatlar,        vs.sifatId || '');
            doldur(el('hksTur'),   veri.katalog.bildirimTurleri, vs.bildirimTuruId || '');
            onSecimRozet(el('hksSifatRozet'), el('hksSifat'), 'varsayılan');
            onSecimRozet(el('hksTurRozet'),   el('hksTur'),   'varsayılan');
            fiyatOnerileriCiz(veri.fiyatOnerileri || []);

            el('hksLoading').hidden = true;
            el('hksContent').hidden = false;
            kontrol();
        })
        .catch(function (e) { hata('Bağlantı hatası: ' + e.message); });
    }

    function kapat() { ov.hidden = true; document.body.style.overflow = ''; }

    el('hksOpenBtn').addEventListener('click', ac);
    el('hksClose').addEventListener('click', kapat);
    el('hksCancel').addEventListener('click', kapat);
    ov.addEventListener('click', function (e) { if (e.target === ov) kapat(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !ov.hidden) kapat(); });

    ['hksSifat','hksTur','hksFiyat'].forEach(function (i) {
        el(i).addEventListener('change', kontrol);
        el(i).addEventListener('input',  kontrol);
    });

    el('hksSave').addEventListener('click', function () {
        var b = veri.beyan;
        var es = veri.eslesme || {};
        if (!confirm('"' + b.partiNo + '" için HKS TASLAĞI oluşturulacak.\n\n' +
                     'Ürün: ' + es.urunAd + '\n' +
                     'Ülke: ' + es.ulkeAd + '\n' +
                     'Plaka: ' + b.plaka + '\n' +
                     'Net KG: ' + b.netKg.toLocaleString('tr-TR') + '\n' +
                     'Birim fiyat: ' + el('hksFiyat').value + '\n\n' +
                     'Bildirim GÖNDERİLMEZ; gönderim Hal Kayıt ekranında yapılır.')) return;

        el('hksSave').disabled = true;
        el('hksSave').textContent = 'Kaydediliyor…';
        fetch('api_beyan_bildirim.php?action=taslak_olustur', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                beyan_id: BEYAN_ID, csrf: CSRF,
                sifatId: el('hksSifat').value,
                bildirimTuruId: el('hksTur').value,
                fiyat: el('hksFiyat').value
            })
        })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
            if (!res.ok) {
                alert('HATA: ' + (res.j.hata || 'Taslak oluşturulamadı.'));
                el('hksSave').disabled = false;
                el('hksSave').textContent = 'Taslağa Kaydet';
                return;
            }
            alert('✅ ' + res.j.mesaj);
            location.reload();
        })
        .catch(function (e) {
            alert('Bağlantı hatası: ' + e.message);
            el('hksSave').disabled = false;
            el('hksSave').textContent = 'Taslağa Kaydet';
        });
    });
})();
</script>
<?php endif; ?>

<?php render_footer(); ?>
