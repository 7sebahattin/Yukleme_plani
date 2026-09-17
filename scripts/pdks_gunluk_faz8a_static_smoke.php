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
$raporSrc = oku('config/pdks_rapor.php');
$kartSrc = oku('isci_kartlari.php');
$pdksCss = oku('assets/pdks.css');

echo "\n=== 1. SÖZ DİZİMİ ===\n";
foreach (['config/pdks_gunluk.php', 'config/pdks_hakedis.php', 'gunluk_isci_giris_cikis.php',
          'isci_kartlari.php', 'migrate.php', 'config/pdks_rapor.php', 'raporlar.php',
          'gunluk_isci_puantaj_detay.php', 'gunluk_puantaj_yazdir.php'] as $f) {
    $cikti = []; $rc = 0;
    exec('php -l ' . escapeshellarg($KOK . '/' . $f) . ' 2>&1', $cikti, $rc);
    ok("$f: php -l geçiyor", $rc === 0, implode("\n", $cikti));
}

echo "\n=== 1B. KART HAVUZU DÜZENLEME MODALI — TAŞMA GÜVENLİĞİ ===\n";
ok('Modal viewport güvenli genişlik ve min-width:0 kullanıyor',
    str_contains($pdksCss, 'width: min(520px, calc(100vw - 32px));')
    && str_contains($pdksCss, 'max-width: calc(100vw - 32px);')
    && str_contains($pdksCss, '.isk-card-modal {'));
ok('Modal gövdesi güvenli iç boşluk, min-width:0 ve dikey kaydırma kullanıyor',
    str_contains($pdksCss, '.isk-card-modal-body {')
    && str_contains($pdksCss, 'min-width: 0;')
    && str_contains($pdksCss, 'overflow-y: auto;')
    && str_contains($pdksCss, 'padding: 16px 20px;'));
ok('Form grid sütunları minmax(0, 1fr), grid çocukları min-width:0 ve kontroller width-safe',
    str_contains($pdksCss, 'grid-template-columns: repeat(2, minmax(0, 1fr));')
    && str_contains($pdksCss, '.isk-card-modal .pdks-form-grid > label {')
    && str_contains($pdksCss, '.isk-card-modal .pdks-form-grid input,')
    && str_contains($pdksCss, 'max-width: 100%;'));
ok('Durum aksiyonları sabit üç sütun yerine auto-fit ile yeniden akar; formlar ve butonlar taşma-korumalıdır',
    str_contains($pdksCss, 'repeat(auto-fit, minmax(min(100%, 152px), 1fr))')
    && str_contains($pdksCss, '.isk-card-status-actions form {')
    && str_contains($pdksCss, '.isk-card-status-actions .btn {'));
ok('Mobilde form ve durum aksiyonları güvenli tek sütuna iner',
    str_contains($pdksCss, 'grid-template-columns: minmax(0, 1fr);')
    && str_contains($pdksCss, '.isk-card-modal .pdks-form-grid .span-2 { grid-column: 1; }'));
ok('Kart modalı içeriği güvenli gövde ve aksiyon sınıflarıyla render edilir',
    str_contains($kartSrc, 'class="isk-card-modal-body"')
    && str_contains($kartSrc, 'class="isk-card-form-actions"')
    && str_contains($kartSrc, 'class="isk-card-status-actions"'));

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
ok("PRE-MERGE GÜVENLİK DÜZELTMESİ: backfill eksik çıkışlı satırlara 'legacy_unresolved' yazıyor, 'open' DEĞİL",
    (bool)preg_match("/\\?\\s*'closed'\\s*:\\s*'legacy_unresolved'/", $bfGovde));
ok("PRE-MERGE GÜVENLİK DÜZELTMESİ: backfill gövdesinde ARTIK status='open' YAZDIRAN bir dal YOK",
    !preg_match("/:\\s*'open'/", $bfGovde));

echo "\n=== 8B. AÇIK-DÖNEM KİLİDİ — TEK DOĞRULUK KAYNAĞI status='open', SOURCE FİLTRESİ YOK ===\n";
// PRE-MERGE GÜVENLİK DÜZELTMESİ (kullanıcının açık talimatı): "open-period
// query must not contain a broad source-based exclusion that could
// accidentally allow a real Phase 8A open period through." Kilit/blokaj
// sorguları (kart_acik_donemi + cikis_kaydet'in eşleştirme sorgusu) ARTIK
// yalnız status='open' arar — source='scan' filtresi TAMAMEN KALDIRILDI,
// çünkü backfill hiçbir zaman 'open' YAZMIYOR (bkz. §8) — filtreye GEREK YOK.
preg_match('/function pdks_gunluk_faz8a_kart_acik_donemi.*?\n\}/s', $gunlukSrc, $adM);
$adGovde = $adM[0] ?? '';
ok('pdks_gunluk_faz8a_kart_acik_donemi() gövdesi çıkarılabildi', $adGovde !== '');
ok("kart_acik_donemi(): status = 'open' filtresi VAR", str_contains($adGovde, "status = 'open'"));
ok("kart_acik_donemi(): source BAZLI bir dışlama YOK (source='scan' / source != 'legacy_backfill' vb. HİÇBİRİ)",
    !preg_match('/\bsource\b/i', $adGovde));

preg_match('/function pdks_gunluk_faz8a_cikis_kaydet.*?\n\}/s', $gunlukSrc, $ckM);
$ckGovde = $ckM[0] ?? '';
ok('pdks_gunluk_faz8a_cikis_kaydet() gövdesi çıkarılabildi', $ckGovde !== '');
ok("cikis_kaydet(): açık dönemi bulan sorguda status = 'open' filtresi ve Faz 8J etkin-kayıt koruması VAR",
    (bool)preg_match("/SELECT \\* FROM daily_worker_work_periods WHERE worker_card_id = \\? AND status = 'open' AND.*LIMIT 1/", $ckGovde));
ok("cikis_kaydet(): AYNI sorguda source BAZLI bir dışlama YOK",
    !preg_match('/status = \'open\' AND source/', $ckGovde));

ok("pdks_gunluk_faz8a_donem_durumu() ÜÇ durumu (open/closed/legacy_unresolved) AÇIKÇA AYRIŞTIRIYOR",
    (bool)preg_match("/function pdks_gunluk_faz8a_donem_durumu.*?'open'.*?'closed'.*?'legacy_unresolved'.*?\n\}/s", $gunlukSrc));
ok("oturum_donemleri(): satır etiketi ARTIK gerçek durum_kod'dan (pdks_gunluk_faz8a_donem_durumu) okunuyor — yalnız cikis_saat NULL/DOLU İKİLİĞİNDEN DEĞİL",
    str_contains($gunlukSrc, "pdks_gunluk_faz8a_donem_durumu((string)\$s['durum_kod'])"));

echo "\n=== 8C. PHASE 8A REVIEW REGRESSION ===\n";

ok('review 1: exception list Phase 8A work-period semantics kullanıyor',
    str_contains($raporSrc, 'function pdks_rapor_istisna_satirlari')
    && str_contains($raporSrc, 'FROM daily_worker_work_periods p')
    && str_contains($raporSrc, "p.status IN ('open','legacy_unresolved')"));

ok('review 2: backfill yalnız duplicate-key hatasını tolere ediyor, diğerlerini yeniden fırlatıyor',
    str_contains($bfGovde, '$duplicateKey')
    && str_contains($bfGovde, '$driverCode === 1062')
    && str_contains($bfGovde, 'throw $e;'));

ok('review 3: Faz 8A rapor eksik-cikis sorguları legacy_unresolved durumunu da sayıyor',
    substr_count($raporSrc, "p.status = 'legacy_unresolved' OR (s.status = 'closed' AND p.status = 'open')") >= 4);

preg_match('/function pdks_gunluk_faz8a_giris_kaydet.*?\n\}/s', $gunlukSrc, $girisReviewM);
preg_match('/function pdks_gunluk_faz8a_cikis_kaydet.*?\n\}/s', $gunlukSrc, $cikisReviewM);
$girisReviewGovde = $girisReviewM[0] ?? '';
$cikisReviewGovde = $cikisReviewM[0] ?? '';

ok('review 4: GİRİŞ/ÇIKIŞ PDO detayını operatora döndürmüyor ve teknik hatayı logluyor',
    $girisReviewGovde !== ''
    && $cikisReviewGovde !== ''
    && str_contains($girisReviewGovde, "error_log('[pdks_gunluk_faz8a_kaydet] ")
    && str_contains($cikisReviewGovde, "error_log('[pdks_gunluk_faz8a_kaydet] ")
    && !preg_match("/'hata'\s*=>\s*[^,\n]*getMessage\(\)/", $girisReviewGovde)
    && !preg_match("/'hata'\s*=>\s*[^,\n]*getMessage\(\)/", $cikisReviewGovde));

preg_match('/function pdks_gunluk_faz8a_migrate.*?return \$rapor;\s*\n\}/s', $gunlukSrc, $migReviewM);
$migReviewGovde = $migReviewM[0] ?? '';
ok('review 5: migrasyon PDO detayını operator mesajına koymuyor, ayrıntıyı server loguna yazıyor',
    $migReviewGovde !== ''
    && substr_count($migReviewGovde, "error_log('[pdks_gunluk_faz8a_migrate]") >= 4
    && !preg_match("/'mesaj'\s*=>\s*\$e->getMessage\(\)/", $migReviewGovde));

preg_match('/function pdks_gunluk_kart_olustur.*?\n\}/s', $gunlukSrc, $kartOlusturReviewM);
$kartOlusturReviewGovde = $kartOlusturReviewM[0] ?? '';

ok('review 6: core kart oluşturma migrasyon öncesi nötr kartı DB yazımından önce güvenli reddediyor',
    $kartOlusturReviewGovde !== ''
    && str_contains($kartOlusturReviewGovde, '$workerTypeId === null && !pdks_gunluk_faz8a_sema_hazir($pdo)')
    && str_contains($kartOlusturReviewGovde, "'faz8a_migrasyon_gerekli'"));

ok('review 7: core kart oluşturma PDO ayrıntısını operatöre sızdırmıyor, server loguna yazıyor',
    $kartOlusturReviewGovde !== ''
    && str_contains($kartOlusturReviewGovde, "error_log('[pdks_gunluk_kart_olustur] ")
    && !preg_match("/'hata'\s*=>\s*[^,\n]*getMessage\(\)/", $kartOlusturReviewGovde));

ok('review 8: Faz 8A nullable migrasyonu foreign key ilişkisini tespit ediyor',
    str_contains($gunlukSrc, 'information_schema.KEY_COLUMN_USAGE')
    && str_contains($gunlukSrc, 'function pdks_gunluk_faz8a_fk_var')
    && str_contains($gunlukSrc, "'worker_type_id',")
    && str_contains($gunlukSrc, "'worker_types',"));

ok('review 9: Faz 8A nullable migrasyonu FK drop -> MODIFY -> restore yapıyor ve readiness FK bütünlüğünü doğruluyor',
    str_contains($migReviewGovde, 'DROP FOREIGN KEY')
    && str_contains($migReviewGovde, 'ADD CONSTRAINT')
    && str_contains($migReviewGovde, 'finally')
    && str_contains($gunlukSrc, 'pdks_gunluk_faz8a_fk_var('));

ok('review 10: MariaDB kolon metadata kontrolü SHOW placeholder kullanmıyor',
    str_contains($gunlukSrc, 'information_schema.COLUMNS')
    && !str_contains($gunlukSrc, 'SHOW COLUMNS FROM `{$tablo}` LIKE ?'));

ok('review 11: MariaDB index metadata kontrolü SHOW placeholder kullanmıyor',
    str_contains($gunlukSrc, 'information_schema.STATISTICS')
    && !str_contains($gunlukSrc, 'SHOW INDEX FROM `{$tablo}` WHERE Key_name = ?'));

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
