<?php
// =========================================================
// puantaj_giris_kaydet.php — Aylık Puantaj › Puantaj Girişi (v554) — TEK YAZMA UCU
//
// Yalnız POST, JSON yanıt. Gövde form alanı ya da JSON olabilir. Alanlar: action (beyaz liste), csrf, + eyleme göre:
//   kisi_ekle     : ad (1–120), gorev (≤80, opsiyonel)
//   kisi_duzenle  : kisi_id, ad, gorev
//   kisi_durum    : kisi_id, aktif (0|1)        — kaldır = pasife al; gün işaretleri silinmez
//   gun_isaretle  : kisi_id, tarih (YYYY-MM-DD), kod (X Y D İ Ü R T; '' = temizle)
//   gun_toplu     : kisi_id, ay (YYYY-MM), mod (haftaici_x | ay_temizle)
//   gun_sutun     : tarih, kod ('' = temizle), q (opsiyonel arama) — görünen AKTİF kişilerin tümüne
// Kapılar: require_login → role_pdks_require_request() (rota kayıtlı; POST = monthly_timesheet.edit) →
// role_pdks_can('monthly_timesheet','edit') → csrf_check (JSON-aware 403) → aktif depo zorunlu →
// tablo hazır (migrate.php › Puantaj Girişi; yoksa 503) → kişi var + depo kuralı (depot_visible_to_user; başka depo 403).
// BAĞIMSIZ veri: employees / PDKS / hakediş tablolarına DOKUNMAZ. Şemayı BU DOSYA kurmaz.
// Her yazma audit_log_event(..., 'puantaj_giris', ...) — audit'e KİŞİ ADI yazılmaz (yalnız id / uzunluk / kod / tarih / adet).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/puantaj_cizelgesi.php';

header('Content-Type: application/json; charset=utf-8');

function pg_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$auth_user = require_login();

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
    pg_json(['ok' => false, 'error' => 'Geçersiz istek.'], 405);
}

role_pdks_require_request();   // rota kayıtlı + POST → monthly_timesheet.edit (JSON-aware 403)
if (!role_pdks_can('monthly_timesheet', 'edit')) {
    pg_json(['ok' => false, 'error' => 'Bu işlem için yetkiniz yok.', 'code' => 403], 403);
}

$in = pi_girdi_coz((string)file_get_contents('php://input'), (string)($_SERVER['CONTENT_TYPE'] ?? ''), $_POST);
csrf_check(isset($in['csrf']) && is_string($in['csrf']) ? $in['csrf'] : null);   // JSON-aware: hatada 403 + JSON
if (function_exists('enforce_active_depot')) enforce_active_depot();
$depo = function_exists('active_depot') ? (string)(active_depot() ?? '') : '';
if ($depo === '') pg_json(['ok' => false, 'error' => 'Önce bir depo seçmelisiniz.'], 400);

$action = $in['action'] ?? null;
if (!is_string($action) || !in_array($action, ['kisi_ekle', 'kisi_duzenle', 'kisi_durum', 'gun_isaretle', 'gun_toplu', 'gun_sutun'], true)) {
    pg_json(['ok' => false, 'error' => 'Geçersiz işlem.'], 400);
}

$pdo = db();
if (!pg_hazir($pdo)) {
    pg_json(['ok' => false, 'error' => 'Puantaj Girişi tabloları kurulmamış. Bir yöneticinin migrate.php › Puantaj Girişi kartını çalıştırması gerekiyor.'], 503);
}
$uid = (int)($auth_user['id'] ?? 0) ?: null;

/** Alan: metin (dizi/nesne reddedilir). */
$metin = static function (string $k) use ($in): ?string {
    return isset($in[$k]) && is_string($in[$k]) ? $in[$k] : null;
};
/** Kişi: kimlik doğrula + var + depo kuralı; aksi halde JSON hata. */
$kisiAl = static function () use ($pdo, $in): array {
    $raw = $in['kisi_id'] ?? null;
    if (!is_scalar($raw) || !ctype_digit((string)$raw) || (int)$raw <= 0) pg_json(['ok' => false, 'error' => 'Geçersiz kişi.'], 400);
    $k = pg_kisi_bul($pdo, (int)$raw);
    if ($k === null) pg_json(['ok' => false, 'error' => 'Kişi bulunamadı.'], 404);
    if (!depot_visible_to_user($k['depo'])) pg_json(['ok' => false, 'error' => 'Bu kişi başka bir depoya ait.', 'code' => 403], 403);
    return $k;
};
$audit = static function (?array $a): void {
    if ($a !== null) audit_log_event($a[0], 'puantaj_giris', $a[1], $a[2], $a[3]);
};

/** Görev: alan yoksa '' (opsiyonel); dizi/nesne gibi metin olmayan değer reddedilir. */
$gorevAl = static function () use ($in, $metin): string {
    if (!array_key_exists('gorev', $in)) return '';
    $g = $metin('gorev');
    if ($g === null) pg_json(['ok' => false, 'error' => 'Geçersiz görev.'], 400);
    return $g;
};

switch ($action) {
    case 'kisi_ekle':
        $s = pg_kisi_ekle($pdo, (string)$metin('ad'), $gorevAl(), $depo, $uid);
        if (!$s['ok']) pg_json(['ok' => false, 'error' => (string)$s['hata']], 400);
        $audit($s['audit']);
        pg_json(['ok' => true, 'islem' => 'create', 'kisi_id' => $s['id']]);

    case 'kisi_duzenle':
        $k = $kisiAl();
        $s = pg_kisi_duzenle($pdo, $k, (string)$metin('ad'), $gorevAl());
        if (!$s['ok']) pg_json(['ok' => false, 'error' => (string)$s['hata']], 400);
        $audit($s['audit']);
        pg_json(['ok' => true, 'islem' => $s['islem'], 'kisi_id' => $k['id']]);

    case 'kisi_durum':
        $k = $kisiAl();
        $a = $in['aktif'] ?? null;
        if (!is_scalar($a) || !in_array((string)$a, ['0', '1'], true)) pg_json(['ok' => false, 'error' => 'Geçersiz durum.'], 400);
        $s = pg_kisi_durum($pdo, $k, (string)$a === '1');
        if (!$s['ok']) pg_json(['ok' => false, 'error' => (string)$s['hata']], 500);
        $audit($s['audit']);
        pg_json(['ok' => true, 'islem' => $s['islem'], 'kisi_id' => $k['id'], 'aktif' => (int)$a]);

    case 'gun_isaretle':
        $k = $kisiAl();
        if ((int)$k['aktif'] !== 1) pg_json(['ok' => false, 'error' => 'Pasif kişiye işaret girilemez. Önce geri alın.'], 400);
        $tarih = $metin('tarih'); $kod = $metin('kod');
        if ($tarih === null || pi_tarih_dogrula($tarih) === null) pg_json(['ok' => false, 'error' => 'Geçersiz tarih.'], 400);
        if ($kod === null || ($kod !== '' && !pg_kod_gecerli($kod))) pg_json(['ok' => false, 'error' => 'Geçersiz işaret kodu.'], 400);
        $s = pg_gun_uygula($pdo, $k['id'], $tarih, $kod, $uid);
        if (!$s['ok']) pg_json(['ok' => false, 'error' => (string)$s['hata']], 400);
        $audit($s['audit']);
        pg_json(['ok' => true, 'islem' => $s['islem'], 'kod' => $s['kod'], 'tarih' => $tarih, 'kisi_id' => $k['id'],
            'degisim' => [['k' => $k['id'], 't' => $tarih, 'c' => $s['kod']]]]);

    case 'gun_toplu':
        $k = $kisiAl();
        if ((int)$k['aktif'] !== 1) pg_json(['ok' => false, 'error' => 'Pasif kişi için toplu işlem yapılamaz. Önce geri alın.'], 400);
        $ay = $metin('ay'); $mod = $metin('mod');
        if ($ay === null || $mod === null) pg_json(['ok' => false, 'error' => 'Geçersiz istek.'], 400);
        $s = pg_toplu_uygula($pdo, $k['id'], $ay, $mod, $uid);
        if (!$s['ok']) pg_json(['ok' => false, 'error' => (string)$s['hata']], 400);
        audit_log_event('update', 'puantaj_giris', $k['id'], null, ['kisi_id' => $k['id'], 'ay' => $ay, 'mod' => $mod, 'adet' => $s['adet']]);
        pg_json(['ok' => true, 'islem' => $mod, 'adet' => $s['adet'], 'kisi_id' => $k['id'], 'degisim' => $s['degisim']]);

    case 'gun_sutun':
        $tarih = $metin('tarih'); $kod = $metin('kod');
        if ($tarih === null || pi_tarih_dogrula($tarih) === null) pg_json(['ok' => false, 'error' => 'Geçersiz tarih.'], 400);
        if ($kod === null || ($kod !== '' && !pg_kod_gecerli($kod))) pg_json(['ok' => false, 'error' => 'Geçersiz işaret kodu.'], 400);
        $q = mb_substr(trim((string)$metin('q')), 0, 60);
        $adaylar = [];
        foreach (pg_kisiler_oku($pdo, false) as $kk) $adaylar[] = ['id' => $kk['id'], 'ad' => $kk['ad'], 'alt' => $kk['gorev']];
        $idler = array_map(static fn($r) => (int)$r['id'], pc_satir_filtre($adaylar, $q));   // ekrandaki arama süzgeciyle AYNI küme
        $s = pg_sutun_uygula($pdo, $tarih, $idler, $kod, $uid);
        if (!$s['ok']) pg_json(['ok' => false, 'error' => (string)$s['hata']], 400);
        if ($s['adet'] > 0) audit_log_event('update', 'puantaj_giris', null, null, ['mod' => 'gun_sutun', 'tarih' => $tarih, 'kod' => $kod, 'adet' => $s['adet']]);
        pg_json(['ok' => true, 'islem' => 'gun_sutun', 'adet' => $s['adet'], 'kisi_sayisi' => count($idler), 'tarih' => $tarih, 'kod' => $kod, 'degisim' => $s['degisim']]);
}
