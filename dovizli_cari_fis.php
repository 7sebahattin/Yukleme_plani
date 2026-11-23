<?php
// Dövizli Cari Fiş — yer tutucu sayfa (içerik sonra eklenecek). Yalnız yönetici.
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!muhasebe_sayfa_can('dovizli_cari_fis')) { forbidden(); }
render_header('Dövizli Cari Fiş');
?>
<div class="card"><h2>Dövizli Cari Fiş</h2><p>Bu bölüm hazırlanıyor.</p></div>
<?php render_footer();
