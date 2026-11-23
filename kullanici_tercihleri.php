<?php
// Kullanıcı Tercihleri — yer tutucu sayfa (içerik sonra eklenecek). Yalnız yönetici.
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!muhasebe_sayfa_can('kullanici_tercihleri')) { forbidden(); }
render_header('Kullanıcı Tercihleri');
?>
<div class="card"><h2>Kullanıcı Tercihleri</h2><p>Bu bölüm hazırlanıyor.</p></div>
<?php render_footer();
