<?php
// Depo Tanımları — mevcut sayfaya yönlendirme (v439). Yalnız yönetici; hedef kendi yetki kapısını uygular.
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!is_admin()) { forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.'); }
header('Location: definitions.php?type=depo', true, 302);
exit;
