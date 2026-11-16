<?php
// =========================================================
// scripts/kasa_ui_e2e_setup.php — scripts/kasa_ui_smoke.js için KALICI dosya SQLite hazırlar (canlı DB'ye HİÇ dokunmaz).
//   php scripts/kasa_ui_e2e_setup.php <db-yolu> [ornek|fisler|bos|sema|kasasiz]
//   ornek = cari + kasa şeması + 2 cari + 4 kasa (hareket YOK) · cok = ornek + Merkez Kasa'da 130 hareket (2 sayfa; sayfalama çubuğu testi) · fisler = ornek + örnek hareketler (gerçek kasa_hareket_ekle / kasa_virman_ekle / kasa_hareket_iptal) ·
//   bos = şema, veri yok (hiç kasa yok) · sema = tablo YOK (uyarı kartı senaryosu) · kasasiz = bos ile aynı
// =========================================================
$yol = (string)($argv[1] ?? '');
if ($yol === '') { fwrite(STDERR, "Kullanım: php scripts/kasa_ui_e2e_setup.php <db-yolu> [ornek|fisler|bos|sema]\n"); exit(2); }
putenv('KASA_E2E_DB=' . $yol);
require_once __DIR__ . '/_kasa_test_lib.php';
$mod = (string)($argv[2] ?? 'ornek');
if ($mod !== 'sema') {
    $db = db();
    kasa_t_sema_kur($db);
    if ($mod === 'ornek' || $mod === 'fisler' || $mod === 'cok') {
        $O = kasa_t_ornek_veri($db);
        if ($mod === 'cok') {
            $K = $O['kasa']; $C = $O['cari'];
            $r = kasa_hareket_ekle($db, kasa_t_hareket($K['Merkez Kasa'], 'acilis', '500.000,00', ['aciklama' => 'Açılış bakiyesi']), 1);
            if (empty($r['ok'])) throw new RuntimeException('cok: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
            for ($i = 0; $i < 129; $i++) {
                $r = kasa_hareket_ekle($db, kasa_t_hareket($K['Merkez Kasa'], $i % 2 ? 'cari_tahsilat' : 'cari_odeme', (string)(100 + $i) . ',25', ['cari_id' => (string)$C['ÖRNEK TİCARET A.Ş.'], 'aciklama' => 'Çok hareketli kasa satırı ' . $i, 'tarih' => date('Y-m-d', strtotime('-' . (130 - $i) . ' days'))]), 1);
                if (empty($r['ok'])) throw new RuntimeException('cok: ' . json_encode($r, JSON_UNESCAPED_UNICODE));
            }
        }
        if ($mod === 'fisler') {
            $K = $O['kasa']; $C = $O['cari'];
            $hep = static function (array $r) { if (empty($r['ok'])) throw new RuntimeException('örnek hareket: ' . json_encode($r, JSON_UNESCAPED_UNICODE)); return $r; };
            $hep(kasa_hareket_ekle($db, kasa_t_hareket($K['Merkez Kasa'], 'acilis', '50.000,00', ['aciklama' => 'Açılış bakiyesi']), 1));
            $hep(kasa_hareket_ekle($db, kasa_t_hareket($K['Örnek Banka TL'], 'acilis', '120.000,00', ['aciklama' => 'Açılış bakiyesi']), 1));
            $hep(kasa_hareket_ekle($db, kasa_t_hareket($K['Döviz Kasası'], 'acilis', '1.000,00', ['aciklama' => 'Açılış bakiyesi']), 1));
            $hep(kasa_hareket_ekle($db, kasa_t_hareket($K['Merkez Kasa'], 'cari_tahsilat', '1.500,00', ['cari_id' => (string)$C['ÖRNEK TİCARET A.Ş.'], 'aciklama' => 'Örnek tahsilat', 'evrak_no' => 'MKB-1']), 1));
            $kalem = static function (string $tur, string $ad) use ($db): int { foreach (kasa_tanim_listesi($db, $tur) as $t) if ($t['ad'] === $ad) return (int)$t['id']; return 0; };
            $hep(kasa_hareket_ekle($db, kasa_t_hareket($K['Merkez Kasa'], 'gider', '250,00', ['kalem_id' => (string)$kalem('gider', 'Yakıt'), 'aciklama' => 'Araç yakıtı']), 1));
            $hep(kasa_hareket_ekle($db, kasa_t_hareket($K['Merkez Kasa'], 'gelir', '75,50', ['kalem_id' => (string)$kalem('gelir', 'Faiz Geliri')]), 1));
            $hep(kasa_virman_ekle($db, ['kaynak_kasa_id' => (string)$K['Merkez Kasa'], 'hedef_kasa_id' => (string)$K['Örnek Banka TL'], 'tarih' => date('Y-m-d'), 'tutar' => '5.000,00', 'aciklama' => 'Bankaya yatırma'], 1));
            $x = $hep(kasa_hareket_ekle($db, kasa_t_hareket($K['Merkez Kasa'], 'gider', '10,00', ['kalem_id' => (string)$kalem('gider', 'Kırtasiye'), 'aciklama' => 'Hatalı giriş']), 1));
            $hep(kasa_hareket_iptal($db, (int)$x['id'], 'Hatalı giriş iptali', 1));
            $hep(kasa_hareket_ekle($db, kasa_t_hareket($K['Merkez Kasa'], 'cari_tahsilat', '35.000,00', ['cari_id' => (string)$C['ÖRNEK TEDARİK LTD.'], 'aciklama' => 'Büyük nakit tahsilat']), 1));
        }
    }
}
echo "hazir $mod\n";
