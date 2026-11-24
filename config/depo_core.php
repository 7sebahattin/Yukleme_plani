<?php
// =========================================================
// config/depo_core.php — Depo Tanımları çekirdeği (v574)
//
// NEDEN: Eski sistemde depo = `material_definitions` (type='depo') içindeki SERBEST METİN adı ve
// ~35 tabloda `depo` metin kolonu. Eski depolar/veri kalkıyor; yeni depolar eskilerle KARIŞMAMALI.
//
// TASARIM (sahip kararı, 2026-11):
//   • Şube kavramı YOK; yalnız depo.
//   • `depolar`  — yeni depo kaydı: sabit `id` + DEĞİŞMEZ `kod` (D001…); ad = tr_upper (sistem geleneği);
//                  `ad_anahtar` = TR-duyarsız katlanmış ad (UNIQUE → "Karaman"/"KARAMAN" iki kez açılamaz).
//   • `depo_kullanicilar` — kullanıcı ↔ depo ataması ID ile (eski `user_depolar` ad-metni tablosu GEÇİŞTEN SONRA okunmaz).
//   • `depo_eski_adlar`   — "ESKİ" kayıt defteri: geçiş anında bilinen tüm eski depo adları (tanım + kullanıcı ataması +
//                  veri kolonlarındaki değerler) damgalanır. Yeni depo bu adlardan birini ALAMAZ → eski satırlar
//                  yeni bir depoya sessizce "bağlanamaz". Eski satırlar silinmez; hiçbir yeni listede görünmez.
//   • `depo_ayar`  — 'gecis_tarihi' satırı = yeni sistem AÇIK. Satır yoksa her şey ESKİ davranışta (fail-safe).
//   • Eski `depo` METİN kolonları dokunulmadan kalır (veri satırı yeni depo ADINI taşır); ad değişince
//     `depo_ad_aktar()` TÜM depo kolonlarını (keşfedilen) yeni ada çeker.
//
// Şema yalnız migrate.php › "Depolar" kartından kurulur (sayfalar ALTER/CREATE atmaz).
// Gereken (çağrı anında): helpers.php (tr_upper, audit_log_event), auth.php (depo_fold).
// =========================================================
declare(strict_types=1);

/** Tablo adı => CREATE TABLE IF NOT EXISTS DDL. Yalnız ekleyici (ALTER / yabancı anahtar YOK). */
function depo_tablolar(): array
{
    return [
        'depolar' => "CREATE TABLE IF NOT EXISTS `depolar` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `kod`        VARCHAR(20)  NOT NULL,
  `ad`         VARCHAR(100) NOT NULL,
  `ad_anahtar` VARCHAR(100) NOT NULL,
  `renk`       VARCHAR(7)   NULL DEFAULT NULL,
  `aktif`      TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at` DATETIME     NOT NULL,
  `created_by` INT          NULL DEFAULT NULL,
  `updated_at` DATETIME     NULL DEFAULT NULL,
  `updated_by` INT          NULL DEFAULT NULL,
  UNIQUE KEY `uq_depolar_kod` (`kod`),
  UNIQUE KEY `uq_depolar_anahtar` (`ad_anahtar`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'depo_kullanicilar' => "CREATE TABLE IF NOT EXISTS `depo_kullanicilar` (
  `depo_id`    INT      NOT NULL,
  `user_id`    INT      NOT NULL,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`depo_id`, `user_id`),
  INDEX `idx_dk_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'depo_eski_adlar' => "CREATE TABLE IF NOT EXISTS `depo_eski_adlar` (
  `ad_anahtar` VARCHAR(100) NOT NULL,
  `ad`         VARCHAR(150) NOT NULL,
  `kaynak`     VARCHAR(60)  NOT NULL,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`ad_anahtar`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'depo_ayar' => "CREATE TABLE IF NOT EXISTS `depo_ayar` (
  `anahtar`    VARCHAR(40)  NOT NULL,
  `deger`      VARCHAR(255) NOT NULL,
  `updated_at` DATETIME     NOT NULL,
  PRIMARY KEY (`anahtar`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}

/** Depo adı taşıyan veri kolonları (keşif bunlara bakar). Tablolar: depo_kolonlari() hariç tutar. */
const DEPO_KOLON_ADLARI = ['depo', 'exit_depot', 'hedef_depo'];
/** Veri kolonu taraması DIŞINDA kalan tablolar (kendi depo yönetimi / eski atama / kayıt defteri). */
const DEPO_TARAMA_DISI = ['depolar', 'depo_kullanicilar', 'depo_eski_adlar', 'depo_ayar', 'user_depolar', 'material_definitions'];

/** Bu istekte önbelleği sıfırla (testler + yazma sonrası). */
function depo_onbellek_sifirla(): void
{
    $GLOBALS['_depo_onbellek'] = [];
}

function depo_tablo_var(PDO $pdo, string $t): bool
{
    try { $pdo->query("SELECT 1 FROM `$t` LIMIT 0"); return true; } catch (PDOException $e) { return false; }
}

/** 4 tablo da var mı? */
function depo_hazir(PDO $pdo): bool
{
    $k = 'hazir' . spl_object_id($pdo);
    if (isset($GLOBALS['_depo_onbellek'][$k])) return true;
    foreach (array_keys(depo_tablolar()) as $t) if (!depo_tablo_var($pdo, $t)) return false;
    return $GLOBALS['_depo_onbellek'][$k] = true;
}

/**
 * Yeni depo sistemi AÇIK mı? (tablolar var + 'gecis_tarihi' ayarı var). Değilse ESKİ davranış sürer.
 * Hata/tablo yok → false (fail-safe: eski sistem çalışmaya devam eder).
 */
function depo_gecis_aktif(PDO $pdo): bool
{
    $k = 'gecis' . spl_object_id($pdo);
    if (array_key_exists($k, $GLOBALS['_depo_onbellek'] ?? [])) return $GLOBALS['_depo_onbellek'][$k];
    try {
        $v = $pdo->query("SELECT deger FROM `depo_ayar` WHERE anahtar = 'gecis_tarihi'")->fetchColumn();
        $a = $v !== false && trim((string)$v) !== '';
    } catch (PDOException $e) { $a = false; }
    return $GLOBALS['_depo_onbellek'][$k] = $a;
}

/** Yalnız migrate.php. TEST KANCASI: $GLOBALS['_depo_ddl_calistir'] = fn(PDO,string $ad,string $ddl): void. */
function depo_migrate(PDO $pdo): array
{
    $rapor = [];
    $kanca = $GLOBALS['_depo_ddl_calistir'] ?? null;
    foreach (depo_tablolar() as $ad => $ddl) {
        if (depo_tablo_var($pdo, $ad)) { $rapor[] = ['tablo' => $ad, 'durum' => 'var', 'mesaj' => 'Tablo zaten mevcut.']; continue; }
        try {
            if (is_callable($kanca)) $kanca($pdo, $ad, $ddl); else $pdo->exec($ddl);
            $rapor[] = ['tablo' => $ad, 'durum' => 'olusturuldu', 'mesaj' => 'Tablo oluşturuldu.'];
        } catch (PDOException $e) {
            error_log('[depo_migrate] ' . $ad . ': ' . $e->getMessage());
            $rapor[] = ['tablo' => $ad, 'durum' => 'hata', 'mesaj' => 'Tablo oluşturulamadı (ayrıntı sunucu günlüğünde).'];
        }
    }
    depo_onbellek_sifirla();
    return $rapor;
}

// ── Ad kuralları ─────────────────────────────────────────

/** Eşleştirme anahtarı: TR-duyarsız katlanmış ad. */
function depo_anahtar(string $ad): string
{
    return mb_substr(depo_fold($ad), 0, 100);
}

/** Girdi adını normalize eder (boşluk sadeleştir, TR büyük harf). @return array{0:string,1:string} [ad, hata] */
function depo_ad_dogrula(string $girdi): array
{
    $ad = trim((string)preg_replace('/\s+/u', ' ', $girdi));
    if ($ad === '') return ['', 'Depo adı zorunludur.'];
    if (preg_match('/[\x00-\x1F\x7F]/u', $ad)) return ['', 'Depo adında geçersiz karakter var.'];
    $ad = tr_upper($ad);
    if (mb_strlen($ad) < 2) return ['', 'Depo adı en az 2 karakter olmalı.'];
    if (mb_strlen($ad) > 100) return ['', 'Depo adı en çok 100 karakter olabilir.'];
    return [$ad, ''];
}

// ── Veri kolonu keşfi + kullanım ─────────────────────────

/**
 * Depo adı taşıyan [tablo, kolon] çiftleri (keşif: MySQL information_schema / SQLite pragma).
 * Eski/yeni ayrımı yapmaz; depo yönetim tablolarını ve material_definitions'ı atlar.
 * @return list<array{0:string,1:string}>
 */
function depo_kolonlari(PDO $pdo): array
{
    $k = 'kolon' . spl_object_id($pdo);
    if (isset($GLOBALS['_depo_onbellek'][$k])) return $GLOBALS['_depo_onbellek'][$k];
    $out = [];
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $tablolar = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tablolar as $t) {
                if (in_array($t, DEPO_TARAMA_DISI, true)) continue;
                foreach ($pdo->query('PRAGMA table_info(`' . str_replace('`', '', (string)$t) . '`)')->fetchAll() as $c) {
                    if (in_array((string)$c['name'], DEPO_KOLON_ADLARI, true)) $out[] = [(string)$t, (string)$c['name']];
                }
            }
        } else {
            $in = implode(',', array_fill(0, count(DEPO_KOLON_ADLARI), '?'));
            $st = $pdo->prepare("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ($in)
                  AND DATA_TYPE IN ('varchar','char','text') ORDER BY TABLE_NAME, COLUMN_NAME");
            $st->execute(DEPO_KOLON_ADLARI);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as [$t, $c]) {
                if (!in_array((string)$t, DEPO_TARAMA_DISI, true)) $out[] = [(string)$t, (string)$c];
            }
        }
    } catch (PDOException $e) { error_log('[depo_kolonlari] ' . $e->getMessage()); }
    return $GLOBALS['_depo_onbellek'][$k] = $out;
}

/**
 * Veri kolonlarındaki DEPO DEĞERLERİ ve satır sayıları (boş değerler atlanır).
 * @return list<array{tablo:string,kolon:string,deger:string,adet:int}>
 */
function depo_kullanim_satirlari(PDO $pdo): array
{
    $out = [];
    foreach (depo_kolonlari($pdo) as [$t, $c]) {
        try {
            $q = $pdo->query("SELECT `$c` AS d, COUNT(*) AS n FROM `$t` WHERE `$c` IS NOT NULL AND TRIM(`$c`) <> '' GROUP BY `$c`");
            foreach ($q->fetchAll() as $r) $out[] = ['tablo' => $t, 'kolon' => $c, 'deger' => (string)$r['d'], 'adet' => (int)$r['n']];
        } catch (PDOException $e) { /* tablo/kolon okunamadı — atla */ }
    }
    return $out;
}

/** anahtar => toplam satır sayısı (tüm veri kolonları). */
function depo_kullanim_toplam(PDO $pdo): array
{
    $t = [];
    foreach (depo_kullanim_satirlari($pdo) as $r) { $a = depo_anahtar($r['deger']); $t[$a] = ($t[$a] ?? 0) + $r['adet']; }
    return $t;
}

/**
 * Bir depo adını TÜM veri kolonlarında yeni ada çeker (TR-duyarsız eşleşme). Dönen: güncellenen satır sayısı.
 * $tablolar verilirse yalnız o tablolar (eski → yeni depoya aktarım aracı); null = hepsi.
 */
function depo_ad_aktar(PDO $pdo, string $eskiAd, string $yeniAd, ?array $tablolar = null): int
{
    $hedef = depo_anahtar($eskiAd);
    if ($hedef === '' || $yeniAd === '') return 0;
    $toplam = 0;
    foreach (depo_kolonlari($pdo) as [$t, $c]) {
        if ($tablolar !== null && !in_array($t, $tablolar, true)) continue;
        try {
            $vals = $pdo->query("SELECT DISTINCT `$c` FROM `$t` WHERE `$c` IS NOT NULL AND `$c` <> ''")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($vals as $v) {
                $v = (string)$v;
                if ($v !== $yeniAd && depo_anahtar($v) === $hedef) {
                    $st = $pdo->prepare("UPDATE `$t` SET `$c` = ? WHERE `$c` = ?");
                    $st->execute([$yeniAd, $v]);
                    $toplam += $st->rowCount();
                }
            }
        } catch (PDOException $e) { error_log('[depo_ad_aktar] ' . $t . '.' . $c . ': ' . $e->getMessage()); throw $e; }
    }
    return $toplam;
}

// ── Eski ad kayıt defteri ────────────────────────────────

/**
 * Bilinen ESKİ depo adları (anahtar => [ad, kaynak]). Geçiş öncesi: canlı eski kaynaklar da dahil
 * (material_definitions type='depo', user_depolar, veri kolonu değerleri) → yeni depo bunlarla çakışamaz.
 * Geçiş sonrası: yalnız `depo_eski_adlar` defteri.
 */
function depo_eski_adlar(PDO $pdo): array
{
    $out = [];
    if (depo_tablo_var($pdo, 'depo_eski_adlar')) {
        foreach ($pdo->query('SELECT ad_anahtar, ad, kaynak FROM `depo_eski_adlar`')->fetchAll() as $r) $out[(string)$r['ad_anahtar']] = [(string)$r['ad'], (string)$r['kaynak']];
    }
    if (!depo_gecis_aktif($pdo)) {
        foreach (depo_eski_kaynak_adlari($pdo) as $a => $v) if (!isset($out[$a])) $out[$a] = $v;
    }
    return $out;
}

/** Eski kaynaklardan (tanım + atama + veri) ad listesi — defter DEĞİL, canlı tarama. anahtar => [ad, kaynak]. */
function depo_eski_kaynak_adlari(PDO $pdo): array
{
    $out = [];
    $ekle = function (string $ad, string $kaynak) use (&$out): void {
        $ad = trim($ad); if ($ad === '') return;
        $a = depo_anahtar($ad); if ($a !== '' && !isset($out[$a])) $out[$a] = [$ad, $kaynak];
    };
    foreach (["SELECT name FROM material_definitions WHERE type = 'depo'" => 'tanim', 'SELECT DISTINCT depo FROM user_depolar' => 'atama'] as $sql => $kaynak) {
        try { foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $v) $ekle((string)$v, $kaynak); } catch (PDOException $e) { /* kaynak yok */ }
    }
    foreach (depo_kullanim_satirlari($pdo) as $r) $ekle($r['deger'], 'veri:' . $r['tablo'] . '.' . $r['kolon']);
    return $out;
}

// ── Okuma ────────────────────────────────────────────────

/** Tüm depolar (aktif+pasif) ad sırasıyla. @return list<array<string,mixed>> */
function depo_hepsi(PDO $pdo): array
{
    try { return $pdo->query('SELECT id, kod, ad, ad_anahtar, renk, aktif, created_at FROM `depolar` ORDER BY aktif DESC, ad')->fetchAll(); }
    catch (PDOException $e) { return []; }
}

function depo_getir(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT id, kod, ad, ad_anahtar, renk, aktif FROM `depolar` WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    return $r ?: null;
}

/** AKTİF depo adları (depo_sec / depot_options kaynağı). */
function depo_aktif_adlar(PDO $pdo): array
{
    try { return array_map('strval', $pdo->query('SELECT ad FROM `depolar` WHERE aktif = 1 ORDER BY ad')->fetchAll(PDO::FETCH_COLUMN)); }
    catch (PDOException $e) { return []; }
}

/**
 * Kullanıcının atandığı AKTİF depo adları. Hiç atama satırı yoksa null (kısıtsız — eski kuralla aynı);
 * atama var ama hepsi pasifse [] (hiçbir depo).
 */
function depo_atanan_adlar(PDO $pdo, int $userId): ?array
{
    try {
        $s = $pdo->prepare('SELECT COUNT(*) FROM `depo_kullanicilar` WHERE user_id = ?');
        $s->execute([$userId]);
        if ((int)$s->fetchColumn() === 0) return null;
        $st = $pdo->prepare('SELECT d.ad FROM `depo_kullanicilar` k JOIN `depolar` d ON d.id = k.depo_id WHERE k.user_id = ? AND d.aktif = 1 ORDER BY d.ad');
        $st->execute([$userId]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) { return []; }   // okunamadı → fail-closed (kapsamı GENİŞLETME)
}

/** depo_id => [user_id, …] */
function depo_kullanici_listesi(PDO $pdo): array
{
    $out = [];
    try { foreach ($pdo->query('SELECT depo_id, user_id FROM `depo_kullanicilar`')->fetchAll() as $r) $out[(int)$r['depo_id']][] = (int)$r['user_id']; }
    catch (PDOException $e) { /* boş */ }
    return $out;
}

/** user_id => [depo adı, …] (yalnız AKTİF depolar; kullanıcı ekranı etiketleri için). */
function depo_kullanici_haritasi(PDO $pdo): array
{
    $out = [];
    try {
        foreach ($pdo->query('SELECT k.user_id, d.ad FROM `depo_kullanicilar` k JOIN `depolar` d ON d.id = k.depo_id WHERE d.aktif = 1 ORDER BY d.ad')->fetchAll() as $r) $out[(int)$r['user_id']][] = (string)$r['ad'];
    } catch (PDOException $e) { /* boş */ }
    return $out;
}

function depo_renk(PDO $pdo, string $ad): ?string
{
    try {
        $st = $pdo->prepare('SELECT renk FROM `depolar` WHERE ad_anahtar = ? LIMIT 1');
        $st->execute([depo_anahtar($ad)]);
        $r = $st->fetchColumn();
        return is_string($r) && preg_match('/^#[0-9a-fA-F]{6}$/', $r) ? $r : null;
    } catch (PDOException $e) { return null; }
}

// ── Yazma ────────────────────────────────────────────────

function depo_uid(): ?int
{
    $u = function_exists('current_user') ? current_user() : null;
    return $u ? (int)$u['id'] : null;
}

/** Ad başka bir YENİ depoda ya da ESKİ defterde var mı? Hata metni ya da ''. $haricId = düzenlenen depo. */
function depo_ad_cakisma(PDO $pdo, string $ad, int $haricId = 0): string
{
    $a = depo_anahtar($ad);
    $st = $pdo->prepare('SELECT id FROM `depolar` WHERE ad_anahtar = ? AND id <> ?');
    $st->execute([$a, $haricId]);
    if ($st->fetchColumn()) return 'Bu adla bir depo zaten var.';
    $eski = depo_eski_adlar($pdo);
    if (isset($eski[$a])) return 'Bu ad ESKİ bir depoya ait (' . $eski[$a][0] . '). Eski verilerle karışmaması için farklı bir ad verin.';
    return '';
}

function depo_renk_dogrula($v): ?string
{
    $v = trim((string)$v);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : null;
}

/** Atanacak kullanıcı kimliklerini DOĞRULA (users'da var + aktif). */
function depo_kullanici_dogrula(PDO $pdo, array $ids): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($i) => $i > 0)));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id FROM users WHERE id IN ($in)");
    $st->execute($ids);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Bir deponun kullanıcılarını değiştirir (yeni liste). */
function depo_kullanicilari_yaz(PDO $pdo, int $depoId, array $userIds): void
{
    $pdo->prepare('DELETE FROM `depo_kullanicilar` WHERE depo_id = ?')->execute([$depoId]);
    $ins = $pdo->prepare('INSERT INTO `depo_kullanicilar` (depo_id, user_id, created_at) VALUES (?, ?, ?)');
    foreach (depo_kullanici_dogrula($pdo, $userIds) as $u) $ins->execute([$depoId, $u, date('Y-m-d H:i:s')]);
}

/** Bir KULLANICININ AKTİF depolarını ad listesinden değiştirir (Kullanıcılar ekranı). Bilinmeyen/pasif ad sessizce atlanır. */
function depo_kullanici_depolari_yaz(PDO $pdo, int $userId, array $adlar): void
{
    // Yalnız AKTİF depolardaki atamalar yeniden yazılır (ekranda yalnız onlar görünür); pasif depodaki atama korunur.
    $pdo->prepare('DELETE FROM `depo_kullanicilar` WHERE user_id = ? AND depo_id IN (SELECT id FROM `depolar` WHERE aktif = 1)')->execute([$userId]);
    $ins = $pdo->prepare('INSERT INTO `depo_kullanicilar` (depo_id, user_id, created_at) VALUES (?, ?, ?)');
    $bul = $pdo->prepare('SELECT id FROM `depolar` WHERE ad_anahtar = ? AND aktif = 1');
    foreach ($adlar as $ad) {
        $bul->execute([depo_anahtar((string)$ad)]);
        $id = $bul->fetchColumn();
        if ($id) $ins->execute([(int)$id, $userId, date('Y-m-d H:i:s')]);
    }
}

/**
 * Yeni depo ekler. $g: ad, renk, aktif(1), kullanicilar[] . @return array{ok:bool,hata:string,id:int}
 */
function depo_ekle(PDO $pdo, array $g): array
{
    [$ad, $h] = depo_ad_dogrula((string)($g['ad'] ?? ''));
    if ($h !== '') return ['ok' => false, 'hata' => $h, 'id' => 0];
    $h = depo_ad_cakisma($pdo, $ad);
    if ($h !== '') return ['ok' => false, 'hata' => $h, 'id' => 0];
    $uid = depo_uid();
    try {
        $pdo->beginTransaction();
        $pdo->prepare('INSERT INTO `depolar` (kod, ad, ad_anahtar, renk, aktif, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute(['_' . bin2hex(random_bytes(8)), $ad, depo_anahtar($ad), depo_renk_dogrula($g['renk'] ?? ''), array_key_exists('aktif', $g) && empty($g['aktif']) ? 0 : 1, date('Y-m-d H:i:s'), $uid]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare('UPDATE `depolar` SET kod = ? WHERE id = ?')->execute([sprintf('D%03d', $id), $id]);
        if (!empty($g['kullanicilar'])) depo_kullanicilari_yaz($pdo, $id, (array)$g['kullanicilar']);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[depo_ekle] ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Depo kaydedilemedi.', 'id' => 0];
    }
    depo_onbellek_sifirla();
    if (function_exists('audit_log_event')) audit_log_event('create', 'depolar', $id, null, ['ad' => $ad, 'kod' => sprintf('D%03d', $id)]);
    return ['ok' => true, 'hata' => '', 'id' => $id];
}

/**
 * Depoyu günceller. Ad değişirse TÜM veri kolonları yeni ada çekilir (aynı işlemde).
 * $g: ad, renk, aktif, kullanicilar[] . @return array{ok:bool,hata:string,aktarilan:int}
 */
function depo_guncelle(PDO $pdo, int $id, array $g): array
{
    $d = depo_getir($pdo, $id);
    if (!$d) return ['ok' => false, 'hata' => 'Depo bulunamadı.', 'aktarilan' => 0];
    [$ad, $h] = depo_ad_dogrula((string)($g['ad'] ?? $d['ad']));
    if ($h !== '') return ['ok' => false, 'hata' => $h, 'aktarilan' => 0];
    $h = depo_ad_cakisma($pdo, $ad, $id);
    if ($h !== '') return ['ok' => false, 'hata' => $h, 'aktarilan' => 0];
    $uid = depo_uid(); $aktarilan = 0;
    $aktif = array_key_exists('aktif', $g) ? (empty($g['aktif']) ? 0 : 1) : (int)$d['aktif'];
    $renk  = array_key_exists('renk', $g) ? depo_renk_dogrula($g['renk']) : ($d['renk'] ?? null);
    try {
        $pdo->beginTransaction();
        if ($ad !== (string)$d['ad']) $aktarilan = depo_ad_aktar($pdo, (string)$d['ad'], $ad);
        $pdo->prepare('UPDATE `depolar` SET ad = ?, ad_anahtar = ?, renk = ?, aktif = ?, updated_at = ?, updated_by = ? WHERE id = ?')
            ->execute([$ad, depo_anahtar($ad), $renk, $aktif, date('Y-m-d H:i:s'), $uid, $id]);
        $oncekiKul = depo_kullanici_listesi($pdo)[$id] ?? [];
        if (array_key_exists('kullanicilar', $g)) depo_kullanicilari_yaz($pdo, $id, (array)$g['kullanicilar']);
        $yeniKul = depo_kullanici_listesi($pdo)[$id] ?? [];
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[depo_guncelle] ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Depo güncellenemedi.', 'aktarilan' => 0];
    }
    depo_onbellek_sifirla();
    if (function_exists('audit_log_event')) audit_log_event('update', 'depolar', $id, ['ad' => $d['ad'], 'aktif' => (int)$d['aktif'], 'kullanicilar' => $oncekiKul], ['ad' => $ad, 'aktif' => $aktif, 'aktarilan_satir' => $aktarilan, 'kullanicilar' => $yeniKul]);
    return ['ok' => true, 'hata' => '', 'aktarilan' => $aktarilan];
}

/** Depoyu siler — YALNIZ hiçbir veri satırı o adı taşımıyorsa. Kullanımdaysa pasifleştirilir (hata mesajı yönlendirir). */
function depo_sil(PDO $pdo, int $id): array
{
    $d = depo_getir($pdo, $id);
    if (!$d) return ['ok' => false, 'hata' => 'Depo bulunamadı.'];
    $kul = depo_kullanim_toplam($pdo)[(string)$d['ad_anahtar']] ?? 0;
    if ($kul > 0) return ['ok' => false, 'hata' => "Bu depo $kul kayıtta kullanılıyor; silinemez. Pasif yaparak gizleyebilirsiniz."];
    try {
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM `depo_kullanicilar` WHERE depo_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM `depolar` WHERE id = ?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[depo_sil] ' . $e->getMessage());
        return ['ok' => false, 'hata' => 'Depo silinemedi.'];
    }
    depo_onbellek_sifirla();
    if (function_exists('audit_log_event')) audit_log_event('delete', 'depolar', $id, ['ad' => $d['ad'], 'kod' => $d['kod']], null);
    return ['ok' => true, 'hata' => ''];
}

// ── Geçiş (migrate.php) ──────────────────────────────────

/**
 * Geçişin ÖN KONTROLÜ. @return list<string> engel mesajları (boşsa geçiş yapılabilir).
 * Yeni depo adı, eski adlardan (veri taşıyan) biriyle aynıysa engel: eski satırlar yeni depoya bağlanır.
 */
function depo_gecis_engelleri(PDO $pdo): array
{
    $e = [];
    if (!depo_hazir($pdo)) return ['Depo tabloları kurulu değil.'];
    if (depo_gecis_aktif($pdo)) return ['Geçiş zaten tamamlanmış.'];
    $aktif = depo_aktif_adlar($pdo);
    if (!$aktif) $e[] = 'Önce en az bir AKTİF yeni depo tanımlayın (Yönetim › Depo Tanımları).';
    $kullanim = depo_kullanim_toplam($pdo);
    foreach (depo_hepsi($pdo) as $d) {
        $n = $kullanim[(string)$d['ad_anahtar']] ?? 0;
        if ($n > 0) $e[] = 'Yeni depo "' . $d['ad'] . '" adı eski verilerde ' . $n . ' satırda geçiyor → eski satırlar yeni depoya karışır. Depoyu farklı bir adla yeniden adlandırın (ya da silin).';
    }
    return $e;
}

/**
 * Geçişi uygular: bilinen TÜM eski adları deftere damgalar + 'gecis_tarihi' ayarını yazar. HİÇBİR veri satırı silinmez/değişmez.
 * @return array{ok:bool,mesaj:string,eski_ad:int}
 */
function depo_gecis_uygula(PDO $pdo): array
{
    $e = depo_gecis_engelleri($pdo);
    if ($e) return ['ok' => false, 'mesaj' => implode(' ', $e), 'eski_ad' => 0];
    try {
        $pdo->beginTransaction();
        $say = 0;
        $var = $pdo->prepare('SELECT 1 FROM `depo_eski_adlar` WHERE ad_anahtar = ?');
        $ins = $pdo->prepare('INSERT INTO `depo_eski_adlar` (ad_anahtar, ad, kaynak, created_at) VALUES (?, ?, ?, ?)');
        foreach (depo_eski_kaynak_adlari($pdo) as $a => [$ad, $kaynak]) {
            $var->execute([$a]);
            if ($var->fetchColumn()) continue;
            $ins->execute([$a, mb_substr($ad, 0, 150), mb_substr($kaynak, 0, 60), date('Y-m-d H:i:s')]);
            $say++;
        }
        $pdo->prepare('INSERT INTO `depo_ayar` (anahtar, deger, updated_at) VALUES (?, ?, ?)')->execute(['gecis_tarihi', date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        $pdo->commit();
    } catch (Throwable $e2) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[depo_gecis_uygula] ' . $e2->getMessage());
        return ['ok' => false, 'mesaj' => 'Geçiş uygulanamadı (ayrıntı sunucu günlüğünde).', 'eski_ad' => 0];
    }
    depo_onbellek_sifirla();
    if (function_exists('audit_log_event')) audit_log_event('migrate', 'depolar', null, null, ['operation' => 'gecis', 'eski_ad' => $say]);
    return ['ok' => true, 'mesaj' => "Geçiş tamamlandı; $say eski depo adı deftere işlendi. Eski veri silinmedi, gizlendi.", 'eski_ad' => $say];
}

/** Geçişi GERİ AL (yalnız ayar satırı kalkar; defter ve veri aynen kalır → eski davranışa dönülür). */
function depo_gecis_geri_al(PDO $pdo): bool
{
    try {
        $pdo->exec("DELETE FROM `depo_ayar` WHERE anahtar = 'gecis_tarihi'");
    } catch (PDOException $e) { return false; }
    depo_onbellek_sifirla();
    if (function_exists('audit_log_event')) audit_log_event('migrate', 'depolar', null, null, ['operation' => 'gecis_geri_al']);
    return true;
}

/**
 * Tablo grubu (aktarım ekranı ve rapor için): 'yedek' (pdks_yedek_* gibi yedek tabloları — raporda gizlenir, aktarılmaz),
 * 'serbest' (depo FİLTRESİ olmayan serbest metin: customs_declarations.exit_depot "NAR ÇIKIŞ: …" gibi — aktarılırsa bilgi kaybolur),
 * 'canli' (Sipariş / Frugro / Personel-PDKS / Puantaj / Ters kart — depoya göre FİLTRELENEN canlı modüller),
 * 'eski' (Yükleme/Mal Kabul/Stok/Hesap/Maliyet vb. eski modüller).
 */
function depo_tablo_grubu(string $t): string
{
    if (preg_match('/(^|_)yedek(_|$)/', $t)) return 'yedek';
    if ($t === 'customs_declarations') return 'serbest';
    if (preg_match('/^(siparis_|frugro_|urun_siparis|daily_|ters_|worker_|puantaj_|pdks_|employees$|foremen|attendance_|gunluk_)/', $t)) return 'canli';
    return 'eski';
}

/**
 * Tek eski adı TÜM (ya da seçili) veri kolonlarında yeni ada çeker; HER TABLO KENDİ İŞLEMİNDE (biri hata verirse diğerleri yine aktarılır).
 * Benzersiz anahtar çakışması (SQLSTATE 23000 — ör. daily_work_sessions UNIQUE(çavuş, gün, depo): hedef depoda aynı çavuş+gün kaydı zaten var)
 * olursa o tablo IGNORE ile yeniden denenir: çakışmayan satırlar taşınır, çakışanlar ESKİ adda KALIR ve 'atlanan' olarak sayılıp raporlanır.
 * @return array{satir:int,tablo:array<string,int>,atlanan:array<string,int>,hata:array<string,string>}
 */
function depo_ad_aktar_ayrintili(PDO $pdo, string $eskiAd, string $yeniAd, ?array $tablolar = null): array
{
    $out = ['satir' => 0, 'tablo' => [], 'atlanan' => [], 'hata' => []];
    $hedef = depo_anahtar($eskiAd);
    if ($hedef === '' || $yeniAd === '') return $out;
    $ignore = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'UPDATE OR IGNORE' : 'UPDATE IGNORE';
    foreach (depo_kolonlari($pdo) as [$t, $c]) {
        if ($tablolar !== null && !in_array($t, $tablolar, true)) continue;
        $anahtar = $t . '.' . $c;
        $dene = function (string $update) use ($pdo, $t, $c, $hedef, $yeniAd): array {
            $n = 0; $aday = 0;
            $pdo->beginTransaction();
            $vals = $pdo->query("SELECT DISTINCT `$c` FROM `$t` WHERE `$c` IS NOT NULL AND `$c` <> ''")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($vals as $v) {
                $v = (string)$v;
                if ($v === $yeniAd || depo_anahtar($v) !== $hedef) continue;
                $sc = $pdo->prepare("SELECT COUNT(*) FROM `$t` WHERE `$c` = ?"); $sc->execute([$v]); $aday += (int)$sc->fetchColumn();
                $st = $pdo->prepare("$update `$t` SET `$c` = ? WHERE `$c` = ?");
                $st->execute([$yeniAd, $v]);
                $n += $st->rowCount();
            }
            $pdo->commit();
            return [$n, $aday];
        };
        try {
            [$n, ] = $dene('UPDATE');
            if ($n > 0) { $out['tablo'][$anahtar] = $n; $out['satir'] += $n; }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $benzersiz = ($e instanceof PDOException) && (string)$e->getCode() === '23000';
            if (!$benzersiz) {
                error_log('[depo_ad_aktar_ayrintili] ' . $anahtar . ': ' . $e->getMessage());
                $out['hata'][$anahtar] = mb_substr((string)preg_replace('/\s+/', ' ', $e->getMessage()), 0, 160);
                continue;
            }
            try {   // çakışmayanları taşı, çakışanları eski adda bırak ve say
                [$n, $aday] = $dene($ignore);
                if ($n > 0) { $out['tablo'][$anahtar] = $n; $out['satir'] += $n; }
                if ($aday - $n > 0) $out['atlanan'][$anahtar] = $aday - $n;
            } catch (Throwable $e2) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[depo_ad_aktar_ayrintili/ignore] ' . $anahtar . ': ' . $e2->getMessage());
                $out['hata'][$anahtar] = mb_substr((string)preg_replace('/\s+/', ' ', $e2->getMessage()), 0, 160);
            }
        }
    }
    return $out;
}

/**
 * ESKİ depo adı/adlarındaki veriyi YENİ bir depoya aktarır (canlı modüllerde — Personel/Sipariş vb. — eski adla damgalı
 * satırlar gizlenmesin diye). $eskiAdlar tek ad ya da ad listesi; $tablolar = yalnız seçilen tablolar (null = yedek hariç hepsi).
 * Her tablo ayrı işlemdedir: bir tabloda hata olsa da diğerleri aktarılır, hatalar mesajda tablo.kolon olarak listelenir.
 * @return array{ok:bool,mesaj:string,satir:int,tablo:array<string,int>,atlanan:array<string,int>,hata:array<string,string>}
 */
function depo_eski_veriyi_aktar(PDO $pdo, string|array $eskiAdlar, int $yeniDepoId, ?array $tablolar = null): array
{
    $bos = ['tablo' => [], 'atlanan' => [], 'hata' => []];
    $d = depo_getir($pdo, $yeniDepoId);
    if (!$d || !(int)$d['aktif']) return ['ok' => false, 'mesaj' => 'Hedef depo geçerli ve aktif olmalı.', 'satir' => 0] + $bos;
    $adlar = array_values(array_unique(array_filter(array_map('strval', (array)$eskiAdlar), fn($a) => trim($a) !== '')));
    if (!$adlar) return ['ok' => false, 'mesaj' => 'Aktarılacak eski depo adı seçilmedi.', 'satir' => 0] + $bos;
    if (is_array($tablolar) && !$tablolar) return ['ok' => false, 'mesaj' => 'Aktarılacak tablo seçilmedi (en az bir tablo işaretleyin).', 'satir' => 0] + $bos;
    $eski = depo_eski_adlar($pdo);
    foreach ($adlar as $a) if (!isset($eski[depo_anahtar($a)])) return ['ok' => false, 'mesaj' => 'Kaynak ad eski depo listesinde değil: ' . $a, 'satir' => 0] + $bos;
    if ($tablolar === null) {   // varsayılan: yedek tabloları hariç hepsi
        $tablolar = [];
        foreach (depo_kolonlari($pdo) as [$t]) if (depo_tablo_grubu($t) !== 'yedek') $tablolar[$t] = $t;
        $tablolar = array_values($tablolar);
    }
    $toplam = 0; $tablo = []; $hata = []; $atlanan = [];
    foreach ($adlar as $a) {
        $r = depo_ad_aktar_ayrintili($pdo, $a, (string)$d['ad'], $tablolar);
        $toplam += $r['satir'];
        foreach ($r['tablo'] as $k => $n) $tablo[$k] = ($tablo[$k] ?? 0) + $n;
        foreach ($r['hata'] as $k => $m) $hata[$k] = $m;
        foreach ($r['atlanan'] as $k => $n) $atlanan[$k] = ($atlanan[$k] ?? 0) + $n;
    }
    depo_onbellek_sifirla();
    if (function_exists('audit_log_event')) audit_log_event('update', 'depolar', $yeniDepoId, null, ['operation' => 'eski_veri_aktar', 'eski_adlar' => $adlar, 'satir' => $toplam, 'tablolar' => $tablolar, 'hatali_tablolar' => array_keys($hata), 'atlanan' => $atlanan]);
    $mesaj = "$toplam satır \"{$d['ad']}\" deposuna aktarıldı";
    if ($tablo) $mesaj .= ' (' . implode(', ', array_map(fn($k, $n) => "$k: $n", array_keys($tablo), $tablo)) . ')';
    $mesaj .= '.';
    if ($toplam === 0 && !$hata && !$atlanan) $mesaj = 'Seçilen ad(lar) seçilen tablolarda satır bulunamadı; hiçbir satır aktarılmadı.';
    if ($atlanan) $mesaj .= ' ATLANAN (hedef depoda aynı anahtarlı kayıt zaten var, ESKİ adda kaldı): ' . implode(', ', array_map(fn($k, $n) => "$k: $n", array_keys($atlanan), $atlanan)) . '.';
    if ($hata) $mesaj .= ' HATA (aktarılmadı): ' . implode('; ', array_map(fn($k, $m) => "$k — $m", array_keys($hata), $hata));
    return ['ok' => !$hata && $toplam > 0, 'mesaj' => $mesaj, 'satir' => $toplam, 'tablo' => $tablo, 'atlanan' => $atlanan, 'hata' => $hata];
}
