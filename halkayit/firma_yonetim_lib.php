<?php
// =============================================================================
// HKS PANEL — FİRMA YÖNETİMİ KÜTÜPHANESİ (include-only)
// =============================================================================
// api.php `firma_kaydet` / `firma_sil` uçlarının DB + audit mantığı. Yetki ve CSRF
// kapısı BURADA DEĞİL, api.php'dedir (`hks_firma_yonetici_kapisi`): bu dosya kapıdan
// SONRA çağrılır. Saf kütüphane: HTTP'ye yazmaz, exit etmez, config/DB yüklemez —
// tablo adı, şifreleme ve audit yazıcısı çağırandan gelir (test SQLite + sahte
// yazıcılarla gerçek fonksiyonları çalıştırır).
//
// AUDIT İLKESİ: dizi BEYAZ LİSTEYLE sıfırdan kurulur. hks_firma_bul() çıktısı
// (düz metin şifreler dahil) ASLA doğrudan audit'e geçirilmez; config/helpers.php
// `_audit_sanitize` yalnız password/token/csrf/cookie... anahtarlarını süzer,
// servicePassword / password_enc / service_password_enc'i SÜZMEZ. Yazılanlar: firma_id,
// ad, MASKELİ vergi no (son 4 hane), renk, değişen alan adları ve şifre/kullanıcı
// adı için yalnız "değişti/verildi" bayrakları — kullanıcı adı DEĞERİ de yazılmaz.
// record_id NULL'dur (firma id'si 'f1730…' biçiminde metin; audit_log_event ?int ister).
// =============================================================================

require_once __DIR__ . '/kisi_havuzu_lib.php';   // hks_kisi_tc_maskele

// Beyaz listeli audit görünümü (şifre/kullanıcı adı alanı İÇERMEZ).
function hks_firma_audit_degerleri(array $f): array {
  return [
    'firma_id' => (string)($f['id'] ?? ''),
    'ad'       => trim((string)($f['ad'] ?? '')),
    'vergi_no' => hks_kisi_tc_maskele($f['vergiNo'] ?? ''),
    'renk'     => (string)($f['renk'] ?? ''),
  ];
}

// firma_kaydet gövdesi (yetki + CSRF kapısından SONRA). Dönüş: [http kodu, yanıt dizisi].
//   $bul($id): hks_firma_bul biçiminde firma ya da null
//   $sifrele($duz): saklanacak şifreli metin
//   $audit($action, ?$eski, ?$yeni): audit yazıcısı (hata yutması çağıranındır)
function hks_firma_kaydet_uygula(PDO $db, string $tablo, array $g, callable $bul, callable $sifrele, callable $audit): array {
  if (empty($g['ad']) || empty($g['vergiNo'])) return [400, ['hata' => 'Firma adı ve vergi no zorunludur.']];
  $ad    = trim((string)$g['ad']);
  $vergi = trim((string)$g['vergiNo']);

  if (!empty($g['id'])) {
    $mevcut = $bul($g['id']);
    if (!$mevcut) return [400, ['hata' => 'Firma bulunamadı.']];
    $pass = !empty($g['password']) ? $g['password'] : $mevcut['password'];
    $srv  = !empty($g['servicePassword']) ? $g['servicePassword'] : $mevcut['servicePassword'];
    $user = !empty($g['userName']) ? trim($g['userName']) : $mevcut['userName'];
    $renk = $g['renk'] ?? $mevcut['renk'];
    $st = $db->prepare('UPDATE ' . $tablo . '
      SET ad=?, renk=?, user_name=?, password_enc=?, service_password_enc=?, vergi_no=? WHERE id=?');
    $st->execute([$ad, $renk, $user, $sifrele($pass), $sifrele($srv), $vergi, $g['id']]);

    $eski = hks_firma_audit_degerleri($mevcut);
    $yeni = hks_firma_audit_degerleri(['id' => $mevcut['id'], 'ad' => $ad, 'vergiNo' => $vergi, 'renk' => $renk]);
    $degisen = [];
    if ($eski['ad'] !== $yeni['ad']) $degisen[] = 'ad';
    if (trim((string)$mevcut['vergiNo']) !== $vergi) $degisen[] = 'vergi_no';
    if ($eski['renk'] !== $yeni['renk']) $degisen[] = 'renk';
    $yeni['degisen_alanlar']        = $degisen;
    $yeni['kullanici_adi_degisti']  = (string)$mevcut['userName'] !== (string)$user;
    $yeni['sifre_degisti']          = !empty($g['password']);          // çözülmüş şifreler karşılaştırılmaz
    $yeni['servis_sifresi_degisti'] = !empty($g['servicePassword']);
    $audit('update', $eski, $yeni);
    return [200, ['tamam' => true]];
  }

  if (empty($g['userName']) || empty($g['password']) || empty($g['servicePassword'])) {
    return [400, ['hata' => 'Yeni firma için kullanıcı adı ve şifreler zorunludur.']];
  }
  $id   = 'f' . round(microtime(true) * 1000);
  $renk = $g['renk'] ?? 'teal';
  $st = $db->prepare('INSERT INTO ' . $tablo . '
    (id, ad, renk, user_name, password_enc, service_password_enc, vergi_no)
    VALUES (?,?,?,?,?,?,?)');
  $st->execute([$id, $ad, $renk, trim((string)$g['userName']),
    $sifrele($g['password']), $sifrele($g['servicePassword']), $vergi]);
  $yeni = hks_firma_audit_degerleri(['id' => $id, 'ad' => $ad, 'vergiNo' => $vergi, 'renk' => $renk]);
  $yeni['kullanici_adi_verildi']  = true;
  $yeni['sifre_verildi']          = true;
  $yeni['servis_sifresi_verildi'] = true;
  $audit('create', null, $yeni);
  return [200, ['tamam' => true]];
}

// firma_sil gövdesi (kapıdan SONRA). Olmayan/boş id sessiz "tamam" DÖNMEZ (400, audit yok).
function hks_firma_sil_uygula(PDO $db, string $tablo, array $g, callable $bul, callable $audit): array {
  $id = trim((string)($g['id'] ?? ''));
  if ($id === '') return [400, ['hata' => 'Firma kimliği gerekli.']];
  $eski = $bul($id);
  if (!$eski) return [400, ['hata' => 'Firma bulunamadı.']];
  $db->prepare('DELETE FROM ' . $tablo . ' WHERE id = ?')->execute([$id]);
  $audit('delete', hks_firma_audit_degerleri($eski), null);
  return [200, ['tamam' => true]];
}
