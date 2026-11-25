<?php
// =========================================================
// index.php - Ana Sayfa / Dashboard
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
// 'dashboard.read' yoksa 403 BASMA: giriş akışı buraya düşer (login →
// depo_sec → index.php) ve kullanıcı sistemi hiç kullanamazdı. Bunun yerine
// gerçekten açabildiği ilk sayfaya yolla; hiçbiri yoksa anlaşılır bir mesaj
// ver (yetkisiz rol atanmış demektir).
if (!can('dashboard.read')) {
    $ilk_sayfa = first_allowed_page();
    if ($ilk_sayfa !== null) {
        header('Location: ' . base_url() . $ilk_sayfa);
        exit;
    }
    forbidden('Hesabınıza henüz hiçbir sayfa için yetki tanımlanmamış. Lütfen sistem yöneticinize başvurun.');
}

// ── DB-Backup-01: Admin girişinde günlük otomatik yedek ───
$db_backup_result = null;
$db_backup_eski   = null;   // DB-Backup-02: son başarılı yedek yok/eski ise uyarı metni
if (is_admin()) {
    require_once __DIR__ . '/config/db_backup_helpers.php';
    try {
        $db_backup_result = create_daily_backup_if_needed(db(), (int)($auth_user['id'] ?? 0));
    } catch (Throwable $_bkp_err) {
        // backup hatası ana sayfayı çökertmemeli
    }
    if ($db_backup_result === null) {
        try {
            $_bkp_son = last_successful_backup(db());
            $_bkp_yas = _bh_last_age_hours($_bkp_son);
            if ($_bkp_son === null) {
                $db_backup_eski = 'Henüz başarılı bir veritabanı yedeği yok';
            } elseif ($_bkp_yas !== null && $_bkp_yas > DB_BACKUP_STALE_SAAT) {
                $db_backup_eski = 'Son başarılı veritabanı yedeği ' . _bh_yas_metni($_bkp_yas) . ' önce';
            }
        } catch (Throwable $_bkp_err) {}
    }
}

// Temel sayaçlar — aktif depo kapsamında
[$_ds_ix, $_ds_ix_v] = depo_sql_records_in('loading_records');
$_ds_ix_and = $_ds_ix !== '' ? " AND $_ds_ix" : '';
$_st_ix = db()->prepare("
    SELECT
        (SELECT COUNT(*) FROM loading_records WHERE type='yukleme'{$_ds_ix_and}) AS toplam_kayit,
        (SELECT COUNT(*) FROM loading_records WHERE type='cikma'{$_ds_ix_and})   AS toplam_cikma,
        (SELECT COUNT(*) FROM material_definitions WHERE is_active=1) AS tanim_sayisi
");
$_st_ix->execute(array_merge($_ds_ix_v, $_ds_ix_v));
$stats = $_st_ix->fetch();

// Hal Kayıt bekleyen taslak sayısı (yeni halkayit paneli — hks_taslaklar tablosu)
try {
    $hks_taslak = (int)db()->query("SELECT COUNT(*) FROM hks_taslaklar")->fetchColumn();
} catch (PDOException $e) { $hks_taslak = 0; }

// Bildirim bekleyen beyan sayısı — beyan_view'daki buton kapısının SQL karşılığı:
// plaka dolu + eşleştirme tam + net KG > 0 + durum uygun + aktif bağ yok.
// Kapı mantığı iki yerde; birini değiştirirken diğerini de güncelle.
$beyan_bildirim_bekleyen = 0;
if (can_beyan('notify') && can_beyan('hks.write') && (can('records.write') || is_admin())) {
    try {
        $__bd = implode(',', array_fill(0, count(beyan_hks_uygun_durumlar()), '?'));
        $__st = db()->prepare(
            "SELECT COUNT(*) FROM customs_declarations d
             WHERE d.deleted_at IS NULL
               AND COALESCE(d.vehicle_plate, '') <> ''
               AND COALESCE(d.hks_firma_id, '') <> ''
               AND COALESCE(d.hks_urun_id,  '') <> ''
               AND COALESCE(d.hks_ulke_id,  '') <> ''
               AND COALESCE(d.net_kg, 0) > 0
               AND d.status IN ($__bd)
               AND NOT EXISTS (SELECT 1 FROM beyan_hks_bildirim b
                               WHERE b.beyan_id = d.id AND b.durum IN (" . beyan_hks_aktif_durumlar_sql() . "))"
        );
        $__st->execute(beyan_hks_uygun_durumlar());
        $beyan_bildirim_bekleyen = (int)$__st->fetchColumn();
    } catch (PDOException $e) { $beyan_bildirim_bekleyen = 0; }   // tablo/kolon yoksa pasif
}

// Personel Takibi kartı için: BUGÜNÜN eksik çıkışları (Günlük İşçi modülü) —
// aktif depo kapsamında (uygulamanın "zorunlu tek depo" kuralıyla AYNI).
// Hub erişimi personel/kart sayfalarını da kapsar. Günlük işçi sayısı ise
// yalnız günlük giriş/çıkış veya puantaj sayfasını görebilenlere gösterilir.
// pdks_gunluk_eksik_cikislar() KENDİ SQL'i YAZILMAZ,
// config/pdks_gunluk.php'nin tek paylaşılan fonksiyonu (gunluk_isci_puantaj.php
// İLE AYNI kaynak) çağrılır.
$personel_eksik_cikis = 0;
if (role_pdks_can('daily_scan') || role_pdks_can('daily_reports')) {
    try {
        require_once __DIR__ . '/config/pdks_gunluk.php';
        if (pdks_gunluk_sema_hazir(db())) {
            $personel_eksik_cikis = count(pdks_gunluk_eksik_cikislar(date('Y-m-d'), active_depot() ?? '', null, db()));
        }
    } catch (Throwable $e) { $personel_eksik_cikis = 0; }
}

// Hesap modülü özet — KİŞİSEL: yalnız oturumdaki kullanıcının kayıtları (K3).
// Eskiden şirketin tamamının bugünkü harcaması ve tüm bekleyen kayıt sayısı
// herkese görünüyordu; TRY/USD/EUR da toplanıp "₺" yazılıyordu. Tutar yalnız TRY.
// Yönetici (is_admin / hesap.admin) ek olarak onay bekleyen sayısını görür.
$hesap_bugun = 0.0; $hesap_bekleyen = 0; $hesap_onay_bekleyen = 0;
$hesap_yonetici = is_admin() || can('hesap.admin');
if (can('hesap.read') || is_admin()) {
    try {
        $_hu = (int)(current_user()['id'] ?? 0);
        $_hs = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM account_transactions
            WHERE user_id = ? AND currency = 'TRY' AND transaction_date = CURDATE()
              AND type IN ('gider','nakit','havale') AND status <> 'rejected'");
        $_hs->execute([$_hu]);
        $hesap_bugun = (float)$_hs->fetchColumn();
        $_hs = db()->prepare("SELECT COUNT(*) FROM account_transactions
            WHERE user_id = ? AND status IN ('draft','submitted')");
        $_hs->execute([$_hu]);
        $hesap_bekleyen = (int)$_hs->fetchColumn();
        if ($hesap_yonetici) {
            $hesap_onay_bekleyen = (int)db()->query("SELECT COUNT(*) FROM account_transactions
                WHERE status = 'submitted' AND user_id IS NOT NULL")->fetchColumn();
        }
    } catch (PDOException $e) {
        $hesap_bugun = 0.0; $hesap_bekleyen = 0; $hesap_onay_bekleyen = 0;
    }
}

// Stok özet artık index'te kullanılmıyor (kartlar reports.php'ye taşındı)

// Mail Merkezi özeti — kartın kapısı mail.php'nin kapısıyla AYNI (can_mail). Tablo kurulu
// değilse kart yine görünür (sayfa kurulum durumunu anlatır), yalnız rozet yok.
$mail_okunmamis = 0;
$mail_cevapsiz24 = 0;   // 24 saattir cevap bekleyen mail (needs_reply=1, replied_at boş, ACL kapsamında)
if (can_mail('read')) {
    try {
        $mail_okunmamis = nav_mail_okunmamis();   // kenar çubuğu rozetiyle AYNI sayı (istek başına tek sorgu)
        require_once __DIR__ . '/config/mail_core.php';
        $mail_cevapsiz24 = mail_cevapsiz_eski_sayisi((int)$auth_user['id'], 24);
    } catch (Throwable $e) { $mail_okunmamis = 0; $mail_cevapsiz24 = 0; }
}

// Kullanıcı sayısı — sadece users.admin yetkisiyle hesapla
$kullanici_aktif = 0;
if (can('users.admin')) {
    try { $kullanici_aktif = (int)db()->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn(); } catch (PDOException $e) {}
}

// Audit son 24 saat — sadece admin için
$audit_son24h = 0;
if (is_admin()) {
    try { $audit_son24h = (int)db()->query("SELECT COUNT(*) FROM audit_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn(); } catch (PDOException $e) {}
}

// Bölüm görünürlükleri
// Ana sayfa grup başlıkları, sıralaması, kart sırası ve yetki kapıları TEK kaynak
// olan nav_menu_modeli('') üzerinden sol kenar çubuğu (render_desktop_sidebar) ile birebir üretilir.
$__home_menu = nav_menu_modeli('');

render_header('Ana Sayfa');
render_flash();
// Backup bildirimi (admin, HTML link içerdiği için render_flash() dışında)
// busy: başka bir yedek şu anda çalışıyor — kutu gösterilmez.
if ($db_backup_result !== null && empty($db_backup_result['busy'])): ?>
<div class="flash flash-<?= $db_backup_result['ok'] ? 'success' : 'error' ?>" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <span>
        <?php if ($db_backup_result['ok']): ?>
            🗄️ Bugünün veritabanı yedeği oluşturuldu (<?= h($db_backup_result['method']) ?>).
        <?php elseif (!empty($db_backup_result['file_ok']) && empty($db_backup_result['db_ok'])): ?>
            ⚠️ Yedek dosyası oluşturuldu fakat kayıt tablosuna yazılamadı.
        <?php else: ?>
            ⚠️ Otomatik veritabanı yedeği oluşturulamadı<?= !empty($db_backup_result['error']) ? ': ' . h(mb_substr((string)$db_backup_result['error'], 0, 160)) : '.' ?>
        <?php endif; ?>
    </span>
    <span style="display:flex;gap:8px;flex-wrap:wrap">
        <?php if ($db_backup_result['ok'] && $db_backup_result['id'] > 0): ?>
        <a href="admin_db_backups.php?action=download&id=<?= (int)$db_backup_result['id'] ?>"
           style="font-weight:700;color:inherit;text-decoration:underline">⬇ İndir</a>
        <?php endif; ?>
        <a href="admin_db_backups.php" style="color:inherit;text-decoration:underline">Tüm yedekler</a>
    </span>
</div>
<?php elseif ($db_backup_eski !== null): ?>
<div class="flash flash-error" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
    <span>⚠️ <?= h($db_backup_eski) ?>.</span>
    <a href="admin_db_backups.php" style="color:inherit;text-decoration:underline">Tüm yedekler</a>
</div>
<?php endif; ?>

<style>
/* Dashboard Personel Takibi — örnek görseldeki yeşil giriş / kırmızı çıkış 3D ikonu.
   Yalnız bu kartı etkiler; yetki, PDKS ve diğer iş mantığına dokunmaz. */
.home-card-icon-personel {
    position: relative;
    overflow: hidden;
    background: linear-gradient(145deg, #12633f 0%, #073d2c 58%, #052c22 100%) !important;
}
.home-card-icon-personel::after {
    content: "";
    position: absolute;
    inset: 0;
    border-radius: inherit;
    box-shadow: inset 0 2px 5px rgba(255,255,255,.18), inset 0 -4px 8px rgba(0,0,0,.28);
    pointer-events: none;
}
.personel-arrow {
    position: absolute;
    left: 22%;
    width: 48%;
    height: 18%;
    border-radius: 999px 3px 3px 999px;
    filter: drop-shadow(0 3px 2px rgba(0,0,0,.34));
}
.personel-arrow::after {
    content: "";
    position: absolute;
    top: 50%;
    width: 0;
    height: 0;
    transform: translateY(-50%);
    border-top: 13px solid transparent;
    border-bottom: 13px solid transparent;
}
.personel-arrow-in {
    top: 27%;
    background: linear-gradient(#39ef50, #08c92d);
    box-shadow: inset 0 2px 2px rgba(255,255,255,.38);
}
.personel-arrow-in::after {
    right: -20px;
    border-left: 23px solid #0bd333;
}
.personel-arrow-out {
    bottom: 27%;
    left: 30%;
    background: linear-gradient(#ff5458, #f02028);
    box-shadow: inset 0 2px 2px rgba(255,255,255,.34);
}
.personel-arrow-out::after {
    left: -20px;
    border-right: 23px solid #f4262d;
}
@media (min-width: 900px) {
    .personel-arrow::after {
        border-top-width: 9px;
        border-bottom-width: 9px;
    }
    .personel-arrow-in::after {
        right: -13px;
        border-left-width: 15px;
    }
    .personel-arrow-out::after {
        left: -13px;
        border-right-width: 15px;
    }
}
</style>

<?php
$__home_bg = [
    'siparisler.php'       => '#fdf0dc',
    'frugro_siparis.php'   => '#fdf0dc',
    'urun_siparis.php'     => '#fff0e2',
    'beyanlar.php'         => '#ede9fe',
    'halkayit/index.php'   => '#e8f0fe',
    'mail.php'             => '#e0f4f9',
    'reports.php'          => '#faf0ff',
    'hizli_maliyet.php'    => 'transparent',
    'malzeme_stok.php'     => '#fdf2f8',
    'hesap.php'            => '#fff3e0',
    'users.php'            => '#e8eaf6',
    'roles.php'            => '#e7eefc',
    'audit.php'            => '#fce4ec',
    'admin_db_backups.php' => '#e8f5e9',
    'depo_tanimlari.php'   => '#e3f2fd',
];
$__rozet_gizle = !icerik_can('dashboard.rozetler');
if ($__rozet_gizle) ob_start();   // v507: sayaç rozetleri ve özet satırları içerik yetkisine bağlı
?>
<div class="home-grid">
<?php foreach ($__home_menu['gruplar'] as $__hg):
    if (empty($__hg['ogeler'])) continue; ?>
<div class="home-section-title"><?= h($__hg['ad']) ?></div>
<?php foreach ($__hg['ogeler'] as $__ho):
    $__href = (string)$__ho['href'];
    $__bg   = $__home_bg[$__href] ?? 'var(--primary-soft)';
?>
    <a href="<?= h($__href) ?>" class="home-card">
        <?php if ($__href === 'personel_takip.php'): ?>
        <div class="home-card-icon home-card-icon-personel" aria-hidden="true">
            <span class="personel-arrow personel-arrow-in"></span>
            <span class="personel-arrow personel-arrow-out"></span>
        </div>
        <?php elseif ($__href === 'hizli_maliyet.php'): ?>
        <div class="home-card-icon" style="background:transparent"><img src="<?= h(nav_alt_ikon_url(nav_alt_sayfalar()['qcost'], '')) ?>" width="64" height="64" alt="" aria-hidden="true"></div>
        <?php else: ?>
        <div class="home-card-icon" style="background:<?= h($__bg) ?>"><?= h($__href === 'admin_db_backups.php' ? '🗄️' : (string)$__ho['ikon']) ?></div>
        <?php endif; ?>
        <div class="home-card-title"><?= h($__ho['etiket']) ?></div>
        <?php if ($__href === 'beyanlar.php'): ?>
            <?php if ($beyan_bildirim_bekleyen > 0): ?>
            <div class="home-card-badge" style="background:#7c3aed"
                 title="Hal Kayıt bildirimi bekleyen beyan"><?= (int)$beyan_bildirim_bekleyen ?></div>
            <?php endif; ?>
        <?php elseif ($__href === 'halkayit/index.php'): ?>
            <?php if ($hks_taslak > 0): ?>
            <div class="home-card-badge" style="background:#6366f1"><?= (int)$hks_taslak ?></div>
            <?php endif; ?>
        <?php elseif ($__href === 'mail.php'): ?>
            <?php if ($mail_okunmamis > 0): ?>
            <div class="home-card-badge" style="background:var(--warn)"><?= (int)$mail_okunmamis ?></div>
            <div class="home-card-sub">Okunmamış: <?= (int)$mail_okunmamis ?></div>
            <?php endif; ?>
            <?php if ($mail_cevapsiz24 > 0): ?>
            <div class="home-card-sub home-card-uyari" style="color:var(--danger);font-weight:600">24 saattir cevapsız <?= (int)$mail_cevapsiz24 ?> mail</div>
            <?php endif; ?>
        <?php elseif ($__href === 'hesap.php'): ?>
            <?php if ($hesap_bekleyen > 0): ?>
            <div class="home-card-badge" style="background:var(--warn)"><?= $hesap_bekleyen ?></div>
            <?php endif; ?>
            <?php if ($hesap_bugun > 0): ?>
            <div class="home-card-sub">Bugün: <?= number_format($hesap_bugun, 2, ',', '.') ?> ₺</div>
            <?php endif; ?>
            <?php if ($hesap_yonetici && $hesap_onay_bekleyen > 0): ?>
            <div class="home-card-sub">Onay bekleyen: <?= $hesap_onay_bekleyen ?></div>
            <?php endif; ?>
        <?php elseif ($__href === 'personel_takip.php'): ?>
            <?php if ($personel_eksik_cikis > 0): ?>
            <div class="home-card-badge" style="background:var(--danger)"
                 title="Bugün eksik çıkışı olan mesai"><?= (int)$personel_eksik_cikis ?></div>
            <?php endif; ?>
            <div class="home-card-sub">Personel, günlük işçi, hakediş ve cari</div>
        <?php elseif ($__href === 'users.php'): ?>
            <?php if ($kullanici_aktif > 0): ?>
            <div class="home-card-badge" style="background:#5c6bc0"><?= $kullanici_aktif ?></div>
            <?php endif; ?>
        <?php elseif ($__href === 'audit.php'): ?>
            <?php if ($audit_son24h > 0): ?>
            <div class="home-card-badge" style="background:#e91e63"><?= $audit_son24h ?></div>
            <?php endif; ?>
            <div class="home-card-sub">Son 24 saat</div>
        <?php elseif ($__href === 'admin_db_backups.php'): ?>
            <div class="home-card-sub">Günlük otomatik</div>
        <?php endif; ?>
    </a>
<?php endforeach; ?>
<?php endforeach; unset($__home_menu, $__home_bg, $__hg, $__ho, $__href, $__bg); ?>
</div>
<?php if ($__rozet_gizle) {
    // Rozet ve özet satırları HTML'e hiç yazılmaz (yalnız gizlemek sayıları kaynakta bırakırdı).
    echo preg_replace('~<div class="home-card-(?:badge|sub)\b[^>]*>.*?</div>\s*~su', '', (string)ob_get_clean());
} ?>

<!-- Sürüm damgası — mobilde sidebar görünmediği için buraya da konur.
     "Deploy yansıdı mı?" sorusunun telefondan cevabı. -->
<div class="muted" style="text-align:center;font-size:.75rem;margin:18px 0 6px;opacity:.7">
    <?= h(APP_SURUM) ?>
</div>

<?php render_footer(); ?>
