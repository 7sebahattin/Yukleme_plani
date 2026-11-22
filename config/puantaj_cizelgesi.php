<?php
// =========================================================
// config/puantaj_cizelgesi.php — Aylık Puantaj Çizelgesi (Personel Takibi)
//
// SALT OKUNUR türetme: yeni tablo / şema / yazma YOK. Sayfalar
// (puantaj_cizelgesi.php, puantaj_cizelgesi_yazdir.php) ince kalır; ay/gün
// hesabı, giriş-çıkış eşleme, pivot, özet ve Excel satırları BURADA.
// Saf fonksiyonlar (pc_*) DB/oturum bilmez → scripts/puantaj_cizelgesi_smoke.php.
//
// Ortak "tablo" yapısı (iki sekme de aynısını üretir; ekran/yazdır/Excel okur):
//   ['tur'=>'personel'|'gunluk', 'ay'=>'YYYY-MM', 'gunler'=>pc_gunler(),
//    'satirlar'=>[ ['id'=>int,'ad'=>str,'alt'=>str,
//                   'hucre'=>[ 'YYYY-MM-DD' => ['k'=>'X'|'!','d'=>float|int,'ilk'=>?,'son'=>?,'acik'=>int] ],
//                   'ozet'=>['gun'=>int,'toplam'=>float|int,'eksik'=>int] ] ],
//    'gun_say'=>[tarih=>sayı], 'genel'=>['kisi','gun','toplam','eksik']]
// Hata/şema hazır değilse yalnız ['hata'=>mesaj] döner (sayfa 500 vermez).
// =========================================================
declare(strict_types=1);

/** Aynı kişinin GİRİŞ→ÇIKIŞ çifti en çok bu kadar saat sürebilir; fazlası "çıkış eksik" sayılır. */
const PC_VARDIYA_MAX_SAAT = 16;

function pc_gun_adlari(): array { return [1 => 'Pzt', 2 => 'Sal', 3 => 'Çar', 4 => 'Per', 5 => 'Cum', 6 => 'Cmt', 7 => 'Paz']; }
function pc_gun_adlari_uzun(): array { return [1 => 'Pazartesi', 2 => 'Salı', 3 => 'Çarşamba', 4 => 'Perşembe', 5 => 'Cuma', 6 => 'Cumartesi', 7 => 'Pazar']; }
function pc_ay_adlari(): array {
    return [1 => 'Ocak', 2 => 'Şubat', 3 => 'Mart', 4 => 'Nisan', 5 => 'Mayıs', 6 => 'Haziran',
            7 => 'Temmuz', 8 => 'Ağustos', 9 => 'Eylül', 10 => 'Ekim', 11 => 'Kasım', 12 => 'Aralık'];
}

/** 'YYYY-MM' doğrular; geçersizse varsayılan (verilmezse bu ay). */
function pc_ay_dogrula(string $ay, ?string $varsayilan = null): string
{
    $ay = trim($ay);
    if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $ay, $m) && (int)$m[1] >= 2000 && (int)$m[1] <= 2100) return $ay;
    return $varsayilan ?? date('Y-m');
}

/** Ayı $delta ay kaydırır (yıl dönüşü dahil). */
function pc_ay_gez(string $ay, int $delta): string
{
    $ay = pc_ay_dogrula($ay);
    $d = new DateTimeImmutable($ay . '-01');
    return $d->modify(($delta >= 0 ? '+' : '') . $delta . ' month')->format('Y-m');
}

/** 'Kasım 2026' */
function pc_ay_basligi(string $ay): string
{
    $ay = pc_ay_dogrula($ay);
    return pc_ay_adlari()[(int)substr($ay, 5, 2)] . ' ' . substr($ay, 0, 4);
}

/** Ayın gün sayısı (Şubat/artık yıl dahil). */
function pc_ay_gun_sayisi(string $ay): int
{
    return (int)(new DateTimeImmutable(pc_ay_dogrula($ay) . '-01'))->format('t');
}

/** Ayın gün listesi: n, tarih, hg (ISO 1=Pzt..7=Paz), ad, hs (hafta sonu), bugun. */
function pc_gunler(string $ay, ?string $bugun = null): array
{
    $ay = pc_ay_dogrula($ay);
    $bugun = $bugun ?? date('Y-m-d');
    $adlar = pc_gun_adlari();
    $out = [];
    $n = pc_ay_gun_sayisi($ay);
    for ($i = 1; $i <= $n; $i++) {
        $tarih = sprintf('%s-%02d', $ay, $i);
        $hg = (int)(new DateTimeImmutable($tarih))->format('N');
        $out[] = ['n' => $i, 'tarih' => $tarih, 'hg' => $hg, 'ad' => $adlar[$hg], 'hs' => $hg >= 6, 'bugun' => $tarih === $bugun];
    }
    return $out;
}

/** Türkçe duyarsız arama katlaması (İ/ı/ş/ç/ğ/ö/ü → ascii küçük). Arama yalnız sunucuda yapılır (toplam/Excel/yazdır tutarlı kalsın). */
function pc_fold(string $s): string
{
    $s = strtr($s, ['İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ş' => 's', 'ş' => 's', 'Ç' => 'c', 'ç' => 'c', 'Ğ' => 'g', 'ğ' => 'g', 'Ö' => 'o', 'ö' => 'o', 'Ü' => 'u', 'ü' => 'u']);
    return mb_strtolower($s, 'UTF-8');
}

/** Saat / sayı gösterimi: en çok 1 ondalık, ",0" atılır (8,5 · 8 · 1.234,5). */
function pc_sayi_fmt($v): string
{
    $s = number_format((float)$v, 1, ',', '.');
    return str_ends_with($s, ',0') ? substr($s, 0, -2) : $s;
}

/**
 * Tek kişinin KRONOLOJİK olay akışını gün bazına çözer.
 * Olay: ['t'=>'GIRIS'|'CIKIS', 'z'=>'Y-m-d H:i:s'].
 * Kurallar (basit tutuldu):
 *  - GİRİŞ→ÇIKIŞ çiftleri sıralı eşlenir; süre çiftin GİRİŞ gününe yazılır (gece yarısını
 *    aşan vardiya giriş gününde sayılır).
 *  - Açık GİRİŞ varken yeni GİRİŞ gelirse eskisi "çıkış eksik" kalır, yenisi açılır.
 *  - Çift PC_VARDIYA_MAX_SAAT'ten uzunsa eşleşmez: giriş "çıkış eksik", o çıkış yok sayılır.
 *  - GİRİŞsiz ÇIKIŞ yok sayılır (önceki ayın vardiyası olabilir).
 * Dönüş: [tarih => ['saat'=>float,'tam'=>int,'acik'=>int,'ilk'=>'H:i'|null,'son'=>'H:i'|null,'olay'=>int]]
 */
function pc_olay_cozumle(array $olaylar): array
{
    $gunler = [];
    $ac = static function (string $gun) use (&$gunler): void {
        if (!isset($gunler[$gun])) $gunler[$gun] = ['saat' => 0.0, 'tam' => 0, 'acik' => 0, 'ilk' => null, 'son' => null, 'olay' => 0];
    };
    $acik = null;   // ['ts'=>int,'gun'=>str]
    foreach ($olaylar as $o) {
        $t = strtoupper(trim((string)($o['t'] ?? '')));
        $ts = strtotime((string)($o['z'] ?? ''));
        if ($ts === false || ($t !== 'GIRIS' && $t !== 'CIKIS')) continue;
        if ($t === 'GIRIS') {
            if ($acik !== null) { $ac($acik['gun']); $gunler[$acik['gun']]['acik']++; }
            $gun = date('Y-m-d', $ts);
            $ac($gun);
            $gunler[$gun]['olay']++;
            if ($gunler[$gun]['ilk'] === null) $gunler[$gun]['ilk'] = date('H:i', $ts);
            $acik = ['ts' => $ts, 'gun' => $gun];
            continue;
        }
        if ($acik === null) continue;
        $sure = $ts - $acik['ts'];
        $g = $acik['gun'];
        if ($sure < 0 || $sure > PC_VARDIYA_MAX_SAAT * 3600) {
            $gunler[$g]['acik']++;
        } else {
            $gunler[$g]['saat'] += $sure / 3600;
            $gunler[$g]['tam']++;
            $gunler[$g]['olay']++;
            $gunler[$g]['son'] = date('H:i', $ts);
        }
        $acik = null;
    }
    if ($acik !== null) { $ac($acik['gun']); $gunler[$acik['gun']]['acik']++; }
    ksort($gunler);
    return $gunler;
}

/** Çözülmüş günü hücreye çevirir: eksik çıkış varsa '!', değilse tam çift varsa 'X'. */
function pc_hucre_personel(array $g): ?array
{
    if ($g['acik'] > 0) $k = '!';
    elseif ($g['tam'] > 0) $k = 'X';
    else return null;
    return ['k' => $k, 'd' => round((float)$g['saat'], 4), 'ilk' => $g['ilk'], 'son' => $g['son'], 'acik' => (int)$g['acik']];
}

/** Satırların özetini, gün sayaçlarını ve genel toplamı hesaplar. */
function pc_tablo_tamamla(string $tur, string $ay, array $gunler, array $satirlar): array
{
    $gunSay = [];
    foreach ($gunler as $g) $gunSay[$g['tarih']] = 0;
    $genel = ['kisi' => count($satirlar), 'gun' => 0, 'toplam' => 0.0, 'eksik' => 0];
    foreach ($satirlar as &$s) {
        $oz = ['gun' => 0, 'toplam' => 0.0, 'eksik' => 0];
        foreach ($s['hucre'] as $tarih => $h) {
            if (!isset($gunSay[$tarih])) continue;   // ay dışı güvenlik
            if ($tur === 'gunluk') {
                if ((int)$h['d'] > 0) $oz['gun']++;
                $oz['toplam'] += (int)$h['d'];
                $gunSay[$tarih] += (int)$h['d'];
            } else {
                if ($h['k'] === 'X') $oz['gun']++;
                $oz['toplam'] += (float)$h['d'];
                $gunSay[$tarih] += 1;
            }
            $oz['eksik'] += (int)$h['acik'];
        }
        if ($tur === 'gunluk') $oz['toplam'] = (int)$oz['toplam'];
        $s['ozet'] = $oz;
        $genel['gun'] += $oz['gun'];
        $genel['toplam'] += $oz['toplam'];
        $genel['eksik'] += $oz['eksik'];
    }
    unset($s);
    if ($tur === 'gunluk') $genel['toplam'] = (int)$genel['toplam'];
    return ['tur' => $tur, 'ay' => $ay, 'gunler' => $gunler, 'satirlar' => $satirlar, 'gun_say' => $gunSay, 'genel' => $genel];
}

/** Ad/alt metninde (Türkçe duyarsız) arar; boş sorgu = hepsi. */
function pc_satir_filtre(array $satirlar, string $q): array
{
    $q = pc_fold(trim($q));
    if ($q === '') return $satirlar;
    return array_values(array_filter($satirlar, static fn($s) => str_contains(pc_fold($s['ad'] . ' ' . $s['alt']), $q)));
}

/**
 * SEKME 1 (saf): çalışanlar + olaylar → tablo.
 * $calisanlar: [['id','full_name','personnel_no','department'],…]
 * $olaylar: [['employee_id','event_type','server_event_time'],…] (herhangi sırada; burada sıralanır)
 */
function pc_tablo_personel_kur(string $ay, array $calisanlar, array $olaylar, string $q = ''): array
{
    $gunler = pc_gunler($ay);
    $gruplar = [];
    foreach ($olaylar as $o) $gruplar[(int)$o['employee_id']][] = ['t' => (string)$o['event_type'], 'z' => (string)$o['server_event_time']];
    $satirlar = [];
    foreach ($calisanlar as $c) {
        $id = (int)$c['id'];
        $ev = $gruplar[$id] ?? [];
        usort($ev, static fn($a, $b) => strcmp($a['z'], $b['z']));
        $hucre = [];
        foreach (pc_olay_cozumle($ev) as $tarih => $g) {
            if (strncmp($tarih, $ay, 7) !== 0) continue;
            $h = pc_hucre_personel($g);
            if ($h !== null) $hucre[$tarih] = $h;
        }
        $alt = trim((string)($c['personnel_no'] ?? ''));
        $dep = trim((string)($c['department'] ?? ''));
        if ($dep !== '') $alt = $alt === '' ? $dep : $alt . ' · ' . $dep;
        $satirlar[] = ['id' => $id, 'ad' => (string)$c['full_name'], 'alt' => $alt, 'hucre' => $hucre];
    }
    return pc_tablo_tamamla('personel', $ay, $gunler, pc_satir_filtre($satirlar, $q));
}

/**
 * SEKME 2 (saf): pdks_rapor_cavus_toplu_dokum() satırları → çavuş × gün.
 * Aynı çavuşun aynı gündeki birden çok oturumu toplanır. Hücre = toplam işçi (kart bazlı).
 */
function pc_tablo_gunluk_kur(string $ay, array $rapor, string $q = ''): array
{
    $gunler = pc_gunler($ay);
    $cav = [];
    foreach ($rapor as $r) {
        $tarih = substr((string)$r['tarih'], 0, 10);
        if (strncmp($tarih, $ay, 7) !== 0) continue;
        $id = (int)$r['foreman_id'];
        if (!isset($cav[$id])) $cav[$id] = ['id' => $id, 'ad' => (string)$r['cavus_adi'], 'alt' => trim((string)($r['cavus_kodu'] ?? '')), 'hucre' => []];
        $isci = (int)$r['toplam_isci'];
        $eksik = (int)$r['eksik_cikis'];
        $h = $cav[$id]['hucre'][$tarih] ?? ['k' => 'X', 'd' => 0, 'ilk' => null, 'son' => null, 'acik' => 0];
        $h['d'] += $isci;
        $h['acik'] += $eksik;
        $h['k'] = $h['acik'] > 0 ? '!' : 'X';
        $cav[$id]['hucre'][$tarih] = $h;
    }
    foreach ($cav as &$c) {   // işçisiz boş oturum günü hücre sayılmaz
        $c['hucre'] = array_filter($c['hucre'], static fn($h) => (int)$h['d'] > 0 || (int)$h['acik'] > 0);
    }
    unset($c);
    $satirlar = array_values($cav);
    usort($satirlar, static fn($a, $b) => strcmp(pc_fold($a['ad']), pc_fold($b['ad'])) ?: $a['id'] <=> $b['id']);
    return pc_tablo_tamamla('gunluk', $ay, $gunler, pc_satir_filtre($satirlar, $q));
}

// ── Sekme / yetki ───────────────────────────────────────────

function pc_sekme_adlari(): array { return ['personel' => 'Personel', 'gunluk' => 'Günlük İşçi']; }

/** Kullanıcının görebildiği sekmeler (sayfa Gör + sekmenin kaynağı Gör). Yönetici ikisini de görür. */
function pc_sekmeler(): array
{
    if (!function_exists('role_pdks_can') || !role_pdks_can('monthly_timesheet')) return [];
    $out = [];
    if (role_pdks_can('employees')) $out[] = 'personel';
    if (role_pdks_can('daily_reports')) $out[] = 'gunluk';
    return $out;
}

/** İstenen sekme izinli değilse ilk izinli sekme; hiç yoksa null. */
function pc_sekme_sec(string $istek, array $izinli): ?string
{
    if ($izinli === []) return null;
    return in_array($istek, $izinli, true) ? $istek : $izinli[0];
}

/** Sayfa bağlantısı: boş değerler atılır. */
function pc_url(string $dosya, array $param): string
{
    $p = array_filter($param, static fn($v) => $v !== null && $v !== '' && $v !== false);
    return $dosya . ($p ? '?' . http_build_query($p) : '');
}

// ── DB okuyucuları (salt okunur) ────────────────────────────

/**
 * SEKME 1 veri kaynağı: aktif depoya göre çalışanlar + ayın giriş/çıkış olayları (TEK olay SELECT'i).
 * Çift eşleme için ham olaylar çekilir (MIN/MAX tek başına çoklu giriş-çıkışı çözemez); ay ~ kişi başı birkaç satır.
 * Depo: depo_sql_in('e.depo') (pozisyonel; atanmamış-depo kuralı geçerli). Hata fırlatabilir (PDOException).
 */
function pc_personel_oku(PDO $pdo, string $ay, bool $pasif = false): array
{
    $ay = pc_ay_dogrula($ay);
    $bas = $ay . '-01 00:00:00';
    $son = pc_ay_gez($ay, 1) . '-01 00:00:00';
    $where = ['1=1']; $params = [];
    if (!$pasif) { $where[] = "e.status = 'aktif'"; }
    [$dsql, $dparams] = function_exists('depo_sql_in') ? depo_sql_in('e.depo') : ['', []];
    if ($dsql !== '') { $where[] = $dsql; $params = array_merge($params, $dparams); }
    $w = implode(' AND ', $where);

    $st = $pdo->prepare("SELECT e.id, e.full_name, e.personnel_no, e.department, e.status
                           FROM employees e WHERE $w ORDER BY e.full_name ASC, e.id ASC");
    $st->execute($params);
    $calisanlar = $st->fetchAll();

    $st = $pdo->prepare("SELECT ae.employee_id, ae.event_type, ae.server_event_time
                           FROM attendance_events ae
                           JOIN employees e ON e.id = ae.employee_id
                          WHERE ae.server_event_time >= ? AND ae.server_event_time < ? AND $w
                          ORDER BY ae.employee_id ASC, ae.server_event_time ASC, ae.id ASC");
    $st->execute(array_merge([$bas, $son], $params));
    return ['calisanlar' => $calisanlar, 'olaylar' => $st->fetchAll()];
}

/** SEKME 1: tablo ya da ['hata'=>…]. Tablolar yoksa nazik mesaj. */
function pc_tablo_personel(PDO $pdo, string $ay, string $q = '', bool $pasif = false): array
{
    if (defined('PDKS_AKTIF') && !PDKS_AKTIF) return ['hata' => 'Personel modülü şu anda kapalıdır.'];
    if (!pdks_tablo_var($pdo, 'employees') || !pdks_tablo_var($pdo, 'attendance_events')) {
        return ['hata' => 'Personel tabloları henüz hazır değil. Bir yöneticinin migrate.php sayfasından "PDKS Tablolarını Oluştur" demesi gerekiyor.'];
    }
    try {
        $v = pc_personel_oku($pdo, $ay, $pasif);
        return pc_tablo_personel_kur($ay, $v['calisanlar'], $v['olaylar'], $q);
    } catch (Throwable $e) {
        error_log('[puantaj_cizelgesi] personel: ' . $e->getMessage());
        return ['hata' => 'Personel puantajı şu anda okunamadı.'];
    }
}

/** SEKME 2: tablo ya da ['hata'=>…]. Günlük işçi şeması hazır değilse nazik mesaj. */
function pc_tablo_gunluk(PDO $pdo, string $ay, string $depo = '', string $q = ''): array
{
    try {
        if (!pdks_gunluk_sema_hazir($pdo) || !pdks_gunluk_faz8a_sema_hazir($pdo)) {
            return ['hata' => 'Günlük işçi modülü tabloları henüz hazır değil. Bir yöneticinin migrate.php sayfasından "Günlük İşçi Tablolarını Oluştur" demesi gerekiyor.'];
        }
        $rapor = pdks_rapor_cavus_toplu_dokum($ay, $depo !== '' ? $depo : null, null, false, $pdo);
        return pc_tablo_gunluk_kur($ay, $rapor, $q);
    } catch (Throwable $e) {
        error_log('[puantaj_cizelgesi] gunluk: ' . $e->getMessage());
        return ['hata' => 'Günlük işçi puantajı şu anda okunamadı.'];
    }
}

// ── Dışa aktarım (ekranla AYNI veri) ────────────────────────

/** Dışa aktarılacak hücre değeri: personel → saat sayısı ya da '!'; günlük → işçi sayısı. */
function pc_disa_hucre(string $tur, ?array $h)
{
    if ($h === null) return null;
    if ($tur === 'gunluk') return (int)$h['d'] > 0 ? (int)$h['d'] : null;
    return $h['k'] === '!' ? '!' : round((float)$h['d'], 2);
}

/** CSV/XLSX ortak başlık satırı. */
function pc_disa_baslik(array $tablo): array
{
    $gunluk = $tablo['tur'] === 'gunluk';
    $b = [$gunluk ? 'Çavuş' : 'Ad Soyad', $gunluk ? 'Çavuş Kodu' : 'Sicil / Departman'];
    foreach ($tablo['gunler'] as $g) $b[] = $g['n'] . ' ' . $g['ad'];
    return array_merge($b, ['Çalışılan Gün', $gunluk ? 'Toplam İşçi-Gün' : 'Toplam Saat', 'Eksik Çıkış']);
}

/** CSV/XLSX ortak veri satırları + "o gün" satırı + genel toplam satırı. */
function pc_disa_satirlar(array $tablo): array
{
    $tur = $tablo['tur'];
    $out = [];
    foreach ($tablo['satirlar'] as $s) {
        $r = [$s['ad'], $s['alt']];
        foreach ($tablo['gunler'] as $g) $r[] = pc_disa_hucre($tur, $s['hucre'][$g['tarih']] ?? null);
        $oz = $s['ozet'];
        $r[] = $oz['gun'];
        $r[] = $tur === 'gunluk' ? (int)$oz['toplam'] : round((float)$oz['toplam'], 2);
        $r[] = $oz['eksik'];
        $out[] = $r;
    }
    $say = [$tur === 'gunluk' ? 'O gün toplam işçi' : 'O gün çalışan', ''];
    foreach ($tablo['gunler'] as $g) $say[] = $tablo['gun_say'][$g['tarih']] ?? 0;
    array_push($say, '', '', '');
    $out[] = $say;
    $ge = $tablo['genel'];
    $genel = ['GENEL TOPLAM (' . $ge['kisi'] . ($tur === 'gunluk' ? ' çavuş)' : ' kişi)'), ''];
    foreach ($tablo['gunler'] as $_) $genel[] = null;
    array_push($genel, $ge['gun'], $tur === 'gunluk' ? (int)$ge['toplam'] : round((float)$ge['toplam'], 2), $ge['eksik']);
    $out[] = $genel;
    return $out;
}

/** XLSX sayfa tanımı (xlsx_indir()). Gün sütunları 'sayi' (sayı ya da '!' metni), toplam satırı elle (SUBTOTAL yok). */
function pc_xlsx_sayfa(array $tablo, string $aciklama = ''): array
{
    $gunluk = $tablo['tur'] === 'gunluk';
    $sut = [['baslik' => $gunluk ? 'Çavuş' : 'Ad Soyad'], ['baslik' => $gunluk ? 'Çavuş Kodu' : 'Sicil / Departman']];
    foreach ($tablo['gunler'] as $g) $sut[] = ['baslik' => $g['n'] . ' ' . $g['ad'], 'tip' => 'sayi'];
    $sut[] = ['baslik' => 'Çalışılan Gün', 'tip' => 'tamsayi'];
    $sut[] = ['baslik' => $gunluk ? 'Toplam İşçi-Gün' : 'Toplam Saat', 'tip' => $gunluk ? 'tamsayi' : 'sayi'];
    $sut[] = ['baslik' => 'Eksik Çıkış', 'tip' => 'tamsayi'];
    return [
        'ad' => $gunluk ? 'Günlük İşçi' : 'Personel',
        'baslik' => 'Aylık Puantaj Çizelgesi (' . ($gunluk ? 'Günlük İşçi' : 'Personel') . ') — ' . pc_ay_basligi($tablo['ay']),
        'aciklama' => $aciklama,
        'sutunlar' => $sut,
        'satirlar' => pc_disa_satirlar($tablo),
        'toplam' => false,
    ];
}

/** Hücre gösterimi: [css sınıfı, iç HTML (yalnız rakam/harf), title metni (çağıran h() ile kaçırır)]. Renk + HARF birlikte. */
function pc_hucre_goster(string $tur, ?array $h): array
{
    if ($h === null) return ['pc-b', '·', ''];
    if ($tur === 'gunluk') {
        $n = (int)$h['d'];
        $e = (int)$h['acik'];
        return [$e > 0 ? 'pc-e' : 'pc-x', '<b>' . $n . '</b>' . ($e > 0 ? '<i>!</i>' : ''),
                $n . ' işçi (kart bazında)' . ($e > 0 ? ' · ' . $e . ' kartın çıkışı eksik' : '')];
    }
    $zaman = trim((string)($h['ilk'] ?? '') . (($h['son'] ?? null) ? ' – ' . $h['son'] : ''));
    $saat = (float)$h['d'];
    if ($h['k'] === '!') {
        return ['pc-e', '<b>!</b>' . ($saat > 0 ? '<i>' . pc_sayi_fmt($saat) . '</i>' : ''), trim($zaman . ' · çıkış eksik')];
    }
    return ['pc-x', '<b>X</b><i>' . pc_sayi_fmt($saat) . '</i>', trim($zaman . ' · ' . pc_sayi_fmt($saat) . ' saat')];
}

/** Ekrandaki/yazdırmadaki tek satır gösterge (lejant) metni. */
function pc_lejant_metni(string $tur): string
{
    return $tur === 'gunluk'
        ? 'Sayı = o gün çalışan işçi (kart bazında) · ! = çıkışı eksik kart var · · = kayıt yok · gri sütun = hafta sonu'
        : 'X = geldi (altında saat) · ! = çıkış eksik · · = kayıt yok · gri sütun = hafta sonu';
}
