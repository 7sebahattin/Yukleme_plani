<?php
// =========================================================
// gunluk_isci_puantaj_detay.php — Tek Mesai Detayı (Günlük İşçi, Faz 3)
//
// Kart dökümü ve sayaçlar mevcut ortak fonksiyonlardan gelir. Faz 8H'nin
// yalnız admin yeniden açma eylemi de paylaşılan backend işlevini kullanır.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_faz8h.php';
require_once __DIR__ . '/config/pdks_faz8j.php';
// ⚠ Faz 9E / F: "Manuel Çıkış Gir" derin bağlantısı — manuel_cikis.php'nin
// KENDİ yetkisiyle (entitlements_finalize) AYNI kapıyı burada da OKUR,
// böylece yetkisi olmayan bir kullanıcı tıklayıp 403'e gitmez.
require_once __DIR__ . '/config/pdks_hakedis.php';
// v299: "Mesai Tanımı" sütunu — TEK sınıflandırıcı (pdks_faz8b_donem_siniflandir)
// sayfa katmanında yüklenir; config/pdks_gunluk.php faz8b'yi require ETMEZ.
require_once __DIR__ . '/config/pdks_faz8b.php';
// v299 Servis Ücreti (pencere + liste; kurallar config/pdks_servis.php'de) — pdks_faz8b.php yükler.
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
require_pdks_gunluk('daily_reports');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if (!$id) { set_flash('error', 'Geçersiz mesai.'); header('Location: gunluk_isci_puantaj.php'); exit; }

$st = $pdo->prepare("SELECT * FROM daily_work_sessions WHERE id = ?");
$st->execute([$id]);
$oturum = $st->fetch();
if (!$oturum) { set_flash('error', 'Mesai bulunamadı.'); header('Location: gunluk_isci_puantaj.php'); exit; }

// ⚠ Faz 9A / M-01 düzeltmesi: bu sayfa ?id= ile DOĞRUDAN açılıyor —
// GÖRÜNTÜLEME dahil, oturumun aktif depoya ait olduğu SUNUCU tarafında
// doğrulanır. Aşağıdaki puantaj_duzeltme/puantaj_iptal/yeniden_ac zaten
// KENDİ depo kontrollerini de yapıyor (bkz. pdks_faz8j_aktif_depo_kontrol,
// pdks_gunluk_oturum_yeniden_ac) — bu, sayfanın TAMAMI (özet/kart listesi/
// iptal geçmişi) için erken ve tek bir kapıdır.
if ($depoHata = pdks_gunluk_depo_kontrol((string)$oturum['depo'])) {
    forbidden($depoHata);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'yeniden_ac') {
    csrf_check($_POST['csrf'] ?? null);
    $sonuc = pdks_gunluk_oturum_yeniden_ac((int)$id, (string)($_POST['sebep'] ?? ''), (int)$auth_user['id'], $pdo);
    set_flash($sonuc['ok'] ? 'success' : 'error', $sonuc['ok'] ? 'Mesai yeniden açıldı. Aynı oturumda taramaya devam edebilirsiniz.' : $sonuc['hata']);
    header('Location: gunluk_isci_puantaj_detay.php?id=' . (int)$id);
    exit;
}

// v299: Kapanış notu düzenleme (yalnız yönetici; yetki/depo/kapalı-mesai kapıları işlevin İÇİNDE).
// Mesai id'si SUNUCUDAN ($id) — istemci mesai kimliği göndermez.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'kapanis_notu') {
    csrf_check($_POST['csrf'] ?? null);
    $sonuc = pdks_gunluk_oturum_not_guncelle((int)$id, (string)($_POST['kapanis_notu'] ?? ''), (int)$auth_user['id'], $pdo);
    set_flash($sonuc['ok'] ? 'success' : 'error', $sonuc['ok'] ? (!empty($sonuc['degisti']) ? 'Kapanış notu güncellendi.' : 'Kapanış notu zaten bu şekilde.') : $sonuc['hata']);
    header('Location: gunluk_isci_puantaj_detay.php?id=' . (int)$id);
    exit;
}

$aktifDepo = function_exists('active_depot') ? (active_depot() ?? '') : '';
// v299: "✏ Notu Düzenle" — yalnız yönetici, kapalı mesai, mesai aktif depoda (her tarih).
$notDuzenleGoster = is_admin() && $oturum['status'] === 'closed' && $aktifDepo !== '' && $oturum['depo'] === $aktifDepo;

// v295: KARIŞIK → Otomatik Ata / Geri Al (yalnız yönetici; işlevler yetki + depo +
// kesin hakediş + istek_id kapılarını KENDİLERİ uygular). Mesai id'si SUNUCUDAN.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['karisik_ata', 'karisik_geri_al'], true)) {
    csrf_check($_POST['csrf'] ?? null);
    if (($_POST['action'] ?? '') === 'karisik_ata') {
        $sonuc = pdks_faz8j_karisik_ata((int)$id, (int)($_POST['kadin'] ?? 0), (int)($_POST['erkek'] ?? 0),
            (string)($_POST['reason'] ?? ''), (string)($_POST['istek_id'] ?? ''), (int)$auth_user['id'], $pdo);
        $mesaj = $sonuc['ok'] ? ((int)$sonuc['kadin'] . ' Kadın, ' . (int)$sonuc['erkek'] . ' Erkek atandı (' . $sonuc['atama_id'] . ').'
            . ((int)$sonuc['kalan'] > 0 ? ' ' . (int)$sonuc['kalan'] . ' kayıt Karışık kaldı.' : '')) : $sonuc['hata'];
    } else {
        $kaId = trim((string)($_POST['atama_id'] ?? ''));
        $kaKayit = pdks_faz8j_karisik_atama_bul($pdo, $kaId);
        // Atama BU mesaiye ait olmalı (başka mesaiyi bu sayfadan değiştirtmeyi engeller).
        $sonuc = (!$kaKayit || (int)$kaKayit['record_id'] !== (int)$id)
            ? ['ok' => false, 'hata' => 'Atama bu mesaiye ait değil ya da bulunamadı.']
            : pdks_faz8j_karisik_geri_al($kaId, (string)($_POST['reason'] ?? ''), (int)$auth_user['id'], $pdo);
        $mesaj = $sonuc['ok'] ? ((int)$sonuc['geri_alinan'] . ' kayıt yeniden Karışık yapıldı (atama ' . $kaId . ' geri alındı).'
            . ((int)$sonuc['atlanan'] > 0 ? ' ' . (int)$sonuc['atlanan'] . ' kayıt değiştirildiği/iptal edildiği için atlandı.' : '')) : $sonuc['hata'];
    }
    set_flash($sonuc['ok'] ? 'success' : 'error', $mesaj);
    header('Location: gunluk_isci_puantaj_detay.php?id=' . (int)$id); exit;
}
// v299: Servis Ücreti ekle / iptal (yalnız yönetici; işlevler yetki + depo + mesai kilidi +
// kesin hakediş + istek_id kapılarını KENDİLERİ uygular). Mesai id'si SUNUCUDAN.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['servis_ekle', 'servis_iptal'], true)) {
    csrf_check($_POST['csrf'] ?? null);
    if (($_POST['action'] ?? '') === 'servis_ekle') {
        $sonuc = pdks_servis_ekle((int)$id, (int)($_POST['buyuk'] ?? 0), (int)($_POST['kucuk'] ?? 0),
            (string)($_POST['note'] ?? ''), (string)($_POST['istek_id'] ?? ''), (int)$auth_user['id'], $pdo);
        $mesaj = $sonuc['ok'] ? pdks_servis_ekle_mesaji($sonuc) : $sonuc['hata'];
    } else {
        $sonuc = pdks_servis_iptal((int)($_POST['servis_id'] ?? 0), (int)$id, (string)($_POST['reason'] ?? ''), (int)$auth_user['id'], $pdo);
        $mesaj = $sonuc['ok'] ? 'Servis kaydı iptal edildi. Taslak hakediş yeniden hesaplanmalıdır.' : $sonuc['hata'];
    }
    set_flash($sonuc['ok'] ? 'success' : 'error', $mesaj);
    header('Location: gunluk_isci_puantaj_detay.php?id=' . (int)$id . '#servisler'); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['puantaj_duzeltme', 'puantaj_iptal', 'puantaj_ekle', 'toplu_geri_al'], true)) {
    csrf_check($_POST['csrf'] ?? null);
    if (($_POST['action'] ?? '') === 'toplu_geri_al') {
        // v294: toplu işlemi geri al. Toplu kimlik BU mesaiye ait olmalı (başka mesaiyi bu sayfadan iptal ettirmeyi engeller).
        $tpId = trim((string)($_POST['toplu_id'] ?? ''));
        $tpKayit = function_exists('pdks_faz8j_toplu_bul') ? pdks_faz8j_toplu_bul($pdo, $tpId) : null;
        if (!$tpKayit || (int)$tpKayit['record_id'] !== (int)$id) {
            $sonuc = ['ok' => false, 'hata' => 'Toplu işlem bu mesaiye ait değil ya da bulunamadı.'];
        } else {
            $sonuc = pdks_faz8j_toplu_geri_al($tpId, (string)($_POST['reason'] ?? ''), (int)$auth_user['id'], $pdo);
        }
        set_flash($sonuc['ok'] ? 'success' : 'error', $sonuc['ok'] ? ((int)$sonuc['iptal_edilen'] . ' kayıt iptal edildi (toplu işlem ' . $tpId . ' geri alındı).' . ((int)$sonuc['atlanan'] > 0 ? ' ' . (int)$sonuc['atlanan'] . ' kayıt zaten iptaldi.' : '')) : $sonuc['hata']);
        header('Location: gunluk_isci_puantaj_detay.php?id=' . (int)$id); exit;
    }
    if (($_POST['action'] ?? '') === 'puantaj_ekle') {
        // v291: geçmişe dönük çalışma ekle (yalnız yönetici — işlev kendisi de kontrol eder).
        // Çavuş/tarih/depo SUNUCU değerleridir (array_merge: istemci ezemez).
        // v294: kart alanı 'kartsiz' → kartsiz=1 (açık alan; (int) dönüşümüne güvenilmez).
        $ekleGirdi = $_POST;
        if (($ekleGirdi['worker_card_id'] ?? '') === 'kartsiz') { $ekleGirdi['kartsiz'] = 1; $ekleGirdi['worker_card_id'] = 0; } else { unset($ekleGirdi['kartsiz']); }
        $sonuc = pdks_faz8j_gecmis_ekle(array_merge($ekleGirdi, [
            'foreman_id' => (int)$oturum['foreman_id'], 'work_date' => (string)$oturum['work_date'], 'depo' => $aktifDepo,
        ]), (int)$auth_user['id'], $pdo);
        $ekleMesaj = 'Çalışma kaydı eklendi.';
        if ($sonuc['ok']) {
            if (!empty($sonuc['kartsiz'])) $ekleMesaj = 'Kartsız çalışma kaydı eklendi (' . $sonuc['card_no'] . ').';
            elseif (!empty($sonuc['acik'])) $ekleMesaj = 'Çalışma kaydı eklendi; kişi içeride yazıldı, çıkışta kartını okutacak.';
            if (!empty($sonuc['yeni_mesai'])) $ekleMesaj .= ' Bu gün için yeni mesai açıldı.';
            // v298: tanımlı kart başka çavuş/tip/depoya bağlıysa ENGEL DEĞİL, bilgi.
            if (!empty($sonuc['uyarilar'])) $ekleMesaj .= ' ⚠ ' . implode(' ', $sonuc['uyarilar']);
        }
        set_flash($sonuc['ok'] ? 'success' : 'error', $sonuc['ok'] ? $ekleMesaj : $sonuc['hata']);
        header('Location: gunluk_isci_puantaj_detay.php?id=' . (int)($sonuc['ok'] ? $sonuc['session_id'] : $id)); exit;
    }
    if (($_POST['action'] ?? '') === 'puantaj_iptal') {
        $sonuc = pdks_faz8j_void((int)($_POST['period_id'] ?? 0), (int)$id, $aktifDepo, (string)($_POST['reason'] ?? ''), (int)$auth_user['id'], $pdo);
    } else {
        $sonuc = pdks_faz8j_duzelt($_POST + ['session_id' => $id, 'depo' => $aktifDepo], (int)$auth_user['id'], $pdo);
    }
    set_flash($sonuc['ok'] ? 'success' : 'error', $sonuc['ok'] ? 'Puantaj kaydı güncellendi.' : $sonuc['hata']);
    header('Location: gunluk_isci_puantaj_detay.php?id=' . (int)$id); exit;
}

$yenidenAcGoster = function_exists('is_admin') && is_admin()
    && $oturum['status'] === 'closed'
    && $oturum['work_date'] === date('Y-m-d')
    && $aktifDepo !== '' && $oturum['depo'] === $aktifDepo;
$kesinHakedis = null;
$hakedisKontrolHatasi = false;
if ($yenidenAcGoster) {
    try {
        $stEnt = $pdo->prepare("SELECT id FROM foreman_daily_entitlements WHERE session_id = ? AND status = 'final'");
        $stEnt->execute([(int)$id]);
        $kesinHakedis = $stEnt->fetchColumn() ?: null;
    } catch (PDOException $e) {
        $hakedisKontrolHatasi = true;
    }
}

$ozet   = pdks_gunluk_oturum_ozet($id, $pdo);
$durum  = pdks_gunluk_oturum_durumu((string)$oturum['status'], (int)$ozet['eksik_toplam']);
$kartlar = pdks_gunluk_oturum_kartlari($id, $pdo);
// v299: Kart Hareketleri varsayılan sırası = EN YENİ İŞLEM ÜSTTE (JS kapalıyken de). Son işlem
// zamanı: çıkış varsa çıkış, yoksa giriş; beraberlikte dönem id'si büyük olan üstte. Sıralama
// YALNIZ bu sayfada — paylaşılan pdks_gunluk_faz8a_oturum_donemleri() SQL'i (ASC) Çavuş Gün
// Sonu Fişi ile ortaktır ve kronolojik KALIR.
$sonIslemZamani = static fn(array $k): int => (int)(strtotime((string)(!empty($k['cikis_saat']) ? $k['cikis_saat'] : ($k['giris_saat'] ?? ''))) ?: 0);
usort($kartlar, static fn(array $a, array $b): int => [$sonIslemZamani($b), (int)($b['period_id'] ?? 0)] <=> [$sonIslemZamani($a), (int)($a['period_id'] ?? 0)]);
$faz8jHazir = function_exists('pdks_faz8j_sema_hazir') && pdks_faz8j_sema_hazir($pdo);
$manuelCikisYetkisi = function_exists('pdks_hakedis_can') && pdks_hakedis_can('entitlements_finalize');
$manuelCikisDepoUygun = $oturum['depo'] === $aktifDepo;
// ⚠ Faz 9E / E: bu oturumun dönemlerine (period_id) DETERMİNİSTİK bağlı
// iptal/düzeltme geçmişi — bkz. pdks_gunluk_puantaj_denetim_gecmisi() docblock.
$denetimGecmisi = pdks_gunluk_puantaj_denetim_gecmisi(array_column($kartlar, 'period_id'), $pdo, 20, (int)$id);   // v294: sessionId → toplu işlem satırları da gelir
$iptaller = [];
if ($faz8jHazir && is_admin() && $oturum['depo'] === $aktifDepo) {
    $stVoid = $pdo->prepare("SELECT p.*, w.card_no, u.display_name FROM daily_worker_work_periods p JOIN worker_cards w ON w.id=p.worker_card_id LEFT JOIN users u ON u.id=p.voided_by_user_id WHERE p.session_id=? AND p.is_voided=1 ORDER BY p.voided_at DESC");
    $stVoid->execute([$id]); $iptaller = $stVoid->fetchAll();
}
$duzeltmeKartlar = $faz8jHazir && is_admin() ? $pdo->query("SELECT id, card_no FROM worker_cards WHERE status <> 'disabled' ORDER BY card_no")->fetchAll() : [];
// ⚠ Faz 9B / H-01 kapanışı: TEK paylaşılan politikadan (config/pdks_gunluk.php)
// gelir — backend'in (pdks_faz8j_desteklenen_tip(), AYNI politikayı SARAR)
// kabul ettiğiyle BİREBİR AYNI küme. UI'nin sunduğu bir tip backend'de
// asla reddedilmez.
$duzeltmeTipler = $faz8jHazir && is_admin() ? pdks_gunluk_desteklenen_tip_listele($pdo) : [];
// v291: "Çalışma Ekle" — yalnız yönetici, bu mesai aktif depoda ve şema hazırken.
$ekleGoster = $faz8jHazir && is_admin() && $oturum['depo'] === $aktifDepo && function_exists('pdks_faz8j_gecmis_ekle');
$ekleKartlar = $ekleGoster ? pdks_faz8j_bos_kartlar((string)$oturum['work_date'], $pdo) : [];
// v294: Toplu İşlem — boş kartlar işçi tipiyle birlikte (kartları tipine göre öne almak için), mesaiye ait toplu işlemler.
$topluKartlar = [];
if ($ekleKartlar) {
    $stTk = $pdo->prepare('SELECT id, card_no, worker_type_id FROM worker_cards WHERE id IN (' . implode(',', array_fill(0, count($ekleKartlar), '?')) . ') ORDER BY card_no');
    $stTk->execute(array_map('intval', array_column($ekleKartlar, 'id')));
    $topluKartlar = $stTk->fetchAll();
}
$topluListe = $ekleGoster && function_exists('pdks_faz8j_toplu_listele') ? pdks_faz8j_toplu_listele((int)$id, $pdo) : [];
// v299: Servis Ücreti — pencere/iptal YALNIZ "Çalışma Ekle" ile AYNI kapı ($ekleGoster) + şema
// + gelecek gün değil; liste herkese (salt okunur). Tablo yoksa özellik GİZLİ.
$servisHazir = pdks_servis_sema_hazir($pdo);
$servisGoster = $ekleGoster && $servisHazir && (string)$oturum['work_date'] <= date('Y-m-d');
$servisListe = $servisHazir ? pdks_servis_listele((int)$id, $pdo) : [];
$servisFiyat = $servisGoster ? pdks_servis_ucret_gecerli((int)$oturum['foreman_id'], (string)$oturum['work_date'], $pdo) : null;
$servisKesin = $servisGoster && pdks_faz8j_entitlement($pdo, (int)$id) === 'final';
$servisFiyatLink = function_exists('pdks_hakedis_can') && pdks_hakedis_can('rates');
// v295: Karışık havuzu (herkese bilgi kartı; Otomatik Ata yalnız Toplu İşlem ile AYNI kapı: $ekleGoster).
$karisikTipId = $ekleGoster ? (pdks_gunluk_karisik_tip_garanti($pdo)['id'] ?? null) : pdks_gunluk_karisik_tip_id($pdo);
$karisikTipId = $karisikTipId !== null ? (int)$karisikTipId : null;
$karisikOzet = function_exists('pdks_faz8j_karisik_ozet') ? pdks_faz8j_karisik_ozet((int)$id, $pdo) : ['karisik_kalan' => 0, 'acik' => 0, 'kapali' => 0];
$karisikAtamalar = $ekleGoster && function_exists('pdks_faz8j_karisik_atamalar') ? pdks_faz8j_karisik_atamalar((int)$id, $pdo) : [];
// v294: kartsız (sanal kartlı) dönemler — rozet + Düzenle'de kart kilidi.
$kartsizKartIds = [];
if ($kartlar && pdks_gunluk_kolon_var($pdo, 'worker_cards', 'enrolled_source')) {
    $stKs = $pdo->prepare('SELECT id, enrolled_source FROM worker_cards WHERE id IN (' . implode(',', array_fill(0, count($kartlar), '?')) . ')');
    $stKs->execute(array_map('intval', array_column($kartlar, 'worker_card_id')));
    foreach ($stKs->fetchAll() as $kr) if (function_exists('pdks_faz8j_kartsiz_mi') && pdks_faz8j_kartsiz_mi($kr)) $kartsizKartIds[(int)$kr['id']] = true;
}
// v299: Mesai Tanımı — dönem id → sınıflandırma (faz8b şeması yoksa sütun gizli).
$mesaiTanimGoster = function_exists('pdks_faz8b_sema_hazir') && pdks_faz8b_sema_hazir($pdo);
$mesaiTanimF = [];
if ($mesaiTanimGoster && $kartlar) {
    foreach (pdks_faz8b_oturum_donemleri((int)$id, $pdo) as $mtD) $mesaiTanimF[(int)$mtD['id']] = $mtD['faz8b'];
}
$mesaiTanimMetni = function (array $k) use ($mesaiTanimF, $karisikTipId, $oturum): string {
    $karisik = $karisikTipId !== null && (int)($k['worker_type_id_snapshot'] ?? 0) === $karisikTipId;
    $suruyor = empty($k['cikis_saat']) && ($oturum['status'] ?? '') === 'open';
    return pdks_faz8b_mesai_tanimi_etiketi($mesaiTanimF[(int)($k['period_id'] ?? 0)] ?? null, $suruyor, $karisik);
};
// v299: başlık sıralaması için hücre/kart HAM değerleri (zaman = epoch, süre = saniye, metin = küçük harf;
// boş = sona). Görünen metni DEĞİŞTİRMEZ — yalnız data-sirala-deger / data-sd-* öznitelikleri.
$sdDegerler = function (array $k) use ($mesaiTanimMetni, $mesaiTanimGoster, $sonIslemZamani): array {
    $g = strtotime((string)($k['giris_saat'] ?? ''));
    $c = !empty($k['cikis_saat']) ? strtotime((string)$k['cikis_saat']) : false;
    $tanim = $mesaiTanimGoster ? $mesaiTanimMetni($k) : '';
    return [
        'kart'   => mb_strtolower((string)($k['card_no'] ?? ''), 'UTF-8'),
        'tip'    => mb_strtolower((string)($k['tip'] ?? ''), 'UTF-8'),
        'mesai'  => mb_strtolower((string)($k['mesai_sinifi_etiket'] ?? ''), 'UTF-8'),
        'giris'  => $g !== false ? (string)$g : '',
        'cikis'  => $c !== false ? (string)$c : '',
        'sure'   => ($g !== false && $c !== false && $c >= $g) ? (string)($c - $g) : '',
        // Durum etiketi emoji ile başlar (✅/⚠️) — emoji sıralamayı bozmasın.
        'durum'  => mb_strtolower((string)preg_replace('/^[^\p{L}\p{N}]+/u', '', (string)($k['durum']['etiket'] ?? '')), 'UTF-8'),
        'tanim'  => ($tanim === '—') ? '' : mb_strtolower($tanim, 'UTF-8'),
        'son'    => (string)$sonIslemZamani($k),
    ];
};
// v294: Toplu İşlem JSON uçları (çıktıdan ÖNCE). Çavuş/gün/depo mesaiden gelir.
$topluAjaxKapi = $ekleGoster && $oturum['work_date'] <= date('Y-m-d');
$topluAjaxSabit = ['foreman_id' => (int)$oturum['foreman_id'], 'work_date' => (string)$oturum['work_date'], 'depo' => $aktifDepo];
require __DIR__ . '/_puantaj_toplu_ajax.php';

render_header('Mesai Detayı');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>

<div class="page-head">
    <h1>📅 <?= h($oturum['foreman_name_snapshot']) ?></h1>
    <div class="page-head-actions">
        <a href="gunluk_isci_puantaj.php" class="btn btn-geri">← Günlük Puantaj</a>
        <?php if (is_admin() && $oturum['status'] === 'open' && $oturum['work_date'] === date('Y-m-d') && $oturum['depo'] === $aktifDepo): ?>
        <a href="gunluk_isci_giris_cikis.php" class="btn btn-primary">Giriş / Çıkışa Dön</a>
        <?php endif; ?>
        <a href="gunluk_puantaj_yazdir.php?id=<?= (int)$id ?>" class="btn btn-ghost">🖨️ Yazdır — Çavuş Gün Sonu Fişi</a>
    </div>
</div>

<?php if ($yenidenAcGoster): ?>
<div class="card" style="padding:18px 20px;margin-bottom:18px">
    <h2 style="margin-top:0;font-size:1rem">Mesaiyi Yeniden Aç · Yalnız Yönetici</h2>
    <?php if ($hakedisKontrolHatasi): ?>
    <p>Hakediş durumu doğrulanamadı. Mesaiyi yeniden açmadan önce muhasebe kaydını kontrol edin.</p>
    <?php elseif ($kesinHakedis): ?>
    <p>Bu mesai için kesinleşmiş hakediş bulunmaktadır. Önce hakedişi admin tarafından yeniden açın.</p>
    <a class="btn" href="cavus_hakedis_detay.php?id=<?= (int)$kesinHakedis ?>">Hakediş Detayı</a>
    <?php else: ?>
    <form method="post" onsubmit="return confirm('Bu mesaiyi aynı oturum kimliğiyle yeniden açmak istiyor musunuz?');">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="yeniden_ac">
        <label><span class="form-label">Yeniden açma gerekçesi *</span>
            <textarea name="sebep" rows="2" maxlength="500" required placeholder="Örn. Yanlışlıkla kapatıldı; operasyon devam ediyor"></textarea>
        </label>
        <button type="submit" class="btn btn-primary" style="margin-top:10px">Mesaiyi Yeniden Aç</button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="pdks-kiosk-counters" style="margin:0 0 18px">
    <h3><?= h(date('d.m.Y', strtotime($oturum['work_date']))) ?><?= $oturum['depo'] ? ' — ' . h($oturum['depo']) : '' ?>
        · <span class="pdks-badge pdks-badge-<?= h($durum['kod']) ?>"><?= h($durum['etiket']) ?></span></h3>
    <div class="pdks-kiosk-counter-totals">
        <?php foreach (pdks_gunluk_tip_sistem_sutunlari() as $tc): /* v299: Kadın / Erkek / Rampacı — tip kayıt defterinden */ ?>
        <div class="pdks-kiosk-counter-box"><div class="lbl"><?= h($tc['kisa']) ?></div><div class="val"><?= (int)($ozet['giris'][$tc['ad']] ?? 0) ?></div></div>
        <?php endforeach; ?>
        <?php if ((int)($ozet['giris'][PDKS_GUNLUK_KARISIK_AD] ?? 0) > 0): ?><div class="pdks-kiosk-counter-box"><div class="lbl">Karışık</div><div class="val"><?= (int)$ozet['giris'][PDKS_GUNLUK_KARISIK_AD] ?></div></div><?php endif; ?>
        <div class="pdks-kiosk-counter-box"><div class="lbl">Toplam Giriş</div><div class="val"><?= (int)$ozet['giris_toplam'] ?></div></div>
        <div class="pdks-kiosk-counter-box"><div class="lbl">Toplam Çıkış</div><div class="val"><?= (int)$ozet['cikis_toplam'] ?></div></div>
        <div class="pdks-kiosk-counter-box eksik"><div class="lbl">Eksik Çıkış</div><div class="val"><?= (int)$ozet['eksik_toplam'] ?></div></div>
    </div>
</div>

<div class="table-wrap pc-only" style="margin-bottom:18px">
<table class="data-table">
<tbody>
<tr><th style="width:180px">Çavuş</th><td><?= h($oturum['foreman_name_snapshot']) ?> (<?= h($oturum['foreman_code_snapshot']) ?>)</td></tr>
<tr><th>Tarih</th><td><?= h(date('d.m.Y', strtotime($oturum['work_date']))) ?></td></tr>
<tr><th>Depo</th><td><?= h($oturum['depo'] ?: '—') ?></td></tr>
<tr><th>Açılış</th><td><?= h(date('d.m.Y H:i', strtotime($oturum['opened_at']))) ?> — <?= h(pdks_gunluk_kullanici_adi($oturum['opened_by_user_id'] !== null ? (int)$oturum['opened_by_user_id'] : null, $pdo)) ?></td></tr>
<tr><th>Kapanış</th><td><?= $oturum['closed_at'] ? h(date('d.m.Y H:i', strtotime($oturum['closed_at']))) . ' — ' . h(pdks_gunluk_kullanici_adi($oturum['closed_by_user_id'] !== null ? (int)$oturum['closed_by_user_id'] : null, $pdo)) : '—' ?></td></tr>
<tr><th>İlk Giriş</th><td><?= $ozet['ilk_giris'] ? h(date('H:i', strtotime($ozet['ilk_giris']))) : '—' ?></td></tr>
<tr><th>Son Çıkış</th><td><?= $ozet['son_cikis'] ? h(date('H:i', strtotime($ozet['son_cikis']))) : '—' ?></td></tr>
<tr><th>Kapanış Notu</th><td><div class="pdks-not-satir"><span class="pdks-not-metin"><?= h($oturum['notes'] ?: '—') ?></span><?php if ($notDuzenleGoster): ?><button type="button" class="btn btn-sm pdks-not-duzenle" onclick="pdksPuantajDialogAc('kapanisNotu')">✏ Notu Düzenle</button><?php endif; ?></div></td></tr>
</tbody>
</table>
</div>

<div class="pdks-cards mobile-only" style="margin-bottom:18px">
<div class="pdks-card-item">
    <div class="pdks-row-sub">Tarih: <?= h(date('d.m.Y', strtotime($oturum['work_date']))) ?><?= $oturum['depo'] ? ' / ' . h($oturum['depo']) : '' ?></div>
    <div class="pdks-row-sub">Açılış: <?= h(date('d.m.Y H:i', strtotime($oturum['opened_at']))) ?> — <?= h(pdks_gunluk_kullanici_adi($oturum['opened_by_user_id'] !== null ? (int)$oturum['opened_by_user_id'] : null, $pdo)) ?></div>
    <?php if ($oturum['closed_at']): ?>
    <div class="pdks-row-sub">Kapanış: <?= h(date('d.m.Y H:i', strtotime($oturum['closed_at']))) ?> — <?= h(pdks_gunluk_kullanici_adi($oturum['closed_by_user_id'] !== null ? (int)$oturum['closed_by_user_id'] : null, $pdo)) ?></div>
    <?php endif; ?>
    <?php if ($oturum['notes'] || $notDuzenleGoster): ?>
    <div class="pdks-row-sub pdks-not-satir"><span class="pdks-not-metin">Not: <?= h($oturum['notes'] ?: '—') ?></span><?php if ($notDuzenleGoster): ?><button type="button" class="btn btn-sm pdks-not-duzenle" onclick="pdksPuantajDialogAc('kapanisNotu')">✏ Notu Düzenle</button><?php endif; ?></div>
    <?php endif; ?>
</div>
</div>
<?php if ($notDuzenleGoster): /* v299: kapanış notu penceresi — native <dialog> (Mesai Detayı deseni); opener bu sayfadaki pdksPuantajDialogAc ile AYNI gövde */ ?>
<dialog id="kapanisNotu" class="pm-dialog isk-card-modal" aria-labelledby="kapanisNotuBaslik">
<div class="pm-header"><h2 class="pm-title" id="kapanisNotuBaslik">✏ Kapanış Notunu Düzenle</h2><button type="button" class="pm-close" aria-label="Kapat" onclick="this.closest('dialog').close()">✕</button></div>
<form method="post" class="isk-card-modal-body">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="kapanis_notu">
    <p class="muted" style="margin:0 0 10px"><?= h($oturum['foreman_name_snapshot']) ?> · <?= h(date('d.m.Y', strtotime($oturum['work_date']))) ?> mesaisinin kapanış notu. Boş bırakıp kaydetmek notu siler. Bu not yalnız bilgi amaçlıdır; hiçbir hesaplamada kullanılmaz.</p>
    <label><span class="form-label">Kapanış notu</span><textarea name="kapanis_notu" rows="5" maxlength="<?= (int)PDKS_GUNLUK_KAPANIS_NOTU_MAX ?>"><?= h((string)$oturum['notes']) ?></textarea></label>
    <div class="isk-card-form-actions"><button type="submit" class="btn btn-primary">Kaydet</button><button type="button" class="btn" onclick="this.closest('dialog').close()">Vazgeç</button></div>
</form>
</dialog>
<script>
function pdksPuantajDialogAc(id) {   // Mesai Detayı'ndaki ile aynı gövde (o blok koşullu basılır; aynı ad, aynı iş)
    document.querySelectorAll('dialog[open]').forEach(function (d) { d.close(); });
    document.getElementById(id).showModal();
}
</script>
<?php endif; ?>

<?php if ((int)$karisikOzet['karisik_kalan'] > 0): ?>
<div class="pdks-karisik-uyari" id="karisikUyari" role="status">
    <div>
        <strong>🎲 <?= (int)$karisikOzet['karisik_kalan'] ?> Karışık kayıt atanmamış</strong>
        <div class="muted"><?= (int)$karisikOzet['acik'] > 0 ? (int)$karisikOzet['acik'] . ' kişi içeride · ' : '' ?>Hakediş, Karışık kayıtlar Kadın/Erkek'e atanmadan hesaplanamaz.</div>
    </div>
    <?php if ($ekleGoster): ?><button type="button" class="btn btn-primary btn-sm" onclick="pdksPuantajDialogAc('karisikAta')">🎲 Otomatik Ata</button><?php endif; ?>
</div>
<?php endif; ?>

<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:0 0 8px">
    <h2 style="font-size:1.05rem;margin:0">Kart Hareketleri</h2>
    <?php if ($ekleGoster): ?><div style="display:flex;gap:8px;flex-wrap:wrap"><button type="button" class="btn btn-primary btn-sm" onclick="pdksPuantajDialogAc('ekle')">➕ Çalışma Ekle</button><button type="button" class="btn btn-sm" onclick="pdksPuantajDialogAc('toplu')">👥 Toplu İşlem</button><?php if ($servisGoster): ?><button type="button" class="btn btn-sm" onclick="pdksPuantajDialogAc('servis')">🚌 Servis Ücreti</button><?php endif; ?></div><?php endif; ?>
</div>

<?php if (is_admin() && !$faz8jHazir): ?><div class="flash flash-error">Puantaj düzeltme merkezi için Faz 8J migrasyonu henüz çalıştırılmadı.</div><?php endif; ?>

<?php if (empty($kartlar)): ?>
<div class="pdks-empty">
    <span class="pdks-empty-icon" aria-hidden="true">🪪</span>
    <p>Bu mesaide henüz kart hareketi yok.</p>
</div>
<?php else: ?>

<div class="table-wrap pc-only">
<table class="data-table" data-pdks-sirala data-sirala-varsayilan="son işlem, yeni üstte">
<thead><tr>
    <?php /* v299: başlık tıklama sıralaması — config/pdks_liste_ui.php (TEK mekanizma). Manuel Çıkış / İşlem sıralanmaz. */
    foreach (['Kart No' => 'metin', 'Tip' => 'metin', 'Mesai' => 'metin', 'Giriş Saati' => 'zaman', 'Çıkış Saati' => 'zaman', 'Süre' => 'sayi', 'Durum' => 'metin'] as $thEt => $thTip): ?>
    <th data-sirala="<?= $thTip ?>"><button type="button" class="pdks-sirala-btn"><?= h($thEt) ?><span class="pdks-sirala-ok" aria-hidden="true"></span></button></th>
    <?php endforeach; ?>
    <?php if ($mesaiTanimGoster): ?><th data-sirala="metin"><button type="button" class="pdks-sirala-btn">Mesai Tanımı<span class="pdks-sirala-ok" aria-hidden="true"></span></button></th><?php endif; ?>
    <th>Manuel Çıkış</th>
    <?php if (is_admin() && $faz8jHazir && $oturum['depo'] === $aktifDepo): ?><th>İşlem</th><?php endif; ?>
</tr></thead>
<tbody>
<?php foreach ($kartlar as $k):
    // ⚠ Faz 9E / F: 'cikis_yok' (canlı açık dönem) VE 'legacy_unresolved'
    // (geriye aktarılmış tarihsel kayıt) İKİSİ de pdks_faz8e_manuel_cikis_kaydet()
    // tarafından KABUL EDİLİR (bkz. o fonksiyonun status IN ('open','legacy_unresolved')
    // kontrolü) — burada YENİ bir kısıtlama İCAT EDİLMEZ, yalnız YETKİSİZ bir
    // kullanıcının 403'e giden bir bağlantı GÖRMESİ engellenir.
    $manuelUygun = empty($k['cikis_saat']) && in_array($k['durum']['kod'] ?? '', ['cikis_yok', 'legacy_unresolved'], true);
    $sd = $sdDegerler($k);
?>
<tr>
    <td class="pdks-uid" data-sirala-deger="<?= h($sd['kart']) ?>"><?= h($k['card_no']) ?><?php if (!empty($kartsizKartIds[(int)$k['worker_card_id']])): ?> <span class="pdks-badge pdks-badge-kartsiz" title="Kartsız mesai (sanal kart)">Kartsız</span><?php endif; ?><?php if (($k['kaynak'] ?? '') === 'manual'): ?> <span class="pdks-badge pdks-badge-elle" title="Geçmişe dönük elle eklendi">✍ Elle eklendi</span><?php endif; ?><?php if (($k['kaynak'] ?? '') === 'tanimli'): ?> <span class="pdks-badge pdks-badge-tanimli" title="Tanımlı Giriş ile (kartın tanımlı çavuşuna) girildi">🏷 Tanımlı</span><?php endif; ?></td>
    <td data-sirala-deger="<?= h($sd['tip']) ?>"><?php if ($karisikTipId !== null && (int)($k['worker_type_id_snapshot'] ?? 0) === $karisikTipId): ?><span class="pdks-badge pdks-badge-karisik" title="Karışık giriş — Otomatik Ata ile Kadın/Erkek'e atanır">Karışık</span><?php else: ?><?= h($k['tip']) ?><?php endif; ?></td>
    <td class="muted" data-sirala-deger="<?= h($sd['mesai']) ?>"><?= isset($k['mesai_sinifi_etiket']) ? h($k['mesai_sinifi_etiket']) : '—' ?></td>
    <td data-sirala-deger="<?= h($sd['giris']) ?>"><?= h(date('H:i', strtotime($k['giris_saat']))) ?></td>
    <td class="muted" data-sirala-deger="<?= h($sd['cikis']) ?>"><?= $k['cikis_saat'] ? h(date('H:i', strtotime($k['cikis_saat']))) : '—' ?></td>
    <td class="muted" data-sirala-deger="<?= h($sd['sure']) ?>"><?= $k['cikis_saat'] ? h(pdks_gunluk_sure_etiketi($k['giris_saat'], $k['cikis_saat'])) : '—' ?></td>
    <td data-sirala-deger="<?= h($sd['durum']) ?>"><span class="pdks-badge pdks-badge-<?= h($k['durum']['kod']) ?>"><?= h($k['durum']['etiket']) ?></span></td>
    <?php if ($mesaiTanimGoster): ?><td class="pdks-mesai-tanim" data-sirala-deger="<?= h($sd['tanim']) ?>" data-mesai-tanim><?= h($mesaiTanimMetni($k)) ?></td><?php endif; ?>
    <td>
        <?php if ($manuelUygun && $manuelCikisYetkisi && $manuelCikisDepoUygun): ?>
        <a href="manuel_cikis.php?period_id=<?= (int)$k['period_id'] ?>&session_id=<?= (int)$id ?>" class="btn btn-sm">✍️ Manuel Çıkış Gir</a>
        <?php elseif ($manuelUygun): ?>
        <span class="muted" style="font-size:.85em">Yetki gerekir</span>
        <?php else: ?>—<?php endif; ?>
    </td>
    <?php if (is_admin() && $faz8jHazir && $oturum['depo'] === $aktifDepo): ?><td><button type="button" class="btn btn-sm" onclick="pdksPuantajDialogAc('edit<?= (int)$k['period_id'] ?>')">Düzenle</button><button type="button" class="btn btn-sm btn-danger" onclick="pdksPuantajDialogAc('void<?= (int)$k['period_id'] ?>')">Kaydı İptal Et</button></td><?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>

<div class="pdks-sirala-sec-satir mobile-only">
    <label for="kartSiralaSec">Sırala</label>
    <select id="kartSiralaSec" class="pdks-sirala-sec" data-pdks-sirala-sec data-hedef="kartKartlar">
        <option value="">Son işlem (yeni üstte)</option>
        <option value="giris:desc" data-tip="zaman">Giriş (yeni önce)</option>
        <option value="giris:asc" data-tip="zaman">Giriş (eski önce)</option>
        <option value="cikis:desc" data-tip="zaman">Çıkış (yeni önce)</option>
        <option value="cikis:asc" data-tip="zaman">Çıkış (eski önce)</option>
        <option value="kart:asc" data-tip="metin">Kart no (A → Z)</option>
        <option value="tip:asc" data-tip="metin">Tip (A → Z)</option>
        <option value="sure:desc" data-tip="sayi">Süre (uzun önce)</option>
        <option value="sure:asc" data-tip="sayi">Süre (kısa önce)</option>
    </select>
</div>
<div class="pdks-cards mobile-only" id="kartKartlar">
<?php foreach ($kartlar as $k):
    $manuelUygun = empty($k['cikis_saat']) && in_array($k['durum']['kod'] ?? '', ['cikis_yok', 'legacy_unresolved'], true);
    $sd = $sdDegerler($k);
?>
<div class="pdks-card-item" data-sirala-oge<?php foreach (['kart', 'tip', 'giris', 'cikis', 'sure'] as $sdA): ?> data-sd-<?= $sdA ?>="<?= h($sd[$sdA]) ?>"<?php endforeach; ?>>
    <div class="pdks-card-top">
        <div class="pdks-card-meta">
            <div class="pdks-row-name"><?= h($k['card_no']) ?><?php if (!empty($kartsizKartIds[(int)$k['worker_card_id']])): ?> <span class="pdks-badge pdks-badge-kartsiz" title="Kartsız mesai (sanal kart)">Kartsız</span><?php endif; ?> · <?php if ($karisikTipId !== null && (int)($k['worker_type_id_snapshot'] ?? 0) === $karisikTipId): ?><span class="pdks-badge pdks-badge-karisik">Karışık</span><?php else: ?><?= h($k['tip']) ?><?php endif; ?><?= isset($k['mesai_sinifi_etiket']) ? ' · ' . h($k['mesai_sinifi_etiket']) : '' ?><?php if (($k['kaynak'] ?? '') === 'manual'): ?> <span class="pdks-badge pdks-badge-elle" title="Geçmişe dönük elle eklendi">✍ Elle eklendi</span><?php endif; ?><?php if (($k['kaynak'] ?? '') === 'tanimli'): ?> <span class="pdks-badge pdks-badge-tanimli" title="Tanımlı Giriş ile (kartın tanımlı çavuşuna) girildi">🏷 Tanımlı</span><?php endif; ?></div>
            <div class="pdks-row-sub">Giriş <?= h(date('H:i', strtotime($k['giris_saat']))) ?> · Çıkış <?= $k['cikis_saat'] ? h(date('H:i', strtotime($k['cikis_saat']))) : '—' ?><?= $k['cikis_saat'] ? ' · ' . h(pdks_gunluk_sure_etiketi($k['giris_saat'], $k['cikis_saat'])) : '' ?></div>
        </div>
        <span class="pdks-badge pdks-badge-<?= h($k['durum']['kod']) ?>"><?= h($k['durum']['etiket']) ?></span>
    </div>
    <?php if ($mesaiTanimGoster): ?><div class="pdks-row-sub pdks-mesai-tanim" data-mesai-tanim>Mesai Tanımı: <strong><?= h($mesaiTanimMetni($k)) ?></strong></div><?php endif; ?>
    <?php if ($manuelUygun && $manuelCikisYetkisi && $manuelCikisDepoUygun): ?>
    <div style="margin-top:6px"><a href="manuel_cikis.php?period_id=<?= (int)$k['period_id'] ?>&session_id=<?= (int)$id ?>" class="btn btn-sm">✍️ Manuel Çıkış Gir</a></div>
    <?php elseif ($manuelUygun): ?>
    <div class="pdks-row-sub muted">Manuel düzeltme için yetki gerekir</div>
    <?php endif; ?>
    <?php if (is_admin() && $faz8jHazir && $oturum['depo'] === $aktifDepo): ?><div class="isk-card-form-actions"><button type="button" class="btn btn-sm" onclick="pdksPuantajDialogAc('edit<?= (int)$k['period_id'] ?>')">Düzenle</button><button type="button" class="btn btn-sm btn-danger" onclick="pdksPuantajDialogAc('void<?= (int)$k['period_id'] ?>')">Kaydı İptal Et</button></div><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php pdks_liste_ui_js(); /* v299: başlık/Sırala seçici davranışı — TEK ortak script (ikinci çağrı bir şey basmaz) */ ?>

<?php endif; ?>

<?php if (is_admin() && $faz8jHazir && $oturum['depo'] === $aktifDepo): foreach ($kartlar as $k): ?>
<dialog id="edit<?= (int)$k['period_id'] ?>" class="pm-dialog isk-card-modal"><div class="pm-header"><h2 class="pm-title">Çalışma Dönemini Düzenle</h2><button type="button" class="pm-close" onclick="this.closest('dialog').close()">✕</button></div><form method="post" class="isk-card-modal-body"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="puantaj_duzeltme"><input type="hidden" name="period_id" value="<?= (int)$k['period_id'] ?>"><div class="pdks-form-grid"><?php if (!empty($kartsizKartIds[(int)$k['worker_card_id']])): /* v294: kartsız dönem başka karta taşınamaz (sunucu da reddeder) — kart sabit metin + gizli alan */ ?><div><span class="form-label">Kart</span><div class="pdks-uid"><?=h($k['card_no'])?> <span class="pdks-badge pdks-badge-kartsiz">Kartsız</span></div><input type="hidden" name="worker_card_id" value="<?= (int)$k['worker_card_id'] ?>"></div><?php else: $mevcutKartVar = in_array((int)$k['worker_card_id'], array_map('intval', array_column($duzeltmeKartlar, 'id')), true); ?><label><span class="form-label">Kart</span><select name="worker_card_id"><?php if (!$mevcutKartVar): /* pasif kart listede yoksa mevcut kart yine de seçili kalsın */ ?><option value="<?= (int)$k['worker_card_id'] ?>" selected><?=h($k['card_no'])?> (pasif)</option><?php endif; ?><?php foreach($duzeltmeKartlar as $c):?><option value="<?= (int)$c['id'] ?>" <?= (int)$c['id']===(int)$k['worker_card_id']?'selected':'' ?>><?=h($c['card_no'])?></option><?php endforeach;?></select></label><?php endif; ?><label><span class="form-label">İşçi tipi</span><select name="worker_type_id"><?php
    // ⚠ Faz 9B / görev talimatı §7: bu dönemin GEÇERLİ (snapshot) tipi
    // artık desteklenen listede yoksa (tarihsel/başka kurulumdan gelen bir
    // satır — ör. Faz 9B öncesi bir "FORKLIFT" ataması), SESSİZCE listedeki
    // İLK seçeneğe (bambaşka bir tipe) atlamak yerine gerçek değeri gösteren,
    // seçili ama devre dışı bir seçenek eklenir — kaydedilirse backend AÇIK
    // bir mesajla reddeder (pdks_faz8j_desteklenen_tip()), tip SESSİZCE
    // başka bir şeye DÖNÜŞTÜRÜLMEZ.
    $mevcutDestekliMi = in_array((int)$k['worker_type_id_snapshot'], array_column($duzeltmeTipler, 'id'), true);
    if (!$mevcutDestekliMi):
?><option value="<?= (int)$k['worker_type_id_snapshot'] ?>" selected disabled><?= h($k['tip'] ?? '') ?><?= ($karisikTipId !== null && (int)($k['worker_type_id_snapshot'] ?? 0) === $karisikTipId) ? ' (atanmamış — Kadın/Erkek seçin)' : ' (artık desteklenmiyor)' ?></option><?php endif; ?><?php foreach($duzeltmeTipler as $t):?><option value="<?= (int)$t['id'] ?>" <?= (int)$t['id']===(int)$k['worker_type_id_snapshot']?'selected':'' ?>><?=h($t['name'])?></option><?php endforeach;?></select></label><label><span class="form-label">Giriş</span><input name="entry_date" type="date" value="<?=h(substr($k['giris_saat'],0,10))?>"><input name="entry_clock" type="time" value="<?=h(substr($k['giris_saat'],11,5))?>"></label><label><span class="form-label">Çıkış</span><input name="exit_date" type="date" value="<?=h($k['cikis_saat']?substr($k['cikis_saat'],0,10):'')?>"><input name="exit_clock" type="time" value="<?=h($k['cikis_saat']?substr($k['cikis_saat'],11,5):'')?>"></label><label class="span-2"><span class="form-label">Düzeltme nedeni *</span><textarea name="reason" maxlength="500" required></textarea></label><label class="span-2"><span class="form-label">Açıklama</span><textarea name="note" maxlength="1000"></textarea></label></div><div class="isk-card-form-actions"><button class="btn btn-primary">Kaydet</button><button type="button" class="btn" onclick="this.closest('dialog').close()">Vazgeç</button></div></form></dialog>
<dialog id="void<?= (int)$k['period_id'] ?>" class="pm-dialog isk-card-modal"><div class="pm-header"><h2 class="pm-title">Kaydı İptal Et</h2><button type="button" class="pm-close" onclick="this.closest('dialog').close()">✕</button></div><form method="post" class="isk-card-modal-body"><input type="hidden" name="csrf" value="<?=h(csrf_token())?>"><input type="hidden" name="action" value="puantaj_iptal"><input type="hidden" name="period_id" value="<?= (int)$k['period_id'] ?>"><p><?=h($k['card_no'])?> kartının <?=h($k['giris_saat'])?>–<?=h($k['cikis_saat']?:'çıkış yok')?> çalışma kaydı puantajdan çıkarılacaktır. Ham kart okutma geçmişi silinmeyecektir.</p><label><span class="form-label">İptal nedeni *</span><textarea name="reason" maxlength="500" required></textarea></label><div class="isk-card-form-actions"><button class="btn btn-danger">Kaydı İptal Et</button><button type="button" class="btn" onclick="this.closest('dialog').close()">Vazgeç</button></div></form></dialog>
<?php endforeach; ?>
<?php if ($ekleGoster) {
    $ekleWorkDate = (string)$oturum['work_date'];
    $ekleTipler = $duzeltmeTipler;
    $ekleSabitCavus = ['id' => (int)$oturum['foreman_id'], 'name' => (string)$oturum['foreman_name_snapshot']];
    require __DIR__ . '/_puantaj_ekle.php';
    // v294: Toplu İşlem penceresi (aynı çavuş + gün; uçlar bu sayfada)
    $topluWorkDate = $ekleWorkDate; $topluTipler = $duzeltmeTipler; $topluSabitCavus = $ekleSabitCavus;
    $topluUrlOnizle = 'gunluk_isci_puantaj_detay.php?id=' . (int)$id . '&ajax=toplu_onizle';
    $topluUrlEkle   = 'gunluk_isci_puantaj_detay.php?id=' . (int)$id . '&ajax=toplu_ekle';
    require __DIR__ . '/_puantaj_toplu.php';
?>
<?php if ((int)$karisikOzet['karisik_kalan'] > 0): $kaN = (int)$karisikOzet['karisik_kalan']; ?>
<dialog id="karisikAta" class="pm-dialog isk-card-modal isk-karisik" aria-labelledby="karisikAtaBaslik">
<div class="pm-header"><h2 class="pm-title" id="karisikAtaBaslik">🎲 Otomatik Ata</h2><button type="button" class="pm-close" onclick="this.closest('dialog').close()">✕</button></div>
<form method="post" class="isk-card-modal-body" id="karisikAtaForm" data-havuz="<?= $kaN ?>">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="karisik_ata">
    <input type="hidden" name="istek_id" value="<?= h(bin2hex(random_bytes(16))) ?>"><?php /* tekrar gönderim koruması */ ?>
    <p class="ka-havuz"><strong id="kaHavuz"><?= $kaN ?> Karışık atanmamış</strong><?= (int)$karisikOzet['acik'] > 0 ? ' <span class="muted">(' . (int)$karisikOzet['acik'] . ' kişi içeride)</span>' : '' ?><br>
        <span class="muted" style="font-size:.85rem">Girilen sayılar kadar kayıt <b>rastgele</b> Kadın/Erkek'e atanır; kalanlar Karışık kalır. Tam/Yarım ve FM onayları korunur, ham kart okutmaları değişmez.</span></p>
    <div class="ka-sayilar">
        <label><span class="form-label">Kadın</span><input type="number" name="kadin" id="kaKadin" min="0" max="<?= $kaN ?>" step="1" value="0" inputmode="numeric" required></label>
        <label><span class="form-label">Erkek</span><input type="number" name="erkek" id="kaErkek" min="0" max="<?= $kaN ?>" step="1" value="0" inputmode="numeric" required></label>
    </div>
    <div class="ka-toplam" id="kaToplam" aria-live="polite">Toplam 0 / <?= $kaN ?>, kalan <?= $kaN ?></div>
    <label><span class="form-label">Atama nedeni *</span><textarea name="reason" id="kaSebep" maxlength="500" required rows="2"></textarea></label>
    <div class="isk-card-form-actions"><button class="btn btn-primary" id="kaKaydet" disabled>🎲 Ata</button><button type="button" class="btn" onclick="this.closest('dialog').close()">Vazgeç</button></div>
</form>
</dialog>
<script>
(function () {
    var f = document.getElementById('karisikAtaForm'); if (!f) return;
    var n = parseInt(f.getAttribute('data-havuz'), 10) || 0;
    var k = document.getElementById('kaKadin'), e = document.getElementById('kaErkek');
    var top = document.getElementById('kaToplam'), btn = document.getElementById('kaKaydet'), seb = document.getElementById('kaSebep');
    function sayi(el) { var v = parseInt(el.value, 10); return isNaN(v) || v < 0 ? 0 : v; }
    function guncelle() {
        var t = sayi(k) + sayi(e), asim = t > n;
        top.textContent = asim ? ('Toplam ' + t + ' / ' + n + ' — en fazla ' + n + ' atanabilir') : ('Toplam ' + t + ' / ' + n + ', kalan ' + (n - t));
        top.classList.toggle('ka-asim', asim);
        btn.disabled = asim || t < 1 || seb.value.trim() === '';
    }
    [k, e, seb].forEach(function (el) { el.addEventListener('input', guncelle); });
    f.addEventListener('submit', function (ev) {
        var t = sayi(k) + sayi(e);
        if (t < 1 || t > n || seb.value.trim() === '') { ev.preventDefault(); guncelle(); return; }
        btn.disabled = true;   // çift tıklama — sunucu istek_id ile ayrıca korur
    });
    guncelle();
})();
</script>
<?php endif; ?>
<?php if ($karisikAtamalar): ?>
<dialog id="kaGeriAl" class="pm-dialog isk-card-modal">
<div class="pm-header"><h2 class="pm-title">Karışık Atamasını Geri Al</h2><button type="button" class="pm-close" onclick="this.closest('dialog').close()">✕</button></div>
<form method="post" class="isk-card-modal-body">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="karisik_geri_al">
    <input type="hidden" name="atama_id" id="kaGeriAlId" value="">
    <p style="margin:0 0 10px">Atama <strong id="kaGeriAlEtiket"></strong> ile Kadın/Erkek yapılan kayıtlar yeniden Karışık olur. Sonradan elle değiştirilmiş ya da iptal edilmiş kayıtlar atlanır.</p>
    <label><span class="form-label">Geri alma nedeni *</span><textarea name="reason" maxlength="500" required rows="3"></textarea></label>
    <div class="isk-card-form-actions"><button class="btn btn-danger">↩ Geri Al</button><button type="button" class="btn" onclick="this.closest('dialog').close()">Vazgeç</button></div>
</form>
</dialog>
<?php endif; ?>
<?php if ($topluListe): ?>
<dialog id="tpGeriAl" class="pm-dialog isk-card-modal">
<div class="pm-header"><h2 class="pm-title">Toplu İşlemi Geri Al</h2><button type="button" class="pm-close" onclick="this.closest('dialog').close()">✕</button></div>
<form method="post" class="isk-card-modal-body">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="toplu_geri_al">
    <input type="hidden" name="toplu_id" id="tpGeriAlId" value="">
    <p style="margin:0 0 10px">Toplu işlem <strong id="tpGeriAlEtiket"></strong> ile eklenen ve hâlâ aktif olan tüm kayıtlar iptal edilir (puantajdan çıkar). Ham kart okutma geçmişi silinmez.</p>
    <label><span class="form-label">Geri alma nedeni *</span><textarea name="reason" maxlength="500" required rows="3"></textarea></label>
    <div class="isk-card-form-actions"><button class="btn btn-danger">↩ Geri Al</button><button type="button" class="btn" onclick="this.closest('dialog').close()">Vazgeç</button></div>
</form>
</dialog>
<?php endif; ?>
<?php } ?>
<script>
// ⚠ Bu sayfadaki Düzenle/İptal dialog'ları native <dialog> — .pm-overlay
// sarmalayıcısı YOK. Bir dialog açıkken diğerine tıklanırsa ikisi de
// showModal() ile açık kalıp üst üste biner; yeni açmadan önce açık
// olanları kapatmak bunu engeller.
function pdksPuantajDialogAc(id) {
    document.querySelectorAll('dialog[open]').forEach(function (d) { d.close(); });
    document.getElementById(id).showModal();
}
</script>
<?php endif; ?>

<?php if ($servisGoster || $servisListe) require __DIR__ . '/_puantaj_servis.php';   // v299 Servis Ücreti ?>

<?php if ($topluListe): ?>
<h2 style="font-size:1.05rem;margin-top:22px">Toplu İşlemler</h2>
<div class="table-wrap pc-only" style="margin-bottom:18px">
<table class="data-table tp-liste">
<thead><tr><th>Tarih / Saat</th><th>Kullanıcı</th><th>Kayıt</th><th>Sebep</th><th class="actions-col">İşlem</th></tr></thead>
<tbody>
<?php foreach ($topluListe as $tp): ?>
<tr>
    <td><?= h(date('d.m.Y H:i', strtotime($tp['created_at']))) ?><div class="muted" style="font-size:.78rem"><?= h($tp['toplu_id']) ?></div></td>
    <td><?= h($tp['kullanici']) ?></td>
    <td><?= (int)$tp['kartli'] ?> kartlı + <?= (int)$tp['kartsiz'] ?> kartsız <span class="muted">· <?= (int)$tp['aktif'] ?> aktif<?= (int)$tp['iptal'] > 0 ? ', ' . (int)$tp['iptal'] . ' iptal' : '' ?></span></td>
    <td><?= h($tp['reason']) ?><?= $tp['note'] !== '' ? '<div class="muted" style="font-size:.82rem">' . h($tp['note']) . '</div>' : '' ?></td>
    <td class="actions-col"><?php if ((int)$tp['aktif'] > 0): ?><button type="button" class="btn btn-sm btn-danger" data-tp-geri="<?= h($tp['toplu_id']) ?>">↩ Geri Al</button><?php else: ?><span class="muted"><?= $tp['geri_alindi'] ? 'Geri alındı' : 'Tümü iptal' ?></span><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<div class="pdks-cards mobile-only" style="margin-bottom:18px">
<?php foreach ($topluListe as $tp): ?>
<div class="pdks-card-item">
    <div class="pdks-row-sub"><?= h(date('d.m.Y H:i', strtotime($tp['created_at']))) ?> · <?= h($tp['kullanici']) ?></div>
    <div class="pdks-row-name" style="font-size:.95rem"><?= (int)$tp['kartli'] ?> kartlı + <?= (int)$tp['kartsiz'] ?> kartsız · <?= (int)$tp['aktif'] ?> aktif<?= (int)$tp['iptal'] > 0 ? ', ' . (int)$tp['iptal'] . ' iptal' : '' ?></div>
    <div class="pdks-row-sub"><?= h($tp['reason']) ?></div>
    <?php if ((int)$tp['aktif'] > 0): ?><div class="isk-card-form-actions"><button type="button" class="btn btn-sm btn-danger" data-tp-geri="<?= h($tp['toplu_id']) ?>">↩ Geri Al</button></div><?php else: ?><div class="pdks-row-sub muted"><?= $tp['geri_alindi'] ? 'Geri alındı' : 'Tümü iptal' ?></div><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<script>
document.querySelectorAll('[data-tp-geri]').forEach(function (b) {
    b.addEventListener('click', function () {
        var id = b.getAttribute('data-tp-geri');
        document.getElementById('tpGeriAlId').value = id;
        document.getElementById('tpGeriAlEtiket').textContent = id;
        pdksPuantajDialogAc('tpGeriAl');
    });
});
</script>
<?php endif; ?>

<?php if ($karisikAtamalar): ?>
<h2 style="font-size:1.05rem;margin-top:22px">Karışık Atamaları</h2>
<div class="pdks-cards" style="margin-bottom:18px">
<?php foreach ($karisikAtamalar as $ka): ?>
<div class="pdks-card-item">
    <div class="pdks-row-sub"><?= h(date('d.m.Y H:i', strtotime($ka['created_at']))) ?> · <?= h($ka['kullanici']) ?> · <span class="muted"><?= h($ka['atama_id']) ?></span></div>
    <div class="pdks-row-name" style="font-size:.95rem"><?= (int)$ka['kadin'] ?> Kadın, <?= (int)$ka['erkek'] ?> Erkek</div>
    <div class="pdks-row-sub"><?= h($ka['reason']) ?></div>
    <?php if (!$ka['geri_alindi'] && (int)$ka['geri_alinabilir'] > 0): ?><div class="isk-card-form-actions"><button type="button" class="btn btn-sm btn-danger" data-ka-geri="<?= h($ka['atama_id']) ?>">↩ Geri Al</button></div>
    <?php else: ?><div class="pdks-row-sub muted"><?= $ka['geri_alindi'] ? 'Geri alındı' : 'Geri alınabilecek kayıt kalmadı' ?></div><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<script>
document.querySelectorAll('[data-ka-geri]').forEach(function (b) {
    b.addEventListener('click', function () {
        var id = b.getAttribute('data-ka-geri');
        document.getElementById('kaGeriAlId').value = id;
        document.getElementById('kaGeriAlEtiket').textContent = id;
        pdksPuantajDialogAc('kaGeriAl');
    });
});
</script>
<?php endif; ?>

<?php if ($iptaller): ?><h2>İptal Edilen Kayıtlar</h2><div class="table-wrap"><table class="data-table"><thead><tr><th>Kart No</th><th>Tip</th><th>Giriş</th><th>Çıkış</th><th>İptal nedeni</th><th>İptal eden</th><th>İptal zamanı</th></tr></thead><tbody><?php foreach($iptaller as $v): ?><tr><td><?=h($v['card_no'])?></td><td><?=h($v['worker_type_name_snapshot'])?></td><td><?=h($v['entry_time'])?></td><td><?=h($v['exit_time']?:'—')?></td><td><?=h($v['void_reason'])?></td><td><?=h($v['display_name']?:'—')?></td><td><?=h($v['voided_at'])?></td></tr><?php endforeach;?></tbody></table></div><?php endif; ?>

<?php if ($denetimGecmisi): ?>
<!-- ⚠ Faz 9E / E: BAĞLAMSAL geçmiş — audit.php'nin genel/admin kayıt
     defteri DEĞİL, yalnız bu oturumun dönemlerine deterministik bağlı
     iptal/düzeltme satırları. Ham JSON YOK, yalnız okunur etiket/detay. -->
<h2 style="font-size:1.05rem">İşlem Geçmişi</h2>
<div class="pdks-cards">
<?php foreach ($denetimGecmisi as $d): ?>
<div class="pdks-card-item">
    <div class="pdks-row-sub"><?= h(date('d.m.Y H:i', strtotime($d['created_at']))) ?> · <?= h($d['aktor']) ?></div>
    <div class="pdks-row-name" style="font-size:.95rem"><?= h($d['islem_etiket']) ?></div>
    <?php if ($d['detay']): ?><div class="pdks-row-sub"><?= h($d['detay']) ?></div><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php render_footer(); ?>
