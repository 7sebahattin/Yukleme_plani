<?php
// =========================================================
// config/bi_rapor_core.php — ERP Faz 5: Yeni Raporlama Merkezi & Dinamik BI Tasarımcısı
// Kurallar: Include-only (404) · Sıfır runtime DDL · Katı Allowlist Sorgu Motoru (SIFIR SQL Injection)
// · CSV Formula Injection Koruması (B-33)
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

if (!function_exists('db_tx')) {
    require_once __DIR__ . '/db_tx_helpers.php';
}

// ── Yetki / Yanıt Yardımcıları ─────────────────────────────────────────────

function bi_can(string $eylem = 'read'): bool
{
    if (function_exists('is_admin') && is_admin()) return true;
    if ($eylem === 'read') {
        return function_exists('muhasebe_grup_okuma_can') && muhasebe_grup_okuma_can('raporlar_grup');
    }
    return function_exists('muhasebe_islem_can') && muhasebe_islem_can('raporlar_grup', $eylem);
}

function bi_kapi(string $eylem = 'read'): void
{
    if (!bi_can($eylem)) {
        if (function_exists('forbidden')) forbidden();
        http_response_code(403);
        exit('Bu işlem için yetkiniz yok.');
    }
}

function bi_no_store(): void
{
    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
    }
}

function bi_json_istek(): bool
{
    return (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest'
        || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json');
}

function bi_geri_guvenli(string $geri, string $varsayilan = 'rapor_tanimlari.php'): string
{
    $g = trim($geri);
    if ($g === '' || str_contains($g, '://') || str_starts_with($g, '//') || str_contains($g, "\n") || str_contains($g, "\r")) {
        return $varsayilan;
    }
    return preg_match('/^[a-zA-Z0-9_.-]+\.php(?:\?[a-zA-Z0-9_=&%-]*)?$/', $g) ? $g : $varsayilan;
}

/** @param array<string,mixed> $sonuc */
function bi_cevap(array $sonuc, string $geri, string $basariMesaj, bool $json): never
{
    $ok = !empty($sonuc['ok']);
    $kod = (int)($sonuc['kod'] ?? ($ok ? 200 : 422));
    if ($json) {
        http_response_code($kod);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($sonuc, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('flash_set')) {
        flash_set($ok ? 'success' : 'error', (string)($ok ? ($sonuc['mesaj'] ?? $basariMesaj) : ($sonuc['hata'] ?? 'İşlem başarısız.')));
    }
    header('Location: ' . $geri);
    exit;
}

function bi_tarih_gecerli(string $t): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $t, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

// ── Tablolar & Migrasyon ───────────────────────────────────────────────────

/** @return list<string> */
function bi_rapor_tablolar(): array
{
    return ['bi_rapor_sablonlari', 'bi_rapor_zamanlama'];
}

/** @return list<string> */
function bi_rapor_eksik_tablolar(PDO $pdo): array
{
    $eksik = [];
    foreach (bi_rapor_tablolar() as $t) {
        if (!db_table_exists($pdo, $t)) $eksik[] = $t;
    }
    return $eksik;
}

function bi_rapor_hazir(PDO $pdo): bool
{
    return bi_rapor_eksik_tablolar($pdo) === [];
}

function bi_rapor_sayfa_kapisi(PDO $pdo, bool $json = false): void
{
    $eksik = bi_rapor_eksik_tablolar($pdo);
    if ($eksik === []) return;
    if ($json) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'BI Raporlama şeması kurulu değil: ' . implode(', ', $eksik)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('render_header')) render_header('Raporlama & BI Merkezi');
    if (function_exists('bi_head_varlik')) bi_head_varlik();
    if (function_exists('bi_sema_uyari')) {
        echo bi_sema_uyari($eksik, 'bi-raporlama', 'Yeni Raporlama Merkezi & Dinamik BI (ERP Faz 5)');
    }
    if (function_exists('render_footer')) render_footer();
    exit;
}

/** @return list<array{tablo:string,durum:string,mesaj:string}> */
function bi_rapor_migrate(PDO $pdo): array
{
    $isSqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $ai  = $isSqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $eng = $isSqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $ddl = [
        'bi_rapor_sablonlari' => "CREATE TABLE IF NOT EXISTS `bi_rapor_sablonlari` (
            `id` $ai,
            `kod` VARCHAR(40) NOT NULL UNIQUE,
            `ad` VARCHAR(160) NOT NULL,
            `veri_kaynagi` VARCHAR(50) NOT NULL,
            `sutunlar_json` TEXT NOT NULL,
            `filtreler_json` TEXT NOT NULL,
            `siralama_sutun` VARCHAR(50) NOT NULL DEFAULT 'id',
            `siralama_yon` VARCHAR(4) NOT NULL DEFAULT 'DESC',
            `limit_satir` INT NOT NULL DEFAULT 200,
            `paylasimli` TINYINT(1) NOT NULL DEFAULT 1,
            `olusturan_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'bi_rapor_zamanlama' => "CREATE TABLE IF NOT EXISTS `bi_rapor_zamanlama` (
            `id` $ai,
            `sablon_id` INT NOT NULL,
            `periyot` VARCHAR(20) NOT NULL DEFAULT 'haftalik',
            `alici_eposta` VARCHAR(200) NOT NULL,
            `format` VARCHAR(12) NOT NULL DEFAULT 'csv',
            `aktif` TINYINT(1) NOT NULL DEFAULT 1,
            `son_calisma_at` DATETIME NULL,
            `olusturan_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
    ];

    $sonuc = [];
    foreach ($ddl as $tablo => $sql) {
        $var = db_table_exists($pdo, $tablo);
        if (!$var) $pdo->exec($sql);
        $sonuc[] = ['tablo' => $tablo, 'durum' => $var ? 'mevcut' : 'olusturuldu', 'mesaj' => $var ? 'Tablo zaten mevcut.' : 'Tablo oluşturuldu.'];
    }
    return $sonuc;
}

// ── 1) Katı Allowlist BI Veri Kaynakları Kataloğu (12 ERP Modülü) ──────────

/**
 * Her veri kaynağı: tablo adı, tarih kolonu, arama kolonu ve izinli sütun kataloğu [kolon => [etiket, sayisal_mi]].
 * @return array<string,array<string,mixed>>
 */
function bi_veri_kaynaklari(): array
{
    return [
        'genel_muhasebe' => [
            'ad' => 'Genel Muhasebe — Yevmiye Fişleri',
            'tablo' => 'muh_fisler',
            'tarih_kolon' => 'tarih',
            'ara_kolon' => 'aciklama',
            'sutunlar' => [
                'fis_no'        => ['Fiş No', false],
                'tarih'         => ['Tarih', false],
                'tur'           => ['Tür', false],
                'aciklama'      => ['Açıklama', false],
                'durum'         => ['Durum', false],
                'toplam_borc'   => ['Toplam Borç', true],
                'toplam_alacak' => ['Toplam Alacak', true],
            ],
        ],
        'edonusum' => [
            'ad' => 'e-Dönüşüm — Giden e-Fatura / e-Arşiv',
            'tablo' => 'edonusum_zarflar',
            'tarih_kolon' => 'tarih',
            'ara_kolon' => 'alici_unvan',
            'sutunlar' => [
                'belge_no'     => ['Belge No', false],
                'belge_turu'   => ['Tür', false],
                'tarih'        => ['Tarih', false],
                'alici_unvan'  => ['Alıcı', false],
                'para_birimi'  => ['PB', false],
                'ara_toplam'   => ['Ara Toplam', true],
                'kdv_toplam'   => ['KDV', true],
                'genel_toplam' => ['Genel Toplam', true],
                'durum'        => ['Durum', false],
            ],
        ],
    ];
}

// ── 2) Konsolide Yönetici Özeti (Sıfır N+1, Tek-Pass Agregatlar) ──────────

/** @return array<string,array{baslik:string,deger:string,alt:string,durum:string}> */
function bi_yonetici_ozeti(PDO $pdo, string $basTarih, string $bitTarih): array
{
    $kpis = [];
    $fmt = static fn(float $v, int $d = 2): string => number_format($v, $d, ',', '.');

    // 1) Genel Muhasebe
    if (db_table_exists($pdo, 'muh_fisler')) {
        $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(toplam_borc), 0) AS t FROM `muh_fisler` WHERE durum = 'onayli' AND tarih BETWEEN ? AND ?");
        $st->execute([$basTarih, $bitTarih]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 't' => 0];
        $kpis['muhasebe'] = ['baslik' => 'Yevmiye Hacmi', 'deger' => $fmt((float)$r['t']) . ' TL', 'alt' => (int)$r['n'] . ' onaylı fiş', 'durum' => 'ok'];
    }
    // 2) e-Dönüşüm
    if (db_table_exists($pdo, 'edonusum_zarflar')) {
        $st = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(genel_toplam), 0) AS t FROM `edonusum_zarflar` WHERE tarih BETWEEN ? AND ?');
        $st->execute([$basTarih, $bitTarih]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['n' => 0, 't' => 0];
        $kpis['edonusum'] = ['baslik' => 'e-Dönüşüm Belgeleri', 'deger' => $fmt((float)$r['t']) . ' TL', 'alt' => (int)$r['n'] . ' giden belge', 'durum' => 'ok'];
    }
    return $kpis;
}

// ── 3) Dinamik BI Şablon CRUD & Zamanlama ──────────────────────────────────

/** @return array{ok:bool,id?:int,kod?:string,hata?:string,mesaj?:string,kod_http?:int} */
function bi_sablon_kaydet(PDO $pdo, array $g, int $uid): array
{
    $kaynaklar = bi_veri_kaynaklari();
    $kod  = mb_strtoupper(mb_substr(trim((string)($g['kod'] ?? '')), 0, 40, 'UTF-8'), 'UTF-8');
    $ad   = mb_substr(trim((string)($g['ad'] ?? '')), 0, 160, 'UTF-8');
    $src  = (string)($g['veri_kaynagi'] ?? '');
    if (!isset($kaynaklar[$src])) return ['ok' => false, 'hata' => 'Geçersiz veri kaynağı seçildi.', 'kod' => 422];
    if ($ad === '') return ['ok' => false, 'hata' => 'Rapor şablon adı zorunludur.', 'kod' => 422];

    $izinliSutunlar = array_keys($kaynaklar[$src]['sutunlar']);
    $secilen = [];
    $rawSut = $g['sutunlar'] ?? [];
    if (is_string($rawSut)) $rawSut = array_filter(array_map('trim', explode(',', $rawSut)));
    foreach ((array)$rawSut as $c) {
        if (in_array((string)$c, $izinliSutunlar, true)) $secilen[] = (string)$c;
    }
    if ($secilen === []) $secilen = $izinliSutunlar;

    $sirSut = (string)($g['siralama_sutun'] ?? 'id');
    if ($sirSut !== 'id' && !in_array($sirSut, $izinliSutunlar, true)) $sirSut = 'id';
    $sirYon = strtoupper((string)($g['siralama_yon'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    $limit  = max(1, min(1000, (int)($g['limit_satir'] ?? 200)));
    $filtre = [
        'bas_tarih' => bi_tarih_gecerli((string)($g['bas_tarih'] ?? '')) ? (string)$g['bas_tarih'] : '',
        'bit_tarih' => bi_tarih_gecerli((string)($g['bit_tarih'] ?? '')) ? (string)$g['bit_tarih'] : '',
        'ara'       => mb_substr(trim((string)($g['ara'] ?? '')), 0, 100, 'UTF-8'),
    ];
    if ($kod === '') $kod = 'RPR-' . date('ym') . '-' . random_int(100, 999);

    return db_tx($pdo, static function (PDO $p) use ($kod, $ad, $src, $secilen, $filtre, $sirSut, $sirYon, $limit, $uid): array {
        $chk = $p->prepare('SELECT 1 FROM `bi_rapor_sablonlari` WHERE kod = ?');
        $chk->execute([$kod]);
        if ($chk->fetchColumn()) return ['ok' => false, 'hata' => "Bu şablon kodu zaten mevcut ($kod).", 'kod' => 409];

        $p->prepare(
            'INSERT INTO `bi_rapor_sablonlari` (kod, ad, veri_kaynagi, sutunlar_json, filtreler_json, siralama_sutun, siralama_yon, limit_satir, paylasimli, olusturan_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
        )->execute([$kod, $ad, $src, json_encode($secilen), json_encode($filtre, JSON_UNESCAPED_UNICODE), $sirSut, $sirYon, $limit, $uid ?: null]);
        $id = (int)$p->lastInsertId();
        if (function_exists('audit_log_event')) {
            audit_log_event('create', 'bi_rapor_sablon', $id, null, ['kod' => $kod, 'kaynak' => $src]);
        }
        return ['ok' => true, 'id' => $id, 'sablon_kod' => $kod, 'mesaj' => "BI rapor şablonu kaydedildi ($kod — $ad)."];
    });
}

/** @return array{ok:bool,hata?:string,mesaj?:string,kod?:int} */
function bi_sablon_sil(PDO $pdo, int $id, int $uid): array
{
    $st = $pdo->prepare('SELECT kod FROM `bi_rapor_sablonlari` WHERE id = ?');
    $st->execute([$id]);
    $kod = $st->fetchColumn();
    if (!$kod) return ['ok' => false, 'hata' => 'Rapor şablonu bulunamadı.', 'kod' => 404];
    $pdo->prepare('DELETE FROM `bi_rapor_zamanlama` WHERE sablon_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM `bi_rapor_sablonlari` WHERE id = ?')->execute([$id]);
    if (function_exists('audit_log_event')) {
        audit_log_event('delete', 'bi_rapor_sablon', $id, ['kod' => $kod], ['uid' => $uid]);
    }
    return ['ok' => true, 'mesaj' => "Rapor şablonu silindi ($kod)."];
}

/** @return array{ok:bool,id?:int,hata?:string,mesaj?:string,kod?:int} */
function bi_zamanlama_kaydet(PDO $pdo, array $g, int $uid): array
{
    $sid = (int)($g['sablon_id'] ?? 0);
    $per = (string)($g['periyot'] ?? 'haftalik');
    if (!in_array($per, ['gunluk', 'haftalik', 'aylik'], true)) $per = 'haftalik';
    $ep  = trim((string)($g['alici_eposta'] ?? ''));
    $fmt = (string)($g['format'] ?? 'csv');
    if (!in_array($fmt, ['csv', 'html'], true)) $fmt = 'csv';

    if ($sid <= 0) return ['ok' => false, 'hata' => 'Geçerli bir rapor şablonu seçin.', 'kod' => 422];
    if (!filter_var($ep, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'hata' => 'Geçerli bir alıcı e-posta adresi girin.', 'kod' => 422];

    $pdo->prepare(
        'INSERT INTO `bi_rapor_zamanlama` (sablon_id, periyot, alici_eposta, format, aktif, olusturan_id) VALUES (?, ?, ?, ?, 1, ?)'
    )->execute([$sid, $per, $ep, $fmt, $uid ?: null]);
    return ['ok' => true, 'id' => (int)$pdo->lastInsertId(), 'mesaj' => "Zamanlanmış rapor dağıtımı kaydedildi ($per · $ep)."];
}

// ── 4) Dinamik BI Sorgu Motoru (Katı Allowlist + Parametreli Filtre) ───────

/**
 * Şablonu veya ad-hoc veri kaynağı seçimini güvenle çalıştırır ve sayısal sütun toplamlarını üretir.
 * @return array{ok:bool,baslik?:string,sutunlar?:array<string,array{0:string,1:bool}>,satirlar?:list<array<string,mixed>>,toplamlar?:array<string,float>,kayit_sayisi?:int,hata?:string}
 */
function bi_sablon_calistir(PDO $pdo, array $sablon, array $ekFiltre = []): array
{
    $kaynaklar = bi_veri_kaynaklari();
    $src = (string)($sablon['veri_kaynagi'] ?? '');
    if (!isset($kaynaklar[$src])) return ['ok' => false, 'hata' => 'Bilinmeyen veri kaynağı.'];
    $meta = $kaynaklar[$src];
    $tablo = (string)$meta['tablo'];
    if (!db_table_exists($pdo, $tablo)) {
        return ['ok' => false, 'hata' => "Bu raporun kaynak tablosu ($tablo) henüz kurulmamış (migrate.php)."];
    }

    $sutMeta = $meta['sutunlar'];
    $secilen = [];
    $rawSut = is_array($sablon['sutunlar'] ?? null)
        ? $sablon['sutunlar']
        : (array)json_decode((string)($sablon['sutunlar_json'] ?? '[]'), true);
    foreach ($rawSut as $c) {
        if (isset($sutMeta[(string)$c])) $secilen[(string)$c] = $sutMeta[(string)$c];
    }
    if ($secilen === []) $secilen = $sutMeta;

    $colsSql = implode(', ', array_map(static fn(string $c): string => '`' . $c . '`', array_keys($secilen)));

    $kayitliFiltre = is_array($sablon['filtreler'] ?? null)
        ? $sablon['filtreler']
        : (array)json_decode((string)($sablon['filtreler_json'] ?? '{}'), true);
    $basT = trim((string)($ekFiltre['bas_tarih'] ?? ($kayitliFiltre['bas_tarih'] ?? '')));
    $bitT = trim((string)($ekFiltre['bit_tarih'] ?? ($kayitliFiltre['bit_tarih'] ?? '')));
    $ara  = trim((string)($ekFiltre['ara'] ?? ($kayitliFiltre['ara'] ?? '')));

    $where = ['1=1'];
    $params = [];
    $tKol = $meta['tarih_kolon'];
    if ($tKol !== null) {
        if (bi_tarih_gecerli($basT)) { $where[] = "`$tKol` >= ?"; $params[] = $basT; }
        if (bi_tarih_gecerli($bitT)) { $where[] = "`$tKol` <= ?"; $params[] = $bitT; }
    }
    $aKol = $meta['ara_kolon'];
    if ($ara !== '' && $aKol !== null) {
        $where[] = "`$aKol` LIKE ?";
        $params[] = '%' . $ara . '%';
    }

    $sirSut = (string)($sablon['siralama_sutun'] ?? 'id');
    if ($sirSut !== 'id' && !isset($sutMeta[$sirSut])) $sirSut = 'id';
    $sirYon = strtoupper((string)($sablon['siralama_yon'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
    $limit  = max(1, min(1000, (int)($sablon['limit_satir'] ?? 200)));

    $sql = "SELECT $colsSql FROM `$tablo` WHERE " . implode(' AND ', $where) . " ORDER BY `$sirSut` $sirYon LIMIT $limit";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $satirlar = $st->fetchAll(PDO::FETCH_ASSOC);

    $toplamlar = [];
    foreach ($secilen as $k => [, $sayisal]) {
        if ($sayisal) $toplamlar[$k] = 0.0;
    }
    foreach ($satirlar as $s) {
        foreach ($toplamlar as $k => $v) {
            $toplamlar[$k] = round($v + (float)($s[$k] ?? 0), 2);
        }
    }

    return [
        'ok'           => true,
        'baslik'       => (string)($sablon['ad'] ?? $meta['ad']),
        'sutunlar'     => $secilen,
        'satirlar'     => $satirlar,
        'toplamlar'    => $toplamlar,
        'kayit_sayisi' => count($satirlar),
    ];
}

// ── 5) Güvenli CSV Üretici (B-33 Formula Injection Korumalı) ───────────────

/**
 * Türkçe Excel uyumlu (; ayırıcı + UTF-8 BOM) ve CSV formula injection korumalı çıktı üretir.
 * @param array<string,array{0:string,1:bool}> $sutunlar
 * @param list<array<string,mixed>> $satirlar
 */
function bi_csv_uret(array $sutunlar, array $satirlar): string
{
    $hucre = static function ($v): string {
        $s = (string)($v ?? '');
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && !is_numeric($s)) {
            $s = "'" . $s;
        }
        return '"' . str_replace('"', '""', $s) . '"';
    };

    $lines = [];
    $lines[] = implode(';', array_map(static fn($m) => $hucre($m[0]), $sutunlar));
    foreach ($satirlar as $r) {
        $row = [];
        foreach (array_keys($sutunlar) as $k) {
            $row[] = $hucre($r[$k] ?? '');
        }
        $lines[] = implode(';', $row);
    }
    return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
}

// ── Listeler ───────────────────────────────────────────────────────────────

/** @return list<array<string,mixed>> */
function bi_sablon_listesi(PDO $pdo): array
{
    return $pdo->query('SELECT * FROM `bi_rapor_sablonlari` ORDER BY id DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string,mixed>> */
function bi_zamanlama_listesi(PDO $pdo): array
{
    return $pdo->query(
        'SELECT z.*, s.kod AS sablon_kod, s.ad AS sablon_ad FROM `bi_rapor_zamanlama` z INNER JOIN `bi_rapor_sablonlari` s ON s.id = z.sablon_id ORDER BY z.id DESC LIMIT 100'
    )->fetchAll(PDO::FETCH_ASSOC);
}
