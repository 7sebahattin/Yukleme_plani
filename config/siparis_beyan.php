<?php
// =========================================================
// config/siparis_beyan.php — Sevkiyat ↔ Gümrük beyanı bağı (öneri + elle)
//
// Bağ siparis_beyan tablosuna yazılır; yükleme planına DOKUNULMAZ (beyan_eslestir.php'nin
// plan doldurma/kilitleme yan etkileri burada TEKRARLANMAZ). v413: bağ kurulunca sevkiyatın
// plaka/şoför bilgisi beyana AKTARILIR (siparis_beyan_arac_aktar) — beyanın başka alanına dokunulmaz. "Bir beyan tek bir sevkiyata aktif bağlı" kuralı kodda,
// transaction + FOR UPDATE (MySQL) altında. Beyanlar depo filtresizdir (global);
// dışarı verilen her beyan özeti beyan_redact_fields()'ten geçer.
// Kapı (sayfada): can_siparis('match') && can_beyan('read').
// =========================================================
declare(strict_types=1);

require_once __DIR__ . '/beyan_permissions.php';
require_once __DIR__ . '/siparis_core.php';
require_once __DIR__ . '/siparis_pkl.php';

/** Beyan özeti için okunan kolonlar (beyaz liste). */
const SIPARIS_BEYAN_KOLONLAR = 'id, status, party_no, vehicle_plate, tractor_plate, net_kg, pallet_count, product_name, created_at';

/** Beyan satırını beyaz listeye indirger ve yetkiye göre gizler. */
function siparis_beyan_ozet(array $r): array
{
    $o = [];
    foreach (['id', 'status', 'party_no', 'vehicle_plate', 'tractor_plate', 'net_kg', 'pallet_count', 'product_name', 'created_at'] as $k) $o[$k] = $r[$k] ?? null;
    $o['id'] = (int)$o['id'];
    return function_exists('beyan_redact_fields') ? beyan_redact_fields($o) : $o;
}

/** Plaka karşılaştırma biçimi: yalnız harf/rakam, büyük harf. */
function siparis_beyan_plaka_anahtar(?string $p): string
{
    return (string)preg_replace('/[^A-Z0-9]/', '', mb_strtoupper(beyan_plaka_normalize((string)$p), 'UTF-8'));
}

/** Sevkiyatın PKL'indeki toplam net kg (N sütunu); hesaplanamazsa null. */
function siparis_beyan_pkl_net(PDO $pdo, array $sev): ?float
{
    try {
        $d = siparis_pkl_durum($pdo, (int)$sev['id']);
        if ($d['pkl'] === null) return null;
        $top = 0.0; $var = false;
        foreach (siparis_pkl_satirlar($sev, siparis_kalemler($pdo, (int)$sev['id']), $d['veri'], 1) as $r) {
            if (isset($r['N']) && is_numeric($r['N'][1])) { $top += (float)$r['N'][1]; $var = true; }
        }
        return $var ? $top : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Beyan önerileri: yükleme tarihi −7 / +3 gün penceresindeki (tarih yoksa son 30 gün), silinmemiş,
 * başka sevkiyata aktif bağlı OLMAYAN beyanlar puanlanır; puan ≥ 20 olanlar puan sırasıyla.
 * Puan: plaka (ikisi +50 / biri +30), parti no order_ref ya da tur içeriyor +40, palet sayısı aynı +15,
 * net kg PKL Σ N ±%3 +20, ürün NAR/POMEGRANATE +5.
 * @return list<array{beyan_id:int,puan:int,gerekce:list<string>,beyan:array}>
 */
function siparis_beyan_oneriler(PDO $pdo, array $sev, int $limit = 10): array
{
    $sid = (int)($sev['id'] ?? 0);
    $yt = siparis_tarih_coz((string)($sev['yukleme_tarihi'] ?? ''));
    if ($yt !== null) {
        $bas = date('Y-m-d 00:00:00', (int)strtotime($yt . ' 12:00:00 -7 day'));
        $bit = date('Y-m-d 00:00:00', (int)strtotime($yt . ' 12:00:00 +4 day'));
    } else {
        $bas = date('Y-m-d 00:00:00', (int)strtotime('-30 day'));
        $bit = date('Y-m-d 00:00:00', (int)strtotime('+1 day'));
    }
    $st = $pdo->prepare('SELECT ' . SIPARIS_BEYAN_KOLONLAR . " FROM customs_declarations c
        WHERE c.deleted_at IS NULL AND COALESCE(c.status, '') <> ? AND c.created_at >= ? AND c.created_at < ?
          AND NOT EXISTS (SELECT 1 FROM siparis_beyan sb WHERE sb.beyan_id = c.id AND sb.durum = 'aktif')
        ORDER BY c.id DESC LIMIT 500");
    $st->execute([siparis_beyan_gizli_durum(), $bas, $bit]);
    $adaylar = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$adaylar) return [];
    $plakaKaynak = trim((string)($sev['plakalar'] ?? '')) !== '' ? (string)$sev['plakalar'] : (string)($sev['planlanan_plakalar'] ?? '');
    $parcalar = array_values(array_filter(array_map('siparis_beyan_plaka_anahtar', explode('-', $plakaKaynak)), fn($x) => $x !== ''));
    $ref = mb_strtoupper(trim((string)($sev['order_ref'] ?? '')), 'UTF-8');
    $tur = mb_strtoupper(trim((string)($sev['tur_no'] ?? '')), 'UTF-8');
    $net = $sid > 0 ? siparis_beyan_pkl_net($pdo, $sev) : null;
    $out = [];
    foreach ($adaylar as $b) {
        $puan = 0; $ger = [];
        $bp = array_values(array_filter([siparis_beyan_plaka_anahtar($b['tractor_plate'] ?? ''), siparis_beyan_plaka_anahtar($b['vehicle_plate'] ?? '')], fn($x) => $x !== ''));
        $eslesen = count(array_intersect($parcalar, $bp));
        if ($eslesen >= 2 || ($eslesen >= 1 && count($parcalar) === 1 && count($bp) === 1)) { $puan += 50; $ger[] = 'plaka eşleşti'; }
        elseif ($eslesen === 1) { $puan += 30; $ger[] = 'bir plaka eşleşti'; }
        $parti = mb_strtoupper((string)($b['party_no'] ?? ''), 'UTF-8');
        if ($parti !== '' && (($ref !== '' && str_contains($parti, $ref)) || ($tur !== '' && str_contains($parti, $tur)))) { $puan += 40; $ger[] = 'parti no sipariş/tur numarasını içeriyor'; }
        if ($b['pallet_count'] !== null && ($sev['toplam_palet'] ?? null) !== null && (int)$b['pallet_count'] === (int)$sev['toplam_palet']) { $puan += 15; $ger[] = 'palet sayısı aynı'; }
        if ($net !== null && $net > 0 && $b['net_kg'] !== null && abs((float)$b['net_kg'] - $net) <= $net * 0.03) { $puan += 20; $ger[] = 'net kg PKL ile uyumlu (±%3)'; }
        $urun = mb_strtoupper((string)($b['product_name'] ?? ''), 'UTF-8');
        if ($urun !== '' && (str_contains($urun, 'NAR') || str_contains($urun, 'POMEGRANATE'))) { $puan += 5; $ger[] = 'ürün nar'; }
        if ($puan >= 20) $out[] = ['beyan_id' => (int)$b['id'], 'puan' => $puan, 'gerekce' => $ger, 'beyan' => siparis_beyan_ozet($b)];
    }
    usort($out, fn($a, $b) => [$b['puan'], $b['beyan_id']] <=> [$a['puan'], $a['beyan_id']]);
    return array_slice($out, 0, max(1, $limit));
}

/** Elle arama: id, parti no, plakalar (boşluksuz büyük harf). Depo filtresiz; özetler gizlenmiş. @return list<array> */
function siparis_beyan_ara(PDO $pdo, string $q, int $limit = 20): array
{
    $q = trim($q);
    if ($q === '') return [];
    $limit = max(1, min(100, $limit));
    // LIKE kaçışı '!' ile: ters bölü MySQL ve SQLite'ta farklı yorumlanır ('\\' ESCAPE taşınabilir değil)
    $kac = fn(string $x): string => '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $x) . '%';
    $like = $kac(mb_strtoupper($q, 'UTF-8'));
    $plaka = $kac(beyan_plaka_normalize($q));
    $id = preg_match('/^\d{1,10}$/', $q) ? (int)$q : -1;
    $st = $pdo->prepare('SELECT ' . SIPARIS_BEYAN_KOLONLAR . " FROM customs_declarations
        WHERE deleted_at IS NULL AND COALESCE(status, '') <> ? AND (id = ? OR UPPER(party_no) LIKE ? ESCAPE '!' OR UPPER(vehicle_plate) LIKE ? ESCAPE '!' OR UPPER(tractor_plate) LIKE ? ESCAPE '!')
        ORDER BY id DESC LIMIT " . $limit);
    $st->execute([siparis_beyan_gizli_durum(), $id, $like, $plaka, $plaka]);
    return array_map('siparis_beyan_ozet', $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Sevkiyatın beyan bağları (varsayılan yalnız aktif) + 'beyan' özeti (gizlenmiş). @return list<array> */
function siparis_beyan_baglar(PDO $pdo, int $sid, bool $gecmisDahil = false): array
{
    $st = $pdo->prepare('SELECT * FROM siparis_beyan WHERE sevkiyat_id = ?' . ($gecmisDahil ? '' : " AND durum = 'aktif'") . ' ORDER BY id DESC');
    $st->execute([$sid]);
    $out = [];
    $b = $pdo->prepare('SELECT ' . SIPARIS_BEYAN_KOLONLAR . ' FROM customs_declarations WHERE id = ?');
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $b->execute([(int)$r['beyan_id']]);
        $br = $b->fetch(PDO::FETCH_ASSOC);
        $r['beyan'] = $br ? siparis_beyan_ozet($br) : null;
        $out[] = $r;
    }
    return $out;
}

/** Kapalı sayılmasa da OGL ekranında GÖSTERİLMEYEN beyan durumu: yüklendi (iş bitti, sevkiyata bağlanmaz). */
function siparis_beyan_gizli_durum(): string
{
    return 'yuklendi';
}

/**
 * Sevkiyat plakalarını (sıra: 1. = çekici, 2. = dorse) ayırır. Biçim siparis_plaka_coz() çıktısıdır ('-' ile).
 * Tek plaka → yalnız çekici. İkiden fazlaysa ilk ikisi alınır.
 * @return array{cekici:string,dorse:string}
 */
function siparis_beyan_plaka_ayir(?string $plakalar): array
{
    $p = array_values(array_filter(array_map('beyan_plaka_normalize', preg_split('/[\-\/,;|]+/', (string)$plakalar) ?: []), fn($x) => $x !== ''));
    return ['cekici' => $p[0] ?? '', 'dorse' => $p[1] ?? ''];
}

/** customs_declarations kolonu var mı (eski kurulumda driver_* / updated_* olmayabilir). */
function siparis_beyan_kolon_var(PDO $pdo, string $kolon): bool
{
    static $onbellek = [];
    $k = spl_object_id($pdo) . ':' . $kolon;
    if (isset($onbellek[$k])) return $onbellek[$k];
    try {
        $pdo->query('SELECT `' . $kolon . '` FROM customs_declarations LIMIT 0');
        return $onbellek[$k] = true;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Sevkiyatın plaka (1. çekici → tractor_plate, 2. dorse → vehicle_plate) ve şoför adı/telefonunu beyana yazar.
 * Sipariş değeri DOĞRU kabul edilir (beyandakini ezer); sipariş tarafı BOŞSA beyandaki değere dokunulmaz.
 * Kapı: can_beyan('write') (şoför için ayrıca 'contact.write'); yetkisiz alan SESSİZCE atlanır (bağ yine kurulur).
 * Çağıran transaction içindedir (hata → bağ da geri alınır). Audit: siparis_beyan_arac_aktar (yalnız alan adları + plakalar;
 * şoför adı/telefonu audit'e YAZILMAZ).
 * @return list<string> yazılan alan adları
 */
function siparis_beyan_arac_aktar(PDO $pdo, array $sev, int $beyanId, int $uid): array
{
    if (!function_exists('can_beyan') || !can_beyan('write')) return [];
    $mevcut = $pdo->prepare('SELECT * FROM customs_declarations WHERE id = ? AND deleted_at IS NULL');
    $mevcut->execute([$beyanId]);
    $b = $mevcut->fetch(PDO::FETCH_ASSOC);
    if (!$b) return [];
    if (in_array((string)($b['status'] ?? ''), beyan_kapali_durumlar(), true)) return [];   // iptal/red beyana yazılmaz (yüklendi zaten bağlanamaz)
    $plakalar = trim((string)($sev['plakalar'] ?? ''));   // yalnız GERÇEK plaka (planlanan değil)
    $pl = siparis_beyan_plaka_ayir($plakalar);
    $yeni = [];
    if ($pl['cekici'] !== '') $yeni['tractor_plate'] = mb_substr($pl['cekici'], 0, 20, 'UTF-8');
    if ($pl['dorse'] !== '') $yeni['vehicle_plate'] = mb_substr($pl['dorse'], 0, 30, 'UTF-8');
    if (can_beyan('contact.write')) {
        $ad = trim((string)($sev['sofor_adi'] ?? ''));
        $tel = beyan_telefon_normalize((string)($sev['sofor_telefon'] ?? ''));
        $rakam = strlen((string)preg_replace('/\D+/', '', $tel));
        if ($ad !== '') $yeni['driver_name'] = mb_substr($ad, 0, 150, 'UTF-8');
        if ($tel !== '' && $rakam >= 7 && $rakam <= 15) $yeni['driver_phone'] = $tel;
    }
    $set = []; $prm = []; $eski = []; $degisen = [];
    foreach ($yeni as $kol => $v) {
        if (!siparis_beyan_kolon_var($pdo, $kol)) continue;
        if ((string)($b[$kol] ?? '') === $v) continue;      // aynıysa yazma
        $set[] = "`$kol` = ?"; $prm[] = $v; $degisen[] = $kol;
        $eski[$kol] = (string)($b[$kol] ?? '');
    }
    if (!$set) return [];
    if (siparis_beyan_kolon_var($pdo, 'updated_by')) { $set[] = 'updated_by = ?'; $prm[] = $uid; }
    if (siparis_beyan_kolon_var($pdo, 'updated_at')) { $set[] = 'updated_at = ?'; $prm[] = siparis_simdi(); }
    $prm[] = $beyanId;
    $pdo->prepare('UPDATE customs_declarations SET ' . implode(', ', $set) . ' WHERE id = ? AND deleted_at IS NULL')->execute($prm);
    $kisisel = ['driver_name', 'driver_phone'];
    $audEski = array_diff_key($eski, array_flip($kisisel));
    $audYeni = array_diff_key(array_intersect_key($yeni, array_flip($degisen)), array_flip($kisisel));
    siparis_audit('siparis_beyan_arac_aktar', (int)($sev['id'] ?? 0), ['beyan_id' => $beyanId] + $audEski, ['beyan_id' => $beyanId, 'alanlar' => $degisen] + $audYeni, $uid);
    return $degisen;
}

/**
 * Bağ kurar (tek transaction; iki tarafın aktif bağları kilitlenir). Hata: aynı çift zaten aktif /
 * beyan başka sevkiyata aktif bağlı. Olay beyan_baglandi + audit siparis_beyan_bagla.
 * Plaka/şoför beyana aktarılır (siparis_beyan_arac_aktar). @return array{ok:bool,hata?:string,aktarilan?:list<string>}
 */
function siparis_beyan_bagla(PDO $pdo, int $sid, int $beyanId, int $uid, string $tur, string $gerekce): array
{
    if (!in_array($tur, ['elle', 'oneri'], true)) return ['ok' => false, 'hata' => 'Geçersiz bağ türü.'];
    $gerekce = siparis_kirp(trim($gerekce), 255);
    try {
        return siparis_tx($pdo, function () use ($pdo, $sid, $beyanId, $uid, $tur, $gerekce): array {
            $sev = siparis_sevkiyat($pdo, $sid);
            if ($sev === null) return ['ok' => false, 'hata' => 'Sevkiyat bulunamadı.'];
            $b = $pdo->prepare('SELECT id, party_no, status FROM customs_declarations WHERE id = ? AND deleted_at IS NULL');
            $b->execute([$beyanId]);
            $br = $b->fetch(PDO::FETCH_ASSOC);
            if ($br === false) return ['ok' => false, 'hata' => 'Beyan bulunamadı.'];
            if ((string)($br['status'] ?? '') === siparis_beyan_gizli_durum()) return ['ok' => false, 'hata' => 'Yüklendi durumundaki beyan sevkiyata bağlanamaz.'];
            $st = $pdo->prepare("SELECT sb.id, sb.sevkiyat_id, sb.beyan_id, s.order_ref FROM siparis_beyan sb LEFT JOIN siparis_sevkiyatlar s ON s.id = sb.sevkiyat_id
                WHERE sb.durum = 'aktif' AND (sb.sevkiyat_id = ? OR sb.beyan_id = ?)" . siparis_kilit_sql($pdo));
            $st->execute([$sid, $beyanId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $x) {
                if ((int)$x['beyan_id'] !== $beyanId) continue;
                if ((int)$x['sevkiyat_id'] === $sid) return ['ok' => false, 'hata' => 'Bu beyan bu sevkiyata zaten bağlı.'];
                return ['ok' => false, 'hata' => 'Bu beyan başka bir sevkiyata bağlı (' . ($x['order_ref'] ?? ('#' . $x['sevkiyat_id'])) . ') — önce oradaki bağı kaldırın.'];
            }
            $pdo->prepare('INSERT INTO siparis_beyan (sevkiyat_id, beyan_id, tur, gerekce, durum, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$sid, $beyanId, $tur, $gerekce !== '' ? $gerekce : null, 'aktif', $uid, siparis_simdi()]);
            $bagId = (int)$pdo->lastInsertId();
            siparis_olay($pdo, $sid, 'beyan_baglandi', null, '#' . $beyanId, $tur === 'oneri' ? 'Öneriden bağlandı' : 'Elle bağlandı', null, $uid);
            siparis_audit('siparis_beyan_bagla', $sid, null, ['sevkiyat_id' => $sid, 'beyan_id' => $beyanId, 'bag_id' => $bagId, 'tur' => $tur], $uid);
            $aktar = siparis_beyan_arac_aktar($pdo, $sev, $beyanId, $uid);
            return ['ok' => true, 'aktarilan' => $aktar];
        });
    } catch (PDOException $e) {
        error_log('[siparis_beyan_bagla] ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Bağ kaydedilemedi.'];
    }
}

/** Aktif bağı kaldırır (satır SİLİNMEZ, durum 'kaldirildi'). Olay + audit siparis_beyan_kaldir. @return array{ok:bool,hata?:string} */
function siparis_beyan_bag_kaldir(PDO $pdo, int $bagId, int $uid): array
{
    try {
        return siparis_tx($pdo, function () use ($pdo, $bagId, $uid): array {
            $st = $pdo->prepare("SELECT * FROM siparis_beyan WHERE id = ? AND durum = 'aktif'" . siparis_kilit_sql($pdo));
            $st->execute([$bagId]);
            $b = $st->fetch(PDO::FETCH_ASSOC);
            if (!$b) return ['ok' => false, 'hata' => 'Aktif bağ bulunamadı.'];
            $pdo->prepare("UPDATE siparis_beyan SET durum = 'kaldirildi', kaldiran = ?, kaldirildi_at = ? WHERE id = ?")->execute([$uid, siparis_simdi(), $bagId]);
            siparis_olay($pdo, (int)$b['sevkiyat_id'], 'beyan_kaldirildi', '#' . (int)$b['beyan_id'], null, 'Beyan bağı kaldırıldı', null, $uid);
            siparis_audit('siparis_beyan_kaldir', (int)$b['sevkiyat_id'], null, ['sevkiyat_id' => (int)$b['sevkiyat_id'], 'beyan_id' => (int)$b['beyan_id'], 'bag_id' => $bagId], $uid);
            return ['ok' => true];
        });
    } catch (PDOException $e) {
        error_log('[siparis_beyan_bag_kaldir] ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Bağ kaldırılamadı.'];
    }
}

/**
 * Liste için: sevkiyat → aktif bağlı beyanların parti no'su (tek sorgu, N+1 yok). Silinmiş beyanlar atlanır;
 * parti no'su boş beyan sayılmaz. Birden çok bağ varsa ilk (en eski bağ) + fazla = diğerlerinin sayısı.
 * Kapı: can_beyan('read') yoksa ya da beyan tabloları/kolonları yoksa BOŞ dizi (çağıran Tur no'ya düşer);
 * parti no beyan_redact_fields()'ten geçirilir (bugün hiçbir izin grubunda değil, grup eklenirse otomatik gizlenir).
 * @param list<int> $sevIds
 * @return array<int,array{parti:string,fazla:int}>
 */
function siparis_beyan_parti_haritasi(PDO $pdo, array $sevIds): array
{
    $sevIds = array_values(array_unique(array_filter(array_map('intval', $sevIds), fn($x) => $x > 0)));
    if (!$sevIds || !function_exists('can_beyan') || !can_beyan('read')) return [];
    $out = [];
    try {
        $st = $pdo->prepare("SELECT sb.sevkiyat_id, c.party_no FROM siparis_beyan sb
            JOIN customs_declarations c ON c.id = sb.beyan_id
            WHERE sb.durum = 'aktif' AND c.deleted_at IS NULL AND sb.sevkiyat_id IN (" . implode(',', array_fill(0, count($sevIds), '?')) . ')
            ORDER BY sb.sevkiyat_id, sb.id');
        $st->execute($sevIds);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $red = function_exists('beyan_redact_fields') ? beyan_redact_fields(['party_no' => $r['party_no']]) : ['party_no' => $r['party_no']];
            $p = trim((string)($red['party_no'] ?? ''));
            if ($p === '') continue;
            $sid = (int)$r['sevkiyat_id'];
            if (!isset($out[$sid])) $out[$sid] = ['parti' => $p, 'fazla' => 0, 'liste' => [$p]];
            elseif (!in_array($p, $out[$sid]['liste'], true)) { $out[$sid]['liste'][] = $p; $out[$sid]['fazla']++; }
        }
    } catch (PDOException $e) {
        error_log('[siparis_beyan_parti_haritasi] ' . $e->getMessage());
        return [];
    }
    return $out;
}
