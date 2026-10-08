<?php
// =========================================================
// scripts/mail_schema_static_smoke.php — MySQL/InnoDB DDL statik denetimi (canlı DB'ye dokunmaz)
// Canlıda ilk kurulumda "Specified key was too long" (1071) çıkmasın: utf8mb4 = 4 bayt/karakter,
// COMPACT/REDUNDANT satır biçiminde indeks anahtarı ≤ 767 bayt (DYNAMIC'te 3072 — ama sunucu ayarını bilmiyoruz → en kötü durum).
//   php scripts/mail_schema_static_smoke.php
// =========================================================
require_once __DIR__ . '/_mail_test_lib.php';
require_once $ROOT . '/config/mail_core.php';

function sutun_bayt(string $tip): ?int {
    if (preg_match('/^(?:VAR)?CHAR\((\d+)\)/i', $tip, $m)) return 4 * (int)$m[1];
    if (preg_match('/^(BIGINT)/i', $tip)) return 8;
    if (preg_match('/^(INT|INTEGER)\b/i', $tip)) return 4;
    if (preg_match('/^(TINYINT)/i', $tip)) return 1;
    if (preg_match('/^(DATETIME|TIMESTAMP)/i', $tip)) return 8;
    return null;   // TEXT/BLOB: indekslenemez (önek uzunluğu gerekir)
}
$LIMIT = 767; $kontrol = 0; $kotu = [];
foreach (mail_tablolar() as $ad => $ddl) {
    $sut = [];
    preg_match_all('/^\s*`(\w+)`\s+([A-Za-z]+(?:\(\d+\))?)/m', $ddl, $mm, PREG_SET_ORDER);
    foreach ($mm as $m) $sut[$m[1]] = $m[2];
    preg_match_all('/(?:PRIMARY KEY|UNIQUE KEY\s+`\w+`|INDEX\s+`\w+`|KEY\s+`\w+`)\s*\(([^)]+)\)/i', $ddl, $ix);
    foreach ($ix[0] as $i => $def) {
        $toplam = 0; $ok = true;
        foreach (explode(',', $ix[1][$i]) as $c) {
            $c = trim($c, " `");
            $b = isset($sut[$c]) ? sutun_bayt($sut[$c]) : null;
            if ($b === null) { $ok = false; $kotu[] = "$ad: $def → '$c' indekslenemez tip"; break; }
            $toplam += $b;
        }
        $kontrol++;
        if ($ok && $toplam > $LIMIT) $kotu[] = "$ad: $def → $toplam bayt > $LIMIT";
    }
}
ok("tüm indeks/anahtarlar ($kontrol adet) ≤ $LIMIT bayt (utf8mb4, en kötü satır biçimi)", $kotu === [], implode(' | ', $kotu));
ok('indeks ayrıştırıcı gerçekten indeks buldu (testin kendisi boş geçmesin)', $kontrol >= 15, (string)$kontrol);
$dinamik = true; foreach (mail_tablolar() as $ad => $ddl) if (!str_contains($ddl, 'ROW_FORMAT=DYNAMIC')) $dinamik = false;
ok('her tablo açıkça ROW_FORMAT=DYNAMIC (COMPACT varsayılanlı sunucuda 1118 "Row size too large" önlenir)', $dinamik);
// Hesap e-postası ile Message-ID alanı: üretilen kimlik sütuna sığmalı
require_once $ROOT . '/config/mail_smtp.php';
$en = mail_yeni_message_id('a@' . str_repeat('x', 96) . '.com');
ok('üretilen Message-ID ≤ 190 karakter (UNIQUE out_message_id)', strlen($en) <= 190, (string)strlen($en));
ok('aşırı uzun alan adı yerel alana düşer (sütunu taşırmaz)', strlen(mail_yeni_message_id('a@' . str_repeat('y', 250) . '.com')) <= 190);
mail_test_bitir();
