<?php
// Araç Yükleme Fişleri — geçiş dönemi 302 yönlendirmesi (B-25). Kapı: muhasebe_sayfa_can('arac_yukleme_fisleri'); records.php okuma kapısı senkrondur.
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!muhasebe_sayfa_can('arac_yukleme_fisleri')) { forbidden(); }
header('Location: records.php', true, 302);
exit;
