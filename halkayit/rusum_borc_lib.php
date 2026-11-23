<?php
// =============================================================================
// HAL KAYIT — RÜSUM BORCU (v566) KÜTÜPHANESİ (include-only; .htaccess'te kapalı)
//
// NEDEN BU KADAR DOLAYLI: HKS web servislerinde (Genel/Urun/Bildirim; canlı WSDL +
// GTB Servis Geliştirici Kılavuzu 0.1.14) borç / ödeme / tahakkuk sorgusu YOKTUR.
// Portaldaki "Borç Sorgulama"nın servis karşılığı yok. Servisin verdiği tek rüsum
// bilgisi bildirim kaydındaki `RusumMiktari`dır (BildirimSorguDTO). Bu kütüphane:
//   · bildirimcinin yaptığı bildirimleri (BildirimServisBildirimcininYaptigiBildirimListesi)
//     tarih pencereleriyle çeker,
//   · rüsumu BİLDİRİM GÜNÜNE göre toplar (portaldaki "Grup" satırı = bir günün rüsumu),
//   · "Son Ödeme Tarihi"ni tahakkuk gününden 5 İŞ GÜNÜ sonrası olarak HESAPLAR
//     (portal ekran görüntüsündeki 20 satırın hepsini tutturan kural; resmî tatiller
//     aşağıdaki listeden — her yıl güncellenmeli),
//   · ÖDENDİ bilgisi servisten gelmez: kullanıcı elle işaretler (hks_kv, şema değişmez).
// Sonuç RESMÎ BORÇ DEĞİL, bildirimlerden türetilmiş tahmindir; sayfa bunu söyler.
//
// Saf fonksiyonlar HTTP'ye yazmaz / exit etmez; test doğrudan require eder.
// Ağ çağrısı yalnız hks_rusum_pencere() içindedir (testte $cagir ile değiştirilir).
// =============================================================================

require_once __DIR__ . '/hks_soap.php';

// Resmî tatiller (yyyy-mm-dd). Arife YARIM gün olduğu için iş günü sayılır (portaldaki
// 28.10.2026 → 05.11.2026 satırı bunu doğrular). Dini bayram tarihleri her yıl
// değişir; yeni yıl için bu listeye ekle. Listede olmayan yılda yalnız hafta sonu atlanır.
function hks_rusum_tatiller(): array {
  return [
    // 2026
    '2026-01-01', '2026-03-20', '2026-03-21', '2026-03-22', '2026-04-23', '2026-05-01', '2026-05-19',
    '2026-05-27', '2026-05-28', '2026-05-29', '2026-05-30', '2026-07-15', '2026-08-30', '2026-10-29',
    // 2027
    '2027-01-01', '2027-03-09', '2027-03-10', '2027-03-11', '2027-04-23', '2027-05-01',
    '2027-05-16', '2027-05-17', '2027-05-18', '2027-05-19', '2027-07-15', '2027-08-30', '2027-10-29',
  ];
}

const HKS_RUSUM_VADE_IS_GUNU = 5;

// Saf: 'Y-m-d' tahakkuk gününden VADE_IS_GUNU iş günü sonrasının 'Y-m-d'si.
// Tahakkuk günü sayılmaz (hafta sonu/tatil gününe düşen tahakkuk da bir sonraki iş gününden sayar).
function hks_rusum_son_odeme(string $tahakkuk, ?array $tatiller = null): string {
  $d = DateTime::createFromFormat('!Y-m-d', $tahakkuk);
  if (!$d || $d->format('Y-m-d') !== $tahakkuk) return '';
  $tatil = array_flip($tatiller ?? hks_rusum_tatiller());
  $sayac = 0;
  while ($sayac < HKS_RUSUM_VADE_IS_GUNU) {
    $d->modify('+1 day');
    if ((int)$d->format('N') >= 6 || isset($tatil[$d->format('Y-m-d')])) continue;
    $sayac++;
  }
  return $d->format('Y-m-d');
}

// Portaldaki "Grup" kodu: <VKN>-<tahakkuk gg>-<son ödeme gg> (örn. 0091880872-20261020-20261027).
// VKN yalnız rakam; 10 haneye soldan sıfırla tamamlanır.
function hks_rusum_grup_kodu(string $vkn, string $tahakkuk, string $sonOdeme): string {
  $v = preg_replace('/\D+/', '', $vkn);
  if ($v === '') $v = '0';
  return str_pad($v, 10, '0', STR_PAD_LEFT) . '-' . str_replace('-', '', $tahakkuk) . '-' . str_replace('-', '', $sonOdeme);
}
function hks_rusum_grup_gecerli(string $grup): bool {
  return (bool)preg_match('/^\d{10,11}-\d{8}-\d{8}$/', $grup);
}

// ── HKS çağrısı ──────────────────────────────────────────────────────────
// Tek pencere (≤27 gün), tek künye türü. KunyeTuru 1 = referans, 2 = nihai tüketim
// (hks_kunye_detay_penceresi notu): rüsum her iki türdeki bildirimde olabilir → ikisi de çekilir.
// Dönen satır: kunyeNo, tarih (Y-m-d), rusum (float).
function hks_rusum_pencere($cfg, DateTime $bas, DateTime $bit, int $kunyeTuru): array {
  $p = ['<Istek xmlns:a="' . HKS_NS_SC . '">'];
  $p[] = '<a:BaslangicTarihi>' . $bas->format('Y-m-d\TH:i:s') . '</a:BaslangicTarihi>';
  $p[] = '<a:BitisTarihi>' . $bit->format('Y-m-d\TH:i:s') . '</a:BitisTarihi>';
  $p[] = '<a:KalanMiktariSifirdanBuyukOlanlar>false</a:KalanMiktariSifirdanBuyukOlanlar>';
  $p[] = '<a:KunyeNo>0</a:KunyeNo>';
  $p[] = '<a:KunyeTuru>' . (int)$kunyeTuru . '</a:KunyeTuru>';
  $p[] = '</Istek>';
  $xml = hks_soap_cagir('Bildirim', 'BildirimServisBildirimcininYaptigiBildirimListesi',
    hks_taban_istek('BaseRequestMessageOf_BildirimSorguIstek', implode('', $p), $cfg));
  return hks_rusum_yanit_ayikla(hks_sadelestir($xml));
}

// Saf: sadeleştirilmiş yanıt → satırlar. Hata modeli → Exception.
function hks_rusum_yanit_ayikla(string $duz): array {
  $hata = hks_islem_kontrol($duz);
  if ($hata) throw new Exception($hata);
  $rows = [];
  foreach (hks_bloklar($duz, 'BildirimSorguDTO') as $b) {
    $d = hks_bildirimsorgu_dto_ayikla($b);
    if ($d['kunyeNo'] === '') continue;
    $ts = $d['tarihSaat'] !== '' ? $d['tarihSaat'] : (string)hks_deger($b, 'KayitTarihi');
    $rows[] = ['kunyeNo' => $d['kunyeNo'], 'tarih' => substr($ts, 0, 10), 'rusum' => (float)$d['rusum']];
  }
  return $rows;
}

// $cagir (testte ağsız): function(DateTime,DateTime,int $kunyeTuru): array. Pencere hatası
// işlemi durdurmaz (kismiHata); HİÇ çağrı başarılı değilse son hata fırlatılır.
// Dönüş: satirlar (künyeNo ile tekilleştirilmiş), kismiHata, cagri.
function hks_rusum_tahakkuk_cek($cfg, DateTime $bas, DateTime $bit, ?callable $cagir = null): array {
  $pen = hks_gelen_pencereler($bas, $bit);
  if (count($pen) > 8) throw new Exception('Tarih aralığı çok geniş.');
  $cagir = $cagir ?: function ($b, $e, $t) use ($cfg) { return hks_rusum_pencere($cfg, $b, $e, $t); };
  $tum = [];
  $basarili = 0; $kismiHata = 0; $cagri = 0; $sonHata = null;
  foreach ($pen as [$b, $e]) {
    foreach ([1, 2] as $tur) {
      $cagri++;
      try {
        foreach ($cagir($b, $e, $tur) as $r) $tum[$r['kunyeNo']] = $r;
        $basarili++;
      } catch (Throwable $ex) {
        $kismiHata++; $sonHata = $ex;
      }
    }
  }
  if ($cagri && $basarili === 0 && $sonHata) throw new Exception($sonHata->getMessage());
  return ['satirlar' => array_values($tum), 'kismiHata' => $kismiHata, 'cagri' => $cagri];
}

// ── Gruplama ─────────────────────────────────────────────────────────────
// Saf: satırlardan gün bazlı borç grupları (eski → yeni, portal sırası). Yalnız rüsumu > 0 olanlar.
// Dönüş: ['gruplar' => [...], 'bildirim' => toplam satır, 'rusumlu' => rüsumlu satır]
function hks_rusum_grupla(array $satirlar, string $vkn, ?array $tatiller = null): array {
  $gun = [];
  $rusumlu = 0;
  foreach ($satirlar as $r) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$r['tarih'])) continue;
    if ((float)$r['rusum'] <= 0) continue;
    $rusumlu++;
    $t = $r['tarih'];
    if (!isset($gun[$t])) $gun[$t] = ['tutar' => 0.0, 'adet' => 0];
    $gun[$t]['tutar'] += (float)$r['rusum'];
    $gun[$t]['adet']++;
  }
  ksort($gun);
  $out = [];
  foreach ($gun as $t => $g) {
    $son = hks_rusum_son_odeme($t, $tatiller);
    $out[] = [
      'grup' => hks_rusum_grup_kodu($vkn, $t, $son),
      'tahakkuk' => $t, 'sonOdeme' => $son,
      'tutar' => round($g['tutar'], 2), 'adet' => $g['adet'],
    ];
  }
  return ['gruplar' => $out, 'bildirim' => count($satirlar), 'rusumlu' => $rusumlu];
}

// ── Ödendi işareti (hks_kv; şema/migration YOK) ──────────────────────────
const HKS_RUSUM_ODEME_LIMIT = 2000;
function hks_rusum_odeme_anahtar($firmaId): string {
  return 'rod_' . substr(md5((string)$firmaId), 0, 12);
}
// {grup: {t: 'Y-m-d', k: kullanıcı adı}}
function hks_rusum_odeme_oku($firmaId): array {
  $v = hks_kv_oku(hks_rusum_odeme_anahtar($firmaId), []);
  return is_array($v) ? $v : [];
}
// Toplu işaret: tek kv yazımıyla $gruplar'ı işaretler ($odendi=true) ya da kaldırır (false).
// Biçimi geçersiz grup varsa HİÇBİRİ yazılmaz (-1). Dönüş: işlenen grup sayısı, hata → -1.
// Tek çağrıda en çok HKS_RUSUM_TOPLU_LIMIT grup; ödendi sayısı HKS_RUSUM_ODEME_LIMIT'i aşmaz.
const HKS_RUSUM_TOPLU_LIMIT = 400;
function hks_rusum_odeme_toplu_yaz($firmaId, array $gruplar, bool $odendi, string $kullanici = '', ?string $bugun = null): int {
  $liste = [];
  foreach ($gruplar as $g) {
    if (!is_string($g) || !hks_rusum_grup_gecerli($g)) return -1;
    $liste[$g] = true;
  }
  if (!$liste || count($liste) > HKS_RUSUM_TOPLU_LIMIT) return -1;
  $harita = hks_rusum_odeme_oku($firmaId);
  if ($odendi) {
    $yeniSayi = count(array_diff_key($liste, $harita));
    if (count($harita) + $yeniSayi > HKS_RUSUM_ODEME_LIMIT) return -1;
    $kayit = ['t' => $bugun ?? date('Y-m-d'), 'k' => mb_substr($kullanici, 0, 60, 'UTF-8')];
    foreach ($liste as $g => $_) $harita[$g] = $kayit;
  } else {
    foreach ($liste as $g => $_) unset($harita[$g]);
  }
  hks_kv_yaz(hks_rusum_odeme_anahtar($firmaId), $harita);
  return count($liste);
}
// Tek grup (eski imza): $odendi=true → işaretle, false → kaldır. Dönüş: yazıldı mı.
function hks_rusum_odeme_yaz($firmaId, string $grup, bool $odendi, string $kullanici = '', ?string $bugun = null): bool {
  return hks_rusum_odeme_toplu_yaz($firmaId, [$grup], $odendi, $kullanici, $bugun) === 1;
}

// Saf: 'odendi' | 'gecikmis' (vadesi geçmiş, ödenmemiş) | 'odenmedi'.
function hks_rusum_durum(array $grup, array $odemeHaritasi, string $bugun): string {
  if (isset($odemeHaritasi[$grup['grup']])) return 'odendi';
  return ($grup['sonOdeme'] !== '' && $grup['sonOdeme'] < $bugun) ? 'gecikmis' : 'odenmedi';
}

// Saf: durumları işleyip toplamları çıkarır. Dönüş: gruplar (durum + odemeTarihi eklenmiş), toplam.
function hks_rusum_durumlari_isle(array $gruplar, array $odemeHaritasi, string $bugun): array {
  $t = ['tutar' => 0.0, 'odendi' => 0.0, 'odenmedi' => 0.0, 'gecikmis' => 0.0, 'adet' => count($gruplar)];
  foreach ($gruplar as &$g) {
    $g['durum'] = hks_rusum_durum($g, $odemeHaritasi, $bugun);
    $g['odemeTarihi'] = (string)($odemeHaritasi[$g['grup']]['t'] ?? '');
    $t['tutar'] += $g['tutar'];
    if ($g['durum'] === 'odendi') $t['odendi'] += $g['tutar'];
    else {
      $t['odenmedi'] += $g['tutar'];
      if ($g['durum'] === 'gecikmis') $t['gecikmis'] += $g['tutar'];
    }
  }
  unset($g);
  foreach (['tutar', 'odendi', 'odenmedi', 'gecikmis'] as $k) $t[$k] = round($t[$k], 2);
  return ['gruplar' => $gruplar, 'toplam' => $t];
}

// Biçim: 1.900,40 (TL, iki ondalık). Tarih: gg.aa.yyyy.
function hks_rusum_tl(float $v): string { return number_format($v, 2, ',', '.'); }
function hks_rusum_tarih(string $t): string {
  return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m) ? $m[3] . '.' . $m[2] . '.' . $m[1] : $t;
}

// ── Son sorgunun önbelleği (Hal Kayıt ana ekranındaki "Rüsum Borcu" kartı için) ─────────
// Ana ekran HER açılışta HKS'e 14 çağrı yapmasın diye: sayfa başarılı (eksiksiz) sorgulanınca
// grup listesi hks_kv'ye yazılır; kart bunu OKUR. Ödendi işaretleri ayrı anahtarda olduğundan
// kart işaretlemeden hemen sonra da, vade günleri geçtikçe de doğru kalır. Şema/migration YOK.
function hks_rusum_onbellek_anahtar($firmaId): string {
  return 'rbg_' . substr(md5((string)$firmaId), 0, 12);
}
// $gruplar: hks_rusum_grupla()['gruplar']. Dönüş: yazıldı mı.
function hks_rusum_onbellek_yaz($firmaId, array $gruplar, string $bas, string $bit, ?string $zaman = null): bool {
  $g = [];
  foreach ($gruplar as $x) {
    if (!hks_rusum_grup_gecerli((string)($x['grup'] ?? ''))) continue;
    $g[] = [$x['grup'], $x['tahakkuk'], $x['sonOdeme'], (float)$x['tutar']];
  }
  hks_kv_yaz(hks_rusum_onbellek_anahtar($firmaId), ['t' => $zaman ?? date('c'), 'bas' => $bas, 'bit' => $bit, 'g' => $g]);
  return true;
}
function hks_rusum_onbellek_oku($firmaId): ?array {
  $v = hks_kv_oku(hks_rusum_onbellek_anahtar($firmaId), null);
  return (is_array($v) && isset($v['g']) && is_array($v['g'])) ? $v : null;
}
// Hangi sorgular önbelleğe yazılır: yalnız "tam" olanlar (≥150 gün ve bugüne yakın). Kısa/eski bir aralık
// sorgulanınca kart eksik toplam göstermesin diye önbelleği EZMEZ.
function hks_rusum_onbellege_uygun(string $bas, string $bit, ?string $bugun = null): bool {
  $b = DateTime::createFromFormat('!Y-m-d', $bas); $e = DateTime::createFromFormat('!Y-m-d', $bit);
  $t = DateTime::createFromFormat('!Y-m-d', $bugun ?? date('Y-m-d'));
  if (!$b || !$e || !$t) return false;
  return ((int)$b->diff($e)->days + 1) >= 150 && (int)$e->diff($t)->format('%r%a') <= 3;
}
const HKS_RUSUM_ESKI_GUN = 2;   // önbellek bu kadar günden eskiyse kart "eski veri" der
// Kart özeti: önbellek yoksa null. vadesiGecmis = vadesi geçmiş ödenmemiş toplam,
// toplam = ödenmemiş toplam ("toplam borç"), zaman/bas/bit = son sorgu, eski = bayatlamış mı.
function hks_rusum_ozet($firmaId, ?string $bugun = null, ?array $tatiller = null): ?array {
  $o = hks_rusum_onbellek_oku($firmaId);
  if ($o === null) return null;
  $bugun = $bugun ?? date('Y-m-d');
  $gruplar = [];
  foreach ($o['g'] as $x) {
    if (!is_array($x) || count($x) < 4) continue;
    $gruplar[] = ['grup' => (string)$x[0], 'tahakkuk' => (string)$x[1], 'sonOdeme' => (string)$x[2], 'tutar' => (float)$x[3], 'adet' => 0];
  }
  $d = hks_rusum_durumlari_isle($gruplar, hks_rusum_odeme_oku($firmaId), $bugun);
  $t = strtotime((string)($o['t'] ?? ''));
  $eski = $t === false || (strtotime($bugun) - $t) > HKS_RUSUM_ESKI_GUN * 86400;
  return [
    'vadesiGecmis' => $d['toplam']['gecikmis'], 'toplam' => $d['toplam']['odenmedi'],
    'zaman' => (string)($o['t'] ?? ''), 'bas' => (string)($o['bas'] ?? ''), 'bit' => (string)($o['bit'] ?? ''), 'eski' => $eski,
  ];
}
