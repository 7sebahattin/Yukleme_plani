<?php
// Günlük Kurlar — yer tutucu sayfa (içerik sonra eklenecek). Roller: muhasebe.<sayfa>.read (varsayılan kapalı); yönetici her zaman.
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!muhasebe_sayfa_can('gunluk_kurlar')) { forbidden(); }
render_header('Günlük Kurlar');
?>
<div class="card"><h2>Günlük Kurlar</h2><p>Bu bölüm hazırlanıyor.</p></div>
<?php render_footer();
