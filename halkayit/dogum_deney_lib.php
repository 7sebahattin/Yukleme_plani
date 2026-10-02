<?php
// =============================================================================
// HKS — DOĞUM TARİHİ BİÇİM DENEYİ KÜTÜPHANESİ (include-only)
// =============================================================================
// .htaccess ile doğrudan erişime kapalı; çıktı basmaz, exit etmez. Yalnız
// hks_soap.php'ye ve hks_kv_oku/hks_kv_yaz'a (db.php) dayanır — test bu iki
// fonksiyonu bellek içi taklitle tanımlayıp lib'i doğrudan require eder
// (scripts/hks_dogum_deney_smoke.php). taslak_lib.php bunu require eder.
// =============================================================================

require_once __DIR__ . '/hks_soap.php';

// =============================================================================
// DOĞUM TARİHİ BİÇİM DENEYİ + TEŞHİS KAYDI (docs/HKS_MERNIS_ILK_KAYIT_ANALIZ.md §10)
// =============================================================================
// Kayıtsız kişili Satın Alım'da HKS "Tc kimlik numarası Mernis sisteminde
// bulunamadı" (satır HataKodu 21) dönüyor; DogumTarihi xs:string ve sunucunun
// beklediği biçim belgelenmemiş. Yönetici, BİR SONRAKİ gönderimin hangi biçimle
// gideceğini TEK KULLANIMLIK olarak seçer (dogum_deney.php). Otomatik yeniden
// gönderim YOKTUR: her deneme operatörün kendi "Gönder"idir.
//
// Mernis ret satır düzeyindedir: künye yok, rüsum yok (YeniKunyeNo 0) — aynı
// taslak farklı biçimle güvenle yeniden gönderilebilir.
//
// KİŞİSEL VERİ: deney ve kayıt yalnız TC'nin SHA-256 özeti + son 4 hanesini,
// doğum tarihinin SINIFINI (gün>12 / gün<=12 / gün=ay) tutar. Ad, TC, tarih,
// cep YAZILMAZ.

const HKS_DOGUM_DENEY_SURE_SN = 86400;   // kurulan deney 24 saat geçerli
const HKS_DOGUM_KAYIT_LIMIT   = 200;     // teşhis kaydı halka tampon boyu

function hks_tc_ozet($tc) {
  $tc = hks_tc_normalize($tc);
  return $tc === '' ? '' : hash('sha256', 'hks-dogum-deney|' . $tc);
}
function hks_tc_son4($tc) {
  $tc = hks_tc_normalize($tc);
  return $tc === '' ? '' : '***' . substr($tc, -4);
}

// Kurulu ve süresi dolmamış deney (yoksa null).
function hks_dogum_deney_oku() {
  try { $d = hks_kv_oku('dogum_deney', null); } catch (Throwable $e) { return null; }
  if (!is_array($d) || !hks_dogum_bicim_gecerli($d['bicim'] ?? null)) return null;
  if ((int)($d['ts'] ?? 0) + HKS_DOGUM_DENEY_SURE_SN < time()) return null;
  return $d;
}

// Deney kurar. Dönüş: null (başarı) ya da Türkçe hata.
function hks_dogum_deney_kur($bicim, $tc, $kullanici = '') {
  if (!hks_dogum_bicim_gecerli($bicim)) return 'Geçersiz biçim.';
  $tc = hks_tc_normalize($tc);
  if (!hks_tc_algoritma_gecerli($tc)) return 'Geçerli bir 11 haneli TC kimlik no girin.';
  hks_kv_yaz('dogum_deney', [
    'bicim' => $bicim, 'tcOzet' => hks_tc_ozet($tc), 'tcSon4' => hks_tc_son4($tc),
    'ts' => time(), 'zaman' => date('c'), 'kullanici' => (string)$kullanici,
  ]);
  return null;
}

function hks_dogum_deney_iptal() {
  hks_kv_yaz('dogum_deney', null);
}

// Bu TC için kurulu deney varsa biçimini döndürür (TÜKETMEZ).
function hks_dogum_deney_bu_tc($tc) {
  $d = hks_dogum_deney_oku();
  if (!$d) return null;
  return hash_equals((string)$d['tcOzet'], hks_tc_ozet($tc)) ? $d['bicim'] : null;
}

// Teşhis kaydı (halka tampon, en yeni başta).
function hks_dogum_deneme_kaydet(array $k) {
  try {
    $l = hks_kv_oku('dogum_denemeleri', []);
    if (!is_array($l)) $l = [];
    array_unshift($l, $k + ['zaman' => date('c')]);
    hks_kv_yaz('dogum_denemeleri', array_slice($l, 0, HKS_DOGUM_KAYIT_LIMIT));
  } catch (Throwable $e) { error_log('[hks] dogum denemesi kaydedilemedi: ' . $e->getMessage()); }
}
function hks_dogum_denemeleri() {
  try { $l = hks_kv_oku('dogum_denemeleri', []); } catch (Throwable $e) { return []; }
  return is_array($l) ? $l : [];
}

// Gönderim sonucunun sınıfı: kunye | mernis | girilmelidir | diger.
function hks_dogum_sonuc_sinifi(array $sonuc) {
  foreach ($sonuc['sonuclar'] ?? [] as $r) {
    if ((string)$r['yeniKunyeNo'] !== '' && (string)$r['yeniKunyeNo'] !== '0' && !(int)$r['hataKodu']) return 'kunye';
  }
  $metin = (string)($sonuc['genelHata'] ?? '');
  foreach ($sonuc['sonuclar'] ?? [] as $r) $metin .= ' ' . (string)($r['mesaj'] ?? '');
  if (mb_stripos($metin, 'mernis', 0, 'UTF-8') !== false) return 'mernis';
  if (hks_dogum_okunmadi_mi($sonuc)) return 'girilmelidir';
  return 'diger';
}
