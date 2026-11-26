<?php
// =========================================================
// config/yukleme_v2_core.php — Yeni Araç Yükleme Fişleri (Temiz Sıfırdan Tasarım)
//
// Eski records.php'den alınan değerli iş mantığı:
//   - Palet bazlı dara & net hesaplama: dara = (kasa_adeti * kasa_dara_kg) + palet_dara_kg + ek_dara_kg; net = max(0, brut - dara)
//   - Kilitli durum ('yuklendi' / 'sevk_edildi') ve gerekçeli revizyon açma (revizyon_notu)
// Eski hantallıkları ortadan kaldıran yeni mimari:
//   - Sıfır runtime DDL · Tek db_tx() transaction · DELETE+INSERT yerine pallet_id bazlı satır güncelleme
//   - Doğrudan WMS Lot (FIFO mamul + ambalaj sarfı) ve Sipariş/İrsaliye kaynak bağı
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/wms_stok_core.php';

/** @return list<string> */
function yukleme_v2_tablolar(): array
{
    return ['yukleme_v2_fisler', 'yukleme_v2_paletler'];
}

/** @return list<string> */
function yukleme_v2_eksik_tablolar(PDO $pdo): array
{
    $e = [];
    foreach (yukleme_v2_tablolar() as $t) {
        if (!db_table_exists($pdo, $t)) $e[] = $t;
    }
    return $e;
}

function yukleme_v2_hazir(PDO $pdo): bool
{
    return yukleme_v2_eksik_tablolar($pdo) === [];
}

function yukleme_v2_sayfa_kapisi(PDO $pdo, bool $json = false): void
{
    $eksik = yukleme_v2_eksik_tablolar($pdo);
    if ($eksik === []) return;
    if ($json) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'hata' => 'Yeni Araç Yükleme tabloları kurulmamış (' . implode(', ', $eksik) . '). migrate.php#yukleme-v2 kartını çalıştırın.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('render_header') && function_exists('erp_op_sema_uyari')) {
        render_header('Yeni Araç Yükleme Fişleri');
        echo erp_op_sema_uyari($eksik, 'yukleme-v2', 'Yeni Araç Yükleme Fişleri (v2)');
        if (function_exists('render_footer')) render_footer();
        exit;
    }
    http_response_code(503);
    exit('Yeni Araç Yükleme şeması kurulmamış.');
}

/** @return list<array{tablo:string,durum:string,mesaj:string}> */
function yukleme_v2_migrate(PDO $pdo): array
{
    $sqlite = db_is_sqlite($pdo);
    $ai  = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
    $eng = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $ddl = [
        'yukleme_v2_fisler' => "CREATE TABLE IF NOT EXISTS `yukleme_v2_fisler` (
            `id` $ai,
            `fis_no` VARCHAR(40) NOT NULL UNIQUE,
            `tarih` DATE NOT NULL,
            `depo` VARCHAR(120) NOT NULL DEFAULT 'MERKEZ',
            `plaka` VARCHAR(40) NOT NULL DEFAULT '',
            `dorse_plaka` VARCHAR(40) NOT NULL DEFAULT '',
            `sofor_adi` VARCHAR(120) NOT NULL DEFAULT '',
            `sofor_tel` VARCHAR(40) NOT NULL DEFAULT '',
            `cari_id` INT NULL,
            `firma_unvan` VARCHAR(200) NOT NULL DEFAULT '',
            `gidecek_ulke` VARCHAR(100) NOT NULL DEFAULT '',
            `tasima_sekli` VARCHAR(40) NOT NULL DEFAULT 'kara',
            `durum` VARCHAR(20) NOT NULL DEFAULT 'taslak',
            `toplam_palet` INT NOT NULL DEFAULT 0,
            `toplam_kasa` INT NOT NULL DEFAULT 0,
            `toplam_brut_kg` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `toplam_dara_kg` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `toplam_net_kg` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `siparis_modul` VARCHAR(40) NULL,
            `siparis_id` INT NULL,
            `irsaliye_no` VARCHAR(60) NULL,
            `wms_dusuldu` TINYINT NOT NULL DEFAULT 0,
            `revizyon_sayisi` INT NOT NULL DEFAULT 0,
            `revizyon_notu` VARCHAR(400) NOT NULL DEFAULT '',
            `kilitli_at` DATETIME NULL,
            `olusturan_id` INT NULL,
            `olusturma_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `guncelleme_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        )$eng",
        'yukleme_v2_paletler' => "CREATE TABLE IF NOT EXISTS `yukleme_v2_paletler` (
            `id` $ai,
            `fis_id` INT NOT NULL,
            `sira_no` INT NOT NULL DEFAULT 1,
            `palet_no` VARCHAR(40) NOT NULL DEFAULT '',
            `urun_stok_id` INT NULL,
            `urun_ad` VARCHAR(180) NOT NULL DEFAULT '',
            `lot_no` VARCHAR(60) NOT NULL DEFAULT '',
            `kalibre` VARCHAR(60) NOT NULL DEFAULT '',
            `kasa_stok_id` INT NULL,
            `kasa_adeti` INT NOT NULL DEFAULT 0,
            `kasa_dara_kg` DECIMAL(10,4) NOT NULL DEFAULT 0.0000,
            `palet_tipi` VARCHAR(60) NOT NULL DEFAULT 'EURO',
            `palet_dara_kg` DECIMAL(10,3) NOT NULL DEFAULT 22.000,
            `ek_dara_kg` DECIMAL(10,3) NOT NULL DEFAULT 0.000,
            `brut_kg` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `dara_kg` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `net_kg` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
            `raf_id` INT NULL,
            `notlar` VARCHAR(300) NOT NULL DEFAULT ''
        )$eng",
    ];

    $rapor = [];
    foreach ($ddl as $t => $sql) {
        $var = db_table_exists($pdo, $t);
        $pdo->exec($sql);
        $rapor[] = [
            'tablo' => $t,
            'durum' => $var ? 'var' : 'olusturuldu',
            'mesaj' => $var ? 'Tablo zaten mevcut.' : 'Tablo oluşturuldu.',
        ];
    }

    foreach ([
        ['yukleme_v2_fisler', 'idx_yv2_tarih_durum', 'CREATE INDEX idx_yv2_tarih_durum ON `yukleme_v2_fisler` (`tarih`, `durum`)'],
        ['yukleme_v2_paletler', 'idx_yv2_palet_fis', 'CREATE INDEX idx_yv2_palet_fis ON `yukleme_v2_paletler` (`fis_id`, `sira_no`)'],
    ] as [$tbl, $ix, $sql]) {
        try {
            if ($sqlite) {
                $pdo->exec(str_replace('CREATE INDEX ', 'CREATE INDEX IF NOT EXISTS ', $sql));
            } else {
                $st = $pdo->prepare('SHOW INDEX FROM `' . $tbl . '` WHERE Key_name = ?');
                $st->execute([$ix]);
                if (!$st->fetch()) $pdo->exec($sql);
            }
        } catch (PDOException $e) {}
    }

    return $rapor;
}

/** @return array{dara_kg:float,net_kg:float} */
function yukleme_v2_palet_hesapla(float $brutKg, int $kasaAdeti, float $kasaDaraKg, float $paletDaraKg, float $ekDaraKg = 0.0): array
{
    $dara = round(($kasaAdeti * max(0.0, $kasaDaraKg)) + max(0.0, $paletDaraKg) + max(0.0, $ekDaraKg), 3);
    $net  = round(max(0.0, $brutKg - $dara), 3);
    return ['dara_kg' => $dara, 'net_kg' => $net];
}

/**
 * Yeni Araç Yükleme Fişi oluşturur veya taslak fişi günceller (paletler pallet_id bazlı eklenir/güncellenir, kör DELETE+INSERT yok).
 * @return array{ok:bool,id?:int,fis_no?:string,hata?:string,mesaj?:string,kod?:int}
 */
function yukleme_v2_fis_kaydet(PDO $pdo, array $g, int $uid): array
{
    $id     = (int)($g['id'] ?? 0);
    $tarih  = trim((string)($g['tarih'] ?? date('Y-m-d')));
    $depo   = mb_strtoupper(mb_substr(trim((string)($g['depo'] ?? 'MERKEZ')), 0, 120, 'UTF-8'), 'UTF-8');
    if ($depo === '') $depo = 'MERKEZ';
    $plaka  = mb_strtoupper(mb_substr(trim((string)($g['plaka'] ?? '')), 0, 40, 'UTF-8'), 'UTF-8');
    $dorse  = mb_strtoupper(mb_substr(trim((string)($g['dorse_plaka'] ?? '')), 0, 40, 'UTF-8'), 'UTF-8');
    $sofor  = mb_substr(trim((string)($g['sofor_adi'] ?? '')), 0, 120, 'UTF-8');
    $tel    = mb_substr(trim((string)($g['sofor_tel'] ?? '')), 0, 40, 'UTF-8');
    $cariId = isset($g['cari_id']) && (int)$g['cari_id'] > 0 ? (int)$g['cari_id'] : null;
    $firma  = mb_substr(trim((string)($g['firma_unvan'] ?? '')), 0, 200, 'UTF-8');
    $ulke   = mb_substr(trim((string)($g['gidecek_ulke'] ?? '')), 0, 100, 'UTF-8');
    $tasima = trim((string)($g['tasima_sekli'] ?? 'kara'));
    if (!in_array($tasima, ['kara', 'deniz', 'hava', 'intermodal'], true)) $tasima = 'kara';
    $sipMod = isset($g['siparis_modul']) && trim((string)$g['siparis_modul']) !== '' ? mb_substr(trim((string)$g['siparis_modul']), 0, 40, 'UTF-8') : null;
    $sipId  = isset($g['siparis_id']) && (int)$g['siparis_id'] > 0 ? (int)$g['siparis_id'] : null;
    $irsNo  = isset($g['irsaliye_no']) && trim((string)$g['irsaliye_no']) !== '' ? mb_substr(trim((string)$g['irsaliye_no']), 0, 60, 'UTF-8') : null;

    if (!erp_op_tarih_gecerli($tarih)) return ['ok' => false, 'hata' => 'Geçersiz yükleme tarihi.', 'kod' => 422];
    if ($plaka === '' && $firma === '') return ['ok' => false, 'hata' => 'En az plaka veya firma ünvanı girilmelidir.', 'kod' => 422];

    $paletler = is_array($g['paletler'] ?? null) ? $g['paletler'] : [];

    return db_tx($pdo, static function (PDO $p) use (
        $id, $tarih, $depo, $plaka, $dorse, $sofor, $tel, $cariId, $firma, $ulke, $tasima, $sipMod, $sipId, $irsNo, $paletler, $uid
    ): array {
        if ($id > 0) {
            $stE = $p->prepare('SELECT * FROM `yukleme_v2_fisler` WHERE id = ?' . db_for_update($p));
            $stE->execute([$id]);
            $mevcut = $stE->fetch(PDO::FETCH_ASSOC);
            if (!$mevcut) return ['ok' => false, 'hata' => 'Yükleme fişi bulunamadı.', 'kod' => 404];
            if ((string)$mevcut['durum'] !== 'taslak') {
                return ['ok' => false, 'hata' => 'Kilitli fiş doğrudan düzenlenemez; önce gerekçeli revizyon açın.', 'kod' => 409];
            }
            $fisNo = (string)$mevcut['fis_no'];
            $p->prepare(
                'UPDATE `yukleme_v2_fisler` SET tarih=?, depo=?, plaka=?, dorse_plaka=?, sofor_adi=?, sofor_tel=?, cari_id=?, firma_unvan=?, gidecek_ulke=?, tasima_sekli=?, siparis_modul=?, siparis_id=?, irsaliye_no=?, guncelleme_at=? WHERE id=?'
            )->execute([$tarih, $depo, $plaka, $dorse, $sofor, $tel, $cariId, $firma, $ulke, $tasima, $sipMod, $sipId, $irsNo, date('Y-m-d H:i:s'), $id]);
            $fisId = $id;
        } else {
            $yil = substr($tarih, 0, 4);
            $stNo = $p->prepare('SELECT COUNT(*) FROM `yukleme_v2_fisler` WHERE fis_no LIKE ?');
            $stNo->execute(["YUK-$yil-%"]);
            $sira = (int)$stNo->fetchColumn() + 1;
            $fisNo = sprintf('YUK-%s-%05d', $yil, $sira);
            while (true) {
                $chk = $p->prepare('SELECT 1 FROM `yukleme_v2_fisler` WHERE fis_no = ?');
                $chk->execute([$fisNo]);
                if (!$chk->fetchColumn()) break;
                $sira++;
                $fisNo = sprintf('YUK-%s-%05d', $yil, $sira);
            }
            $p->prepare(
                "INSERT INTO `yukleme_v2_fisler`
                 (fis_no, tarih, depo, plaka, dorse_plaka, sofor_adi, sofor_tel, cari_id, firma_unvan, gidecek_ulke, tasima_sekli, durum, siparis_modul, siparis_id, irsaliye_no, olusturan_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'taslak', ?, ?, ?, ?)"
            )->execute([$fisNo, $tarih, $depo, $plaka, $dorse, $sofor, $tel, $cariId, $firma, $ulke, $tasima, $sipMod, $sipId, $irsNo, $uid ?: null]);
            $fisId = (int)$p->lastInsertId();
        }

        // Satır bazlı (pallet_id) senkronizasyon: mevcut satırları güncelle, yenileri ekle, yalnızca çıkarılanları sil
        if ($paletler !== []) {
            $mevcutIds = $p->prepare('SELECT id FROM `yukleme_v2_paletler` WHERE fis_id = ?');
            $mevcutIds->execute([$fisId]);
            $eskiSet = array_flip(array_map('intval', $mevcutIds->fetchAll(PDO::FETCH_COLUMN)));
            $tutulan = [];

            $siraNo = 0;
            foreach ($paletler as $row) {
                $siraNo++;
                $pid       = (int)($row['id'] ?? 0);
                $paletNo   = mb_substr(trim((string)($row['palet_no'] ?? ('P-' . $siraNo))), 0, 40, 'UTF-8');
                $urunId    = isset($row['urun_stok_id']) && (int)$row['urun_stok_id'] > 0 ? (int)$row['urun_stok_id'] : null;
                $urunAd    = mb_substr(trim((string)($row['urun_ad'] ?? '')), 0, 180, 'UTF-8');
                $lotNo     = mb_substr(trim((string)($row['lot_no'] ?? '')), 0, 60, 'UTF-8');
                $kalibre   = mb_substr(trim((string)($row['kalibre'] ?? '')), 0, 60, 'UTF-8');
                $kasaId    = isset($row['kasa_stok_id']) && (int)$row['kasa_stok_id'] > 0 ? (int)$row['kasa_stok_id'] : null;
                $kasaAdet  = max(0, (int)($row['kasa_adeti'] ?? 0));
                $kasaDara  = max(0.0, erp_op_sayi($row['kasa_dara_kg'] ?? 0));
                $paletTip  = mb_substr(trim((string)($row['palet_tipi'] ?? 'EURO')), 0, 60, 'UTF-8');
                $paletDara = max(0.0, erp_op_sayi($row['palet_dara_kg'] ?? 22.0));
                $ekDara    = max(0.0, erp_op_sayi($row['ek_dara_kg'] ?? 0));
                $brutKg    = max(0.0, round(erp_op_sayi($row['brut_kg'] ?? 0), 3));
                $rafId     = isset($row['raf_id']) && (int)$row['raf_id'] > 0 ? (int)$row['raf_id'] : null;
                $notlar    = mb_substr(trim((string)($row['notlar'] ?? '')), 0, 300, 'UTF-8');

                $hesap = yukleme_v2_palet_hesapla($brutKg, $kasaAdet, $kasaDara, $paletDara, $ekDara);
                if ($pid > 0 && isset($eskiSet[$pid])) {
                    $p->prepare(
                        'UPDATE `yukleme_v2_paletler` SET sira_no=?, palet_no=?, urun_stok_id=?, urun_ad=?, lot_no=?, kalibre=?, kasa_stok_id=?, kasa_adeti=?, kasa_dara_kg=?, palet_tipi=?, palet_dara_kg=?, ek_dara_kg=?, brut_kg=?, dara_kg=?, net_kg=?, raf_id=?, notlar=? WHERE id=? AND fis_id=?'
                    )->execute([$siraNo, $paletNo, $urunId, $urunAd, $lotNo, $kalibre, $kasaId, $kasaAdet, $kasaDara, $paletTip, $paletDara, $ekDara, $brutKg, $hesap['dara_kg'], $hesap['net_kg'], $rafId, $notlar, $pid, $fisId]);
                    $tutulan[$pid] = true;
                } else {
                    $p->prepare(
                        'INSERT INTO `yukleme_v2_paletler` (fis_id, sira_no, palet_no, urun_stok_id, urun_ad, lot_no, kalibre, kasa_stok_id, kasa_adeti, kasa_dara_kg, palet_tipi, palet_dara_kg, ek_dara_kg, brut_kg, dara_kg, net_kg, raf_id, notlar)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    )->execute([$fisId, $siraNo, $paletNo, $urunId, $urunAd, $lotNo, $kalibre, $kasaId, $kasaAdet, $kasaDara, $paletTip, $paletDara, $ekDara, $brutKg, $hesap['dara_kg'], $hesap['net_kg'], $rafId, $notlar]);
                    $tutulan[(int)$p->lastInsertId()] = true;
                }
            }
            foreach (array_keys($eskiSet) as $oldPid) {
                if (!isset($tutulan[$oldPid])) {
                    $p->prepare('DELETE FROM `yukleme_v2_paletler` WHERE id = ? AND fis_id = ?')->execute([$oldPid, $fisId]);
                }
            }
        }

        // Başlık toplamlarını tek sorguda tazele
        $stT = $p->prepare('SELECT COUNT(*) AS p_say, COALESCE(SUM(kasa_adeti),0) AS k_say, COALESCE(SUM(brut_kg),0) AS b, COALESCE(SUM(dara_kg),0) AS d, COALESCE(SUM(net_kg),0) AS n FROM `yukleme_v2_paletler` WHERE fis_id = ?');
        $stT->execute([$fisId]);
        $tot = $stT->fetch(PDO::FETCH_ASSOC);
        $p->prepare('UPDATE `yukleme_v2_fisler` SET toplam_palet=?, toplam_kasa=?, toplam_brut_kg=?, toplam_dara_kg=?, toplam_net_kg=?, guncelleme_at=? WHERE id=?')
            ->execute([(int)$tot['p_say'], (int)$tot['k_say'], round((float)$tot['b'], 3), round((float)$tot['d'], 3), round((float)$tot['n'], 3), date('Y-m-d H:i:s'), $fisId]);

        if (function_exists('audit_log_event')) {
            audit_log_event($id > 0 ? 'update' : 'create', 'yukleme_v2', $fisId, null, ['fis_no' => $fisNo, 'palet' => (int)$tot['p_say'], 'net_kg' => round((float)$tot['n'], 3)]);
        }
        return ['ok' => true, 'id' => $fisId, 'fis_no' => $fisNo, 'mesaj' => "Yükleme fişi kaydedildi ($fisNo)."];
    });
}

/**
 * Yükleme fişini kilitler ('yuklendi') ve opsiyonel olarak WMS Lot (FIFO mamul + ambalaj) stok düşümünü gerçekleştirir.
 * @return array{ok:bool,hata?:string,mesaj?:string,kod?:int}
 */
function yukleme_v2_kilitle_ve_sevk(PDO $pdo, int $fisId, int $uid, bool $wmsDus = true): array
{
    return db_tx($pdo, static function (PDO $p) use ($fisId, $uid, $wmsDus): array {
        $st = $p->prepare('SELECT * FROM `yukleme_v2_fisler` WHERE id = ?' . db_for_update($p));
        $st->execute([$fisId]);
        $fis = $st->fetch(PDO::FETCH_ASSOC);
        if (!$fis) return ['ok' => false, 'hata' => 'Yükleme fişi bulunamadı.', 'kod' => 404];
        if ((string)$fis['durum'] !== 'taslak') return ['ok' => false, 'hata' => 'Yalnız taslak fiş kilitlenebilir.', 'kod' => 409];

        $stP = $p->prepare('SELECT * FROM `yukleme_v2_paletler` WHERE fis_id = ? ORDER BY sira_no ASC');
        $stP->execute([$fisId]);
        $paletler = $stP->fetchAll(PDO::FETCH_ASSOC);
        if ($paletler === []) return ['ok' => false, 'hata' => 'Palet satırı olmayan fiş kilitlenemez.', 'kod' => 422];

        $wmsYapildi = (int)$fis['wms_dusuldu'];
        if ($wmsDus && $wmsYapildi === 0 && wms_stok_hazir($p)) {
            $urunToplam = [];
            $kasaToplam = [];
            foreach ($paletler as $pl) {
                if (!empty($pl['urun_stok_id']) && (float)$pl['net_kg'] > 0) {
                    $sid = (int)$pl['urun_stok_id'];
                    $urunToplam[$sid] = round(($urunToplam[$sid] ?? 0.0) + (float)$pl['net_kg'], 3);
                }
                if (!empty($pl['kasa_stok_id']) && (int)$pl['kasa_adeti'] > 0) {
                    $kid = (int)$pl['kasa_stok_id'];
                    $kasaToplam[$kid] = round(($kasaToplam[$kid] ?? 0.0) + (int)$pl['kasa_adeti'], 3);
                }
            }
            foreach ($urunToplam as $sid => $kg) {
                $t = wms_lot_tuket_fifo($p, $sid, $kg, (string)$fis['depo'], false);
                if (empty($t['ok'])) return $t;
            }
            foreach ($kasaToplam as $kid => $adet) {
                $t = wms_lot_tuket_fifo($p, $kid, $adet, (string)$fis['depo'], true);
                if (empty($t['ok'])) return $t;
            }
            $wmsYapildi = 1;
        }

        $p->prepare("UPDATE `yukleme_v2_fisler` SET durum='yuklendi', wms_dusuldu=?, kilitli_at=?, guncelleme_at=? WHERE id=?")
            ->execute([$wmsYapildi, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $fisId]);
        if (function_exists('audit_log_event')) {
            audit_log_event('lock', 'yukleme_v2', $fisId, ['durum' => 'taslak'], ['durum' => 'yuklendi', 'wms_dusuldu' => $wmsYapildi, 'uid' => $uid]);
        }
        return ['ok' => true, 'mesaj' => $fis['fis_no'] . ' kilitlendi ve sevke hazırlandı.'];
    });
}

/**
 * Kilitli yükleme fişini gerekçeli revizyon için tekrar taslağa açar.
 * @return array{ok:bool,hata?:string,mesaj?:string,kod?:int}
 */
function yukleme_v2_revizyon_ac(PDO $pdo, int $fisId, string $neden, int $uid): array
{
    $neden = mb_substr(trim($neden), 0, 400, 'UTF-8');
    if (mb_strlen($neden, 'UTF-8') < 5) return ['ok' => false, 'hata' => 'Revizyon gerekçesi en az 5 karakter olmalıdır.', 'kod' => 422];

    return db_tx($pdo, static function (PDO $p) use ($fisId, $neden, $uid): array {
        $st = $p->prepare('SELECT * FROM `yukleme_v2_fisler` WHERE id = ?' . db_for_update($p));
        $st->execute([$fisId]);
        $fis = $st->fetch(PDO::FETCH_ASSOC);
        if (!$fis) return ['ok' => false, 'hata' => 'Yükleme fişi bulunamadı.', 'kod' => 404];
        if ((string)$fis['durum'] !== 'yuklendi') return ['ok' => false, 'hata' => 'Yalnız kilitli (yuklendi) fiş revizyona açılabilir.', 'kod' => 409];

        $rev = (int)$fis['revizyon_sayisi'] + 1;
        $p->prepare("UPDATE `yukleme_v2_fisler` SET durum='taslak', revizyon_sayisi=?, revizyon_notu=?, guncelleme_at=? WHERE id=?")
            ->execute([$rev, $neden, date('Y-m-d H:i:s'), $fisId]);
        if (function_exists('audit_log_event')) {
            audit_log_event('reopen', 'yukleme_v2', $fisId, ['durum' => 'yuklendi'], ['durum' => 'taslak', 'revizyon' => $rev, 'neden' => $neden, 'uid' => $uid]);
        }
        return ['ok' => true, 'mesaj' => $fis['fis_no'] . " revizyona açıldı (#$rev)."];
    });
}

/** @return list<array<string,mixed>> */
function yukleme_v2_fis_listesi(PDO $pdo, array $filtre = []): array
{
    $w = ['1=1']; $p = [];
    if (!empty($filtre['durum'])) { $w[] = 'durum = ?'; $p[] = (string)$filtre['durum']; }
    if (!empty($filtre['bas']) && erp_op_tarih_gecerli((string)$filtre['bas'])) { $w[] = 'tarih >= ?'; $p[] = (string)$filtre['bas']; }
    if (!empty($filtre['bit']) && erp_op_tarih_gecerli((string)$filtre['bit'])) { $w[] = 'tarih <= ?'; $p[] = (string)$filtre['bit']; }
    $st = $pdo->prepare('SELECT * FROM `yukleme_v2_fisler` WHERE ' . implode(' AND ', $w) . ' ORDER BY tarih DESC, id DESC LIMIT 200');
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** @return ?array<string,mixed> */
function yukleme_v2_fis_getir(PDO $pdo, int $id): ?array
{
    if ($id <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM `yukleme_v2_fisler` WHERE id = ?');
    $st->execute([$id]);
    $fis = $st->fetch(PDO::FETCH_ASSOC);
    if (!$fis) return null;
    $stP = $pdo->prepare('SELECT * FROM `yukleme_v2_paletler` WHERE fis_id = ? ORDER BY sira_no ASC, id ASC');
    $stP->execute([$id]);
    $fis['paletler'] = $stP->fetchAll(PDO::FETCH_ASSOC);
    return $fis;
}
