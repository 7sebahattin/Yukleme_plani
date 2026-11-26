<?php
// Raporlar-Listeler — geçiş dönemi 302 yönlendirmesi (B-25). Kapı: muhasebe_sayfa_can('raporlar_listeler'); reports.php okuma kapısı senkrondur.
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!muhasebe_sayfa_can('raporlar_listeler')) { forbidden(); }
header('Location: reports.php', true, 302);
exit;
