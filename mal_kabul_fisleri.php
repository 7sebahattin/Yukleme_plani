<?php
// Mal Kabul Fişleri — yer tutucu sayfa (içerik sonra eklenecek). Eski Mal Kabul'e (kantar.php) YÖNLENDİRMEZ (v559).
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!muhasebe_sayfa_can('mal_kabul_fisleri')) { forbidden(); }
render_header('Mal Kabul Fişleri');
?>
<div class="card"><h2>Mal Kabul Fişleri</h2><p>Bu bölüm hazırlanıyor.</p></div>
<?php render_footer();
