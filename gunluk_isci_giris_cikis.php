<?php
// =========================================================
// gunluk_isci_giris_cikis.php — Günlük İşçi Giriş / Çıkış (Günlük İşçi, Faz 2)
//
// Kalıcı personel giris_cikis.php İLE AYNI kiosk deseni (mod seç → tara →
// sonuç) + BAŞINA bir ÇAVUŞ SEÇ adımı eklenir. Kalıcı personel giris_cikis.php
// DOSYASI HİÇ DEĞİŞTİRİLMEDİ — kullanıcının açık talimatı: "Do not reuse the
// permanent-personnel giris_cikis.php."
//
// Kart/işçi kimliği ve oturum durumu HER ZAMAN sunucuda çözülür
// (config/pdks_gunluk.php → pdks_gunluk_oturum_kaydet()); istemci yalnız ham
// okuma + kaynak (usb_decimal | web_nfc) + seçili mod + oturum id gönderir.
//
// ⚠ NFC: YENİ bir okuma döngüsü İCAT EDİLMEDİ — config/pdks.php'nin
// pdks_nfc_oku_js() ile bastığı PAYLAŞILAN window.PdksNfcOku (teşhis sayfası
// + kalıcı personel giris_cikis.php İLE AYNI kod) burada da REUSE edilir.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks.php';           // UID normalizasyon + PdksNfcOku (REUSE)
require_once __DIR__ . '/config/pdks_gunluk.php';
// ⚠ Faz 9E / B: kapanış kontrol listesi (bkz. ?ajax=kapanis_kontrol) Faz 8B'nin
// bekleyen-sınıf/FM-onayı sayaçlarını ve Faz 4'ün hakediş durumunu SALT
// OKUR — bu iki dosya bunun İÇİN eklendi, günlük tarama akışının kendisi
// hiç DEĞİŞMEDİ.
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_gunluk('daily_scan');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);   // Faz 1 kuralı: normal ziyarette DDL YOK — bkz. o fonksiyon
$base = base_url();

// ⚠ FAZ 8A: şema hazırsa GİRİŞ modu öncesinde İşçi Tipi seçilir
// ve kayıt yeni work-period fonksiyonlarına gider; şema HENÜZ
// hazır değilse (migrasyon çalıştırılmadan önce) sayfa AYNEN Faz 2'nin eski
// davranışını sergiler — kod deploy'u ile migrasyon arasında tarama BOZULMAZ.
$faz8aHazir  = pdks_gunluk_faz8a_sema_hazir($pdo);
// ⚠ Faz 9B / H-01 kapanışı: tek paylaşılan politikadan (config/pdks_gunluk.php)
// gelir — bu artık zaten YALNIZ KADIN/ERKEK döner, aşağıdaki düğme döngüsünde
// ayrıca bir "code IN (...)" filtresi TEKRARLANMAZ.
$isciTipleri = $faz8aHazir ? pdks_gunluk_desteklenen_tip_listele($pdo) : [];

// ⚠ v241 — NFC TEŞHİS MODU: `?nfcdebug=1` ile açılır, VARSAYILAN GÖRÜNÜM
// DEĞİŞMEZ. Teşhis panelinin (#giNfcDebug) kendisi zaten vardı ve her adımı
// (scan started / reading received / backend response) yazıyordu; yalnız
// pdks.css'te `#giNfcBtnWrap { display:none }` ile gizliydi, yani telefonda
// "kart okumuyor" denince bakılacak HİÇBİR iz yoktu. Bu bayrak o paneli
// yalnız açıkça istendiğinde görünür kılar — okuma akışına HİÇ dokunmaz.
$nfcTeshis = (($_GET['nfcdebug'] ?? '') === '1');

// ── AJAX uçları — SAYFANIN İÇİNDE, JSON. Yön istemciden ASLA otomatik
// tahmin edilmez, kart/oturum çözümü TAMAMEN sunucudadır. ──────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['ajax'] ?? '') === 'oturum') {
    header('Content-Type: application/json; charset=utf-8');
    $govde = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($govde)) $govde = [];
    csrf_check($govde['csrf'] ?? null);
    require_pdks_gunluk('daily_scan');

    $foremanId = (int)($govde['foreman_id'] ?? 0);
    $mod       = trim((string)($govde['mode'] ?? ''));
    if ($foremanId <= 0 || !in_array($mod, ['GIRIS', 'CIKIS'], true)) {
        echo json_encode(['ok' => false, 'kod' => 'gecersiz_istek', 'hata' => 'Geçersiz istek.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sonuc = ($mod === 'GIRIS')
        ? pdks_gunluk_oturum_ac_veya_getir($foremanId, (int)$auth_user['id'], $pdo)
        : pdks_gunluk_oturum_bul_acik($foremanId, $pdo);
    echo json_encode($sonuc, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['ajax'] ?? '') === 'kaydet') {
    header('Content-Type: application/json; charset=utf-8');
    $govde = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($govde)) $govde = [];
    csrf_check($govde['csrf'] ?? null);
    require_pdks_gunluk('daily_scan');

    $sessionId = (int)($govde['session_id'] ?? 0);
    $hamUid    = trim((string)($govde['ham_uid'] ?? ''));
    $kaynak    = trim((string)($govde['kaynak'] ?? ''));
    $eventType = trim((string)($govde['event_type'] ?? ''));
    if ($sessionId <= 0) {
        echo json_encode(['ok' => false, 'kod' => 'oturum_yok', 'hata' => 'Önce çavuş ve mod seçin.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ⚠ Güvenlik düzeltmesi (?ajax=kapat'takiyle AYNI desen — Faz 9A/M-01):
    // session_id istemciden geliyor ve yazma fonksiyonları (giriş/çıkış
    // kaydet) KENDİLERİ bir depo kontrolü YAPMAZ. Bu kontrol EKSİKTİ —
    // Depo A'da aktif bir kullanıcı, id'yi değiştirerek Depo B'nin açık bir
    // mesaisine sahte giriş/çıkış olayı yazabiliyordu.
    $stKaydetDepo = $pdo->prepare('SELECT depo FROM daily_work_sessions WHERE id=?');
    $stKaydetDepo->execute([$sessionId]);
    $kaydetDepo = $stKaydetDepo->fetchColumn();
    if ($kaydetDepo === false) {
        echo json_encode(['ok' => false, 'kod' => 'oturum_yok', 'hata' => 'Mesai bulunamadı.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($depoHata = pdks_gunluk_depo_kontrol((string)$kaydetDepo)) {
        echo json_encode(['ok' => false, 'kod' => 'yanlis_depo', 'hata' => $depoHata], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ⚠ FAZ 8A: USB VE Web NFC AYNI sunucu fonksiyonlarından geçer — kaynak
    // (usb_decimal|web_nfc) burada yalnız bir parametredir, iki AYRI iş
    // mantığı YOKTUR (görev talimatı §14).
    if (pdks_gunluk_faz8a_sema_hazir($pdo)) {
        if ($eventType === 'GIRIS') {
            $workerTypeId = (int)($govde['worker_type_id'] ?? 0);
            if ($workerTypeId <= 0) {
                echo json_encode(['ok' => false, 'kod' => 'secim_eksik', 'hata' => 'Önce İşçi Tipi seçin.'], JSON_UNESCAPED_UNICODE);
                exit;
            }

            // Faz 8D: operatör Tam/Yarım seçmez.
            // Gerçek mesai sonucu giriş/çıkış saatlerinden Faz 8B motorunda belirlenir.
            $sonuc = pdks_gunluk_faz8a_giris_kaydet(
                $hamUid,
                $kaynak,
                $sessionId,
                $workerTypeId,
                'auto',
                (int)$auth_user['id'],
                $pdo
            );
        } elseif ($eventType === 'CIKIS') {
            $sonuc = pdks_gunluk_faz8a_cikis_kaydet($hamUid, $kaynak, $sessionId, (int)$auth_user['id'], $pdo);
        } else {
            $sonuc = ['ok' => false, 'kod' => 'gecersiz_yon', 'hata' => 'Geçersiz giriş/çıkış yönü.'];
        }
    } else {
        $sonuc = pdks_gunluk_oturum_kaydet($hamUid, $kaynak, $sessionId, $eventType, (int)$auth_user['id'], $pdo);
    }
    echo json_encode($sonuc, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['ajax'] ?? '') === 'kapat') {
    header('Content-Type: application/json; charset=utf-8');
    $govde = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($govde)) $govde = [];
    csrf_check($govde['csrf'] ?? null);
    require_pdks_gunluk('daily_scan');

    $sessionId = (int)($govde['session_id'] ?? 0);
    $not       = trim((string)($govde['not'] ?? ''));
    if ($sessionId <= 0) {
        echo json_encode(['ok' => false, 'kod' => 'oturum_yok', 'hata' => 'Mesai bulunamadı.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // ⚠ Faz 9A / M-01 düzeltmesi: session_id istemciden geliyor ve
    // pdks_gunluk_oturum_kapat() KENDİSİ bir depo kontrolü YAPMAZ — kapatma
    // ÖNCESİ oturumun aktif depoya ait olduğu burada doğrulanır. Aksi halde
    // Depo A'da aktif bir kullanıcı, id'yi değiştirerek Depo B'nin hâlâ
    // taramaya açık bir mesaisini kapatabilirdi.
    $stKapatDepo = $pdo->prepare('SELECT depo FROM daily_work_sessions WHERE id=?');
    $stKapatDepo->execute([$sessionId]);
    $kapatDepo = $stKapatDepo->fetchColumn();
    if ($kapatDepo === false) {
        echo json_encode(['ok' => false, 'kod' => 'oturum_yok', 'hata' => 'Mesai bulunamadı.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($depoHata = pdks_gunluk_depo_kontrol((string)$kapatDepo)) {
        echo json_encode(['ok' => false, 'kod' => 'yanlis_depo', 'hata' => $depoHata], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sonuc = pdks_gunluk_oturum_kapat($sessionId, $not !== '' ? $not : null, (int)$auth_user['id'], $pdo);
    echo json_encode($sonuc, JSON_UNESCAPED_UNICODE);
    exit;
}

// ⚠ Faz 9E / B — GÜN KAPANIŞI KONTROL LİSTESİ: "Mesaiyi Kapat" tıklanınca,
// onay ekranı AÇILMADAN ÖNCE çağrılır. TAMAMEN BİLGİLENDİRİCİDİR — hiçbir
// yeni ENGEL kuralı YOK, yalnız MEVCUT yetkili kaynaklardan (Faz 8B özeti,
// Faz 4 hakediş kaydı, aynı depodaki diğer açık mesailer) okur ve gösterir.
// Kapatma YETKİSİ/akışı burada HİÇ değişmez — gerçek kapatma hâlâ ?ajax=kapat
// üzerinden, kendi eksik-çıkış mutabakat kuralıyla yürür.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['ajax'] ?? '') === 'kapanis_kontrol') {
    header('Content-Type: application/json; charset=utf-8');
    $govde = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($govde)) $govde = [];
    csrf_check($govde['csrf'] ?? null);
    require_pdks_gunluk('daily_scan');

    $sessionId = (int)($govde['session_id'] ?? 0);
    if ($sessionId <= 0) {
        echo json_encode(['ok' => false, 'kod' => 'oturum_yok', 'hata' => 'Mesai bulunamadı.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $stKk = $pdo->prepare('SELECT depo FROM daily_work_sessions WHERE id=?');
    $stKk->execute([$sessionId]);
    $kkDepo = $stKk->fetchColumn();
    if ($kkDepo === false) {
        echo json_encode(['ok' => false, 'kod' => 'oturum_yok', 'hata' => 'Mesai bulunamadı.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($depoHata = pdks_gunluk_depo_kontrol((string)$kkDepo)) {
        echo json_encode(['ok' => false, 'kod' => 'yanlis_depo', 'hata' => $depoHata], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $kontrol = ['ok' => true, 'bekleyen_sinif' => null, 'bekleyen_fazla_mesai' => null];
    if (pdks_gunluk_faz8a_sema_hazir($pdo) && pdks_faz8b_sema_hazir($pdo)) {
        $f8 = pdks_faz8b_oturum_ozeti($sessionId, $pdo);
        $kontrol['bekleyen_sinif'] = (int)$f8['bekleyen_sinif'];
        $kontrol['bekleyen_fazla_mesai'] = (int)$f8['bekleyen_fazla_mesai'];
    }

    // Aynı depoda, bu oturum HARİÇ, hâlâ açık başka mesai var mı —
    // yalnız farkındalık amaçlı, kapatmayı ENGELLEMEZ.
    $stAcik = $pdo->prepare("SELECT COUNT(*) FROM daily_work_sessions WHERE status='open' AND depo=? AND id<>?");
    $stAcik->execute([(string)$kkDepo, $sessionId]);
    $kontrol['acik_donem_sayisi'] = (int)$stAcik->fetchColumn();

    // Hakediş durumu — yok / taslak / yeniden hesaplama gerekli / kesin.
    $kontrol['hakedis_durum'] = 'yok';
    if (pdks_hakedis_sema_hazir($pdo)) {
        $stHk = $pdo->prepare("SELECT status, needs_recalculation FROM foreman_daily_entitlements WHERE session_id=?");
        $stHk->execute([$sessionId]);
        $hk = $stHk->fetch();
        if ($hk) {
            if ($hk['status'] === 'final') $kontrol['hakedis_durum'] = 'kesin';
            elseif (!empty($hk['needs_recalculation'])) $kontrol['hakedis_durum'] = 'yeniden_hesaplama_gerekli';
            else $kontrol['hakedis_durum'] = 'taslak';
        }
    }

    echo json_encode($kontrol, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── GET render — aktif çavuşlar listesi ────────────────────────────────
$cavuslar = [];
try {
    $cavuslar = $pdo->query("SELECT id, code, name FROM foremen WHERE is_active = 1 ORDER BY name ASC")->fetchAll();
} catch (PDOException $e) { /* pdks_gunluk_sayfa_kapisi() zaten şemayı garanti etti — buraya düşmemeli */ }

render_header('Günlük İşçi Giriş / Çıkış');
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head" id="giPageHead">
    <h1>🚪 Günlük İşçi Giriş / Çıkış</h1>
    <div class="page-head-actions">
        <a href="personel_takip.php" class="btn btn-ghost">← Personel Takibi</a>
    </div>
</div>

<input type="hidden" id="giCsrf" value="<?= h(csrf_token()) ?>">

<div class="pdks-kiosk pdks-mobile-shell pdks-daily-kiosk">

    <!-- ── 0) Çavuş seç ─────────────────────────────────────── -->
    <div id="giCavusSec" class="pdks-kiosk-modesec">
        <p class="muted" style="text-align:center;max-width:420px;margin:0 auto">
            <strong>1. ÇAVUŞ SEÇ</strong><br>
            Günlük işçileri getiren çavuşu seçin.
        </p>
        <?php if (empty($cavuslar)): ?>
        <div class="pdks-empty">
            <span class="pdks-empty-icon" aria-hidden="true">👷</span>
            <p>Henüz aktif çavuş yok.</p>
            <a href="cavus_form.php" class="btn btn-primary">+ Çavuş Ekle</a>
        </div>
        <?php else: ?>
        <?php if (count($cavuslar) > 10): ?>
        <input type="search" id="giCavusFiltre" class="pdks-kiosk-cavus-filter" placeholder="Çavuş adı ara…" autocomplete="off">
        <?php endif; ?>
        <div class="pdks-kiosk-cavus-list" id="giCavusListe">
            <?php foreach ($cavuslar as $c): ?>
            <button type="button" class="pdks-kiosk-cavus-btn" data-gi-cavus-id="<?= (int)$c['id'] ?>"
                    data-gi-cavus-ad="<?= h($c['name']) ?>" data-gi-filtre="<?= h(mb_strtolower($c['name'], 'UTF-8')) ?>">
                <span><?= h($c['name']) ?></span>
                <span class="pdks-kiosk-cavus-kod"><?= h($c['code']) ?></span>
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ── 1) Mod seç ──────────────────────────────────────── -->
    <div id="giModeSec" class="pdks-kiosk-modesec" hidden>
        <div class="pdks-kiosk-selected">
            <div class="pdks-kiosk-selected-label">SEÇİLİ ÇAVUŞ</div>
            <div class="pdks-kiosk-selected-name" id="giSeciliCavusAd"></div>
        </div>
        <button type="button" class="pdks-kiosk-modebtn pdks-kiosk-modebtn-giris" data-gi-mode="GIRIS">
            ✅ GİRİŞ MODU
        </button>
        <button type="button" class="pdks-kiosk-modebtn pdks-kiosk-modebtn-cikis" data-gi-mode="CIKIS">
            🚪 ÇIKIŞ MODU
        </button>
        <button type="button" class="pdks-kiosk-degistir-btn" id="giCavusDegistir1">↩ Çavuşu Değiştir</button>

        <!-- ⚠ v240 (kullanıcı isteği — kesin konum bu ekran, GİRİŞ/ÇIKIŞ MODU
             butonlarının ALTI): günün özeti (cinsiyet bazlı + genel toplamlar,
             ilk giriş/son çıkış) ve "Mesaiyi Kapat" burada. Çavuş seçilir
             seçilmez, herhangi bir mod seçilmeden DOLAR (bkz. modeSecOzetYukle
             — pdks_gunluk_oturum_bul_acik() salt okunur, session AÇMAZ).
             Bugün hiç mesai açılmadıysa özet sıfırlanır ve Kapat gizlenir. -->
        <div class="pdks-kiosk-counters" id="giSayaclar">
            <h3>Bugün — <span id="giSayacDepo"></span></h3>
            <div id="giSayacSatirlar"></div>
            <div class="pdks-kiosk-counter-totals">
                <div class="pdks-kiosk-counter-box"><div class="lbl">Giriş</div><div class="val" id="giGirisToplam">0</div></div>
                <div class="pdks-kiosk-counter-box"><div class="lbl">Çıkış</div><div class="val" id="giCikisToplam">0</div></div>
                <div class="pdks-kiosk-counter-box"><div class="lbl">İçeride</div><div class="val" id="giIcerdeToplam">0</div></div>
                <div class="pdks-kiosk-counter-box eksik"><div class="lbl">Eksik Çıkış</div><div class="val" id="giEksikToplam">0</div></div>
                <div class="pdks-kiosk-counter-box"><div class="lbl">İlk Giriş</div><div class="val" id="giIlkGiris">—</div></div>
                <div class="pdks-kiosk-counter-box"><div class="lbl">Son Çıkış</div><div class="val" id="giSonCikis">—</div></div>
            </div>
            <button type="button" class="btn btn-primary pdks-kiosk-kapat-btn" id="giKapatBtn" hidden>🔒 MESAİYİ KAPAT</button>
        </div>
    </div>

    <?php if ($faz8aHazir): ?>
    <!-- ── 2) Giriş öncesi işçi tipi seçimi ─────────────────── -->
    <div id="giTipSec" class="pdks-kiosk-modesec pdks-kiosk-type-popup" role="dialog" aria-modal="false" aria-labelledby="giTipBaslik" hidden>
        <div class="pdks-kiosk-selected">
            <div class="pdks-kiosk-selected-label">SEÇİLİ ÇAVUŞ · GİRİŞ MODU</div>
            <div class="pdks-kiosk-selected-name" id="giTipCavusAd"></div>
        </div>
        <h2 id="giTipBaslik">İşçi Tipi Seçin</h2>
        <?php // ⚠ Faz 9B: $isciTipleri zaten pdks_gunluk_desteklenen_tip_listele()'den
              // (yalnız KADIN/ERKEK) geliyor — burada İKİNCİ bir "code IN (...)"
              // filtresi TEKRARLANMAZ; politika TEK yerde yaşar. ?>
        <?php foreach ($isciTipleri as $t): ?>
        <button type="button" class="pdks-kiosk-modebtn pdks-kiosk-typebtn<?= $t['code'] === 'KADIN' ? ' pdks-kiosk-typebtn-kadin' : '' ?>" data-gi-tip-id="<?= (int)$t['id'] ?>" data-gi-tip-ad="<?= h($t['name']) ?>"><?= h(mb_strtoupper($t['name'], 'UTF-8')) ?></button>
        <?php endforeach; ?>
        <button type="button" class="btn btn-ghost" id="giTipVazgec">↩ Mod Seçimine Dön</button>
    </div>
    <?php endif; ?>

    <!-- ── 3) Tarama + canlı sayaçlar ───────────────────────── -->
    <div id="giScanSec" class="pdks-kiosk-scan pdks-kiosk-scan-gunluk" hidden>
        <div class="pdks-kiosk-selected pdks-scan-identity" style="margin-bottom:4px">
            <div class="pdks-kiosk-selected-name" id="giScanCavusAd" style="font-size:1.05rem"></div>
            <span id="giModeBadge" class="pdks-kiosk-mode-badge"></span>
        </div>
        <div class="pdks-kiosk-scan-status">
            <span id="giReadStatus" class="pdks-scan-active is-idle"><span aria-hidden="true"></span> OKUMA MODU PASİF</span>
            <span id="giTipBadge" class="pdks-kiosk-mode-badge pdks-kiosk-type-badge" hidden></span>
        </div>

        <div class="pdks-kiosk-scan-hint muted">Mesai kaydı için kartınızı okuyucuya yaklaştırın.</div>
        <div id="giScanVisual" class="pdks-scan-visual pdks-kiosk-scan-icon">
            <span class="pdks-scan-ripple"></span><span class="pdks-scan-ripple"></span><span class="pdks-scan-ripple"></span>
            <span class="pdks-scan-card"><span id="giScanText" class="pdks-kiosk-scan-text">Kartınızı<br>Okutun</span></span>
        </div>
        <div class="pdks-scan-info">ⓘ &nbsp;Kartı birkaç saniye sabit tutun. USB okuyucuya okutun<span id="giNfcHint"></span></div>

        <?php // ⚠ Teşhis modunda `hidden` HİÇ basılmaz: [hidden]{display:none!important}
              // kuralı aksi hâlde paneli ezerdi ve NFC'nin DESTEKLENMEDİĞİ durumda
              // (teşhis edilecek en kritik hâl) hiçbir iz görünmezdi. ?>
        <div id="giNfcBtnWrap"<?= $nfcTeshis ? ' class="nfc-teshis-acik"' : ' hidden' ?>>
            <button type="button" id="giNfcBtn" class="btn btn-lg">📡 NFC İLE KART OKU</button>
            <!-- ⚠ TEŞHİS PANELİ — normalde pdks.css ile gizli; yalnız ?nfcdebug=1
                 ile görünür (bkz. $nfcTeshis). Kalıcı personel giris_cikis.php İLE AYNI amaç. -->
            <pre id="giNfcDebug" class="pdks-kiosk-nfc-debug"></pre>
        </div>

        <input type="text" id="giScanInput" class="pdks-kiosk-hidden-input"
               inputmode="none" autocomplete="off" aria-hidden="true" tabindex="-1">

        <div id="giServerClock" class="pdks-server-clock" aria-label="Sunucu saati">--:--:--</div>

        <div class="pdks-scan-actions">
            <button type="button" class="btn btn-ghost" id="giCavusDegistir2">↩ Çavuşu Değiştir</button>
            <button type="button" class="btn" id="giModDegistir">🔁 Modu Değiştir</button>
        </div>

        <!-- ── Sonuç overlay'i (başarı/hata) ────────────────── -->
        <div id="giResult" class="pdks-kiosk-result" hidden>
            <div id="giResultInner"></div>
        </div>
    </div>

    <!-- ── 4) Kapatma onayı ─────────────────────────────────── -->
    <div id="giCloseConfirmSec" class="pdks-kiosk-modesec" hidden>
        <div class="pdks-kiosk-recon">
            <h2>Mesaiyi Kapat?</h2>
            <p><strong id="giCloseCavus"></strong> · <span id="giCloseTarih"></span></p>
            <div class="pdks-kiosk-counters">
                <div class="pdks-kiosk-counter-totals">
                    <div class="pdks-kiosk-counter-box"><div class="lbl">Giriş</div><div class="val" id="giCloseGiris"></div></div>
                    <div class="pdks-kiosk-counter-box"><div class="lbl">Çıkış</div><div class="val" id="giCloseCikis"></div></div>
                    <div class="pdks-kiosk-counter-box"><div class="lbl">İçeride</div><div class="val" id="giCloseIceride"></div></div>
                    <div class="pdks-kiosk-counter-box eksik"><div class="lbl">Eksik Çıkış</div><div class="val" id="giCloseEksik"></div></div>
                </div>
            </div>
            <!-- ⚠ Faz 9E / B: SALT BİLGİLENDİRME — hiçbiri kapatmayı engellemez,
                 gerçek kural hâlâ ?ajax=kapat'ın eksik-çıkış mutabakatıdır. -->
            <div id="giKapanisKontrol" class="pdks-kiosk-counter-row" style="flex-direction:column;align-items:stretch;gap:6px;margin:10px 0">
                <div class="pdks-kiosk-counter-row"><span>Bu depoda başka açık mesai</span><span class="n" id="gikkAcikDonem">—</span></div>
                <div class="pdks-kiosk-counter-row"><span>Muhasebe kararı bekleyen (Tam/Yarım)</span><span class="n" id="gikkBekleyenSinif">—</span></div>
                <div class="pdks-kiosk-counter-row"><span>FM onayı bekleyen</span><span class="n" id="gikkBekleyenFm">—</span></div>
                <div class="pdks-kiosk-counter-row"><span>Hakediş durumu</span><span class="n" id="gikkHakedis">—</span></div>
            </div>
            <p>Bu işlem çavuşun bugünkü mesaisini kapatacaktır.<br>
               Mesai kapatıldıktan sonra normal giriş/çıkış kart okutma işlemi durur.<br>
               <strong>Devam etmek istiyor musunuz?</strong></p>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
                <button type="button" class="btn btn-ghost" id="giCloseCancelBtn">Vazgeç</button>
                <button type="button" class="btn btn-primary" id="giCloseConfirmBtn">Mesaiyi Kapat</button>
            </div>
        </div>
    </div>

    <!-- ── 5) Mutabakat / kapatma ekranı ────────────────────── -->
    <div id="giReconSec" class="pdks-kiosk-modesec" hidden>
        <div class="pdks-kiosk-recon">
            <h2 style="margin-top:0">Eksik Çıkışlar Var</h2>
            <p class="muted">Tüm kartlar çıkış yapmadan mesai kapatılamaz. Eksik çıkışları kabul edip kapatmak
               için bir gerekçe yazın, veya vazgeçip taramaya devam edin.</p>

            <div class="pdks-kiosk-counters" style="margin:0 0 14px">
                <h3>Özet</h3>
                <div id="giReconSayaclar"></div>
                <div class="pdks-kiosk-counter-totals">
                    <div class="pdks-kiosk-counter-box"><div class="lbl">Giriş</div><div class="val" id="giReconGiris">0</div></div>
                    <div class="pdks-kiosk-counter-box"><div class="lbl">Çıkış</div><div class="val" id="giReconCikis">0</div></div>
                    <div class="pdks-kiosk-counter-box eksik"><div class="lbl">Eksik Çıkış</div><div class="val" id="giReconEksik">0</div></div>
                </div>
            </div>

            <h3 style="font-size:.9rem">Eksik Kartlar</h3>
            <div id="giReconKartlar"></div>

            <label style="display:block;margin-top:14px">
                <span class="form-label">Kapatma Gerekçesi *</span>
                <textarea id="giReconNot" rows="3" placeholder="ör. 1 kart sahada kaldı, yarın teslim edilecek" required></textarea>
            </label>
            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px">
                <button type="button" class="btn btn-primary" id="giReconKapatBtn">Eksik Çıkışlarla Kapat</button>
                <button type="button" class="btn btn-ghost" id="giReconVazgecBtn">Vazgeç — Taramaya Dön</button>
            </div>
        </div>
    </div>
</div>

<?php pdks_nfc_oku_js();   /* ortak Web NFC okuma yolu — giris_cikis.php / pdks_nfc_test.php İLE AYNI kod */ ?>

<script>
(function () {
    'use strict';

    var csrf = document.getElementById('giCsrf').value;

    var pageHead   = document.getElementById('giPageHead');
    var cavusSec   = document.getElementById('giCavusSec');
    var modeSec    = document.getElementById('giModeSec');
    var tipSec     = document.getElementById('giTipSec');
    var scanSec    = document.getElementById('giScanSec');
    var closeConfirmSec = document.getElementById('giCloseConfirmSec');
    var reconSec   = document.getElementById('giReconSec');
    var modeBadge  = document.getElementById('giModeBadge');
    var tipBadge   = document.getElementById('giTipBadge');
    var scanInput  = document.getElementById('giScanInput');
    var resultBox  = document.getElementById('giResult');
    var resultInner = document.getElementById('giResultInner');
    var nfcBtnWrap = document.getElementById('giNfcBtnWrap');
    var nfcBtn     = document.getElementById('giNfcBtn');
    var nfcHint    = document.getElementById('giNfcHint');
    var nfcDebugEl = document.getElementById('giNfcDebug');
    var scanVisual = document.getElementById('giScanVisual');
    var readStatus  = document.getElementById('giReadStatus');
    var scanText    = document.getElementById('giScanText');
    var serverClock = document.getElementById('giServerClock');
    var kapatBtn    = document.getElementById('giKapatBtn');

    // Sunucu zamanı PHP tarafından başlangıçta milisaniye olarak verilir;
    // sayaç istemcide yalnız geçen süreyi ekler, cihazın yerel saatini kullanmaz.
    var serverEpochMs = <?= (int)round(microtime(true) * 1000) ?>;
    var serverClockStartedAt = Date.now();
    function serverClockGuncelle() {
        if (!serverClock) return;
        var d = new Date(serverEpochMs + (Date.now() - serverClockStartedAt));
        serverClock.textContent =
            String(d.getHours()).padStart(2, '0') + ':' +
            String(d.getMinutes()).padStart(2, '0') + ':' +
            String(d.getSeconds()).padStart(2, '0');
    }
    serverClockGuncelle();
    window.setInterval(serverClockGuncelle, 1000);

    // ── FAZ 8A: giriş öncesi işçi tipi seçimi ──
    var seciliTipId = null;
    var seciliTipAd = null;

    // ── Basit ses geri bildirimi — Web Audio API, harici dosya/kütüphane
    // YOK (görev talimatı §15). Ses BAŞARISIZ olursa kayda ASLA engel olmaz
    // (try/catch içinde, sessizce yutulur — tarayıcı autoplay kısıtları dahil). ──
    var audioCtx = null;
    function sesBaglami() {
        try {
            if (!audioCtx) {
                var AC = window.AudioContext || window.webkitAudioContext;
                if (!AC) return null;
                audioCtx = new AC();
            }
            if (audioCtx.state === 'suspended') audioCtx.resume().catch(function () {});
            return audioCtx;
        } catch (e) { return null; }
    }
    function biples(frekans, sureMs, baslangicMs, seviye, dalga) {
        try {
            var ctx = sesBaglami();
            if (!ctx) return;
            var cal = function () {
                try {
                    var osc = ctx.createOscillator();
                    var gain = ctx.createGain();
                    osc.type = dalga || 'sine';
                    osc.frequency.value = frekans;
                    gain.gain.value = seviye == null ? 0.22 : seviye;
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    var basla = ctx.currentTime + (baslangicMs || 0) / 1000;
                    osc.start(basla);
                    osc.stop(basla + sureMs / 1000);
                } catch (e) {}
            };
            // Android Chrome'da resume() asenkrondur. Oscillator'ı context gerçekten
            // running olduktan sonra kurmak, NFC sonucunda sessiz kalmasını önler.
            if (ctx.state === 'running') cal();
            else ctx.resume().then(cal).catch(function () {});
        } catch (e) { /* ses arızası kaydı ASLA engellemez */ }
    }
    function sesBasarili() { biples(1046, 120, 0, 0.22, 'sine'); }
    function sesHata() {
        biples(260, 180, 0,   0.42, 'square');
        biples(190, 220, 210, 0.46, 'square');
        biples(145, 280, 470, 0.50, 'sawtooth');
    }
    // Web Audio kilidini gerçek kullanıcı jestinde aç. NFC okumaları daha sonra
    // kullanıcı jesti olmadan geldiği için context'in önceden running olması gerekir.
    function sesiHazirla() { sesBaglami(); }
    document.addEventListener('pointerdown', sesiHazirla, { once: true, passive: true });
    document.addEventListener('touchstart', sesiHazirla, { once: true, passive: true });
    document.addEventListener('click', sesiHazirla, { once: true });

    var MOD_ETIKET = { GIRIS: '✅ GİRİŞ MODU', CIKIS: '🚪 ÇIKIŞ MODU' };
    var MOD_SINIF  = { GIRIS: 'pdks-kiosk-mode-badge-giris', CIKIS: 'pdks-kiosk-mode-badge-cikis' };

    var seciliCavusId  = null;
    var seciliCavusAd  = null;
    var currentMode    = null;   // 'GIRIS' | 'CIKIS' — İSTEMCİDE ASLA otomatik seçilmez
    var currentSession = null;   // { id, ... } — sunucudan gelir, İCAT EDİLMEZ
    var modeRequest = 0;
    var busy = false;
    var kayitBekci = null;   // bkz. kaydet() — askıda kalan isteğin kilidi kilitlemesini önler
    var resultTimer = null;

    function nfcDebugYaz(satir) {
        if (!nfcDebugEl) return;
        var zaman = new Date().toLocaleTimeString('tr-TR');
        nfcDebugEl.textContent = '[' + zaman + '] ' + satir + '\n' + nfcDebugEl.textContent;
    }

    // ⚠ Kalıcı personel giris_cikis.php İLE AYNI desen — dokunmatikte
    // otomatik odak YOK, masaüstünde (ince işaretçi) USB kutusu her zaman
    // odaklı kalır.
    var inceIsaretci = !!(window.matchMedia && window.matchMedia('(any-pointer: fine)').matches);
    function focusInput() {
        if (!inceIsaretci) return;
        try { scanInput.focus({ preventScroll: true }); } catch (e) { try { scanInput.focus(); } catch (e2) {} }
    }

    // USB klavye tipi okuyucular tarayıcıya bağlantı bilgisi vermez. Bu nedenle
    // aktif durum, USB giriş alanı odaklı olduğunda veya mevcut ortak NFC yolu
    // gerçekten dinlemeye başladığında gösterilir.
    function okumaDurumuGuncelle() {
        if (!readStatus) return;
        var nfcAktif = !!nfcDinlemede;
        var usbAktif = !scanSec.hidden && inceIsaretci && document.activeElement === scanInput;
        var aktif = !scanSec.hidden && (nfcAktif || usbAktif);
        readStatus.classList.toggle('is-active', aktif);
        readStatus.classList.toggle('is-idle', !aktif);
        readStatus.textContent = nfcAktif ? 'NFC OKUMA MODU AKTİF' : (aktif ? 'USB OKUMA MODU AKTİF' : 'OKUMA MODU PASİF');
        // Mobil Web NFC: dinleme henüz başlamadıysa kartın ana çağrısı NFC'yi
        // açmayı söyler; NFC dinlemeye başlayınca normal okutma metnine döner.
        // Masaüstü/USB akışı aynen korunur.
        if (scanText) {
            var mobilNfc = !inceIsaretci && window.PdksNfcOku && PdksNfcOku.destekli();
            scanText.innerHTML = (mobilNfc && !nfcAktif) ? 'NFC<br>Aç' : 'Kartınızı<br>Okutun';
        }
    }

    function ekranGoster(ekran) {
        [cavusSec, modeSec, tipSec, scanSec, closeConfirmSec, reconSec].forEach(function (el) { if (el) el.hidden = (el !== ekran); });
        // ⚠ .page-head display:flex TAŞIR — hidden TEK BAŞINA gizleyemez
        // (bkz. CLAUDE.md maliyet.css notu, giris_cikis.php İLE AYNI düzeltme).
        if (pageHead) pageHead.style.display = (ekran === scanSec) ? 'none' : '';
        okumaDurumuGuncelle();
    }

    // ── 0) Çavuş seçimi ──────────────────────────────────────
    var cavusFiltre = document.getElementById('giCavusFiltre');
    if (cavusFiltre) {
        cavusFiltre.addEventListener('input', function () {
            var q = cavusFiltre.value.trim().toLowerCase();
            document.querySelectorAll('[data-gi-cavus-id]').forEach(function (btn) {
                btn.hidden = q !== '' && btn.getAttribute('data-gi-filtre').indexOf(q) === -1;
            });
        });
    }
    document.querySelectorAll('[data-gi-cavus-id]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            seciliCavusId = parseInt(btn.getAttribute('data-gi-cavus-id'), 10);
            seciliCavusAd = btn.getAttribute('data-gi-cavus-ad');
            document.getElementById('giSeciliCavusAd').textContent = seciliCavusAd;
            if (tipSec) document.getElementById('giTipCavusAd').textContent = seciliCavusAd;
            modeRequest++;
            currentMode = null; currentSession = null;
            seciliTipId = null; seciliTipAd = null;
            ekranGoster(modeSec);
            modeSecOzetYukle();
        });
    });

    // ⚠ v240: mod seçim ekranındaki (giModeSec) "Bugün" özeti + Mesaiyi Kapat —
    // hiçbir mod SEÇİLMEDEN, çavuş seçilir seçilmez dolar. ajax=oturum'u
    // mode=CIKIS ile çağırmak YENİ bir uç nokta İCAT ETMEZ: o dal zaten salt
    // okunur pdks_gunluk_oturum_bul_acik()'e gider (session AÇMAZ/yazmaz),
    // GİRİŞ dalının aksine. Bugün hiç mesai açılmadıysa (kod=oturum_yok)
    // özet sıfırlanır ve Kapat gizlenir — kapatılacak bir şey yoktur.
    function modeSecOzetYukle() {
        if (!seciliCavusId) return;
        var request = ++modeRequest;
        sayaclariGoster({});
        document.getElementById('giSayacDepo').textContent = '';
        kapatBtn.hidden = true;
        fetch('gunluk_isci_giris_cikis.php?ajax=oturum', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ csrf: csrf, foreman_id: seciliCavusId, mode: 'CIKIS' })
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (request !== modeRequest) return;
                if (d && d.ok) {
                    currentSession = d.session;
                    document.getElementById('giSayacDepo').textContent = d.session.depo || '(depo yok)';
                    sayaclariGoster(d.ozet || {});
                    kapatBtn.hidden = false;
                } else {
                    currentSession = null;
                    sayaclariGoster({});
                    kapatBtn.hidden = true;
                }
            })
            .catch(function () {
                if (request !== modeRequest) return;
                currentSession = null;
                kapatBtn.hidden = true;
            });
    }

    function cavusDegistir() {
        // ⚠ Sunucudaki oturum KAPATILMAZ — yalnız istemci ekranı sıfırlanır
        // (kullanıcının açık talimatı: "changing screen/foreman must NOT
        // close the session. Sessions stay server-side until explicitly closed.")
        modeRequest++;
        seciliCavusId = null; seciliCavusAd = null; currentMode = null; currentSession = null;
        seciliTipId = null; seciliTipAd = null;
        if (cavusFiltre) { cavusFiltre.value = ''; document.querySelectorAll('[data-gi-cavus-id]').forEach(function (b) { b.hidden = false; }); }
        ekranGoster(cavusSec);
    }
    document.getElementById('giCavusDegistir1').addEventListener('click', cavusDegistir);
    document.getElementById('giCavusDegistir2').addEventListener('click', cavusDegistir);

    // ── 1) Mod seçimi → sunucudan oturum aç/getir ────────────
    // ⚠ ozet.ilk_giris / ozet.son_cikis "YYYY-MM-DD HH:MM:SS" veya null gelir
    // (pdks_gunluk_faz8a_oturum_ozet — bkz. config/pdks_gunluk.php). Yalnız
    // saat:dakika gösterilir, hiç kayıt yoksa "—".
    function saatKisalt(dt) {
        if (!dt) return '—';
        var parca = String(dt).split(' ')[1] || String(dt);
        return parca.slice(0, 5) || '—';
    }
    function sayaclariGoster(ozet) {
        var satirlar = '';
        var tipler = {};
        Object.keys(ozet.giris || {}).forEach(function (t) { tipler[t] = true; });
        Object.keys(ozet.cikis || {}).forEach(function (t) { tipler[t] = true; });
        Object.keys(tipler).sort().forEach(function (tip) {
            var g = (ozet.giris && ozet.giris[tip]) || 0;
            var c = (ozet.cikis && ozet.cikis[tip]) || 0;
            satirlar += '<div class="pdks-kiosk-counter-row"><span>' + escHtml(tip) + '</span>' +
                '<span class="n">Giriş ' + g + ' · Çıkış ' + c + '</span></div>';
        });
        if (satirlar === '') satirlar = '<div class="pdks-kiosk-counter-row muted"><span>Henüz tarama yok</span></div>';
        document.getElementById('giSayacSatirlar').innerHTML = satirlar;
        document.getElementById('giGirisToplam').textContent  = ozet.giris_toplam || 0;
        document.getElementById('giCikisToplam').textContent  = ozet.cikis_toplam || 0;
        document.getElementById('giIcerdeToplam').textContent = ozet.icerde_toplam || 0;
        document.getElementById('giEksikToplam').textContent  = ozet.eksik_toplam || 0;
        document.getElementById('giIlkGiris').textContent     = saatKisalt(ozet.ilk_giris);
        document.getElementById('giSonCikis').textContent     = saatKisalt(ozet.son_cikis);
    }

    function modSec(mod) {
        if (!seciliCavusId) return;
        if (mod === 'GIRIS' && tipSec && !seciliTipId) return;
        var request = ++modeRequest;
        currentMode = mod;
        fetch('gunluk_isci_giris_cikis.php?ajax=oturum', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ csrf: csrf, foreman_id: seciliCavusId, mode: mod })
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (request !== modeRequest) return;
                if (!d || !d.ok) {
                    alert((d && d.hata) || 'Mesai açılamadı/bulunamadı.');
                    currentMode = null; currentSession = null;
                    ekranGoster(modeSec);
                    modeSecOzetYukle();
                    return;
                }
                currentSession = d.session;
                modeBadge.textContent = MOD_ETIKET[mod];
                modeBadge.className = 'pdks-kiosk-mode-badge ' + MOD_SINIF[mod];
                tipBadge.hidden = (mod !== 'GIRIS' || !seciliTipAd);
                tipBadge.textContent = tipBadge.hidden ? '' : seciliTipAd;
                tipBadge.classList.toggle('pdks-kiosk-type-badge-kadin', !tipBadge.hidden && seciliTipAd.toLocaleUpperCase('tr-TR') === 'KADIN');
                tipBadge.classList.toggle('pdks-kiosk-type-badge-erkek', !tipBadge.hidden && seciliTipAd.toLocaleUpperCase('tr-TR') === 'ERKEK');
                document.getElementById('giScanCavusAd').textContent = seciliCavusAd;
                document.getElementById('giSayacDepo').textContent = currentSession.depo || '(depo yok)';
                sayaclariGoster(d.ozet || {});
                scanInput.value = '';
                resultBox.hidden = true;
                ekranGoster(scanSec);
                focusInput();
            })
            .catch(function () {
                if (request !== modeRequest) return;
                alert('Bağlantı hatası. Tekrar deneyin.');
                currentMode = null; currentSession = null;
                ekranGoster(modeSec);
                modeSecOzetYukle();
            });
    }
    document.querySelectorAll('[data-gi-mode]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var mod = btn.getAttribute('data-gi-mode');
            seciliTipId = null; seciliTipAd = null;
            if (mod === 'GIRIS' && tipSec) {
                ekranGoster(tipSec);
                var ilkTip = tipSec.querySelector('[data-gi-tip-id]');
                if (ilkTip) ilkTip.focus();
                return;
            }
            modSec(mod);
        });
    });
    document.querySelectorAll('[data-gi-tip-id]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            seciliTipId = parseInt(btn.getAttribute('data-gi-tip-id'), 10);
            seciliTipAd = btn.getAttribute('data-gi-tip-ad');
            modSec('GIRIS');
        });
    });
    if (tipSec) document.getElementById('giTipVazgec').addEventListener('click', function () {
        seciliTipId = null; seciliTipAd = null;
        ekranGoster(modeSec);
        modeSecOzetYukle();
        modeSec.querySelector('[data-gi-mode="GIRIS"]').focus();
    });
    document.getElementById('giModDegistir').addEventListener('click', function () {
        currentMode = null; currentSession = null;
        seciliTipId = null; seciliTipAd = null;
        ekranGoster(modeSec);
        modeSecOzetYukle();
    });

    // Sayfa herhangi bir yere tıklanınca odak USB kutusuna dönsün.
    document.addEventListener('click', function (e) {
        if (!scanSec.hidden && e.target !== nfcBtn) focusInput();
    });

    function gosterSonuc(html, sinif, sureMs) {
        clearTimeout(resultTimer);
        resultInner.innerHTML = html;
        resultBox.className = 'pdks-kiosk-result ' + sinif;
        resultBox.hidden = false;
        resultTimer = setTimeout(function () { resultBox.hidden = true; focusInput(); }, sureMs);
    }
    function basariGoster(d) {
        var kart = d.card || {};
        var girisMi = d.event_type === 'GIRIS';
        var baslik = girisMi ? 'GİRİŞ KAYDEDİLDİ' : 'ÇIKIŞ KAYDEDİLDİ';
        var sonucSinif = girisMi ? ' is-giris' : ' is-cikis';
        var saat = ((d.server_time || '').split(' ')[1] || '').slice(0, 5);
        var tip = kart.worker_type_name || kart.declared_class_label || '';
        var tipSinif = tip.toLocaleUpperCase('tr-TR') === 'KADIN' ? ' is-kadin' :
                       (tip.toLocaleUpperCase('tr-TR') === 'ERKEK' ? ' is-erkek' : '');
        var saatBilgi = saat;
        if (d.event_type === 'CIKIS' && kart.entry_time) {
            var girisSaat = (kart.entry_time.split(' ')[1] || kart.entry_time).slice(0, 5);
            saatBilgi = girisSaat + ' → ' + saat;
        }
        // ⚠ v240 (kullanıcı isteği): yeşil tik yerine o günün o cinsiyetteki
        // TOPLAM GİRİŞ sayısı gösterilir — hem GİRİŞ hem ÇIKIŞ okutmasında,
        // sunucudan zaten gelen ozet.giris[tip]'ten (bkz. sayaclariGoster).
        // Eşleşen bir sayı yoksa (tip bilinmiyor/ozet eksikse) eski tike döner.
        var ozet = d.ozet || {};
        var gunlukToplam = (tip && ozet.giris && ozet.giris[tip] != null) ? ozet.giris[tip] : null;
        var ikonHtml = (gunlukToplam != null)
            ? '<div class="pdks-result-3d-icon pdks-result-3d-icon-count" aria-hidden="true"><span>' + escHtml(String(gunlukToplam)) + '</span></div>'
            : '<div class="pdks-result-3d-icon" aria-hidden="true"><span>✓</span></div>';
        // ⚠ v241 (referans tasarım): cinsiyet kapsülünde etiketin yanında kişi
        // ikonu. Emoji DEĞİL satır içi SVG — emoji cihaza göre gri/farklı
        // render ediliyordu; SVG rengi kapsülün kendi --gender-ikon
        // değişkeninden gelir (bkz. pdks.css v241 bölümü).
        var gKisiIkon = '<svg class="pdks-result-gender-ikon" viewBox="0 0 24 24" aria-hidden="true" focusable="false">'
            + '<circle cx="12" cy="7" r="4.2"></circle>'
            + '<path d="M2.8 21.6c0-4.7 4.1-7.5 9.2-7.5s9.2 2.8 9.2 7.5z"></path></svg>';
        sesBasarili();
        gosterSonuc(
            ikonHtml +
            (tip ? '<div class="pdks-result-gender' + tipSinif + '">' + gKisiIkon + '<span>' + escHtml(tip) + '</span></div>' : '') +
            '<div class="pdks-result-time">' + escHtml(saatBilgi) + '</div>' +
            '<div class="pdks-kiosk-result-msg' + sonucSinif + '">' + baslik + '</div>' +
            '<div class="pdks-result-cardno">Kart No: ' + escHtml(kart.card_no || '') + '</div>',
            'pdks-kiosk-result-ok', 5000
        );
    }
    function hataGoster(mesaj) {
        sesHata();
        gosterSonuc(
            '<div class="pdks-result-3d-icon" aria-hidden="true"><span>✕</span></div>' +
            '<div class="pdks-kiosk-result-msg">' + escHtml(mesaj || 'Kayıt yapılamadı.') + '</div>',
            'pdks-kiosk-result-err', 5000
        );
    }
    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = String(s == null ? '' : s);
        return d.innerHTML;
    }

    // ── Kayıt ────────────────────────────────────────────────
    // ⚠ v241: BU FONKSİYONUN ERKEN ÇIKIŞLARI ESKİDEN SESSİZDİ — kart okunur,
    // hiçbir ses/ekran tepkisi olmaz, kullanıcı "NFC kartı okumuyor" derdi
    // (USB'de fark edilmez: orada odak/enter akışı zaten geri bildirim verir).
    // Kural: SESSİZ dönüş YOK. Her erken çıkış ya teşhis paneline yazar ya da
    // ekranda sebebini söyler. Yeni bir erken çıkış eklersen AYNISINI yap.
    function kaydet(hamUid, kaynak) {
        if (busy) { nfcDebugYaz('kaydet atlandı: önceki istek hâlâ sürüyor'); return; }
        if (!currentMode || !currentSession) {
            nfcDebugYaz('kaydet atlandı: mod/mesai yok (mod=' + currentMode + ', mesai=' + (currentSession ? currentSession.id : 'yok') + ')');
            hataGoster('Mesai bağlantısı yok — "Modu Değiştir" ile GİRİŞ/ÇIKIŞ modunu yeniden seçin.');
            return;
        }
        var deger = String(hamUid || '').trim();
        if (deger === '') {
            nfcDebugYaz('kaydet atlandı: UID boş geldi');
            hataGoster('Kart numarası okunamadı — kartı tekrar yaklaştırın.');
            return;
        }
        // GİRİŞ taraması, işçi tipi seçilmeden başlamaz; sunucu da doğrular.
        if (tipSec && currentMode === 'GIRIS' && !seciliTipId) {
            nfcDebugYaz('kaydet atlandı: işçi tipi seçili değil');
            hataGoster('Önce İşçi Tipi (Kadın/Erkek) seçin.');
            return;
        }
        busy = true;
        // ⚠ v241 — KİLİT BEKÇİSİ: `busy` yalnız yanıt/hata dönünce açılıyordu.
        // Mobilde istek ASKIDA kalırsa (ağ kopması, uyku, taşıyıcı değişimi)
        // fetch ne resolve ne reject olur; kilit SONSUZA KADAR kapalı kalır ve
        // O ANDAN SONRAKİ HER KART OKUMASI SESSİZCE YOK SAYILIRDI — kullanıcıya
        // "NFC kartı okumuyor" diye görünen tam olarak budur. USB'de nadir,
        // çünkü masaüstü ağı kopmaz. Bekçi kilidi her hâlükârda açar.
        clearTimeout(kayitBekci);
        kayitBekci = setTimeout(function () {
            if (!busy) return;
            busy = false;
            nfcDebugYaz('istek zaman aşımı — kilit açıldı (yanıt gelmedi)');
            hataGoster('Sunucu yanıt vermedi — kartı tekrar okutun.');
        }, 15000);
        var govde = { csrf: csrf, session_id: currentSession.id, ham_uid: deger, kaynak: kaynak, event_type: currentMode };
        if (tipSec && currentMode === 'GIRIS') {
            govde.worker_type_id = seciliTipId;
        }
        fetch('gunluk_isci_giris_cikis.php?ajax=kaydet', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify(govde)
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                busy = false; clearTimeout(kayitBekci);
                nfcDebugYaz('backend response: ' + ((d && d.ok) ? 'ok' : 'hata (' + ((d && d.kod) || '?') + ')'));
                if (d && d.ok) { basariGoster(d); if (d.ozet) sayaclariGoster(d.ozet); }
                else hataGoster(d && d.hata);
                focusInput();
            })
            .catch(function () {
                busy = false; clearTimeout(kayitBekci);
                nfcDebugYaz('backend response: ağ hatası');
                hataGoster('Bağlantı hatası. Tekrar deneyin.');
                focusInput();
            });
    }

    // ── USB HID girişi — kalıcı personel giris_cikis.php İLE AYNI desen ──
    var usbTimer = null;
    scanInput.addEventListener('input', function () {
        var temiz = scanInput.value.replace(/[^0-9]/g, '');
        if (temiz !== scanInput.value) scanInput.value = temiz;
        clearTimeout(usbTimer);
        if (temiz === '') return;
        usbTimer = setTimeout(function () {
            var v = scanInput.value; scanInput.value = '';
            kaydet(v, 'usb_decimal');
        }, 350);
    });
    scanInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            clearTimeout(usbTimer);
            var v = scanInput.value; scanInput.value = '';
            if (v.trim() !== '') kaydet(v, 'usb_decimal');
        }
    });
    scanInput.addEventListener('focus', okumaDurumuGuncelle);
    scanInput.addEventListener('blur', okumaDurumuGuncelle);

    // ── Web NFC girişi — PAYLAŞILAN PdksNfcOku (config/pdks.php) ─────────
    // ⚠ giris_cikis.php / pdks_nfc_test.php İLE BİREBİR AYNI KOD — burada
    // YENİ bir NDEFReader/AbortController AÇILMAZ (kullanıcının açık
    // talimatı: "Do not reintroduce the previously broken AbortController
    // lifecycle"). Oturum, teşhis sayfasındaki gibi AÇIK KALIR.
    var nfcDinlemede = false;
    var NFC_ETIKET_DINLEME = '🟢 NFC HAZIR — KARTI TELEFONA YAKLAŞTIRIN';

    nfcDebugYaz('NFC support: ' + (('NDEFReader' in window) ? 'evet' : 'hayır') +
        ' · secure context: ' + (window.isSecureContext ? 'evet' : 'hayır'));

    if (PdksNfcOku.destekli()) {
        nfcBtnWrap.hidden = false;
        nfcHint.textContent = ' veya NFC ile telefonun arkasına yaklaştırın';
        nfcBtn.disabled = false;
        nfcDebugYaz('button: enabled');

        // ⚠ Kullanıcı isteği (2026-09-18): ayrı "NFC İLE KART OKU" butonu +
        // teşhis paneli görsel olarak KALDIRILDI (bkz. pdks.css #giNfcBtnWrap),
        // dokunma hedefi kart grafiğine (#giScanVisual) taşındı. YENİ bir
        // tarama akışı AÇILMAZ — kart tıklanınca nfcBtn'e programatik
        // .click() atılır, böylece guard (nfcDinlemede), teşhis logu ve
        // PdksNfcOku.baslat() TEK yerde (aşağıdaki listener) kalır.
        scanVisual.setAttribute('role', 'button');
        scanVisual.setAttribute('tabindex', '0');
        scanVisual.setAttribute('aria-label', 'NFC ile kart oku');
        scanVisual.classList.add('pdks-scan-visual-tappable');
        scanVisual.addEventListener('click', function () { nfcBtn.click(); });
        scanVisual.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); nfcBtn.click(); }
        });

        nfcBtn.addEventListener('click', function () {
            // NFC aktivasyonu kullanıcı jestidir; aynı anda ses context'ini de kesin aç.
            sesiHazirla();
            if (nfcDinlemede) { nfcDebugYaz('zaten dinlemede — yeni scan() açılmadı'); return; }
            nfcDebugYaz('button clicked');
            PdksNfcOku.baslat({
                onOkuma: function (ev) {
                    var ham = (ev.serialNumber != null) ? String(ev.serialNumber) : '';
                    nfcDebugYaz('reading received — serialNumber=' + (ham || '(boş)'));
                    // ⚠ v241: seri no BOŞ gelirse eskiden SESSİZCE hiçbir şey olmuyordu
                    // (Android'de bazı kart/etiketlerde gerçekten boş gelir) —
                    // kullanıcı için bu "kart okumuyor" demekti. Artık sebebi söylenir.
                    if (ham !== '') kaydet(ham, 'web_nfc');
                    else hataGoster('Kart algılandı ama seri numarası boş geldi — kartı tekrar yaklaştırın.');
                },
                onOkumaHatasi: function () {
                    nfcDebugYaz('readingerror');
                    hataGoster('NFC okuma hatası — kartı tekrar yaklaştırın.');
                },
                onBasladi: function () {
                    nfcDinlemede = true;
                    okumaDurumuGuncelle();
                    nfcDebugYaz('scan started');
                    nfcBtn.textContent = NFC_ETIKET_DINLEME;
                    nfcBtn.disabled = true;
                    nfcBtn.classList.add('pdks-kiosk-nfc-armed');
                },
                onHata: function (ad, msj) {
                    nfcDebugYaz('scan() rejected: ' + ad + ' — ' + msj);
                    hataGoster('NFC başlatılamadı. NFC İLE KART OKU butonuna tekrar dokunun.');
                }
            });
        });
    } else {
        nfcBtn.disabled = true;
        nfcDebugYaz('button: disabled (desteklenmiyor veya güvenli bağlam yok)');
    }

    // ── 4) Kapatma onayı / mutabakat ──────────────────────────
    function reconGoster(ozet) {
        var satirlar = '';
        var tipler = {};
        Object.keys(ozet.giris || {}).forEach(function (t) { tipler[t] = true; });
        Object.keys(ozet.cikis || {}).forEach(function (t) { tipler[t] = true; });
        Object.keys(tipler).sort().forEach(function (tip) {
            var g = (ozet.giris && ozet.giris[tip]) || 0;
            var c = (ozet.cikis && ozet.cikis[tip]) || 0;
            var e = (ozet.eksik_tip && ozet.eksik_tip[tip]) || 0;
            satirlar += '<div class="pdks-kiosk-counter-row"><span>' + escHtml(tip) + '</span>' +
                '<span class="n">Giriş ' + g + ' · Çıkış ' + c + (e ? ' · <span style="color:var(--warn)">Eksik ' + e + '</span>' : '') + '</span></div>';
        });
        document.getElementById('giReconSayaclar').innerHTML = satirlar;
        document.getElementById('giReconGiris').textContent = ozet.giris_toplam || 0;
        document.getElementById('giReconCikis').textContent = ozet.cikis_toplam || 0;
        document.getElementById('giReconEksik').textContent = ozet.eksik_toplam || 0;

        var kartHtml = '';
        (ozet.eksik_kartlar || []).forEach(function (k) {
            var saat = (k.giris_zamani || '').split(' ')[1] || k.giris_zamani || '';
            kartHtml += '<div class="pdks-kiosk-recon-missing-row"><span><strong>' + escHtml(k.card_no) + '</strong> — ' + escHtml(k.tip) + '</span>' +
                '<span>Giriş: ' + escHtml(saat) + ' · Çıkış: —</span></div>';
        });
        document.getElementById('giReconKartlar').innerHTML = kartHtml || '<p class="muted">Eksik kart yok.</p>';
        document.getElementById('giReconNot').value = '';
        ekranGoster(reconSec);
    }

    function kapat(not) {
        if (!currentSession) return;
        fetch('gunluk_isci_giris_cikis.php?ajax=kapat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ csrf: csrf, session_id: currentSession.id, not: not || '' })
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d && d.ok) {
                    alert('Mesai kapatıldı.');
                    cavusDegistir();
                    return;
                }
                if (d && d.kod === 'eksik_cikis_var') {
                    reconGoster(d.ozet || {});
                    return;
                }
                alert((d && d.hata) || 'Mesai kapatılamadı.');
            })
            .catch(function () { alert('Bağlantı hatası. Tekrar deneyin.'); });
    }
    // ⚠ Faz 9E / B: hakediş durumu etiketleri — cavus_hakedis.php/detay'daki
    // AYNI durum sözlüğünün (draft/final/needs_recalculation) burada TEK
    // satırlık bir aynasıdır, ayrı bir iş kuralı İCAT ETMEZ.
    var gikkHakedisEtiket = {
        yok: 'Yok', taslak: 'Taslak',
        yeniden_hesaplama_gerekli: '⚠️ Yeniden hesaplama gerekli', kesin: 'Kesin'
    };
    function kapanisKontroluGoster(sessionId) {
        document.getElementById('gikkAcikDonem').textContent = '—';
        document.getElementById('gikkBekleyenSinif').textContent = '—';
        document.getElementById('gikkBekleyenFm').textContent = '—';
        document.getElementById('gikkHakedis').textContent = '—';
        fetch('gunluk_isci_giris_cikis.php?ajax=kapanis_kontrol', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ csrf: csrf, session_id: sessionId })
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) return;   // salt bilgilendirme — sessizce vazgeç
                document.getElementById('gikkAcikDonem').textContent = d.acik_donem_sayisi;
                document.getElementById('gikkBekleyenSinif').textContent = d.bekleyen_sinif === null ? '—' : d.bekleyen_sinif;
                document.getElementById('gikkBekleyenFm').textContent = d.bekleyen_fazla_mesai === null ? '—' : d.bekleyen_fazla_mesai;
                document.getElementById('gikkHakedis').textContent = gikkHakedisEtiket[d.hakedis_durum] || d.hakedis_durum || '—';
            })
            .catch(function () { /* salt bilgilendirme — sessizce vazgeç */ });
    }
    document.getElementById('giKapatBtn').addEventListener('click', function () {
        if (!currentSession) return;
        document.getElementById('giCloseCavus').textContent = seciliCavusAd || '';
        var tarih = String(currentSession.work_date || '').split('-');
        document.getElementById('giCloseTarih').textContent = tarih.length === 3 ? tarih.reverse().join('.') : '';
        document.getElementById('giCloseGiris').textContent = document.getElementById('giGirisToplam').textContent;
        document.getElementById('giCloseCikis').textContent = document.getElementById('giCikisToplam').textContent;
        document.getElementById('giCloseIceride').textContent = document.getElementById('giIcerdeToplam').textContent;
        document.getElementById('giCloseEksik').textContent = document.getElementById('giEksikToplam').textContent;
        kapanisKontroluGoster(currentSession.id);
        ekranGoster(closeConfirmSec);
    });
    // ⚠ v240: Kapat artık YALNIZ giModeSec'ten tetiklenir (hiçbir mod seçilmeden) —
    // vazgeç/reddedince dönülecek ekran da scanSec DEĞİL, modeSec'tir (eskiden
    // Kapat scanSec içindeydi ve currentMode zaten set edilmişti; şimdi bu akışta
    // currentMode her zaman null, dolayısıyla scanSec'e dönmek yarı boş bir ekran
    // gösterirdi). Eksik çıkış varsa kullanıcı modeSec'ten ÇIKIŞ MODU'na tekrar
    // girip taramaya devam edebilir.
    document.getElementById('giCloseCancelBtn').addEventListener('click', function () {
        ekranGoster(modeSec);
        modeSecOzetYukle();
    });
    document.getElementById('giCloseConfirmBtn').addEventListener('click', function () { kapat(''); });
    document.getElementById('giReconKapatBtn').addEventListener('click', function () {
        var not = document.getElementById('giReconNot').value.trim();
        if (not === '') { alert('Kapatma gerekçesi zorunludur.'); return; }
        kapat(not);
    });
    document.getElementById('giReconVazgecBtn').addEventListener('click', function () {
        ekranGoster(modeSec);
        modeSecOzetYukle();
    });
})();
</script>

<?php render_footer(); ?>
