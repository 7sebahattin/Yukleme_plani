<?php
// =========================================================
// frugro_eml.php?id=&tur=pkl|evrak[&govde=] — Frugro "Mail gönderildi" / "Evrak takımı" penceresinin OUTLOOK TASLAĞI (.eml)
//
// GET, siparis.read + aktif depo (başka depo / olmayan / silinmiş → AYNI 404), no-store, nosniff. Yan etkisiz (durum/olay yazmaz).
// tur=pkl  : Kime/Bilgi = Frugro mail ayarı (hks_kv; repoda adres YOK), konu = PKL dosya adı, EKLER = PKL PDF + (üretildiyse) Invoice PDF.
//            Excel EKLENMEZ (sahip kararı). Palet satırı yoksa 409.
// tur=evrak: OGL v411 eşi — EK YOK (evrak takımını kullanıcı Outlook'ta kendisi ekler); yalnız mail_gonderildi / evrak_gonderildi'de (aksi 409).
// govde: penceredeki düzenlenmiş metin → siparis_mail_govde_uygula() (TEK yer; satır sonu/denetim karakteri temizlenir, ≤ 2000).
// X-Unsent: 1, From YOK. Konu/imzadaki satır sonları atılır (başlık enjeksiyonu yok). Audit `frugro_eml` yalnız SAYILAR.
// Ad TEK alt çizgili (sw.js bypass regex'i frugro(_[a-z]+)?.php kapsar).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/siparis_core.php';
require_once __DIR__ . '/config/frugro_core.php';
require_once __DIR__ . '/config/frugro_detay.php';
$auth_user = require_login();
require_siparis('read');
siparis_no_store();
header('X-Content-Type-Options: nosniff');

$hata = static function (int $kod, string $m): void {
    http_response_code($kod);
    header('Content-Type: text/plain; charset=utf-8');
    echo $m;
    exit;
};
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') { header('Allow: GET'); $hata(405, 'Geçersiz istek.'); }
$pdo = db();
if (!frg_hazir($pdo)) $hata(409, 'Frugro Siparişler henüz kurulmadı.');
$id = (int)($_GET['id'] ?? 0);
$s = $id > 0 ? frg_getir($pdo, $id) : null;
if ($s === null || !frg_gorunur($s)) $hata(404, 'Sipariş bulunamadı.');
$tur = (string)($_GET['tur'] ?? 'pkl') === 'evrak' ? 'evrak' : 'pkl';
$imza = (string)(($auth_user['display_name'] ?? '') ?: ($auth_user['username'] ?? ''));
$t = siparis_mail_govde_uygula(frgd_mail_taslak($pdo, $s, $imza, $tur), isset($_GET['govde']) && is_string($_GET['govde']) ? $_GET['govde'] : null);

$ekler = [];
if ($tur === 'evrak') {
    if (!in_array((string)$s['durum'], array_merge(frg_evrak_bekleyen_durumlar(), ['evrak_gonderildi']), true)) $hata(409, 'Evrak takımı taslağı yalnız mail gönderildikten sonra hazırlanır.');
    $eml = siparis_evrak_eml($t);
} else {
    $pkl = frgd_pkl_pdf($s, $pdo);
    if ($pkl === null) $hata(409, 'PKL PDF üretilemedi: önce Packing List palet satırlarını tamamlayın.');
    $ekler[] = ['ad' => frgp_dosya_adi($s, 'pdf'), 'icerik' => $pkl, 'mime' => 'application/pdf'];
    $inv = frgd_invoice_pdf($pdo, $id);
    if ($inv !== null) $ekler[] = ['ad' => $inv['dosya_adi'], 'icerik' => $inv['pdf'], 'mime' => 'application/pdf'];
    $eml = frgd_eml($t, $ekler);
}
audit_log_event('frugro_eml', 'frugro_siparis', $id, null, ['tur' => $tur, 'alici' => count($t['kime']) + count($t['bilgi']), 'ek' => count($ekler), 'metin_duzenlendi' => isset($_GET['govde']) && trim((string)$_GET['govde']) !== '']);

$ad = trim((string)preg_replace('/[^A-Za-z0-9._-]+/', '_', ($t['konu'] !== '' ? $t['konu'] : 'Frugro-' . $s['po_no']) . ($tur === 'evrak' ? '-Evrak' : '')), '_') . '.eml';
while (ob_get_level() > 0) { ob_end_clean(); }
session_write_close();
header('Content-Type: message/rfc822');
header('Content-Disposition: attachment; filename="' . $ad . '"');
header('Content-Length: ' . strlen($eml));
echo $eml;
exit;
