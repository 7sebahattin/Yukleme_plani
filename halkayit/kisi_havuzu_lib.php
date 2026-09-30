<?php
// =============================================================================
// HAL KAYIT — KİŞİ HAVUZU (karşı taraf kişileri: müstahsil / firma)
// =============================================================================
// "Karşı Taraf" bölümündeki eski "Son Kullanılanlar" listesi FİRMA BAZLI hks_kv
// ('sonlar_<firma>'.karsiTaraflar) içinde en çok 10 kişi tutuyordu: aynı
// müstahsil başka firmayla çalışınca listede yoktu, 11. kişi de ilkini
// siliyordu. Havuz GLOBALDİR — tüm firmalar aynı listeyi görür.
//
// Bu dosya include-only bir kütüphanedir (.htaccess web'e kapatır). Çıktı
// basmaz, exit etmez, oturum/yetki okumaz: her fonksiyon PDO alır ve sonuç
// DÖNDÜRÜR. Böylece api.php'nin JSON/exit yan etkileri olmadan bellek içi
// SQLite ile test edilebilir (scripts/hks_kisi_havuzu_smoke.php). SQL bu
// yüzden taşınabilirdir: ON DUPLICATE KEY / REPLACE yerine SELECT → UPDATE /
// INSERT; UNIQUE ihlali (SQLSTATE 23000) çağırana 409 olarak döner.
//
// Kayıtlar KİŞİSEL VERİDİR ve yalnız formu hızlı doldurmak içindir — KARAR
// MERCİİ DEĞİLDİR. Kişi seçilince arayüz yine canlı KayitliKisiSorgu çalıştırır
// (app.html karsiTarafSec).
// =============================================================================

require_once __DIR__ . '/hks_soap.php';   // hks_tc_normalize / hks_tc_algoritma_gecerli (saf, ağsız)

// Listede en çok bu kadar kişi döner; filtre istemcide yapılır.
const HKS_KISI_LISTE_LIMIT = 2000;
// Tek seferlik içe aktarma bayrağı (hks_kv anahtarı).
const HKS_KISI_AKTARIM_BAYRAK = 'kisi_havuzu_aktarildi';

// Tablo adları: config.php yüklüyse panelin ön eki, değilse (CLI testi) 'hks_'.
function hks_kisi_on_ek(): string {
  return defined('HKS_TABLO_ON') ? HKS_TABLO_ON : 'hks_';
}
function hks_kisi_tablo(): string { return hks_kisi_on_ek() . 'kisiler'; }
function hks_kisi_kv_tablo(): string { return hks_kisi_on_ek() . 'kv'; }

// Tabloyu oluştur (yoksa). db.php hks_tablolari_hazirla() çağırır; test de
// aynı fonksiyonu kullanır ki şema iki yerde ayrışmasın. schema.sql ile aynı.
function hks_kisi_tablo_hazirla(PDO $db): void {
  $t = hks_kisi_tablo();
  if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
    $db->exec("CREATE TABLE IF NOT EXISTS {$t} (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      tc VARCHAR(11) NOT NULL UNIQUE,
      ad VARCHAR(200) NOT NULL DEFAULT '',
      cep VARCHAR(20) NOT NULL DEFAULT '',
      dogum DATE NULL,
      kullanim_sayisi INT NOT NULL DEFAULT 0,
      son_kullanim DATETIME NULL,
      olusturma DATETIME NOT NULL,
      guncelleme DATETIME NULL,
      olusturan_id INT NULL
    )");
    return;
  }
  $db->exec("CREATE TABLE IF NOT EXISTS {$t} (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tc VARCHAR(11) NOT NULL,
    ad VARCHAR(200) NOT NULL DEFAULT '',
    cep VARCHAR(20) NOT NULL DEFAULT '',
    dogum DATE NULL,
    kullanim_sayisi INT NOT NULL DEFAULT 0,
    son_kullanim DATETIME NULL,
    olusturma DATETIME NOT NULL,
    guncelleme DATETIME NULL,
    olusturan_id INT NULL,
    UNIQUE KEY uq_kisi_tc (tc),
    KEY ix_kisi_son (son_kullanim)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ── Normalizasyon / doğrulama (saf) ─────────────────────────────────────────

// TC/VKN geçerli mi: 10 hane (VKN — algoritmaya TABİ DEĞİL) ya da algoritmayı
// geçen 11 hane (TC). Girdi önceden rakama indirgenmiş olmalı.
function hks_kisi_tc_gecerli(string $tc): bool {
  if (preg_match('/^\d{10}$/', $tc)) return true;
  return strlen($tc) === 11 && hks_tc_algoritma_gecerli($tc);
}

// Doğum tarihi → 'YYYY-MM-DD' | '' (boş) | null (geçersiz / gelecek).
// GG.AA.YYYY de kabul edilir: gönderim ve eski kv kayıtları bu biçimde
// gelebiliyor (hks_dogum_tarihi_xml ile aynı hoşgörü).
function hks_kisi_dogum_normalize($deger, ?string $bugun = null): ?string {
  $s = trim((string)$deger);
  if ($s === '') return '';
  if (preg_match('/^(\d{2})[.\/](\d{2})[.\/](\d{4})$/', $s, $m)) $s = "{$m[3]}-{$m[2]}-{$m[1]}";
  if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return null;
  if (!checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
  $bugun = $bugun ?? date('Y-m-d');
  if ($s > $bugun) return null;   // ISO biçimde metin karşılaştırması = tarih karşılaştırması
  return $s;
}

// Cep → yalnız rakam; boş ya da 10–13 hane ise değer, değilse null (geçersiz).
function hks_kisi_cep_normalize($deger): ?string {
  $c = preg_replace('/\D+/', '', (string)$deger);
  if ($c === '') return '';
  $n = strlen($c);
  return ($n >= 10 && $n <= 13) ? $c : null;
}

// Pencereden gelen kişi girdisini doğrular. Sunucu OTORİTEDİR; app.html'deki
// ayna yalnız kolaylıktır.
// Dönüş: [['tc','ad','cep','dogum'(''|YYYY-MM-DD)], null] ya da [null, 'Türkçe hata'].
function hks_kisi_dogrula(array $g, ?string $bugun = null): array {
  $tc = hks_tc_normalize($g['tc'] ?? '');
  if ($tc === '') return [null, 'TC/VKN zorunludur.'];
  if (strlen($tc) === 11) {
    if (!hks_tc_algoritma_gecerli($tc)) return [null, 'TC Kimlik No geçersiz (algoritma tutmuyor) — rakamları kontrol edin.'];
  } elseif (strlen($tc) !== 10) {
    return [null, 'TC Kimlik No 11, Vergi No 10 haneli olmalıdır.'];
  }

  $ad = trim(preg_replace('/\s+/u', ' ', (string)($g['ad'] ?? '')));
  if ($ad === '') return [null, 'Ad / Ünvan zorunludur.'];
  if (mb_strlen($ad, 'UTF-8') > 200) return [null, 'Ad / Ünvan en çok 200 karakter olabilir.'];

  $cep = hks_kisi_cep_normalize($g['cep'] ?? '');
  if ($cep === null) return [null, 'Cep telefonu 10–13 haneli olmalıdır (ya da boş bırakın).'];

  $dogum = hks_kisi_dogum_normalize($g['dogum'] ?? '', $bugun);
  if ($dogum === null) return [null, 'Doğum tarihi geçersiz ya da ileri bir tarih.'];

  return [['tc' => $tc, 'ad' => $ad, 'cep' => $cep, 'dogum' => $dogum], null];
}

// Audit için: yalnız son 4 hane görünür ('*******1234'). Tam TC log'a yazılmaz.
function hks_kisi_tc_maskele($tc): string {
  $tc = hks_tc_normalize($tc);
  $n = strlen($tc);
  if ($n <= 4) return str_repeat('*', $n);
  return str_repeat('*', $n - 4) . substr($tc, -4);
}

// Audit değerleri: ad + maskeli TC. cep/doğum YAZILMAZ (kişisel veri) —
// güncellemede yalnız değişip değişmediği bool olarak eklenir.
function hks_kisi_audit_degerleri(array $satir, ?array $eski = null): array {
  $v = ['ad' => (string)($satir['ad'] ?? ''), 'tc' => hks_kisi_tc_maskele($satir['tc'] ?? '')];
  if ($eski !== null) {
    $v['cep_degisti']   = (string)($eski['cep'] ?? '') !== (string)($satir['cep'] ?? '');
    $v['dogum_degisti'] = (string)($eski['dogum'] ?? '') !== (string)($satir['dogum'] ?? '');
  }
  return $v;
}

// DB satırı → API biçimi (sözleşme: dogum/sonKullanim boşsa '').
function hks_kisi_disa(array $r): array {
  $dogum = (string)($r['dogum'] ?? '');
  return [
    'id'             => (int)$r['id'],
    'tc'             => (string)$r['tc'],
    'ad'             => (string)$r['ad'],
    'cep'            => (string)$r['cep'],
    'dogum'          => $dogum !== '' ? substr($dogum, 0, 10) : '',
    'kullanimSayisi' => (int)$r['kullanim_sayisi'],
    'sonKullanim'    => (string)($r['son_kullanim'] ?? ''),
  ];
}

// ── Okuma ───────────────────────────────────────────────────────────────────

function hks_kisi_getir(PDO $db, int $id): ?array {
  $st = $db->prepare('SELECT * FROM ' . hks_kisi_tablo() . ' WHERE id = ?');
  $st->execute([$id]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ?: null;
}
function hks_kisi_tc_ile(PDO $db, string $tc): ?array {
  $st = $db->prepare('SELECT * FROM ' . hks_kisi_tablo() . ' WHERE tc = ?');
  $st->execute([$tc]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ?: null;
}

// Sıra: son kullanılan önce (hiç kullanılmamış — NULL — sona), sonra ada göre.
// "IS NULL" ifadesi MySQL ve SQLite'ta aynı çalışır (NULLS LAST taşınabilir değil).
function hks_kisi_liste(PDO $db, int $limit = HKS_KISI_LISTE_LIMIT): array {
  $limit = max(1, min($limit, HKS_KISI_LISTE_LIMIT));
  $rows = $db->query('SELECT * FROM ' . hks_kisi_tablo() .
    ' ORDER BY (son_kullanim IS NULL), son_kullanim DESC, ad, id LIMIT ' . $limit)->fetchAll(PDO::FETCH_ASSOC);
  return array_map('hks_kisi_disa', $rows);
}

// ── Yazma (pencere: yeni / düzenle / sil) ───────────────────────────────────

function hks_kisi_cakisma_mesaji(string $ad): string {
  return 'Bu TC/VKN havuzda zaten kayıtlı: ' . ($ad !== '' ? $ad : '(adsız kayıt)');
}

// id yok = yeni, id var = güncelle. Pencere düzenlemesi kullanım sayacına ve
// son kullanım zamanına DOKUNMAZ — onlar yalnız gerçek gönderimde değişir.
// Dönüş: ['kod'=>200, 'kisi'=>API biçimi, 'satir'=>yeni satır, 'eski'=>eski satır|null, 'islem'=>'create'|'update']
//        ya da ['kod'=>400|404|409, 'hata'=>'...'].
function hks_kisi_kaydet(PDO $db, array $g, ?int $kullaniciId = null, ?string $bugun = null): array {
  [$k, $hata] = hks_kisi_dogrula($g, $bugun);
  if ($hata !== null) return ['kod' => 400, 'hata' => $hata];

  $t = hks_kisi_tablo();
  $id = (int)($g['id'] ?? 0);
  $eski = null;
  if ($id > 0) {
    $eski = hks_kisi_getir($db, $id);
    if (!$eski) return ['kod' => 404, 'hata' => 'Kişi bulunamadı (silinmiş olabilir).'];
  }
  // Önce açık kontrol → anlaşılır mesaj; UNIQUE yine son savunmadır (yarış).
  $ayni = hks_kisi_tc_ile($db, $k['tc']);
  if ($ayni && (int)$ayni['id'] !== $id) return ['kod' => 409, 'hata' => hks_kisi_cakisma_mesaji((string)$ayni['ad'])];

  $simdi = date('Y-m-d H:i:s');
  $dogum = $k['dogum'] !== '' ? $k['dogum'] : null;
  try {
    if ($eski) {
      $db->prepare("UPDATE {$t} SET tc=?, ad=?, cep=?, dogum=?, guncelleme=? WHERE id=?")
         ->execute([$k['tc'], $k['ad'], $k['cep'], $dogum, $simdi, $id]);
    } else {
      $db->prepare("INSERT INTO {$t} (tc, ad, cep, dogum, kullanim_sayisi, son_kullanim, olusturma, guncelleme, olusturan_id)
                    VALUES (?, ?, ?, ?, 0, NULL, ?, NULL, ?)")
         ->execute([$k['tc'], $k['ad'], $k['cep'], $dogum, $simdi, $kullaniciId]);
      $id = (int)$db->lastInsertId();
    }
  } catch (PDOException $e) {
    if ((string)$e->getCode() === '23000') {
      $ayni = hks_kisi_tc_ile($db, $k['tc']);
      return ['kod' => 409, 'hata' => hks_kisi_cakisma_mesaji($ayni ? (string)$ayni['ad'] : '')];
    }
    throw $e;
  }
  $satir = hks_kisi_getir($db, $id);
  return ['kod' => 200, 'kisi' => hks_kisi_disa($satir), 'satir' => $satir, 'eski' => $eski,
          'islem' => $eski ? 'update' : 'create'];
}

// Dönüş: ['kod'=>200, 'eski'=>satır] ya da ['kod'=>404, 'hata'=>...].
function hks_kisi_sil(PDO $db, int $id): array {
  $eski = $id > 0 ? hks_kisi_getir($db, $id) : null;
  if (!$eski) return ['kod' => 404, 'hata' => 'Kişi bulunamadı (silinmiş olabilir).'];
  $db->prepare('DELETE FROM ' . hks_kisi_tablo() . ' WHERE id = ?')->execute([$id]);
  return ['kod' => 200, 'eski' => $eski];
}

// ── Gönderim sonrası upsert (taslak_gonder) ─────────────────────────────────
// $k: ['tc','ad','cep','dogum'] — ortak.ikinci* alanlarından.
// Kurallar (sözleşme):
//  • Algoritmayı geçmeyen 11 haneli TC / 10-11 dışı hane → YAZILMAZ (false).
//  • ad/cep/dogum YALNIZ yeni değer DOLU ve GEÇERLİYSE üzerine yazılır — kayıtlı
//    kişide HKS bu alanları istemez, boş gelir; o gönderim eski bilgiyi silmesin.
//  • kullanim_sayisi + 1, son_kullanim = şimdi.
// Hata YUTMAZ — yutmak çağıranın işidir (api.php); test hatayı görebilsin.
// Dönüş: true = yazıldı, false = geçersiz TC nedeniyle atlandı.
function hks_kisi_upsert(PDO $db, array $k, ?int $kullaniciId = null, ?string $simdi = null): bool {
  $tc = hks_tc_normalize($k['tc'] ?? '');
  if (!hks_kisi_tc_gecerli($tc)) return false;

  $ad = trim(preg_replace('/\s+/u', ' ', (string)($k['ad'] ?? '')));
  if (mb_strlen($ad, 'UTF-8') > 200) $ad = mb_substr($ad, 0, 200, 'UTF-8');
  $cep   = hks_kisi_cep_normalize($k['cep'] ?? '') ?? '';        // geçersiz = boş say
  $dogum = hks_kisi_dogum_normalize($k['dogum'] ?? '') ?? '';    // geçersiz/gelecek = boş say
  $simdi = $simdi ?? date('Y-m-d H:i:s');
  $t = hks_kisi_tablo();

  $mevcut = hks_kisi_tc_ile($db, $tc);
  if (!$mevcut) {
    try {
      $db->prepare("INSERT INTO {$t} (tc, ad, cep, dogum, kullanim_sayisi, son_kullanim, olusturma, guncelleme, olusturan_id)
                    VALUES (?, ?, ?, ?, 1, ?, ?, NULL, ?)")
         ->execute([$tc, $ad, $cep, $dogum !== '' ? $dogum : null, $simdi, $simdi, $kullaniciId]);
      return true;
    } catch (PDOException $e) {
      // Eşzamanlı başka gönderim aynı TC'yi araya soktu → güncelleme dalına düş.
      if ((string)$e->getCode() !== '23000') throw $e;
      $mevcut = hks_kisi_tc_ile($db, $tc);
      if (!$mevcut) throw $e;
    }
  }
  $set = ['kullanim_sayisi = kullanim_sayisi + 1', 'son_kullanim = ?', 'guncelleme = ?'];
  $par = [$simdi, $simdi];
  if ($ad !== '')    { $set[] = 'ad = ?';    $par[] = $ad; }
  if ($cep !== '')   { $set[] = 'cep = ?';   $par[] = $cep; }
  if ($dogum !== '') { $set[] = 'dogum = ?'; $par[] = $dogum; }
  $par[] = (int)$mevcut['id'];
  $db->prepare("UPDATE {$t} SET " . implode(', ', $set) . ' WHERE id = ?')->execute($par);
  return true;
}

// ── Tek seferlik içe aktarma (eski firma bazlı 'sonlar%'.karsiTaraflar) ──────
// Havuz ilk açıldığında boş görünmesin diye eski listeler bir kez aktarılır.
// Var olan TC atlanır (havuzdaki bilgi daha yeni sayılır), geçersiz TC atlanır.
// Eski kv verisi SİLİNMEZ (geri dönüş yolu açık kalsın). Bayrak ancak döngü
// bitince yazılır: yarıda kalan aktarım bir sonraki çağrıda yeniden denenir ve
// var olan TC'ler atlandığı için idempotenttir.
// Dönüş: aktarılan kişi sayısı; bayrak zaten varsa null.
function hks_kisi_havuzu_ice_aktar(PDO $db, ?string $simdi = null): ?int {
  $kv = hks_kisi_kv_tablo();
  $st = $db->prepare("SELECT deger FROM {$kv} WHERE anahtar = ?");
  $st->execute([HKS_KISI_AKTARIM_BAYRAK]);
  if ($st->fetchColumn() !== false) return null;

  $simdi = $simdi ?? date('Y-m-d H:i:s');
  $t = hks_kisi_tablo();
  $ins = $db->prepare("INSERT INTO {$t} (tc, ad, cep, dogum, kullanim_sayisi, son_kullanim, olusturma, guncelleme, olusturan_id)
                       VALUES (?, ?, ?, ?, 0, NULL, ?, NULL, NULL)");
  $sayi = 0;
  $rows = $db->query("SELECT anahtar, deger FROM {$kv} WHERE anahtar LIKE 'sonlar%' ORDER BY anahtar")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($rows as $r) {
    $son = json_decode((string)$r['deger'], true);
    if (!is_array($son) || !is_array($son['karsiTaraflar'] ?? null)) continue;
    foreach ($son['karsiTaraflar'] as $kt) {
      if (!is_array($kt)) continue;
      $tc = hks_tc_normalize($kt['tc'] ?? '');
      if (!hks_kisi_tc_gecerli($tc)) continue;
      if (hks_kisi_tc_ile($db, $tc)) continue;
      $ad = trim(preg_replace('/\s+/u', ' ', (string)($kt['ad'] ?? '')));
      if (mb_strlen($ad, 'UTF-8') > 200) $ad = mb_substr($ad, 0, 200, 'UTF-8');
      $dogum = hks_kisi_dogum_normalize($kt['dogum'] ?? '') ?? '';
      try {
        $ins->execute([$tc, $ad, hks_kisi_cep_normalize($kt['cep'] ?? '') ?? '',
                       $dogum !== '' ? $dogum : null, $simdi]);
        $sayi++;
      } catch (PDOException $e) {
        if ((string)$e->getCode() !== '23000') throw $e;   // eşzamanlı aktarım — atla
      }
    }
  }
  // Taşınabilir bayrak yazımı (REPLACE/ON DUPLICATE KEY yok).
  $bayrak = json_encode(['zaman' => $simdi, 'aktarilan' => $sayi], JSON_UNESCAPED_UNICODE);
  $up = $db->prepare("UPDATE {$kv} SET deger = ? WHERE anahtar = ?");
  $up->execute([$bayrak, HKS_KISI_AKTARIM_BAYRAK]);
  if ($up->rowCount() === 0) {
    try {
      $db->prepare("INSERT INTO {$kv} (anahtar, deger) VALUES (?, ?)")->execute([HKS_KISI_AKTARIM_BAYRAK, $bayrak]);
    } catch (PDOException $e) {
      if ((string)$e->getCode() !== '23000') throw $e;
    }
  }
  return $sayi;
}
