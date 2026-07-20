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
readfile(__DIR__ . '/app.html');
