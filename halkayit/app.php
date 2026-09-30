<?php
// =============================================================================
// HAL KAYIT — SPA kabuğu (iframe içeriği)
// index.php panel çerçevesini (sidebar + bottomnav) çizer ve bu sayfayı bir
// iframe içinde yükler. Doğrudan da açılsa panel oturumuyla korunur.
// =============================================================================
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$auth_user = require_login();
require_perm('records.write');

header('Content-Type: text/html; charset=utf-8');
// CSRF: yeni yazma uçları (kisi_kaydet / kisi_sil) token ister. app.html statik
// olduğu için token <meta name="csrf-token" content="__CSRF_TOKEN__"> yer
// tutucusuna burada basılır; api() her istekte X-CSRF-Token başlığıyla yollar.
// Yer tutucu yoksa str_replace zararsızdır.
echo str_replace('__CSRF_TOKEN__', h(csrf_token()), (string)file_get_contents(__DIR__ . '/app.html'));
