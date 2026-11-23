<?php
// Döviz Tanımları — yer tutucu sayfa (içerik sonra eklenecek). Yalnız yönetici.
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!muhasebe_sayfa_can('doviz_tanimlari')) { forbidden(); }
render_header('Döviz Tanımları');
?>
<div class="card"><h2>Döviz Tanımları</h2><p>Bu bölüm hazırlanıyor.</p></div>
<?php render_footer();
