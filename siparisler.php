<?php
// =========================================================
// siparisler.php — Sevkiyat (sipariş) listesi: KPI çipleri, filtreler, açık / kapanan bölümler,
// belge yükleme kartı, eşleşmemiş belgeler. Salt okunur sayfa; yazan uçlar siparis_*.php.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/siparis_eslestir.php';
require_once __DIR__ . '/config/siparis_beyan.php';
require_once __DIR__ . '/config/siparis_mail.php';
require_once __DIR__ . '/_siparis_ortak.php';
$auth_user = require_login();
require_siparis('read');
$pdo   = db();
$hazir = siparis_sema_hazir($pdo);

render_header('Siparişler');
siparis_assets();
render_flash();
if (!$hazir) {
    echo '<div class="sip-mod">' . siparis_kurulmadi_html() . '</div>';
    render_footer();
    exit;
}

$yaz = can_siparis('write');
$sil = can_siparis('delete');

// ── filtreler (v382: liste + yazdırma ORTAK — config/siparis_core.php siparis_liste_filtre) ──
$F = siparis_liste_filtre($_GET);
$q = $F['q']; $f_durum = $F['f_durum']; $f_cw = $F['f_cw']; $f_alici = $F['f_alici']; $f_eksik = $F['f_eksik']; $f_catisma = $F['f_catisma']; $f_po = $F['f_po'];
$t_bas = $F['t_bas']; $t_bit = $F['t_bit'];
$acik_page  = max(1, (int)($_GET['acik_page'] ?? 1));
$kapali_page = max(1, (int)($_GET['kapali_page'] ?? 1));
$durumlar   = siparis_durumlar();
$kapaliDur  = siparis_kapali_durumlar();
$dsql = $F['dsql']; $dprm = $F['dprm'];
$kapaliSql = $F['kapaliSql']; $acikSql = $F['acikSql'];
$temelWhere = $F['temelWhere']; $temelPar = $F['temelPar'];
$tek = $F['tek']; $tekSql = $F['tekSql']; $bolumAcik = $F['bolumAcik']; $bolumKapali = $F['bolumKapali'];
$detayFiltre = $F['detayFiltre']; $filtreVar = $F['filtreVar']; $detayOzet = $F['detayOzet'];

$cols = 's.id, s.order_ref, s.tur_no, s.navlun_no, s.po_belge_id, s.cw_hafta, s.cw_yil, s.alici_kodu, s.alici_adi, s.yukleme_tarihi, s.plakalar, s.planlanan_plakalar, '
      . 's.nakliyeci, s.planlanan_nakliyeci, s.toplam_palet, s.toplam_koli, s.var_po, s.var_ll, s.var_pc, s.var_pkl, s.acik_catisma, s.uyari_sayisi, s.durum, s.depo'
      . (siparis_yurtici_gumruk_hazir($pdo) ? ', s.yurtici_gumruk' : '');   // v387: listede YALNIZ yurt içi gümrük (PO gümrüğü yurt dışıdır)
$bolum = function (string $ekSql, string $siralama, int $sayfa, int $per) use ($pdo, $temelWhere, $temelPar, $cols): array {
    $w = $temelWhere . ($ekSql !== '' ? ' AND ' . $ekSql : '');
    $c = $pdo->prepare('SELECT COUNT(*) FROM siparis_sevkiyatlar s WHERE ' . $w);
    $c->execute($temelPar);
    $toplam = (int)$c->fetchColumn();
    $sayfaSay = max(1, (int)ceil($toplam / $per));
    $sayfa = min($sayfa, $sayfaSay);
    $st = $pdo->prepare('SELECT ' . $cols . ' FROM siparis_sevkiyatlar s WHERE ' . $w . ' ORDER BY ' . $siralama . ' LIMIT ' . (int)$per . ' OFFSET ' . (int)(($sayfa - 1) * $per));
    $st->execute($temelPar);
    return ['rows' => $st->fetchAll(PDO::FETCH_ASSOC), 'toplam' => $toplam, 'sayfa' => $sayfa, 'sayfa_say' => $sayfaSay];
};
$acikSira = 's.yukleme_tarihi IS NULL, s.yukleme_tarihi, s.id';
$B_acik = $B_kapali = $B_tek = null;
if ($tek) $B_tek = $bolum($tekSql, $acikSira, $acik_page, 100);
else {
    if ($bolumAcik)   $B_acik   = $bolum($acikSql, $acikSira, $acik_page, 100);
    if ($bolumKapali) $B_kapali = $bolum($kapaliSql, 's.yukleme_tarihi DESC, s.id DESC', $kapali_page, SIPARIS_PER_PAGE);
}

// ── Liste zenginleştirme: listelenen TÜM sevkiyatlar için 2 ek sorgu (N+1 yok) ──
//    ürün kalemleri (Article specification / palet / PF) + aktif bağlı beyanın parti no'su
$listeIds = [];
foreach ([$B_acik, $B_kapali, $B_tek] as $__b) { if ($__b !== null) foreach ($__b['rows'] as $__r) $listeIds[] = (int)$__r['id']; }
$kalemHarita = [];
if ($listeIds) {
    try {
        $kq = $pdo->prepare('SELECT sevkiyat_id, kalibre, sira, urun, aciklama, cesit, koli_icerik, pf, po_palet, etkin_palet, etkin_koli FROM siparis_kalemler
            WHERE sevkiyat_id IN (' . implode(',', array_fill(0, count($listeIds), '?')) . ') ORDER BY sevkiyat_id, sira, (kalibre + 0), kalibre, id');
        $kq->execute($listeIds);
        foreach ($kq->fetchAll(PDO::FETCH_ASSOC) as $__k) $kalemHarita[(int)$__k['sevkiyat_id']][] = $__k;
    } catch (PDOException $e) {
        error_log('[siparisler] kalemler: ' . $e->getMessage());
    }
}
$partiHarita = siparis_beyan_parti_haritasi($pdo, $listeIds);

// ── KPI (yalnız depo süzgeci; açık sevkiyatlar) ─────────
$kpi = ['acik' => 0, 'arac' => 0, 'yolda' => 0, 'pkl' => 0, 'mail' => 0, 'catisma' => 0, 'po' => 0];
try {
    $k = $pdo->prepare("SELECT COUNT(*) AS acik,
        COALESCE(SUM(CASE WHEN s.durum IN ('siparis_alindi','hazirlik') THEN 1 ELSE 0 END),0) AS arac,
        COALESCE(SUM(CASE WHEN s.durum = 'yola_cikti' THEN 1 ELSE 0 END),0) AS yolda,
        COALESCE(SUM(CASE WHEN s.durum = 'yola_cikti' AND s.var_pkl = 0 THEN 1 ELSE 0 END),0) AS pkl,
        COALESCE(SUM(CASE WHEN s.durum = 'pkl_hazir' THEN 1 ELSE 0 END),0) AS mail,
        COALESCE(SUM(CASE WHEN s.acik_catisma > 0 THEN 1 ELSE 0 END),0) AS catisma,
        COALESCE(SUM(CASE WHEN s.var_po = 0 THEN 1 ELSE 0 END),0) AS po
        FROM siparis_sevkiyatlar s WHERE s.deleted_at IS NULL AND " . $acikSql . $dsql);
    $k->execute($dprm);
    $kpi = array_map('intval', $k->fetch(PDO::FETCH_ASSOC) ?: $kpi);
} catch (PDOException $e) {
    error_log('[siparisler] kpi: ' . $e->getMessage());
}
$kapaliSay = 0;
try {
    $c = $pdo->prepare('SELECT COUNT(*) FROM siparis_sevkiyatlar s WHERE s.deleted_at IS NULL AND ' . $kapaliSql . $dsql);
    $c->execute($dprm);
    $kapaliSay = (int)$c->fetchColumn();
} catch (PDOException $e) {
    error_log('[siparisler] kapali say: ' . $e->getMessage());
}

// alıcı listesi
$aliciler = [];
try {
    $a = $pdo->prepare("SELECT DISTINCT s.alici_kodu, s.alici_adi FROM siparis_sevkiyatlar s
        WHERE s.deleted_at IS NULL AND s.alici_kodu IS NOT NULL AND s.alici_kodu <> ''" . $dsql . ' ORDER BY s.alici_adi LIMIT 200');
    $a->execute($dprm);
    $aliciler = $a->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[siparisler] alici: ' . $e->getMessage());
}

// eşleşmemiş / hatalı belgeler (yalnız yazma yetkisi olana işlem düğmeleri)
$yetim = [];
try {
    [$bds, $bdp] = depo_sql_column('b.depo', ':udb');
    $y = $pdo->prepare("SELECT b.id, b.tur, b.durum, b.orijinal_ad, b.hata_mesaj, b.boyut, b.created_at, b.disk_adi FROM siparis_belgeler b
        WHERE b.deleted_at IS NULL AND (b.durum = 'hata' OR NOT EXISTS (SELECT 1 FROM siparis_belge_bag bb WHERE bb.belge_id = b.id))" . $bds . ' ORDER BY b.id DESC LIMIT 20');
    $y->execute($bdp);
    $yetim = $y->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('[siparisler] yetim: ' . $e->getMessage());
}
// bağlama penceresi için açık sevkiyatlar (yalnız yazma)
$acikListe = [];
if ($yaz && $yetim) {
    try {
        $z = $pdo->prepare('SELECT s.id, s.order_ref, s.alici_adi FROM siparis_sevkiyatlar s WHERE s.deleted_at IS NULL AND ' . $acikSql . $dsql . ' ORDER BY s.order_ref LIMIT 300');
        $z->execute($dprm);
        $acikListe = $z->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('[siparisler] acik liste: ' . $e->getMessage());
    }
}

// yükleme sonucu (tek seferlik)
$yuklemeSonuc = [];
if (isset($_SESSION['siparis_yukleme']) && is_array($_SESSION['siparis_yukleme'])) {
    $yuklemeSonuc = $_SESSION['siparis_yukleme'];
    unset($_SESSION['siparis_yukleme']);
}

// v378 — "📧 Mailden al": yalnız siparis.write + mail.read olana; kurulu değilse düğme HİÇ çizilmez. Salt okunur (yazan uç: siparis_mail.php).
// Son sonuçlar yalnız AKTİF DEPODA yapılmış sorgulardan gösterilir; gönderen / konu yalnız izleyenin görünür posta kutularından okunur.
$mailBlok = '';
$mD = []; $mSon = [];
if ($yaz && siparis_mail_yetkisi()) {
    $mUid = (int)$auth_user['id']; $mDepo = (string)(active_depot() ?? '');
    $mHazir = siparis_mail_hazir($pdo);
    $mD = ['hazir' => $mHazir, 'mail_admin' => can_mail('admin')];
    if ($mHazir) {
        $mAyar = siparis_mail_ayar_oku($pdo);
        $mD['ayarli'] = [];
        foreach (array_keys(siparis_mail_turleri()) as $mt) $mD['ayarli'][$mt] = siparis_mail_ayarli_mi($mAyar, $mt);
        $mD['ayar_link'] = siparis_mail_ayar_yetkisi();
        $mY = siparis_mail_yarim($pdo, $mUid);
        $mD['yarim'] = $mY !== null && $mY['depo'] === $mDepo ? $mY : null;
        $mGor = mail_gorunur_hesap_idleri($mUid, $pdo);
        $mSon = [];
        foreach (array_keys(siparis_mail_turleri()) as $mt) {
            $mx = siparis_mail_son($pdo, $mt);
            if ($mx !== null && (string)($mx['depo'] ?? '') === $mDepo) $mSon[] = ['tur' => $mt, 'bitis' => (int)($mx['bitis'] ?? 0), 'sayac' => (array)($mx['sayac'] ?? []), 'html' => sipui_mail_sonuc_html($pdo, $mx, $mGor)];
        }
        usort($mSon, static fn(array $a, array $b): int => $b['bitis'] <=> $a['bitis']);
        $mD['son'] = $mSon;
    }
    $mailBlok = sipui_mail_blok_html($mD);
}
// v382 — yükleme kartı native <details>: varsayılan KAPALI; yalnız yarım kalan mail taraması varsa sunucu açık yazar ("Devam et" şeridi gizlenmesin).
$yukleAcik = !empty($mD['yarim']);

// KPI çipi: etkin mi?
$chipler = [
    ['k' => 'acik',    'et' => 'Açık',          'say' => $kpi['acik'],    'url' => siparis_url(['durum' => 'acik', 'eksik' => null, 'catisma' => null, 'po' => null, 'acik_page' => null, 'kapali_page' => null]),
        'aktif' => $f_durum === 'acik' && $f_eksik === '' && !$f_catisma],
    ['k' => 'arac',    'et' => 'Araç bekleyen', 'say' => $kpi['arac'],    'url' => siparis_url(['durum' => 'arac_bekleyen', 'eksik' => null, 'catisma' => null, 'po' => null, 'acik_page' => null, 'kapali_page' => null]),
        'aktif' => $f_durum === 'arac_bekleyen'],
    ['k' => 'yolda',   'et' => 'Yolda',         'say' => $kpi['yolda'],   'url' => siparis_url(['durum' => 'yola_cikti', 'eksik' => null, 'catisma' => null, 'po' => null, 'acik_page' => null, 'kapali_page' => null]),
        'aktif' => $f_durum === 'yola_cikti' && $f_eksik === ''],
    ['k' => 'pkl',     'et' => 'PKL bekleyen',  'say' => $kpi['pkl'],     'url' => siparis_url(['durum' => 'yola_cikti', 'eksik' => 'pkl', 'catisma' => null, 'po' => null, 'acik_page' => null, 'kapali_page' => null]),
        'aktif' => $f_durum === 'yola_cikti' && $f_eksik === 'pkl'],
    ['k' => 'mail',    'et' => 'Mail bekleyen', 'say' => $kpi['mail'],    'url' => siparis_url(['durum' => 'pkl_hazir', 'eksik' => null, 'catisma' => null, 'po' => null, 'acik_page' => null, 'kapali_page' => null]),
        'aktif' => $f_durum === 'pkl_hazir'],
    ['k' => 'catisma', 'et' => 'Çatışmalı',     'say' => $kpi['catisma'], 'url' => siparis_url(['durum' => null, 'eksik' => null, 'catisma' => '1', 'po' => null, 'acik_page' => null, 'kapali_page' => null]),
        'aktif' => $f_catisma],
    ['k' => 'po',      'et' => 'PO bekleyen',   'say' => $kpi['po'],      'url' => siparis_url(['durum' => 'acik', 'eksik' => 'po', 'catisma' => null, 'po' => null, 'acik_page' => null, 'kapali_page' => null]),
        'aktif' => $f_eksik === 'po'],
];

$sayfalama = function (array $b, string $param): string {
    if ($b['sayfa_say'] <= 1) return '';
    $o = '<nav class="pagination sip-sayfalama" aria-label="Sayfalama">';
    $o .= $b['sayfa'] > 1 ? '<a class="btn btn-sm" href="' . h(siparis_url([$param => $b['sayfa'] - 1 > 1 ? $b['sayfa'] - 1 : null])) . '">‹ Önceki</a>' : '<span class="btn btn-sm" aria-disabled="true" style="opacity:.45">‹ Önceki</span>';
    $o .= '<span class="muted">Sayfa ' . (int)$b['sayfa'] . ' / ' . (int)$b['sayfa_say'] . '</span>';
    $o .= $b['sayfa'] < $b['sayfa_say'] ? '<a class="btn btn-sm" href="' . h(siparis_url([$param => $b['sayfa'] + 1])) . '">Sonraki ›</a>' : '<span class="btn btn-sm" aria-disabled="true" style="opacity:.45">Sonraki ›</span>';
    return $o . '</nav>';
};
// v390 — Açık ve Kapanan bölümler birbirine benziyordu: Beyanlar'daki gibi iki AYRI kart
// (⏳ İşlem Bekleyen / ✅ Kapanan; sol şerit + ikon + not). Başlık div'i (id) ve içindeki
// düğme düzeni DEĞİŞMEDİ — yazdırma düğmeleri yalnız bekleyen kartın başlığında.
$bolumBas = function (string $baslik, int $say, string $id, string $ek = '', string $ikon = '', string $not = ''): string {
    return '<div class="sip-bolum-bas" id="' . h($id) . '">'
        . ($ikon !== '' ? '<span class="sip-bolum-ikon" aria-hidden="true">' . $ikon . '</span>' : '')
        . '<div class="sip-bolum-baslik"><h2>' . h($baslik) . '</h2><span class="sip-say">' . (int)$say . '</span>'
        . ($not !== '' ? '<span class="muted sip-bolum-not">' . h($not) . '</span>' : '') . '</div>' . $ek . '</div>';
};
// v382 — "Açık Sevkiyatlar" yazdırma / PDF düğmeleri (yalnız Açık bölüm ve tek durum "Sonuçlar" bölümü; bölümde satır varsa; Kapananda YOK).
// Veri siparis_yazdir.php'den (GET JSON) her tıkta taze gelir; aynı filtre anahtarları, sayfa numaraları HARİÇ. İzleyiciye de açık (siparis.read).
$yazdirUrl = siparis_url(['acik_page' => null, 'kapali_page' => null], 'siparis_yazdir.php');
$yazdirEk = '<div class="sip-bolum-eylem no-print">'
    . '<button type="button" class="btn btn-sm" data-sip-yazdir="yazdir" data-sip-yazdir-url="' . h($yazdirUrl) . '">🖨️ Yazdır</button>'
    . '<button type="button" class="btn btn-sm" data-sip-yazdir="pdf" data-sip-yazdir-url="' . h($yazdirUrl) . '">📤 PDF Gönder</button></div>';
?>
<div class="sip-mod">
  <div class="page-head">
    <div>
      <h1>📦 Siparişler</h1>
      <p class="muted"><?= (int)$kpi['acik'] ?> açık · <?= (int)$kapaliSay ?> kapanan sevkiyat</p>
    </div>
    <div class="page-head-actions">
      <?php if ($yaz): ?>
        <a class="btn btn-primary btn-lg" href="#yukle">+ Belge yükle</a>
      <?php else: ?>
        <button type="button" class="btn btn-lg" disabled aria-describedby="sipSaltOkunur">+ Belge yükle</button>
      <?php endif; ?>
      <div class="pc-kebab-wrap">
        <button class="btn pc-kebab" type="button" title="Diğer işlemler" aria-label="Diğer işlemler">⋮</button>
        <div class="pc-dropdown" hidden>
          <?php if ($yaz): ?><button type="button" data-sip-ac="sipYeniModal">➕ Elle sevkiyat aç</button><?php endif; ?>
          <a href="siparis_ayarlar.php">⚙ Ayarlar (sayaçlar, profiller)</a>
        </div>
      </div>
    </div>
  </div>
  <?php if (!$yaz): ?>
    <p class="sip-bilgi" id="sipSaltOkunur" role="note">Salt okunur yetkiniz var: belge yükleme, düzenleme ve durum değiştirme kapalıdır (gerekli yetki: siparis.write).</p>
  <?php endif; ?>

  <?= sipui_yukleme_sonuc_html($yuklemeSonuc) ?>

  <?php if ($yaz): ?>
  <details class="card sip-yukle" id="yukle" data-sip-yukle data-sip-yukle-uid="<?= (int)$auth_user['id'] ?>"<?= $yukleAcik ? ' open' : '' ?>>
    <summary class="sip-yukle-ozet"><?= sipui_yukle_ozet_html($mSon, $mD['yarim'] ?? null, !empty($mD['hazir'])) ?></summary>
    <div class="sip-yukle-govde">
      <?= $mailBlok ?>
      <form method="post" action="siparis_yukle.php" enctype="multipart/form-data" <?= sipui_yukleme_ozellikleri() ?>>
        <?= sipui_csrf() ?>
        <?= sipui_dropzone_html('sipDosyaListe', false) ?>
        <div class="sip-yukle-alt">
          <button type="submit" class="btn btn-primary" data-sip-gonder>Yükle ve eşleştir</button>
          <span class="muted">Belge türü dosya adından değil, içeriğinden anlaşılır.</span>
        </div>
      </form>
    </div>
  </details>
  <?php endif; ?>

  <div class="sip-kpi-kap" aria-label="Hızlı filtreler"><div class="sip-kpi">
    <?php foreach ($chipler as $c): ?>
      <a class="sip-chip<?= $c['aktif'] ? ' sip-chip--aktif' : '' ?><?= $c['k'] === 'catisma' && $c['say'] > 0 ? ' sip-chip--uyari' : '' ?>" href="<?= h($c['url']) ?>"<?= $c['aktif'] ? ' aria-current="true"' : '' ?>>
        <span class="sip-chip-et"><?= h($c['et']) ?></span><span class="sip-chip-say"><?= (int)$c['say'] ?></span>
      </a>
    <?php endforeach; ?>
  </div></div>

  <form method="get" action="siparisler.php" class="rpt-filter-card sip-filtre no-print" role="search">
    <div class="sip-filtre-ana">
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="Sipariş no, tur, navlun, plaka, alıcı, nakliyeci…" aria-label="Ara">
      <button type="submit" class="btn btn-primary">Ara</button>
      <button type="button" class="btn sip-filtre-ac" data-sip-filtre-toggle aria-expanded="<?= $detayFiltre ? 'true' : 'false' ?>" aria-controls="sipFiltrePanel">
        ▾ Filtre<?php if ($detayOzet): ?> <span class="sip-chip-say"><?= h(implode(' · ', $detayOzet)) ?></span><?php endif; ?>
      </button>
      <?php if ($filtreVar): ?><a class="btn btn-ghost" href="siparisler.php">Temizle</a><?php endif; ?>
    </div>
    <div class="sip-filtre-panel<?= $detayFiltre ? ' sip-acik' : '' ?>" id="sipFiltrePanel">
      <label>Durum
        <select name="durum">
          <option value="">Tümü</option>
          <option value="acik"<?= $f_durum === 'acik' ? ' selected' : '' ?>>Açık olanlar</option>
          <option value="kapali"<?= $f_durum === 'kapali' ? ' selected' : '' ?>>Kapananlar</option>
          <option value="arac_bekleyen"<?= $f_durum === 'arac_bekleyen' ? ' selected' : '' ?>>Araç bekleyen</option>
          <?php foreach ($durumlar as $dk => $dv): ?>
            <option value="<?= h($dk) ?>"<?= $f_durum === $dk ? ' selected' : '' ?>><?= h($dv['ikon'] . ' ' . $dv['etiket']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Hafta (CW)
        <input type="text" name="cw" value="<?= h($f_cw) ?>" placeholder="43.2026" inputmode="decimal" maxlength="7">
      </label>
      <label>Alıcı
        <select name="alici">
          <option value="">Tümü</option>
          <?php foreach ($aliciler as $al): ?>
            <option value="<?= h((string)$al['alici_kodu']) ?>"<?= $f_alici === (string)$al['alici_kodu'] ? ' selected' : '' ?>><?= h(trim((string)$al['alici_kodu'] . ' ' . (string)$al['alici_adi'])) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Eksik belge
        <select name="eksik">
          <option value="">—</option>
          <?php foreach (['po' => 'PO yok', 'll' => 'Loading List yok', 'pc' => 'Fiyat onayı (PC) yok', 'pkl' => 'PKL yok'] as $ek => $et): ?>
            <option value="<?= h($ek) ?>"<?= $f_eksik === $ek ? ' selected' : '' ?>><?= h($et) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Yükleme (başlangıç)<input type="date" name="tarih_bas" value="<?= h($t_bas) ?>"></label>
      <label>Yükleme (bitiş)<input type="date" name="tarih_bit" value="<?= h($t_bit) ?>"></label>
      <label class="sip-onay-satir"><input type="checkbox" name="catisma" value="1"<?= $f_catisma ? ' checked' : '' ?>> Yalnız çatışmalı</label>
      <?php if ($f_po > 0): ?><input type="hidden" name="po" value="<?= (int)$f_po ?>"><span class="sip-chip-say">PO #<?= (int)$f_po ?> grubu</span><?php endif; ?>
      <button type="submit" class="btn btn-primary sip-uygula">Uygula</button>
    </div>
  </form>

  <?php
  $bolumCiz = function (array $b, string $baslik, string $id, string $param, string $bos, string $ikon = '', string $not = '') use ($bolumBas, $sayfalama, $yaz, $sil, $kalemHarita, $partiHarita, $yazdirEk): void {
      $ek = in_array($id, ['sipAcik', 'sipSonuclar'], true) && $b['rows'] ? $yazdirEk : '';
      $ton = ['sipAcik' => ' sip-bolum-acik', 'sipKapali' => ' sip-bolum-kapali'][$id] ?? '';
      echo '<section class="sip-bolum' . $ton . '" aria-labelledby="' . h($id) . '">' . $bolumBas($baslik, $b['toplam'], $id, $ek, $ikon, $not);
      if (!$b['rows']) {
          echo '<div class="empty sip-bos"><p>' . h($bos) . '</p></div>';
      } else {
          $sec_rows = $b['rows']; $sec_kalem = $kalemHarita; $sec_parti = $partiHarita;
          include __DIR__ . '/_siparis_liste.php';
          echo $sayfalama($b, $param);
      }
      echo '</section>';
  };
  if ($tek) $bolumCiz($B_tek, 'Sonuçlar', 'sipSonuclar', 'acik_page', 'Bu filtreyle eşleşen sevkiyat yok.');
  if ($B_acik !== null) $bolumCiz($B_acik, 'İşlem Bekleyen Sevkiyatlar', 'sipAcik', 'acik_page', $filtreVar ? 'Bu filtreyle eşleşen açık sevkiyat yok.' : ($yaz ? 'Henüz açık sevkiyat yok. Yukarıdan PO, Loading List ya da Price Confirmation PDF\'i yükleyin.' : 'Henüz açık sevkiyat yok.'), '⏳', 'Araç, belge, PKL ya da mail bekleyen açık işler');
  if ($B_kapali !== null) $bolumCiz($B_kapali, 'Kapanan Sevkiyatlar', 'sipKapali', 'kapali_page', 'Kapanan sevkiyat yok.', '✅', 'Tamamlanan kayıtlar (mail gönderildi · iptal)');
  ?>

  <?php if ($yetim): ?>
  <details class="card sip-yetim" id="yetim"<?= $yetim && count(array_filter($yetim, fn($b) => $b['durum'] === 'hata')) > 0 ? ' open' : '' ?>>
    <summary><strong>Eşleşmemiş / hatalı belgeler</strong> <span class="sip-say"><?= count($yetim) ?></span></summary>
    <ul class="sip-yetim-liste">
      <?php foreach ($yetim as $b): ?>
        <li>
          <div class="sip-yetim-bilgi">
            <strong class="sip-kes" title="<?= h((string)$b['orijinal_ad']) ?>"><?= h((string)$b['orijinal_ad']) ?></strong>
            <?= sipui_tur_rozeti((string)$b['tur']) ?>
            <span class="sip-soluk"><?= h(sipui_zaman((string)$b['created_at'])) ?> · <?= h(sipui_boyut((int)$b['boyut'])) ?></span>
            <?php if ($b['durum'] === 'hata'): ?><span class="sip-hata-metin">Hata: <?= h((string)($b['hata_mesaj'] ?? 'işlenemedi')) ?></span>
            <?php else: ?><span class="sip-soluk">Hiçbir sevkiyata bağlı değil.</span><?php endif; ?>
          </div>
          <div class="sip-yetim-eylem">
            <a class="btn btn-sm" href="siparis_belge.php?id=<?= (int)$b['id'] ?>" data-sip-onizle data-id="<?= (int)$b['id'] ?>">Önizle</a>
            <?php if ($yaz): ?>
              <button type="button" class="btn btn-sm" data-sip-ac="sipBaglaModal" data-sip-doldur="<?= sipui_json_attr(['belge_id' => (int)$b['id'], '_yazi:belge_ad' => (string)$b['orijinal_ad']]) ?>">Sevkiyata bağla</button>
              <form method="post" action="siparis_kaydet.php" class="sip-satir-form">
                <?= sipui_csrf() ?><input type="hidden" name="islem" value="yeniden_isle"><input type="hidden" name="belge_id" value="<?= (int)$b['id'] ?>">
                <button type="submit" class="btn btn-sm">Yeniden işle</button>
              </form>
            <?php endif; ?>
            <?php if ($sil): ?>
              <form method="post" action="siparis_kaydet.php" class="sip-satir-form" data-sip-onay="Bu belge silinsin mi? Uygulanmış değerler geri alınmaz.">
                <?= sipui_csrf() ?><input type="hidden" name="islem" value="belge_sil"><input type="hidden" name="belge_id" value="<?= (int)$b['id'] ?>">
                <button type="submit" class="btn btn-sm btn-danger">Sil</button>
              </form>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
  </details>
  <?php endif; ?>

  <?php if ($yaz): ?>
  <div class="pm-overlay" id="sipYeniModal" hidden role="dialog" aria-modal="true" aria-labelledby="sipYeniBaslik">
    <div class="pm-dialog">
      <form method="post" action="siparis_kaydet.php" data-sip-tek>
        <div class="pm-header"><h2 class="pm-title" id="sipYeniBaslik">Elle sevkiyat aç</h2><button type="button" class="pm-close" data-sip-kapat aria-label="Kapat">✕</button></div>
        <div class="pm-body sip-form">
          <?= sipui_csrf() ?><input type="hidden" name="islem" value="yeni">
          <label>Sipariş numarası (order ref)<input type="text" name="order_ref" required maxlength="30" placeholder="TC426865.10" autocapitalize="characters" autocomplete="off" pattern="[A-Za-z]{1,3}[0-9]{5,}\.[0-9]{1,3}"></label>
          <label>Tur numarası <span class="muted">(isteğe bağlı)</span><input type="text" name="tur_no" maxlength="30" autocomplete="off"></label>
          <label>Yükleme tarihi <span class="muted">(isteğe bağlı)</span><input type="date" name="yukleme_tarihi"></label>
          <p class="muted">PO sonradan yüklenirse aynı sipariş numarasıyla otomatik birleşir.</p>
        </div>
        <div class="pm-footer"><button type="button" class="btn" data-sip-kapat>Vazgeç</button><button type="submit" class="btn btn-primary">Sevkiyatı aç</button></div>
      </form>
    </div>
  </div>
  <div class="pm-overlay" id="sipBaglaModal" hidden role="dialog" aria-modal="true" aria-labelledby="sipBaglaBaslik">
    <div class="pm-dialog">
      <form method="post" action="siparis_kaydet.php" data-sip-tek>
        <div class="pm-header"><h2 class="pm-title" id="sipBaglaBaslik">Belgeyi sevkiyata bağla</h2><button type="button" class="pm-close" data-sip-kapat aria-label="Kapat">✕</button></div>
        <div class="pm-body sip-form">
          <?= sipui_csrf() ?><input type="hidden" name="islem" value="belge_bagla"><input type="hidden" name="belge_id" value="">
          <p><strong data-sip-yazi="belge_ad"></strong></p>
          <label>Sevkiyat
            <select name="sevkiyat_id" required>
              <option value="">Seçin…</option>
              <?php foreach ($acikListe as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h((string)$s['order_ref'] . ($s['alici_adi'] ? ' · ' . $s['alici_adi'] : '')) ?></option><?php endforeach; ?>
            </select>
          </label>
          <p class="muted">Belge önce içeriğine göre işlenir; içeriği başka bir sevkiyata aitse oraya bağlanır ve size söylenir.</p>
        </div>
        <div class="pm-footer"><button type="button" class="btn" data-sip-kapat>Vazgeç</button><button type="submit" class="btn btn-primary">Bağla</button></div>
      </form>
    </div>
  </div>
  <?php endif; ?>
  <?= sipui_onizle_modal_html() ?>
</div>
<?php
siparis_scripts();
echo '<script src="assets/siparis_yazdir.js?v=' . (int)@filemtime(__DIR__ . '/assets/siparis_yazdir.js') . '" defer></script>' . "\n";
echo '<script src="assets/siparis_wp.js?v=' . (int)@filemtime(__DIR__ . '/assets/siparis_wp.js') . '" defer></script>' . "\n";
render_footer();
