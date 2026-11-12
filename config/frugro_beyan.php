<?php
// =========================================================
// config/frugro_beyan.php — Frugro sipariş ↔ gümrük beyanı bağı (include-only; önek frgb_)
//
// Şema YOK: bağ hks_kv 'frugro_beyan_<sid>' = {"beyanlar":[{"id","tur":"elle|oneri","at","by"}],"parti_kaynak":?int,"parti_deger":?string}.
// Kural: bir beyan TEK Frugro siparişine bağlanır (bir sipariş birden çok beyan alabilir); OGL'de (siparis_beyan) aktif bağlı beyan da reddedilir.
// customs_declarations'a YALNIZ SELECT (OGL'deki araç aktarımı / UPDATE burada YOK). parti_no boşsa beyandan DOLAR; elle girilmiş değer EZİLMEZ.
// Kapı SAYFADA (frugro_beyan.php: siparis.match + beyan.read + frgui_yetki('edit') + depo); buradaki fonksiyonlar salt DB.
// =========================================================
declare(strict_types=1);
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

require_once __DIR__ . '/siparis_beyan.php';
require_once __DIR__ . '/siparis_invoice.php';
require_once __DIR__ . '/frugro_core.php';

const FRGB_KV_ON = 'frugro_beyan_';

/** @return array{beyanlar:list<array{id:int,tur:string,at:string,by:?int}>,parti_kaynak:?int,parti_deger:?string} */
function frgb_oku(PDO $pdo, int $sid): array
{
    $j = siparis_inv_kv_oku($pdo, FRGB_KV_ON . $sid) ?? [];
    $liste = isset($j['beyanlar']) && is_array($j['beyanlar']) ? $j['beyanlar'] : (array_is_list($j) ? $j : []);
    $out = []; $gor = [];
    foreach ($liste as $e) {
        $id = is_array($e) ? (int)($e['id'] ?? 0) : (int)$e;
        if ($id <= 0 || isset($gor[$id])) continue;
        $gor[$id] = true;
        $out[] = ['id' => $id, 'tur' => (is_array($e) && ($e['tur'] ?? '') === 'oneri') ? 'oneri' : 'elle',
            'at' => is_array($e) ? (string)($e['at'] ?? '') : '', 'by' => is_array($e) && isset($e['by']) ? (int)$e['by'] : null];
    }
    $pk = isset($j['parti_kaynak']) ? (int)$j['parti_kaynak'] : null;
    return ['beyanlar' => $out, 'parti_kaynak' => $pk ?: null, 'parti_deger' => isset($j['parti_deger']) ? (string)$j['parti_deger'] : null];
}

function frgb_yaz(PDO $pdo, int $sid, array $v): bool
{
    return siparis_inv_kv_yaz($pdo, FRGB_KV_ON . $sid, $v);
}

/** beyanId → Frugro sipariş id (tek SELECT). @return array<int,int> */
function frgb_bagli_harita(PDO $pdo): array
{
    $h = [];
    try {
        $st = $pdo->query('SELECT anahtar, deger FROM `' . siparis_evrak_mail_kv_tablo() . "` WHERE anahtar LIKE 'frugro!_beyan!_%' ESCAPE '!'");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (!preg_match('/^frugro_beyan_(\d+)$/D', (string)$r['anahtar'], $m)) continue;
            $j = json_decode((string)$r['deger'], true);
            if (!is_array($j)) continue;
            $liste = isset($j['beyanlar']) && is_array($j['beyanlar']) ? $j['beyanlar'] : (array_is_list($j) ? $j : []);
            foreach ($liste as $e) {
                $b = is_array($e) ? (int)($e['id'] ?? 0) : (int)$e;
                if ($b > 0) $h[$b] = (int)$m[1];
            }
        }
    } catch (Throwable $e) { /* KV yok: bağ yok */ }
    return $h;
}

/** Beyan OGL'de aktif bağlıysa order_ref'i; değilse null (tablo yoksa null). */
function frgb_ogl_bagli(PDO $pdo, int $beyanId): ?string
{
    try {
        $st = $pdo->prepare("SELECT s.order_ref FROM siparis_beyan sb JOIN siparis_sevkiyatlar s ON s.id = sb.sevkiyat_id WHERE sb.beyan_id = ? AND sb.durum = 'aktif' LIMIT 1");
        $st->execute([$beyanId]);
        $r = $st->fetchColumn();
        return $r === false ? null : (string)$r;
    } catch (Throwable $e) {
        return null;
    }
}

/** OGL'de aktif bağlı tüm beyan id'leri (öneri süzgeci). @return array<int,string> */
function frgb_ogl_bagli_harita(PDO $pdo): array
{
    try {
        $h = [];
        foreach ($pdo->query("SELECT sb.beyan_id, s.order_ref FROM siparis_beyan sb JOIN siparis_sevkiyatlar s ON s.id = sb.sevkiyat_id WHERE sb.durum = 'aktif'")->fetchAll(PDO::FETCH_ASSOC) as $r) $h[(int)$r['beyan_id']] = (string)$r['order_ref'];
        return $h;
    } catch (Throwable $e) {
        return [];
    }
}

/** @return list<array{beyan_id:int,tur:string,beyan:?array}> */
function frgb_baglar(PDO $pdo, int $sid): array
{
    $kv = frgb_oku($pdo, $sid)['beyanlar'];
    if (!$kv) return [];
    $ozet = [];
    try {
        $ids = array_column($kv, 'id');
        $st = $pdo->prepare('SELECT ' . SIPARIS_BEYAN_KOLONLAR . ' FROM customs_declarations WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') AND deleted_at IS NULL');
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $ozet[(int)$r['id']] = siparis_beyan_ozet($r);
    } catch (Throwable $e) { /* beyan okunamadı: özet null */ }
    return array_map(static fn($e) => ['beyan_id' => $e['id'], 'tur' => $e['tur'], 'beyan' => $ozet[$e['id']] ?? null], $kv);
}

/** Plaka metnini parçalar ("34 ABC 123 / 34-XY-45"); her parça boşluksuz büyük harf. @return array{parca:list<string>,birlesik:string} */
function frgb_plaka_anahtar(?string $plaka): array
{
    $m = (string)$plaka;
    $parca = [];
    foreach (preg_split('/[\/,;|\r\n]+/', $m) ?: [] as $p) {
        $k = siparis_beyan_plaka_anahtar($p);
        if ($k !== '') $parca[] = $k;
    }
    return ['parca' => $parca, 'birlesik' => siparis_beyan_plaka_anahtar($m)];
}

function frgb_parti_anahtar(?string $p): string
{
    return mb_strtoupper((string)preg_replace('/\s+/u', '', trim((string)$p)), 'UTF-8');
}

/** @return list<array{beyan_id:int,puan:int,gerekce:list<string>,beyan:array}> */
function frgb_oneriler(PDO $pdo, array $s, int $limit = 5): array
{
    $t = siparis_tarih_coz((string)($s['cikis_tarihi'] ?? ''));
    if ($t !== null) {
        $bas = date('Y-m-d 00:00:00', (int)strtotime($t . ' 12:00:00 -7 day'));
        $bit = date('Y-m-d 00:00:00', (int)strtotime($t . ' 12:00:00 +4 day'));
    } else {
        $bas = date('Y-m-d 00:00:00', (int)strtotime('-30 day'));
        $bit = date('Y-m-d 00:00:00', (int)strtotime('+1 day'));
    }
    $haric = frgb_bagli_harita($pdo) + frgb_ogl_bagli_harita($pdo);
    try {
        $st = $pdo->prepare('SELECT ' . SIPARIS_BEYAN_KOLONLAR . " FROM customs_declarations
            WHERE deleted_at IS NULL AND COALESCE(status, '') <> ? AND created_at >= ? AND created_at < ? ORDER BY id DESC LIMIT 500");
        $st->execute([siparis_beyan_gizli_durum(), $bas, $bit]);
        $adaylar = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return [];
    }
    $pl = frgb_plaka_anahtar((string)($s['plaka'] ?? ''));
    $parti = frgb_parti_anahtar((string)($s['parti_no'] ?? ''));
    $out = [];
    foreach ($adaylar as $b) {
        if (isset($haric[(int)$b['id']])) continue;
        $puan = 0; $ger = [];
        $bp = array_values(array_filter([siparis_beyan_plaka_anahtar($b['tractor_plate'] ?? ''), siparis_beyan_plaka_anahtar($b['vehicle_plate'] ?? '')], static fn($x) => strlen($x) >= 5));
        $es = 0;
        foreach ($bp as $x) if (in_array($x, $pl['parca'], true) || ($pl['birlesik'] !== '' && str_contains($pl['birlesik'], $x))) $es++;
        if ($es >= 2) { $puan += 50; $ger[] = 'plaka eşleşti'; }
        elseif ($es === 1) { $puan += 30; $ger[] = 'bir plaka eşleşti'; }
        $bpar = frgb_parti_anahtar($b['party_no'] ?? '');
        if ($parti !== '' && $bpar !== '' && $parti === $bpar) { $puan += 60; $ger[] = 'parti no aynı'; }
        if ($puan > 0) $out[] = ['beyan_id' => (int)$b['id'], 'puan' => $puan, 'gerekce' => $ger, 'beyan' => siparis_beyan_ozet($b)];
    }
    usort($out, static fn($a, $b) => [$b['puan'], $b['beyan_id']] <=> [$a['puan'], $a['beyan_id']]);
    return array_slice($out, 0, max(1, $limit));
}

/** Elle arama; her satıra 'bagli': null | 'bu' | 'PO …' | 'OGL …'. @return list<array> */
function frgb_ara(PDO $pdo, string $q, int $sid): array
{
    $harita = frgb_bagli_harita($pdo);
    $po = [];
    $out = [];
    foreach (siparis_beyan_ara($pdo, $q, 10) as $b) {
        $id = (int)$b['id'];
        $bagli = null;
        if (isset($harita[$id])) {
            if ($harita[$id] === $sid) $bagli = 'bu';
            else {
                if (!isset($po[$harita[$id]])) { $r = frg_getir($pdo, $harita[$id]); $po[$harita[$id]] = $r ? (string)$r['po_no'] : '#' . $harita[$id]; }
                $bagli = 'PO ' . $po[$harita[$id]];
            }
        } else {
            $o = frgb_ogl_bagli($pdo, $id);
            if ($o !== null) $bagli = 'OGL ' . $o;
        }
        $b['bagli'] = $bagli;
        $out[] = $b;
    }
    return $out;
}

/** @return array{ok:bool,hata?:string,parti_dolduruldu?:bool} */
function frgb_bagla(PDO $pdo, array $s, int $beyanId, int $uid, string $tur): array
{
    $sid = (int)$s['id'];
    $tur = $tur === 'oneri' ? 'oneri' : 'elle';
    if ($beyanId <= 0) return ['ok' => false, 'hata' => 'Geçersiz beyan.'];
    try {
        return siparis_tx($pdo, function () use ($pdo, $sid, $beyanId, $uid, $tur): array {
            $st = $pdo->prepare('SELECT id, parti_no FROM frugro_siparisler WHERE id = ? AND deleted_at IS NULL' . siparis_kilit_sql($pdo));
            $st->execute([$sid]);
            $sip = $st->fetch(PDO::FETCH_ASSOC);
            if (!$sip) return ['ok' => false, 'hata' => 'Sipariş bulunamadı.'];
            $bs = $pdo->prepare('SELECT ' . SIPARIS_BEYAN_KOLONLAR . ' FROM customs_declarations WHERE id = ? AND deleted_at IS NULL');
            $bs->execute([$beyanId]);
            $b = $bs->fetch(PDO::FETCH_ASSOC);
            if (!$b) return ['ok' => false, 'hata' => 'Beyan bulunamadı.'];
            if ((string)($b['status'] ?? '') === siparis_beyan_gizli_durum()) return ['ok' => false, 'hata' => 'Yüklendi durumundaki beyan bağlanamaz.'];
            $harita = frgb_bagli_harita($pdo);
            if (isset($harita[$beyanId])) {
                if ($harita[$beyanId] === $sid) return ['ok' => false, 'hata' => 'Beyan zaten bu siparişe bağlı.'];
                $o = frg_getir($pdo, $harita[$beyanId]);
                return ['ok' => false, 'hata' => 'Beyan başka bir Frugro siparişine bağlı' . ($o ? ' (' . $o['po_no'] . ')' : '') . '.'];
            }
            $ogl = frgb_ogl_bagli($pdo, $beyanId);
            if ($ogl !== null) return ['ok' => false, 'hata' => 'Beyan OGL siparişine bağlı (' . $ogl . ').'];
            $v = frgb_oku($pdo, $sid);
            $v['beyanlar'][] = ['id' => $beyanId, 'tur' => $tur, 'at' => date('Y-m-d H:i:s'), 'by' => $uid];
            $doldu = false;
            $bp = trim((string)($b['party_no'] ?? ''));
            if (trim((string)($sip['parti_no'] ?? '')) === '' && $bp !== '' && (string)($b['status'] ?? '') !== 'iptal') {
                $bp = mb_substr($bp, 0, 30);
                $u = $pdo->prepare("UPDATE frugro_siparisler SET parti_no = ?, updated_at = ? WHERE id = ? AND (parti_no IS NULL OR parti_no = '')");
                $u->execute([$bp, date('Y-m-d H:i:s'), $sid]);
                if ($u->rowCount() > 0) { $doldu = true; $v['parti_kaynak'] = $beyanId; $v['parti_deger'] = $bp; }
            }
            if (!frgb_yaz($pdo, $sid, $v)) throw new RuntimeException('Beyan bağı yazılamadı.');
            frg_olay_yaz($pdo, $sid, 'beyan_baglandi', 'Beyan #' . $beyanId . ' (' . ($tur === 'oneri' ? 'öneri' : 'elle') . ')' . ($doldu ? '; parti no beyandan dolduruldu' : ''), $uid);
            return ['ok' => true, 'parti_dolduruldu' => $doldu];
        });
    } catch (Throwable $e) {
        error_log('[frugro_beyan] bağla: ' . get_class($e) . ' ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Bağ kurulamadı (ayrıntı sunucu günlüğünde).'];
    }
}

/** @return array{ok:bool,hata?:string,parti_temizlendi?:bool} */
function frgb_kaldir(PDO $pdo, array $s, int $beyanId, int $uid): array
{
    $sid = (int)$s['id'];
    try {
        return siparis_tx($pdo, function () use ($pdo, $sid, $beyanId, $uid): array {
            $st = $pdo->prepare('SELECT id, parti_no FROM frugro_siparisler WHERE id = ? AND deleted_at IS NULL' . siparis_kilit_sql($pdo));
            $st->execute([$sid]);
            $sip = $st->fetch(PDO::FETCH_ASSOC);
            if (!$sip) return ['ok' => false, 'hata' => 'Sipariş bulunamadı.'];
            $v = frgb_oku($pdo, $sid);
            $kalan = array_values(array_filter($v['beyanlar'], static fn($e) => $e['id'] !== $beyanId));
            if (count($kalan) === count($v['beyanlar'])) return ['ok' => false, 'hata' => 'Bu beyan bu siparişe bağlı değil.'];
            $v['beyanlar'] = $kalan;
            $temiz = false;
            if ($v['parti_kaynak'] === $beyanId) {
                if ($v['parti_deger'] !== null && (string)$sip['parti_no'] === $v['parti_deger']) {
                    $pdo->prepare('UPDATE frugro_siparisler SET parti_no = NULL, updated_at = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $sid]);
                    $temiz = true;
                }
                $v['parti_kaynak'] = null; $v['parti_deger'] = null;
            }
            if (!frgb_yaz($pdo, $sid, $v)) throw new RuntimeException('Beyan bağı yazılamadı.');
            frg_olay_yaz($pdo, $sid, 'beyan_kaldirildi', 'Beyan #' . $beyanId . ' bağı kaldırıldı' . ($temiz ? '; beyandan gelen parti no temizlendi' : ''), $uid);
            return ['ok' => true, 'parti_temizlendi' => $temiz];
        });
    } catch (Throwable $e) {
        error_log('[frugro_beyan] kaldır: ' . get_class($e) . ' ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Bağ kaldırılamadı (ayrıntı sunucu günlüğünde).'];
    }
}

/** Başlık rozeti: parti_no + bağlı beyan parti no'ları, tekil. @return list<string> */
function frgb_parti_listesi(array $s, array $baglar): array
{
    $out = [];
    $ekle = static function (string $p) use (&$out): void {
        $p = trim($p);
        if ($p === '') return;
        foreach ($out as $x) if (frgb_parti_anahtar($x) === frgb_parti_anahtar($p)) return;
        $out[] = $p;
    };
    $ekle((string)($s['parti_no'] ?? ''));
    foreach ($baglar as $bg) if (!empty($bg['beyan'])) $ekle((string)($bg['beyan']['party_no'] ?? ''));
    return $out;
}
