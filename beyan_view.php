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

$beyan = beyan_redact_fields($beyan);

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
$can_match     = !$is_deleted && can_beyan('match') && (can('records.write') || is_admin())
                 && empty($beyan['loading_record_id']);
$match_records = [];
$match_q = trim((string)($_GET['match_q'] ?? ''));
$match_page = max(1, (int)($_GET['match_page'] ?? 1));
$match_pages = 1;
$match_total = 0;
if ($can_match) {
    // Arama tüm tarihlerde, kullanıcının erişebildiği kilitsiz planlarda yapılır.
    [$match_depo_sql, $match_params] = depo_sql_records('r');
    $match_where = "WHERE r.type = 'yukleme' AND COALESCE(r.durum, '') <> 'yuklendi' AND r.locked_at IS NULL" . $match_depo_sql;
    if ($match_q !== '') {
        $match_search = [];
        foreach (['r.firma','r.urun','r.parti_no','r.alici','us.name'] as $si => $sc) {
            $match_search[] = "$sc LIKE :mq$si";
            $match_params[':mq' . $si] = '%' . $match_q . '%';
        }
        $match_where .= ' AND (' . implode(' OR ', $match_search) . ')';
    }
    $match_from = "FROM loading_records r LEFT JOIN material_definitions us ON us.id = r.urun_sahibi_id AND us.type = 'firma' ";
    $mc = db()->prepare("SELECT COUNT(*) $match_from $match_where");
    $mc->execute($match_params);
    $match_total = (int)$mc->fetchColumn();
    $match_pages = max(1, (int)ceil($match_total / 50));
    $match_page = min($match_page, $match_pages);
    $ms = db()->prepare(
        "SELECT r.id, r.tarih, r.firma, r.parti_no, r.alici, r.ulasim, r.gumruk,
                r.urun, r.durum, us.name AS urun_sahibi_adi
         $match_from $match_where
         ORDER BY COALESCE(r.tarih, '0000-00-00') DESC, r.id DESC
         LIMIT :mlim OFFSET :moff"
    );
    foreach ($match_params as $mk => $mv) $ms->bindValue($mk, $mv);
    $ms->bindValue(':mlim', 50, PDO::PARAM_INT);
    $ms->bindValue(':moff', ($match_page - 1) * 50, PDO::PARAM_INT);
    $ms->execute();
    $match_records = $ms->fetchAll();
}

// ── Hal Kayıt (HKS) bildirimi — buton kapısı (Sprint Beyan-Bildirim-01) ──
// Buton yalnızca PLAKA girilmişse açılır (kullanıcı kuralı). Ayrıca durum
// uygun olmalı, beyan silinmemiş olmalı ve yetki çift kapıdan geçmeli:
// beyan.write TEK BAŞINA yetmez — HKS'te rüsum doğuran zincirin ilk halkası
// olduğu için Hal Kayıt panelinin kapısı olan records.write de aranır.
$hks_plaka     = trim((string)($beyan['vehicle_plate'] ?? ''));
$hks_yetki     = can_beyan('notify') && can_beyan('hks.write') && (can('records.write') || is_admin());
$hks_durum_ok  = in_array($cur_status, beyan_hks_uygun_durumlar(), true);
$hks_net_kg    = (float)($beyan['net_kg'] ?? 0);
$hks_aktif     = beyan_hks_aktif($id);
$hks_gecmis    = can_beyan('hks.read') ? beyan_hks_gecmis($id) : [];

// Buton neden kapalı? Tek tek söylenir — "pasif buton" sessiz kalmaz.
$hks_engel = null;
if (!$hks_yetki)                 $hks_engel = 'Bildirim için beyan.write + records.write yetkisi gerekir.';
elseif ($is_deleted)             $hks_engel = 'Arşivlenmiş beyan için bildirim yapılamaz.';
elseif (!$hks_durum_ok)          $hks_engel = 'Bu beyan durumunda bildirim yapılamaz.';
elseif ($hks_plaka === '')       $hks_engel = 'Araç plakası girilmeden bildirim yapılamaz.';
elseif ($hks_net_kg <= 0)        $hks_engel = 'Net KG girilmeden bildirim yapılamaz.';
// Plaka DOLU olsa bile eşleştirme eksikse geçilmez: hangi HKS firması, hangi
// katalog ürünü ve hangi ülke olduğu beyanda tanımlı olmadan bildirim
// kurulamaz. Bu alanlar beyan formundaki "🏛 Hal Bildirim Bilgileri"
// bölümünde girilir.
elseif (!beyan_hks_eslesme_tam($beyan))
                                 $hks_engel = 'Hal Bildirim bilgileri eksik (HKS firması / ürünü / ülke). '
                                            . 'Beyanı düzenleyip "🏛 Hal Bildirim Bilgileri" bölümünü doldurun.';
elseif ($hks_aktif)              $hks_engel = [
                                      'gonderildi' => 'Bu beyan için bildirim zaten gönderildi.',
                                      'silindi'    => 'Bu beyanın HKS taslağı Hal Kayıt ekranından silindi — bildirim yapılmış sayılır. '
                                                    . 'Yeniden bildirim için Düzenle → "Hal Bildirim Durumu" → Tekrar Aktif Et.',
                                    ][$hks_aktif['durum']] ?? 'Bu beyan için bekleyen bir HKS taslağı var.';
$hks_acik = ($hks_engel === null);

// Eşleştirme eksikse HKS Taslağı düğmesinin YERİNE "Eşleştir" düğmesi çıkar:
// tek tıkla firma/ürün/ülke beyan verisinden doldurulur (yalnız boş alanlar,
// api_beyan_bildirim.php?action=eslestir). Tam olunca sayfa yenilenir ve düğme
// "HKS Taslağı Oluştur"a döner. Plaka eksikliği eşleştirmeyle çözülmez; sebep
// yanında yazılır.
$hks_eslestir = $hks_yetki && !$is_deleted && $hks_durum_ok && !$hks_aktif
                && !beyan_hks_eslesme_tam($beyan);
if ($hks_eslestir) {
    $hks_acik  = false;
    $hks_engel = 'Hal Bildirim bilgileri eksik (HKS firması / ürünü / ülke) — Eşleştir\'e basın, beyan verisinden doldurulur.'
               . ($hks_plaka === '' ? ' Araç plakası da girilmeli.' : '')
               . ($hks_net_kg <= 0 ? ' Net KG de girilmeli.' : '');
}
$hks_firma_adi = trim((string)($beyan['hks_firma_id'] ?? '')) !== ''
    ? (beyan_hks_firma_adlari([(string)$beyan['hks_firma_id']])[(string)$beyan['hks_firma_id']] ?? ('#' . $beyan['hks_firma_id']))
    : '';

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
    <div class="beyan-page-heading">
        <h1>🧾 <?= h($beyan['party_no'] ?: 'Beyan #' . $id) ?></h1>
        <button type="button" class="btn btn-whatsapp" id="waKopyalaBtn" title="WhatsApp'ta yapıştırılacak metni kopyala">⧉ Kopyala</button>
        <script type="application/json" id="waMetin"><?= json_encode(beyan_whatsapp_metin($beyan), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
        <?php if ($is_deleted): ?>
        <div style="margin-top:4px">
            <span class="beyan-badge" style="background:#fef3c7;color:#92400e">ARŞİVLENDİ</span>
        </div>
        <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <a href="beyanlar.php" class="btn btn-geri">← Beyanlar</a>
        <?php if (!$is_deleted && can_beyan('edit')): ?>
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

<div class="bv">

<!-- ── BİLDİRİM KARTI ──────────────────────────────────────────────────────
     HKS bildirimi için gereken DÖRT bilgi tek bakışta: Ülke · Ürün · Net KG ·
     Plaka. Eksik olan alan sessiz bir "—" ile geçiştirilmez, işaretlenir —
     kartın amacı "bu beyan bildirime hazır mı" sorusunu tek bakışta
     cevaplamaktır. (Net KG bilerek burada da, Ürün Bilgileri bölümünde de
     görünür; palet/kasa gibi ayrıntılar aşağıdaki bölümlerde kalır.) -->
<?php
$bk_ulke  = trim((string)($beyan['hks_ulke_ad'] ?? ''));
$bk_urun  = trim((string)($beyan['hks_urun_ad'] ?? '')) ?: trim((string)($beyan['product_name'] ?? ''));
$bk_hks   = trim((string)($beyan['hks_urun_ad'] ?? '')) !== '';   // HKS karşılığı seçilmiş mi
$bk_kg    = $beyan['net_kg'] !== null && (float)$beyan['net_kg'] > 0;
$bk_plaka_hizli = !$is_deleted && can_beyan('write') && can_beyan('hks.write');   // v340: hızlı plaka girişi
$bk_hucre = function (string $etiket, string $deger, bool $tam, string $not = '', string $ic = ''): void { ?>
    <div class="bk-hucre<?= $tam ? '' : ' bk-eksik' ?>"<?= $ic !== '' ? ' data-ic="' . h($ic) . '"' : '' ?>>
        <div class="bk-etiket"><?= h($etiket) ?></div>
        <div class="bk-deger"><?= $deger !== '' ? h($deger) : 'eksik' ?></div>
        <?php if ($not !== ''): ?><div class="bk-not"><?= h($not) ?></div><?php endif; ?>
    </div>
<?php };
?>
<?php if (can_beyan('hks.read')): ?>
<div class="beyan-section bk-kart" data-bf="hks">
    <div class="beyan-section-title" data-ic="hal"><span class="bf-emoji" aria-hidden="true">🏛</span> Hal Kayıt Bildirimi</div>
    <div class="bk-grid">
        <?php
        $bk_hucre('HKS Firma', $hks_firma_adi, $hks_firma_adi !== '', '', 'bina');
        $bk_hucre('Ülke',   $bk_ulke, $bk_ulke !== '', '', 'kure');
        $bk_hucre('Ürün',   $bk_urun, $bk_urun !== '' && $bk_hks,
                  $bk_urun !== '' && !$bk_hks ? 'HKS karşılığı seçilmedi' : '', 'kutu');
        $bk_hucre('Net KG', $bk_kg ? fmt_kg($beyan['net_kg']) : '', $bk_kg, '', 'terazi');
        if ($hks_plaka === '' && $bk_plaka_hizli): ?>
        <button type="button" class="bk-hucre bk-eksik bk-tikla" id="plakaHizliBtn" data-ic="arac" title="Plakayı hızlıca gir">
            <div class="bk-etiket">Plaka</div>
            <div class="bk-deger">girmek için dokunun</div>
        </button>
        <?php else: $bk_hucre('Plaka',  $hks_plaka, $hks_plaka !== '', '', 'arac'); endif;
        ?>
    </div>

    <?php if ($hks_yetki && !$is_deleted): ?>
    <div class="bk-alt">
        <?php if ($hks_eslestir): ?>
        <button type="button" class="btn btn-primary" id="hksEslestirBtn">🔗 HKS Bilgilerini Eşleştir</button>
        <span class="bk-engel"><?= h((string)$hks_engel) ?></span>
        <?php elseif ($hks_acik): ?>
        <button type="button" class="btn btn-primary" id="hksOpenBtn">🏛 HKS Taslağı Oluştur</button>
        <?php else: ?>
        <button type="button" class="btn" disabled>🏛 HKS Taslağı Oluştur</button>
        <span class="bk-engel"><?= h((string)$hks_engel) ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($hks_gecmis)): ?>
    <details class="beyan-history">
    <summary>Bildirim geçmişi (<?= count($hks_gecmis) ?>)</summary>
    <div class="table-wrap" style="margin-top:12px">
        <table class="beyan-match-table">
            <thead>
                <tr><th>Durum</th><th>Firma</th><th>Ürün</th><th>Ülke</th>
                    <th>Plaka</th><th class="num">KG</th><th class="num">Fiyat</th><th>Tarih</th></tr>
            </thead>
            <tbody>
            <?php foreach ($hks_gecmis as $hg): ?>
                <tr>
                    <td><span class="beyan-badge"><?= h(beyan_hks_durum_etiket($hg['durum'])) ?></span></td>
                    <td><?= h((string)($hg['hks_firma_ad'] ?? '')) ?: '—' ?></td>
                    <td><?= h((string)($hg['urun_ad'] ?? '')) ?: '—' ?></td>
                    <td><?= h((string)($hg['ulke_ad'] ?? '')) ?: '—' ?></td>
                    <td><?= h((string)($hg['plaka'] ?? '')) ?: '—' ?></td>
                    <td class="num"><?= h(fmt_kg($hg['kg'] ?? 0)) ?></td>
                    <td class="num"><?= h(number_format((float)($hg['fiyat'] ?? 0), 2, ',', '.')) ?></td>
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
    </details>
    <?php endif; ?>
</div>

<?php endif; ?>
<?php if ($bk_plaka_hizli && $hks_plaka === ''): ?>
<dialog id="plakaHizliDlg" class="plaka-hizli" aria-labelledby="plakaHizliBaslik">
    <form method="dialog" id="plakaHizliForm" autocomplete="off">
        <h3 id="plakaHizliBaslik">Araç Plakası</h3>
        <input type="text" id="plakaHizliInput" class="form-control" maxlength="20" inputmode="latin"
               autocapitalize="characters" spellcheck="false" aria-label="Araç plakası">
        <div id="plakaHizliHata" class="plaka-hizli-hata" role="alert" hidden></div>
        <div class="plaka-hizli-btn">
            <button type="button" class="btn" id="plakaHizliIptal">Vazgeç</button>
            <button type="submit" class="btn btn-primary" id="plakaHizliKaydet">Kaydet</button>
        </div>
    </form>
</dialog>
<script>
(function () {
    var btn = document.getElementById('plakaHizliBtn'), dlg = document.getElementById('plakaHizliDlg');
    if (!btn || !dlg || typeof dlg.showModal !== 'function') { if (btn) btn.hidden = true; return; }
    var form = document.getElementById('plakaHizliForm'), inp = document.getElementById('plakaHizliInput'),
        hata = document.getElementById('plakaHizliHata'), kaydet = document.getElementById('plakaHizliKaydet');
    var BEYAN_ID = <?= (int)$id ?>, CSRF = <?= json_encode(csrf_token()) ?>;
    function norm(v) { return v.replace(/\s+/g, '').toLocaleUpperCase('tr-TR'); }
    btn.addEventListener('click', function () { hata.hidden = true; inp.value = ''; dlg.showModal(); inp.focus(); });
    document.getElementById('plakaHizliIptal').addEventListener('click', function () { dlg.close(); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
    inp.addEventListener('input', function () {
        var p = inp.selectionStart, n = norm(inp.value);
        if (n !== inp.value) { inp.value = n; try { inp.setSelectionRange(p, p); } catch (x) {} }
    });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var v = norm(inp.value);
        if (v === '') { hata.textContent = 'Plaka girin.'; hata.hidden = false; return; }
        kaydet.disabled = true; kaydet.textContent = 'Kaydediliyor…';
        fetch('api_beyan_plaka.php', { method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id: BEYAN_ID, plaka: v, csrf: CSRF }) })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
            if (!res.ok || !res.j.ok) throw new Error((res.j && res.j.error) || 'Plaka kaydedilemedi.');
            location.reload();
        })
        .catch(function (err) {
            hata.textContent = err.message; hata.hidden = false;
            kaydet.disabled = false; kaydet.textContent = 'Kaydet';
        });
    });
})();
</script>
<?php endif; ?>
<!-- 1. Temel Bilgiler -->
<div class="beyan-section" data-bf="temel">
    <div class="beyan-section-title" data-ic="pano"><span class="bf-emoji" aria-hidden="true">📋</span> Temel Bilgiler</div>
    <div class="beyan-view-grid">
        <div class="beyan-view-row" data-ic="diyez">
            <span class="lbl">Parti No</span>
            <span class="val"><?= h($beyan['party_no'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row" data-ic="tir">
            <span class="lbl">Nakliye Türü</span>
            <span class="val"><?= h($beyan['transport_type'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row" data-ic="arac">
            <span class="lbl">Araç Plakası</span>
            <span class="val"><?= h(($beyan['vehicle_plate'] ?? '') ?: '—') ?></span>
        </div>
        <div class="beyan-view-row" data-ic="rota">
            <span class="lbl">Hat / Güzergah</span>
            <span class="val"><?= h($beyan['line_type'] ?: '—') ?></span>
        </div>
        <?php if ($beyan['company_name']): ?>
        <div class="beyan-view-row" style="grid-column:1/-1" data-ic="bina">
            <span class="lbl">Gümrük Adı</span>
            <span class="val"><?= h($beyan['company_name']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($beyan['company_address']): ?>
        <div class="beyan-view-row" style="grid-column:1/-1" data-ic="konum">
            <span class="lbl">Gümrük Adresi</span>
            <span class="val" style="white-space:pre-wrap"><?= h($beyan['company_address']) ?></span>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 2. Ürün Bilgileri -->
<div class="beyan-section" data-bf="urun">
    <div class="beyan-section-title" data-ic="paket"><span class="bf-emoji" aria-hidden="true">🍎</span> Ürün Bilgileri</div>
    <div class="beyan-view-grid">
        <div class="beyan-view-row" data-ic="elma">
            <span class="lbl">Ürün Adı</span>
            <span class="val"><?= h($beyan['product_name'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row" data-ic="etiket">
            <span class="lbl">Ürün Çeşidi</span>
            <span class="val"><?= h($beyan['product_variety'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row" data-ic="katman">
            <span class="lbl">Palet Adedi</span>
            <span class="val"><?= $beyan['pallet_count'] !== null ? (int)$beyan['pallet_count'] : '—' ?></span>
        </div>
        <div class="beyan-view-row" data-ic="agirlik">
            <span class="lbl">Brüt KG</span>
            <span class="val"><?= $beyan['gross_kg'] !== null ? fmt_kg($beyan['gross_kg']) : '—' ?></span>
        </div>
        <div class="beyan-view-row" data-ic="terazi">
            <span class="lbl">Net KG</span>
            <span class="val strong"><?= $beyan['net_kg'] !== null ? fmt_kg($beyan['net_kg']) : '—' ?></span>
        </div>
        <div class="beyan-view-row" data-ic="kutu">
            <span class="lbl">Kasa Adedi</span>
            <span class="val"><?= $beyan['crate_count'] !== null ? number_format((int)$beyan['crate_count'], 0, ',', '.') : '—' ?></span>
        </div>
        <div class="beyan-view-row" data-ic="kasa">
            <span class="lbl">Kasa Cinsi</span>
            <span class="val"><?= h($beyan['crate_type'] ?: '—') ?></span>
        </div>
    </div>
</div>

<!-- 3. Lojistik / Alıcı -->
<div class="beyan-section" data-bf="lojistik">
    <div class="beyan-section-title" data-ic="bina"><span class="bf-emoji" aria-hidden="true">🚚</span> Lojistik / Alıcı</div>
    <div class="beyan-view-grid">
        <div class="beyan-view-row" data-ic="depo">
            <span class="lbl">Çıkış Depo</span>
            <span class="val"><?= h($beyan['exit_depot'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row" data-ic="kisi">
            <span class="lbl">Alıcı</span>
            <span class="val"><?= h($beyan['buyer_name'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row" data-ic="rehber">
            <span class="lbl">İlgili Kişi</span>
            <span class="val"><?= h($beyan['contact_person'] ?: '—') ?></span>
        </div>
        <div class="beyan-view-row" data-ic="rozet">
            <span class="lbl">Marka</span>
            <span class="val"><?= h($beyan['brand'] ?: '—') ?></span>
        </div>
    </div>
</div>

<?php
$nm_kasa = ($beyan['sample_crate_count'] ?? null) !== null && (int)$beyan['sample_crate_count'] > 0 ? number_format((int)$beyan['sample_crate_count'], 0, ',', '.') : '';
$nm_cins = trim((string)($beyan['sample_crate_type'] ?? ''));
$nm_brut = ($beyan['sample_gross_kg'] ?? null) !== null && (float)$beyan['sample_gross_kg'] > 0 ? fmt_unit_kg($beyan['sample_gross_kg']) . ' KG' : '';
$nm_net  = ($beyan['sample_net_kg'] ?? null) !== null && (float)$beyan['sample_net_kg'] > 0 ? fmt_unit_kg($beyan['sample_net_kg']) . ' KG' : '';
$nm_parca = array_filter([trim($nm_kasa . ' ' . $nm_cins), $nm_brut !== '' ? 'Brüt: ' . $nm_brut : '', $nm_net !== '' ? 'Net: ' . $nm_net : '']);
?>
<div class="bv-yanyana<?= $nm_parca ? ' bv-yanyana-2' : '' ?>">
<!-- 4. Analiz / Durum -->
<div class="beyan-section" data-bf="durum">
    <div class="beyan-section-title" data-ic="grafik"><span class="bf-emoji" aria-hidden="true">📊</span> Analiz / Durum</div>
    <div class="beyan-view-grid">
        <div class="beyan-view-row" data-ic="tup">
            <span class="lbl">Durum</span>
            <span class="val"><?= beyan_badge_html($cur_status) ?></span>
        </div>
        <div class="beyan-view-row" data-ic="takvim">
            <span class="lbl">Numune Alındı</span>
            <span class="val"><?= $beyan['sample_taken_at'] ? h(fmt_datetime($beyan['sample_taken_at'])) : '—' ?></span>
        </div>
        <div class="beyan-view-row" data-ic="takvim">
            <span class="lbl">Analiz Sonuç Tarihi</span>
            <span class="val"><?= $beyan['analysis_result_at'] ? h(fmt_datetime($beyan['analysis_result_at'])) : '—' ?></span>
        </div>
    </div>
    <?php if ($beyan['analysis_note']): ?>
    <div class="bv-not">
        <div class="bv-not-baslik">Analiz Notu</div>
        <div style="white-space:pre-wrap"><?= h($beyan['analysis_note']) ?></div>
    </div>
    <?php endif; ?>

    <?php
    // ── Durum şeridi: TÜM durumlar tıklanabilir ───────────────────────────
    // Eskiden yalnız beyan_next_statuses() gösteriliyordu; sonuca varmak için
    // ara durumlara tek tek tıklamak gerekiyordu (ve terminal durumda şerit
    // hiç çıkmadığı için geri dönüş yolu yoktu). Artık dokuz durumun hepsi
    // duruyor, seçili olan ÇERÇEVE ile işaretli ve doğrudan sonuca gidilebilir.
    // Sunucu status_only dalında güncel satırın durum/not alanlarını değiştirir.
    $next_states = beyan_next_statuses($cur_status);   // yalnız "önerilen" işareti
    if (!$is_deleted && can_beyan('status')):
    ?>
    <div class="bv-durum">
        <div class="muted bv-durum-ipucu">
            Durum Değiştir: <span style="font-size:.92em">herhangi bir duruma doğrudan geçebilirsiniz</span>
        </div>
        <!-- TEK form. Her durum bir submit butonu (`name="status"`) — buton
             kendi değerini POST eder. Eskiden her buton kendi formunu ve ~26
             gizli alanını taşıyordu; dokuz durumla bu 230+ gizli alan demekti.
             `status` için AYRI bir hidden alan YOK: aynı `name` iki yerden
             gelirse hangisinin kazandığı belirsiz olurdu. -->
        <form method="post" action="beyan_edit.php?id=<?= $id ?>" class="beyan-durum-form">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="status_only" value="1">
            <input type="hidden" name="return_to" value="view">

            <?php $current_sv = $statuses[$cur_status] ?? ['label' => $cur_status, 'css' => 'taslak']; ?>
            <div class="beyan-durum-picker" data-beyan-status-picker>
                <button type="button" class="beyan-badge beyan-badge-<?= h($current_sv['css']) ?> beyan-durum-secili beyan-durum-toggle"
                        data-status-trigger aria-expanded="false" aria-controls="beyanDurumSecenekleri"
                        aria-label="Şu anki durum: <?= h($current_sv['label']) ?>. Durumu değiştir">
                    ✓ <?= h($current_sv['label']) ?> <span class="beyan-durum-chevron" aria-hidden="true">›</span>
                </button>
            <div class="beyan-next-status-wrap" id="beyanDurumSecenekleri" data-status-menu role="group" aria-label="Durum seçenekleri">
                <?php foreach (beyan_durum_akis_sirasi() as $ns):
                    $sv = $statuses[$ns] ?? ['label' => $ns, 'css' => 'taslak'];
                ?>
                <?php if ($ns === $cur_status): ?>
                <!-- Seçili durum: buton DEĞİL. Çerçeve + ✓ ile işaretli —
                     renge tek başına güvenilmez (TEMİZ ile YÜKLENDİ aynı yeşil). -->
                <span class="beyan-badge beyan-badge-<?= h($sv['css']) ?> beyan-durum-secili"
                      aria-current="true" title="Şu anki durum">✓ <?= h($sv['label']) ?></span>
                <?php else: ?>
                <button type="submit" name="status" value="<?= h($ns) ?>"
                        class="beyan-badge beyan-badge-<?= h($sv['css']) ?> beyan-durum-btn<?= in_array($ns, $next_states, true) ? ' beyan-durum-onerilen' : '' ?>"
                        title="Durumu &quot;<?= h($sv['label']) ?>&quot; olarak ayarla"
                        <?= ($ns === 'red') ? 'onclick="return prompt_note(this)"' : '' ?>><?= h($sv['label']) ?></button>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>
            </div>
        </form>
    </div>
    <?php endif; ?>
</div>

<!-- Numune (v334): Analiz kartının yanında, yalnız doluysa -->
<?php if ($nm_parca): ?>
<div class="beyan-section" data-bf="urun">
    <div class="beyan-section-title" data-ic="tup"><span class="bf-emoji" aria-hidden="true">🧪</span> Numune</div>
    <div class="bv-not"><?= implode('<br>', array_map('h', $nm_parca)) ?></div>
</div>
<?php endif; ?>
</div>

<!-- Not (v332) -->
<?php if (trim((string)($beyan['note'] ?? '')) !== ''): ?>
<div class="beyan-section" data-bf="uyari">
    <div class="beyan-section-title" data-ic="kalem"><span class="bf-emoji" aria-hidden="true">📝</span> Not</div>
    <div class="bv-not" style="white-space:pre-wrap"><?= h((string)$beyan['note']) ?></div>
</div>
<?php endif; ?>

<!-- 5. WhatsApp Ham Metni (collapsible) -->
<?php if ($beyan['raw_text']): ?>
<div class="beyan-section bv-katla" data-bf="wa">
    <button type="button" class="beyan-collapsible-toggle" data-ic="mesaj" onclick="toggleCollapsible(this)">
        📱 WhatsApp Ham Metni <span>▶</span>
    </button>
    <div class="beyan-collapsible-body" style="display:none">
<?= nl2br(h($beyan['raw_text'])) ?>
    </div>
</div>
<?php endif; ?>

<!-- 6. Eşleşmeyen Satırlar (collapsible) -->
<?php if ($beyan['unmatched_text']): ?>
<div class="beyan-section bv-katla" data-bf="uyari">
    <button type="button" class="beyan-collapsible-toggle" data-ic="uyari" onclick="toggleCollapsible(this)">
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
<div class="beyan-section" data-bf="baglanti">
    <div class="beyan-section-title" data-ic="baglanti"><span class="bf-emoji" aria-hidden="true">🔗</span> Yükleme Planı Bağlantısı</div>
    <?php if ($linked_record): ?>
    <p>
        <a href="record_view.php?id=<?= (int)$linked_record['id'] ?>" class="btn btn-sm btn-ghost">
            → Yükleme Planını Aç: <?= h($linked_record['parti_no'] ?: '#' . $linked_record['id']) ?>
        </a>
    </p>
    <?php else: ?>
    <p class="muted" style="font-size:.88rem;margin-top:0">Henüz yükleme planına bağlanmadı.</p>
    <?php endif; ?>
    <?php if ($can_match): ?>
    <button type="button" class="btn" id="beyanMatchOpenBtn">🔗 Yükleme Planı ile Eşleştir</button>
    <?php elseif (!$is_deleted && can_beyan('match') && !$linked_record): ?>
    <p class="muted" style="font-size:.82rem">Planla eşleştirmek için yükleme kayıtlarını düzenleme yetkisi gerekir.</p>
    <?php endif; ?>
</div>

</div><!-- /.bv -->

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
    if (!noteInput) {
        noteInput = document.createElement('input');
        noteInput.type = 'hidden';
        noteInput.name = 'analysis_note';
        form.appendChild(noteInput);
    }
    noteInput.value = note.trim();
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
      <form method="get" class="beyan-match-search" style="display:flex;gap:8px;flex-wrap:wrap">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="match_open" value="1">
        <label class="form-label" for="beyanMatchSearch">Tüm tarihlerde plan ara</label>
        <input type="search" name="match_q" id="beyanMatchSearch" class="form-control"
               value="<?= h($match_q) ?>" placeholder="Firma, ürün, parti no, alıcı ara…">
        <button class="btn">Ara</button>
      </form>
      <p class="muted"><?= $match_total ?> uygun plan · Sayfa <?= $match_page ?> / <?= $match_pages ?></p>

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

      <?php if ($match_pages > 1): ?>
      <nav class="beyan-match-footer" aria-label="Yükleme planı sayfaları">
        <?php if ($match_page > 1): ?><a class="btn btn-sm" href="<?= h('beyan_view.php?' . http_build_query(['id' => $id, 'match_open' => 1, 'match_q' => $match_q, 'match_page' => $match_page - 1])) ?>">← Önceki</a><?php endif; ?>
        <?php if ($match_page < $match_pages): ?><a class="btn btn-sm" href="<?= h('beyan_view.php?' . http_build_query(['id' => $id, 'match_open' => 1, 'match_q' => $match_q, 'match_page' => $match_page + 1])) ?>">Sonraki →</a><?php endif; ?>
      </nav>
      <?php endif; ?>
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
    <?php if (!empty($_GET['match_open'])): ?>openModal();<?php endif; ?>
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancel1)  cancel1.addEventListener('click', closeModal);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !overlay.hidden) closeModal();
    });

    // Arama GET formuyla sunucuda yapılır; yalnız görünür sayfayı süzmez.

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
             'Beyandaki Gümrük Adı, yükleme planının Gümrük alanına aktarılır.']
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

<?php if ($hks_eslestir): ?>
<script>
(function () {
    var btn = document.getElementById('hksEslestirBtn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        btn.disabled = true;
        var onceki = btn.textContent;
        btn.textContent = 'Eşleştiriliyor…';
        fetch('api_beyan_bildirim.php?action=eslestir', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ beyan_id: <?= (int)$id ?>, csrf: <?= json_encode(csrf_token()) ?> })
        })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (res) {
            if (!res.ok) {
                alert('HATA: ' + (res.j.hata || 'Eşleştirilemedi.'));
                btn.disabled = false; btn.textContent = onceki;
                return;
            }
            if (!res.j.tam) alert(res.j.mesaj);
            location.reload();
        })
        .catch(function (e) {
            alert('Bağlantı hatası: ' + e.message);
            btn.disabled = false; btn.textContent = onceki;
        });
    });
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
            // Taslak hazır — doğrudan Hal Kayıt taslaklar ekranına git.
            location.href = 'halkayit/index.php?ekran=taslaklar';
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

<script>
(function () {
    var picker = document.querySelector('[data-beyan-status-picker]');
    if (!picker) return;
    var trigger = picker.querySelector('[data-status-trigger]');
    var options = picker.querySelector('[data-status-menu]');
    var desktop = window.matchMedia('(min-width: 1024px)');
    var buttons = options.querySelectorAll('button[name="status"]');
    var closeTimer = null;
    var keyboardFocus = false;
    Array.prototype.forEach.call(buttons, function (button, index) {
        button.style.setProperty('--card-order', index);
    });
    function cancelClose() {
        window.clearTimeout(closeTimer);
        closeTimer = null;
    }
    function setOpen(open) {
        cancelClose();
        open = open && desktop.matches;
        var wasOpen = picker.classList.contains('is-open');
        picker.classList.toggle('is-open', open);
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        options.inert = desktop.matches && !open;
        if (desktop.matches) options.setAttribute('aria-hidden', open ? 'false' : 'true');
        else options.removeAttribute('aria-hidden');
        // Focusing a scrolled option must not resize/reposition it between
        // pointerdown and pointerup, otherwise its click misses the button.
        if (open && !wasOpen) fitOptions();
    }
    function fitOptions() {
        if (!desktop.matches) return;
        var bounds = picker.getBoundingClientRect();
        var triggerBounds = trigger.getBoundingClientRect();
        var left = triggerBounds.right - bounds.left;
        options.style.left = left + 'px';
        options.style.width = Math.max(0, Math.min(bounds.right, window.innerWidth - 20) - triggerBounds.right) + 'px';
    }
    function scheduleClose() {
        cancelClose();
        if (!desktop.matches || picker.matches(':hover') || (keyboardFocus && picker.contains(document.activeElement))) return;
        closeTimer = window.setTimeout(function () { setOpen(false); }, 3000);
    }
    picker.classList.add('is-enhanced');
    setOpen(false);
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Tab') keyboardFocus = true;
    });
    document.addEventListener('pointerdown', function () { keyboardFocus = false; });
    picker.addEventListener('mouseenter', function () { setOpen(true); });
    picker.addEventListener('mouseleave', scheduleClose);
    picker.addEventListener('pointerdown', function () { keyboardFocus = false; });
    trigger.addEventListener('click', function () {
        setOpen(true);
    });
    picker.addEventListener('focusin', function () { setOpen(true); });
    picker.addEventListener('focusout', function (event) {
        if (!picker.contains(event.relatedTarget)) { keyboardFocus = false; scheduleClose(); }
    });
    picker.addEventListener('keydown', function (event) {
        if (!desktop.matches) return;
        keyboardFocus = true;
        if (event.key === 'Escape') {
            event.preventDefault();
            trigger.focus();
            setOpen(false);
        } else if (['ArrowDown', 'ArrowUp', 'ArrowRight', 'ArrowLeft'].indexOf(event.key) !== -1) {
            event.preventDefault();
            setOpen(true);
            var index = Array.prototype.indexOf.call(buttons, document.activeElement);
            index = event.key === 'ArrowDown' || event.key === 'ArrowRight' ? (index + 1) % buttons.length : (index <= 0 ? buttons.length : index) - 1;
            if (buttons[index]) buttons[index].focus();
        }
    });
    document.addEventListener('click', function (event) {
        if (!picker.contains(event.target)) { keyboardFocus = false; setOpen(false); }
    });
    function reset() { keyboardFocus = false; setOpen(false); options.style.left = ''; options.style.width = ''; }
    if (desktop.addEventListener) desktop.addEventListener('change', reset);
    else desktop.addListener(reset);
    window.addEventListener('resize', fitOptions);
    window.addEventListener('scroll', fitOptions, { passive: true });
})();
</script>

<script>
(function () {
    var btn = document.getElementById('waKopyalaBtn');
    var kaynak = document.getElementById('waMetin');
    if (!btn || !kaynak) return;
    var metin = JSON.parse(kaynak.textContent);
    var etiket = btn.textContent;
    function bildir(ok) {
        btn.textContent = ok ? '✓ Kopyalandı' : '✗ Kopyalanamadı';
        setTimeout(function () { btn.textContent = etiket; }, 1800);
    }
    function yedek() {
        var ta = document.createElement('textarea');
        ta.value = metin; ta.setAttribute('readonly', '');
        ta.style.cssText = 'position:fixed;left:-9999px;top:0;font-size:16px';
        document.body.appendChild(ta); ta.select();
        var ok = false;
        try { ok = document.execCommand('copy'); } catch (e) {}
        document.body.removeChild(ta);
        return ok;
    }
    btn.addEventListener('click', function () {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(metin).then(function () { bildir(true); }, function () { bildir(yedek()); });
        } else {
            bildir(yedek());
        }
    });
})();
</script>

<?php render_footer(); ?>
