<?php
// Araç Yükleme Fişleri — yer tutucu sayfa (içerik sonra eklenecek). Eski Yükleme Planı'na YÖNLENDİRMEZ (v559).
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!muhasebe_sayfa_can('arac_yukleme_fisleri')) { forbidden(); }
render_header('Araç Yükleme Fişleri');
?>
<div class="card"><h2>Araç Yükleme Fişleri</h2><p>Bu bölüm hazırlanıyor.</p></div>
<?php render_footer();
