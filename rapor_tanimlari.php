<?php
// Rapor Tanımları — yer tutucu sayfa (içerik sonra eklenecek). Yalnız yönetici.
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!muhasebe_sayfa_can('rapor_tanimlari')) { forbidden(); }
render_header('Rapor Tanımları');
?>
<div class="card"><h2>Rapor Tanımları</h2><p>Bu bölüm hazırlanıyor.</p></div>
<?php render_footer();
