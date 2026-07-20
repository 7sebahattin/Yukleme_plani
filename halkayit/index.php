<?php
// =============================================================================
// HAL KAYIT (HKS) PANELİ — GİRİŞ NOKTASI
// Ana panel oturumuyla korunur. Doğrulama geçtikten sonra SPA kabuğu (app.html)
// sunulur. Tüm veri çağrıları api.php'ye gider (o da aynı oturumla korunur).
// =============================================================================
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$auth_user = require_login();
require_perm('records.write');   // Eski "Hal Bildirimi" ile aynı yetki kapısı

header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/app.html');
