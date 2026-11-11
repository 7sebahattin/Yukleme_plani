<?php
// =========================================================
// frugro_kaydet.php — Frugro Siparişler: elle düzenleme · durum · silme (POST JSON ya da form-POST + PRG)
//
// POST {csrf, islem:'duzenle', id, alanlar:{…}}              siparis.write — yalnız frgui_duzenle_alanlari() beyaz listesi
// POST {csrf, islem:'durum', id, durum:'hazirlik'|'yola_cikti'|'mail_gonderildi'|'evrak_gonderildi'|'iptal'|'geri'|'yeniden_ac', gerekce}
//   siparis.write — TEK yol frg_durum_degistir() (matris, ön koşullar, koşullu UPDATE, olay, audit). iptal/geri gerekçe ZORUNLU;
//   eski istemcinin 'yeniden_ac'ı 'geri'ye eşlenir; 'kapali' artık kabul edilmez (adım adım: mail → evrak takımı).
// POST {csrf, islem:'sil', id, onay:true}                    siparis.delete — soft silme (iki adımlı onayı arayüz yapar)
// FORM-POST (application/x-www-form-urlencoded; frugro_view.php pencereleri, PRG + flash) — aynı dallar, aynı kurallar:
//   islem=alan (id, alan, deger)  → tek alan ✎ (beyaz liste frgui_duzenle_alanlari; 'duzenle' dalına eşlenir) → #bilgi
//   islem=durum (id, hedef, gerekce, onay_pkl, onay_invoice_yok, onay_evrak) → #durum · islem=sil (id, onay=1) → liste
//   Form dalı ek olarak require_siparis('write') (sil: 'delete') kapısından geçer.
// Mail/evrak onayı SUNUCUDA da zorlanır: mail_gonderildi → onay_pkl; Invoice üretilmemişse onay_invoice_yok; evrak → onay_evrak.
// Her işlem frugro_olaylar'a yazılır ve audit_log_event'e düşer (alan adlarıyla; adres/şoför/telefon DEĞERİ audit'e yazılmaz).
// Başka depoya ait kayıt "bulunamadı" der.
// Ad TEK alt çizgili ek içerir: sw.js bypass regex'i (frugro(_[a-z]+)?.php) bunu önbelleğe almaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/frugro_core.php';
require_once __DIR__ . '/config/frugro_ui.php';
require_once __DIR__ . '/config/frugro_detay.php';

$auth_user = require_login();
siparis_no_store();
$form = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['islem']);   // form-POST (PRG) mı, JSON mı
if ($form) {
    csrf_check($_POST['csrf'] ?? null);
    require_siparis(($_POST['islem'] ?? '') === 'sil' ? 'delete' : 'write');
} else {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
}
$formId = (int)($_POST['id'] ?? 0);
$yanit = function (int $kod, array $veri) use ($form, $formId): void {
    if ($form) {   // PRG: flash + dönüş (hata da başarı da aynı sayfaya; silinen kayıt listeye)
        $ok = $kod < 300 && !empty($veri['ok']);
        set_flash($ok ? 'success' : 'error', (string)($ok ? ($veri['mesaj'] ?? 'Kaydedildi.') : ($veri['hata'] ?? 'İşlem yapılamadı.')));
        $islem = (string)($_POST['islem'] ?? '');
        $hedef = ($kod === 404 || ($ok && $islem === 'sil') || $formId <= 0) ? 'frugro_siparis.php'
            : 'frugro_view.php?id=' . $formId . ($islem === 'alan' ? '#bilgi' : '#durum');
        header('Location: ' . $hedef, true, 303);
        exit;
    }
    http_response_code($kod);
    echo json_encode($veri, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') { header('Allow: POST'); $yanit(405, ['ok' => false, 'hata' => 'Geçersiz istek.']); }
if ($form) {
    $in = $_POST;
    if (isset($in['hedef']) && !isset($in['durum'])) $in['durum'] = $in['hedef'];   // pencere formları OGL gibi 'hedef' gönderir
} else {
    $in = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($in)) $in = [];
    csrf_check($in['csrf'] ?? null);   // JSON-aware: hatada 403 + JSON
}
if (!frgui_yetki('read')) $yanit(403, ['ok' => false, 'hata' => 'Bu işlem için yetkiniz yok.']);
try { enforce_active_depot(); } catch (Throwable $e) { $yanit(403, ['ok' => false, 'hata' => 'Aktif depo seçilmeli.']); }
$pdo = db();
if (!frg_hazir($pdo)) $yanit(409, ['ok' => false, 'hata' => 'Frugro Siparişler henüz kurulmadı (Şema Migrasyon Paneli).']);

$uid = (int)$auth_user['id'];
$id = (int)($in['id'] ?? 0);
$islem = (string)($in['islem'] ?? '');
// Başka depoya ait kayıt, olmayan kayıtla AYNI yanıtı verir
$s = $id > 0 ? frg_getir($pdo, $id) : null;
if ($s === null || !frg_gorunur($s)) $yanit(404, ['ok' => false, 'hata' => 'Sipariş bulunamadı.']);
$simdi = date('Y-m-d H:i:s');
$ozetle = static fn(string $x): string => $x === '' ? '—' : (mb_strlen($x) > 80 ? mb_substr($x, 0, 80) . '…' : $x);
$truthy = static fn($v): bool => $v === true || $v === 1 || $v === '1';

if ($islem === 'alan') {   // ✎ tek alan (form-POST): beyaz listedeki TEK alan 'duzenle' dalına eşlenir
    $a = (string)($in['alan'] ?? '');
    if (!isset(frgui_duzenle_alanlari()[$a]) || is_array($in['deger'] ?? null)) $yanit(400, ['ok' => false, 'hata' => 'Geçersiz alan.']);
    $in['alanlar'] = [$a => (string)($in['deger'] ?? '')];
    $islem = 'duzenle';
}

if ($islem === 'duzenle') {
    if (!frgui_yetki('edit')) $yanit(403, ['ok' => false, 'hata' => 'Düzenleme yetkiniz yok.']);
    if (in_array($s['durum'], frg_kapali_durumlar(), true)) $yanit(422, ['ok' => false, 'hata' => 'Kapalı/iptal sipariş düzenlenemez; önce yeniden açın.']);
    $alanlar = $in['alanlar'] ?? null;
    if (!is_array($alanlar)) $yanit(400, ['ok' => false, 'hata' => 'Geçersiz istek.']);
    [$temiz, $hatalar] = frgui_duzenle_dogrula($alanlar);
    if ($hatalar) $yanit(422, ['ok' => false, 'hata' => implode(' ', $hatalar), 'hatalar' => $hatalar]);
    $eski = []; $yeni = [];
    foreach ($temiz as $a => $v) {
        if (frg_norm_deger($s[$a] ?? null) !== frg_norm_deger($v)) { $eski[$a] = $s[$a] ?? null; $yeni[$a] = $v; }
    }
    if (!$yeni) $yanit(200, ['ok' => true, 'degisen' => 0, 'mesaj' => 'Değişiklik yok.']);
    $set = []; $par = [];
    foreach ($yeni as $a => $v) { $set[] = '`' . $a . '` = ?'; $par[] = $v; }   // $a beyaz listeden (frgui_duzenle_alanlari) geldi
    $set[] = 'updated_at = ?'; $par[] = $simdi; $par[] = $id;
    $ozet = implode('; ', array_map(static fn($a) => $a . ': ' . $ozetle(frg_norm_deger($eski[$a])) . ' → ' . $ozetle(frg_norm_deger($yeni[$a])), array_keys($yeni)));
    $sahip = !$pdo->inTransaction();
    if ($sahip) $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE frugro_siparisler SET ' . implode(', ', $set) . ' WHERE id = ? AND deleted_at IS NULL')->execute($par);
        frg_olay_yaz($pdo, $id, 'duzenlendi', 'Elle düzenlendi: ' . $ozet, $uid);
        if ($sahip) $pdo->commit();
    } catch (Throwable $e) {
        if ($sahip && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[frugro_kaydet] ' . $e->getMessage());
        $yanit(500, ['ok' => false, 'hata' => 'Kaydedilemedi (ayrıntı sunucu günlüğünde).']);
    }
    audit_log_event('update', 'frugro_siparis', $id, frgd_audit_maske($eski), frgd_audit_maske($yeni) + ['po_no' => $s['po_no']]);   // adres/şoför/telefon değeri audit'e GİRMEZ
    $yanit(200, ['ok' => true, 'degisen' => count($yeni), 'mesaj' => 'Kaydedildi.']);
}

if ($islem === 'durum') {
    if (!frgui_yetki('edit')) $yanit(403, ['ok' => false, 'hata' => 'Durum değiştirme yetkiniz yok.']);
    $hedef = (string)($in['durum'] ?? '');
    $gerekce = frg_metin(is_string($in['gerekce'] ?? null) ? $in['gerekce'] : '') ?? '';
    if ($hedef === 'kapali') $yanit(422, ['ok' => false, 'hata' => 'Sipariş artık adım adım kapanır: Mail gönderildi, ardından Evrak takımı gönderildi.']);
    // Mail / evrak onayları SUNUCUDA da zorlanır (pencerelerdeki onay kutuları yalnız kolaylık değil)
    $invYok = false;
    if ($hedef === 'mail_gonderildi') {
        if (!$truthy($in['onay_pkl'] ?? null)) $yanit(422, ['ok' => false, 'hata' => 'Önce "PKL\'yi gönderdim" onayını işaretleyin.']);
        $invYok = frgd_invoice_ozet($pdo, $s)['var'] === false;
        if ($invYok && !$truthy($in['onay_invoice_yok'] ?? null)) $yanit(422, ['ok' => false, 'hata' => 'Invoice henüz üretilmedi; "Invoice olmadan işaretle" onayı gerekli.']);
    }
    if ($hedef === 'evrak_gonderildi' && !$truthy($in['onay_evrak'] ?? null)) $yanit(422, ['ok' => false, 'hata' => 'Önce "Evrak takımını gönderdim" onayını işaretleyin.']);
    $r = frg_durum_degistir($pdo, $s, $hedef, $gerekce, $uid, 'elle');
    if (!$r['ok']) $yanit($r['kod'] >= 400 ? $r['kod'] : 422, ['ok' => false, 'hata' => (string)($r['hata'] ?? 'Durum değiştirilemedi.')]);
    if ($hedef === 'mail_gonderildi') audit_log_event('frugro_mail_gonderildi', 'frugro_siparis', $id, null, ['po_no' => $s['po_no'], 'invoice_yok_onayi' => $invYok]);
    $yanit(200, ['ok' => true, 'durum' => $r['durum'], 'mesaj' => 'Durum güncellendi: ' . (frg_durumlar()[$r['durum']] ?? $r['durum']) . '.']);
}

if ($islem === 'sil') {
    if (!frgui_yetki('delete')) $yanit(403, ['ok' => false, 'hata' => 'Silme yetkiniz yok.']);
    if (!$truthy($in['onay'] ?? null)) $yanit(422, ['ok' => false, 'hata' => 'Silme onayı gerekli.']);
    $sahip = !$pdo->inTransaction();
    if ($sahip) $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('UPDATE frugro_siparisler SET deleted_at = ?, updated_at = ? WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$simdi, $simdi, $id]);
        $yok = $st->rowCount() !== 1;
        if ($yok) { if ($sahip) $pdo->rollBack(); }
        else {
            frg_olay_yaz($pdo, $id, 'silindi', 'Sipariş silindi (soft).', $uid);
            if ($sahip) $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($sahip && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[frugro_kaydet] ' . $e->getMessage());
        $yanit(500, ['ok' => false, 'hata' => 'Silinemedi (ayrıntı sunucu günlüğünde).']);
    }
    if ($yok) $yanit(404, ['ok' => false, 'hata' => 'Sipariş bulunamadı.']);
    audit_log_event('delete', 'frugro_siparis', $id, ['po_no' => $s['po_no'], 'durum' => $s['durum'], 'son_musteri' => $s['son_musteri'], 'urun' => $s['urun']], null);
    $yanit(200, ['ok' => true, 'mesaj' => 'Sipariş silindi.']);
}

$yanit(400, ['ok' => false, 'hata' => 'Geçersiz işlem.']);
