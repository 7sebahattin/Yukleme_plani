<?php
// =========================================================
// config/ortam.php — Çalışma ortamı (canlı / test) — include-only (v504)
//
// Ortam sunucuya özel config/local.php içindeki tek sabitle belirlenir (git dışında):
//     define('APP_ORTAM', 'test');
// Sabit YOKSA ortam 'canli' sayılır. Sabit VARSA ve 'canli' DEĞİLSE (yazım hatası dahil,
// ör. 'Test ', 'staging') ortam TEST sayılır: dış gönderim kapanır (fail-closed).
//
// TEST ortamında dış dünyaya giden her yol kapalıdır (canlı veritabanı kopyasıyla çalışıldığı için):
//   • HKS SOAP çağrıları     → halkayit/hks_soap.php  hks_soap_cagir()      (HksAgHatasi errno 7: istek hiç gitmedi)
//   • IMAP + SMTP bağlantısı → config/mail_imap.php   MailSocketStream::baglan()
//   • Mail çevirisi (HTTPS)  → config/mail_translate.php mail_http_istek()
// Yeni bir dış bağlantı yolu (curl, soket, e-posta, SMS, API) eklerken AYNI kapıyı
// app_dis_gonderim_kapali() ile kurun. Kapı her dosyada BU dosyayı kendisi require eder
// (db.php yüklenmiş olmasına güvenme) — yoksa kapı sessizce açık kalırdı.
// =========================================================
declare(strict_types=1);

// Doğrudan istek → 404. TAM YOL karşılaştırılır (basename değil).
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

// Bazı giriş noktaları (cron, halkayit) db.php'den önce buraya gelebilir: local.php'yi kendimiz yükle.
if (!defined('APP_ORTAM') && is_file(__DIR__ . '/local.php')) {
    require_once __DIR__ . '/local.php';
}

if (!function_exists('app_ortam')) {
    /** 'canli' | 'test' — tanımsız sabit = canli; 'canli' dışındaki HER değer = test. */
    function app_ortam(): string
    {
        if (!defined('APP_ORTAM')) return 'canli';
        return strtolower(trim((string)APP_ORTAM)) === 'canli' ? 'canli' : 'test';
    }

    function app_test_ortami(): bool
    {
        return app_ortam() === 'test';
    }

    /** Dış dünyaya (HKS, e-posta, çeviri servisi, …) hiçbir şey gönderilmeyecek mi? Şimdilik = test ortamı. */
    function app_dis_gonderim_kapali(): bool
    {
        return app_test_ortami();
    }
}
