<?php
// Kantar Fişleri — yer tutucu sayfa (içerik sonra eklenecek). Yalnız yönetici.
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!is_admin()) { forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.'); }
render_header('Kantar Fişleri');
?>
<div class="card"><h2>Kantar Fişleri</h2><p>Bu bölüm hazırlanıyor.</p></div>
<?php render_footer();
