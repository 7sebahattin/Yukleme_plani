<?php
// =========================================================
// scripts/pdks_gunluk_faz8a_static_smoke.php — Günlük İşçi Faz 8A
// kaynak-kodu kuralları (statik).
//
// SADECE CLI. Ağ/DB yok — kaynak kodda regex ile kural arar (repodaki
// diğer pdks_*_static_smoke.php dosyalarıyla AYNI desen).
//
//   php scripts/pdks_gunluk_faz8a_static_smoke.php   → çıkış kodu 0 = geçti
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

$KOK = dirname(__DIR__);
$fail = 0; $gecen = 0;
function ok(string $ad, bool $c, string $ipucu = ''): void {
    global $fail, $gecen;
    $c ? $gecen++ : $fail++;
    printf("%-95s %s%s\n", $ad, $c ? 'OK' : '*** HATA', $c ? '' : "\n    → " . $ipucu);
}
function oku(string $p): string { global $KOK; return (string)@file_get_contents($KOK . '/' . $p); }

$gunlukSrc = oku('config/pdks_gunluk.php');
$hakedisSrc = oku('config/pdks_hakedis.php');
$giSrc = oku('gunluk_isci_giris_cikis.php');
$migrateSrc = oku('migrate.php');

echo "\n=== 1. SÖZ DİZİMİ ===\n";
foreach (['config/pdks_gunluk.php', 'config/pdks_hakedis.php', 'gunluk_isci_giris_cikis.php',
          'isci_kartlari.php', 'migrate.php', 'config/pdks_rapor.php', 'raporlar.php',
          'gunluk_isci_puantaj_detay.php', 'gunluk_puantaj_yazdir.php'] as $f) {
    $cikti = []; $rc = 0;
    exec('php -l ' . escapeshellarg($KOK . '/' . $f) . ' 2>&1', $cikti, $rc);
    ok("$f: php -l geçiyor", $rc === 0, implode("\n", $cikti));
}

echo "\n=== 2. WEB NFC / USB YAŞAM DÖNGÜSÜ — MUTLAK REGRESYON KURALI (görev talimatı §13) ===\n";
ok('config/pdks.php: pdks_nfc_oku_js() / PdksNfcOku hiç değişmedi (diff BOŞ)',
    trim((string)shell_exec('cd ' . escapeshellarg($KOK) . ' && git diff --stat -- config/pdks.php 2>&1')) === '');
ok('gunluk_isci_giris_cikis.php: GERÇEK KOD YENİ bir AbortController KULLANMIYOR (yorumlarda "AbortController eklenmedi" AÇIKLAMASI GEÇEBİLİR)',
    !preg_match('/new\s+AbortController|\.abort\(/', $giSrc));
ok('gunluk_isci_giris_cikis.php: kart başına scan() YENİDEN BAŞLATILMIYOR (ndef.scan() yalnız PdksNfcOku.baslat() İÇİNDE, o dosyada TEK yerde)',
    substr_count($giSrc, '.scan(') === 0);
ok('gunluk_isci_giris_cikis.php: hâlâ PAYLAŞILAN PdksNfcOku.baslat()\'ı kullanıyor (kendi NDEFReader\'ını AÇMIYOR)',
    str_contains($giSrc, 'PdksNfcOku.baslat(') && !str_contains($giSrc, 'new NDEFReader'));
ok('gunluk_isci_giris_cikis.php: pdks_nfc_oku_js() paylaşılan yardımcıyı REUSE ediyor',
    str_contains($giSrc, 'pdks_nfc_oku_js()'));
ok('USB VE Web NFC AYNI sunucu fonksiyonlarına gidiyor — kaydet() gövdesinde TEK fetch hedefi (ajax=kaydet), kaynak yalnız bir PARAMETRE',
    substr_count($giSrc, "ajax=kaydet") === 1);

echo "\n=== 3. GİRİŞ/ÇIKIŞ — TEK YAZMA YOLU, PARALEL BİR DOĞRULUK KAYNAĞI YOK (görev talimatı §5/§14) ===\n";
ok('pdks_gunluk_faz8a_giris_kaydet() tanımlı', (bool)preg_match('/function pdks_gunluk_faz8a_giris_kaydet\(/', $gunlukSrc));
ok('pdks_gunluk_faz8a_cikis_kaydet() tanımlı', (bool)preg_match('/function pdks_gunluk_faz8a_cikis_kaydet\(/', $gunlukSrc));
ok('gunluk_isci_giris_cikis.php ajax=kaydet dalı ŞEMA HAZIRSA pdks_gunluk_faz8a_giris_kaydet()/cikis_kaydet() çağırıyor',
    str_contains($giSrc, 'pdks_gunluk_faz8a_giris_kaydet(') && str_contains($giSrc, 'pdks_gunluk_faz8a_cikis_kaydet('));
ok('GİRİŞ fonksiyonu TEK transaction içinde: beginTransaction ... commit (aynı fonksiyon gövdesinde)',
    (bool)preg_match('/function pdks_gunluk_faz8a_giris_kaydet.*?beginTransaction\(\).*?commit\(\).*?\n\}/s', $gunlukSrc));
ok('ÇIKIŞ fonksiyonu TEK transaction içinde: beginTransaction ... commit (aynı fonksiyon gövdesinde)',
    (bool)preg_match('/function pdks_gunluk_faz8a_cikis_kaydet.*?beginTransaction\(\).*?commit\(\).*?\n\}/s', $gunlukSrc));
ok('GİRİŞ dalı hata kodları tanımlı: mukerrer_giris / baska_cavusta_acik',
    str_contains($gunlukSrc, "'mukerrer_giris'") && str_contains($gunlukSrc, "'baska_cavusta_acik'"));
ok('ÇIKIŞ dalı hata kodları tanımlı: acik_donem_yok / yanlis_cavus',
    str_contains($gunlukSrc, "'acik_donem_yok'") && str_contains($gunlukSrc, "'yanlis_cavus'"));
ok('YANLIŞ ÇAVUŞ ret mesajı iç ID (session_id/worker_card_id sayısal) SIZDIRMIYOR — yalnız çavuş adı metni',
    (bool)preg_match("/'hata' => 'Bu kart ' \. \\\$foremanAdi \. ' mesaisinde açık görünüyor\.'/", $gunlukSrc));

echo "\n=== 4. NÖTR KART (görev talimatı §7) ===\n";
ok('worker_cards.worker_type_id base DDL\'de NULL DEFAULT NULL', (bool)preg_match('/`worker_type_id`\s+INT\s+NULL DEFAULT NULL/', $gunlukSrc));
ok('pdks_gunluk_faz8a_migrate() worker_cards.worker_type_id için MODIFY COLUMN ... NULL içeriyor (üretim geçişi)',
    str_contains($gunlukSrc, 'MODIFY COLUMN `worker_type_id` INT NULL'));
ok('isci_kartlari.php: kart oluşturma formunda ARTIK zorunlu bir worker_type_id SEÇİCİSİ YOK (name="worker_type_id" create formunda YOK)',
    !preg_match('/name="action" value="kart_ekle">.*?name="worker_type_id".*?KARTI HAVUZA EKLE/s', oku('isci_kartlari.php')));
ok('isci_kartlari.php: liste sorgusu LEFT JOIN kullanıyor (nötr kartlar listeden düşmüyor)',
    (bool)preg_match('/LEFT JOIN worker_types/', oku('isci_kartlari.php')));

echo "\n=== 5. ESKİ AYNI-GÜN KISITININ KALDIRILMASI (görev talimatı §8) — TEK YERDE, TUTARLI ===\n";
ok('uq_dwce_card_day_depo_type base DDL\'de ARTIK YOK', !str_contains($gunlukSrc, "UNIQUE KEY `uq_dwce_card_day_depo_type`"));
ok('pdks_gunluk_faz8a_migrate() DROP INDEX ile ÜRETİMDEKİ eski kısıtı kaldırıyor',
    str_contains($gunlukSrc, 'DROP INDEX `uq_dwce_card_day_depo_type`'));
ok('pdks_gunluk_kart_gun_kullanimi() (LEGACY ön-kontrol) hâlâ TANIMLI — yalnız şema hazır DEĞİLKEN çağrılıyor, SİLİNMEDİ',
    (bool)preg_match('/function pdks_gunluk_kart_gun_kullanimi\(/', $gunlukSrc));
ok('YENİ GİRİŞ yolu (pdks_gunluk_faz8a_giris_kaydet) pdks_gunluk_kart_gun_kullanimi() ÇAĞIRMIYOR (eski günlük kural YENİ akışta YOK)',
    !(bool)preg_match('/function pdks_gunluk_faz8a_giris_kaydet.*?pdks_gunluk_kart_gun_kullanimi\(.*?\n\}/s', $gunlukSrc));

echo "\n=== 6. ŞEMA TESPİTİ — SAYFA YÜKLEMESİNDE DDL YOK, GÜVENLİ BAŞARISIZLIK (görev talimatı §26/28) ===\n";
ok('pdks_gunluk_faz8a_migrate() repoda YALNIZ migrate.php\'den çağrılıyor',
    (function () use ($KOK) {
        $cagiranlar = [];
        foreach (glob($KOK . '/*.php') as $f) {
            if (str_contains((string)file_get_contents($f), 'pdks_gunluk_faz8a_migrate(')) $cagiranlar[] = basename($f);
        }
        return $cagiranlar === ['migrate.php'];
    })());
ok('migrate.php: Faz 8A için ayrı, kontrollü bir POST aksiyonu var (ne=pdks_gunluk_faz8a)',
    str_contains($migrateSrc, "'pdks_gunluk_faz8a'"));
ok('migrate.php: bu dal da csrf_check() çağırıyor', (bool)preg_match("/'pdks_gunluk_faz8a'\)\s*\{\s*\n\s*csrf_check/", $migrateSrc));
ok('pdks_gunluk_faz8a_sema_hazir() migrate.php dışındaki TÜM okuma fonksiyonlarının başında ÇAĞRILIYOR (6 çağrı noktası: ozet/kartlari/kart_sayimi/gun_ozeti/gun_listesi/eksik_cikislar)',
    substr_count($gunlukSrc, 'if (pdks_gunluk_faz8a_sema_hazir($pdo)) return pdks_gunluk_faz8a_') === 6);

echo "\n=== 7. MALİ GÜVENLİK KAPISI (görev talimatı §23) ===\n";
ok("pdks_hakedis_hesapla() Yarım Mesai içeren oturumları 'faz8a_degerlendirme_gerekli' ile REDDEDİYOR",
    str_contains($hakedisSrc, "'faz8a_degerlendirme_gerekli'"));
ok('Ret mesajı görev talimatındaki metinle EŞLEŞİYOR',
    str_contains($hakedisSrc, 'Bu mesai kaydı yeni Tam/Yarım mesai modelini kullanıyor.')
    && str_contains($hakedisSrc, 'Hakediş Faz 8B mesai değerlendirmesi tamamlanmadan kesinleştirilemez.'));
ok('Bu kontrol pdks_hakedis_finalize() ÖNCESİNDE de geçerli (finalize() hesapla() üzerinden geçer, AYRI bir kontrol İCAT EDİLMEDİ)',
    (bool)preg_match('/function pdks_hakedis_finalize.*?pdks_hakedis_hesapla\(/s', $hakedisSrc));
ok('Faz 8A Yarım Mesai finansal oranı/hesaplaması YOK (declared_attendance_class çarpanı olarak KULLANILMIYOR)',
    !preg_match('/declared_attendance_class.*?[*\/]\s*\$/i', $hakedisSrc));

echo "\n=== 8. LEGACY BACKFILL — YALNIZ EKLER, ASLA GÜNCELLEMEZ/SİLMEZ (görev talimatı §9) ===\n";
preg_match('/function pdks_gunluk_faz8a_backfill.*?\n\}/s', $gunlukSrc, $bfM);
$bfGovde = $bfM[0] ?? '';
ok('pdks_gunluk_faz8a_backfill() gövdesi çıkarılabildi', $bfGovde !== '');
ok('backfill: daily_worker_card_events\'e HİÇBİR UPDATE/DELETE YOK (yalnız SELECT)',
    !preg_match('/\b(UPDATE|DELETE)\s+.*daily_worker_card_events/i', $bfGovde));
ok('backfill: yalnız daily_worker_work_periods\'a INSERT yapıyor',
    str_contains($bfGovde, 'INSERT INTO daily_worker_work_periods'));
ok('backfill: source=\'legacy_backfill\' olarak işaretliyor', str_contains($bfGovde, "'legacy_backfill'"));

echo "\n=== 9. AŞIRI MÜHENDİSLİK YASAKLARI — Faz 8B/8C kapsam dışı ===\n";
$yasakli8b8c = ['overtime_rate', 'fazla_mesai_ucreti', 'approved_by_accounting', 'offline_queue',
                'indexeddb', 'service_worker_mutation', 'fx_rate', 'exchange_rate'];
foreach ($yasakli8b8c as $kelime) {
    ok("config/pdks_gunluk.php GERÇEK KODUNDA '$kelime' YOK (Faz 8B/8C kapsamı)",
        !preg_match('/\b' . preg_quote($kelime, '/') . '\b/i', $gunlukSrc));
}
ok('approved_attendance_class HİÇBİR YERDE OKUNMUYOR/HESAPLANMIYOR — yalnız NULL bırakılan bir kolon (Faz 8B\'ye hazır, henüz KULLANILMIYOR)',
    !preg_match('/\bapproved_attendance_class\s*[=!]==?\s*[\'"]/', $gunlukSrc));

printf("\n=== SONUÇ: %d geçti, %d hata ===\n", $gecen, $fail);
exit($fail > 0 ? 1 : 0);
