<?php
// =========================================================
// mail_hesaplar.php — Mail hesapları (yalnız can_mail('admin'))
// Şifreler yalnız yazılır: forma ASLA geri basılmaz, boş bırakmak = değiştirme.
// Kimlik bilgisi AES-256-GCM ile şifrelenir (config/mail_core.php).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/mail_core.php';
$auth_user = require_login();
require_mail('admin');

$pdo   = db();
$uid   = (int)$auth_user['id'];
$hazir = mail_sema_hazir($pdo);
$hatalar = [];
$basari  = '';

$kullanicilar = [];
try {
    $kullanicilar = $pdo->query("SELECT id, username, display_name FROM users WHERE is_active = 1 ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { $kullanicilar = []; }

if ($hazir && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $islem = (string)($_POST['islem'] ?? '');
    $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;

    if ($islem === 'kaydet') {
        $r = mail_hesap_kaydet($_POST, $id, $uid, $pdo);
        if ($r['ok']) {
            if (!empty($_POST['kullanicilar_gonderildi'])) {
                $secili = array_map('intval', (array)($_POST['kullanicilar'] ?? []));
                $gecerli = array_map('intval', array_column($kullanicilar, 'id'));
                mail_hesap_kullanici_ata($r['id'], array_values(array_intersect($secili, $gecerli)), $pdo);
            }
            // Audit: yalnız kimlik + alan ADLARI; şifre/değer YOK.
            audit_log_event($id === null ? 'mail_account_create' : 'mail_account_update', 'mail_accounts', $r['id'], null, [
                'sifre_degisti' => array_values(array_filter(MAIL_SIFRE_ALANLARI, static fn($a) => ($_POST[$a] ?? '') !== '')),
            ]);
            $basari = $id === null ? 'Hesap eklendi.' : 'Hesap güncellendi.';
            $id = $r['id'];
        } else {
            $hatalar = $r['hatalar'];
        }
    } elseif ($islem === 'durum' && $id !== null) {
        $pdo->prepare('UPDATE mail_accounts SET is_active = 1 - is_active, updated_at = ? WHERE id = ?')
            ->execute([date('Y-m-d H:i:s'), $id]);
        audit_log_event('mail_account_toggle', 'mail_accounts', $id);
        $basari = 'Hesap durumu değiştirildi.';
    }
}

$duzenle = null;
if ($hazir && isset($_GET['duzenle'])) $duzenle = mail_hesap_goster((int)$_GET['duzenle'], $pdo);
$liste = $hazir ? $pdo->query("SELECT id, label, email, is_active FROM mail_accounts ORDER BY label")->fetchAll(PDO::FETCH_ASSOC) : [];
$atananlar = $duzenle ? mail_hesap_kullanicilari((int)$duzenle['id'], $pdo) : [];
$f = $duzenle ?? ['label'=>'','email'=>'','display_name'=>'','imap_host'=>'','imap_port'=>993,'imap_security'=>'ssl','imap_user'=>'',
    'smtp_host'=>'','smtp_port'=>465,'smtp_security'=>'ssl','smtp_user'=>'','reply_to'=>'','sync_folder'=>'INBOX','sent_folder'=>'',
    'append_sent'=>0,'initial_days'=>30,'translate_enabled'=>0,'target_lang'=>'tr','imap_pass_var'=>0,'smtp_pass_var'=>0];
if ($hatalar && ($_POST['islem'] ?? '') === 'kaydet') {
    // Doğrulama hatasında yazılanlar kaybolmasın — ŞİFRELER hariç (forma ASLA geri basılmaz).
    $f = array_merge($f, array_diff_key($_POST, array_flip(['imap_pass', 'smtp_pass', 'csrf'])));
}
$v = static fn(string $k) => h((string)($f[$k] ?? ''));

render_header('Mail Hesapları');
?>
<div class="container">
    <p><a class="btn btn-geri" href="mail.php">← Mail Merkezi</a></p>
    <h1 style="margin:0 0 12px">Mail Hesapları</h1>

    <?php if (!$hazir): ?>
    <div class="card" style="padding:16px"><p>Önce <a href="migrate.php">migrate.php</a> üzerinden Mail tablolarını kurun.</p></div>
    <?php else: ?>
    <?php if (!mail_crypto_hazir()): ?>
    <div class="card" style="padding:12px;border-left:4px solid var(--warn,#d97706);margin-bottom:12px">
        <strong>MAIL_MASTER_KEY tanımlı/geçerli değil.</strong> Hesap kaydedilemez. Anahtarı üretmek için sunucuda:
        <code>php -r 'echo base64_encode(random_bytes(32)),"\n";'</code> ve çıktıyı <code>config/local.php</code> içine
        <code>define('MAIL_MASTER_KEY', '…');</code> olarak yazın. Anahtar git'e girmez.
    </div>
    <?php endif; ?>
    <?php if ($basari): ?><div class="card" style="padding:10px;margin-bottom:12px;border-left:4px solid #16a34a"><?= h($basari) ?></div><?php endif; ?>
    <?php foreach ($hatalar as $e): ?><div class="card" style="padding:10px;margin-bottom:8px;border-left:4px solid #dc2626"><?= h($e) ?></div><?php endforeach; ?>

    <div class="table-wrap" style="margin-bottom:16px">
        <table class="table">
            <thead><tr><th>Etiket</th><th>Adres</th><th>Durum</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($liste as $a): ?>
                <tr>
                    <td><?= h($a['label']) ?></td><td><?= h($a['email']) ?></td>
                    <td><?= (int)$a['is_active'] === 1 ? 'Aktif' : 'Pasif' ?></td>
                    <td>
                        <a class="btn" href="mail_hesaplar.php?duzenle=<?= (int)$a['id'] ?>">Düzenle</a>
                        <form method="post" style="display:inline">
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="islem" value="durum">
                            <input type="hidden" name="id" value="<?= (int)$a['id'] ?>">
                            <button class="btn" type="submit"><?= (int)$a['is_active'] === 1 ? 'Pasifleştir' : 'Aktifleştir' ?></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$liste): ?><tr><td colspan="4">Henüz hesap yok.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="card" style="padding:16px">
        <h2 style="margin-top:0"><?= $duzenle ? 'Hesabı Düzenle' : 'Yeni Hesap' ?></h2>
        <form method="post" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="islem" value="kaydet">
            <input type="hidden" name="kullanicilar_gonderildi" value="1">
            <?php if ($duzenle): ?><input type="hidden" name="id" value="<?= (int)$duzenle['id'] ?>"><?php endif; ?>

            <div class="form-group"><label>Etiket</label><input type="text" name="label" maxlength="100" required value="<?= $v('label') ?>"></div>
            <div class="form-group"><label>E-posta adresi (From)</label><input type="email" name="email" maxlength="190" required value="<?= $v('email') ?>"></div>
            <div class="form-group"><label>Gönderen adı</label><input type="text" name="display_name" maxlength="150" value="<?= $v('display_name') ?>"></div>
            <div class="form-group"><label>Reply-To (isteğe bağlı)</label><input type="email" name="reply_to" maxlength="190" value="<?= $v('reply_to') ?>"></div>

            <h3>Gelen (IMAP)</h3>
            <div class="form-group"><label>Sunucu</label><input type="text" name="imap_host" required value="<?= $v('imap_host') ?>"></div>
            <div class="form-group"><label>Port</label><input type="number" name="imap_port" value="<?= $v('imap_port') ?>"></div>
            <div class="form-group"><label>Güvenlik</label>
                <select name="imap_security"><?php foreach (mail_guvenlik_modlari() as $m): ?><option value="<?= h($m) ?>"<?= $f['imap_security'] === $m ? ' selected' : '' ?>><?= h($m) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Kullanıcı adı</label><input type="text" name="imap_user" required autocomplete="off" value="<?= $v('imap_user') ?>"></div>
            <div class="form-group"><label>Şifre <?= !empty($f['imap_pass_var']) ? '(kayıtlı — değiştirmek için yazın)' : '' ?></label>
                <input type="password" name="imap_pass" autocomplete="new-password" value=""></div>
            <div class="form-group"><label>Senkron klasörü</label><input type="text" name="sync_folder" value="<?= $v('sync_folder') ?>"></div>
            <div class="form-group"><label>İlk senkronda kaç gün geriye</label><input type="number" name="initial_days" min="1" max="365" value="<?= $v('initial_days') ?>"></div>

            <h3>Giden (SMTP)</h3>
            <div class="form-group"><label>Sunucu</label><input type="text" name="smtp_host" required value="<?= $v('smtp_host') ?>"></div>
            <div class="form-group"><label>Port</label><input type="number" name="smtp_port" value="<?= $v('smtp_port') ?>"></div>
            <div class="form-group"><label>Güvenlik</label>
                <select name="smtp_security"><?php foreach (mail_guvenlik_modlari() as $m): ?><option value="<?= h($m) ?>"<?= $f['smtp_security'] === $m ? ' selected' : '' ?>><?= h($m) ?></option><?php endforeach; ?></select></div>
            <div class="form-group"><label>Kullanıcı adı</label><input type="text" name="smtp_user" required autocomplete="off" value="<?= $v('smtp_user') ?>"></div>
            <div class="form-group"><label>Şifre <?= !empty($f['smtp_pass_var']) ? '(kayıtlı — değiştirmek için yazın)' : '' ?></label>
                <input type="password" name="smtp_pass" autocomplete="new-password" value=""></div>
            <div class="form-group"><label>Gönderilenler klasörü (isteğe bağlı)</label><input type="text" name="sent_folder" value="<?= $v('sent_folder') ?>"></div>
            <div class="form-group"><label><input type="checkbox" name="append_sent" value="1"<?= !empty($f['append_sent']) ? ' checked' : '' ?>> Gönderilen kopyayı IMAP Gönderilenler'e yaz</label></div>

            <h3>Çeviri</h3>
            <div class="form-group"><label><input type="checkbox" name="translate_enabled" value="1"<?= !empty($f['translate_enabled']) ? ' checked' : '' ?>> Bu hesap için otomatik çeviri (sağlayıcı ayrıca açılmalı)</label></div>
            <div class="form-group"><label>Hedef dil</label><input type="text" name="target_lang" maxlength="10" value="<?= $v('target_lang') ?>"></div>

            <h3>Erişebilecek kullanıcılar</h3>
            <p style="color:#666;font-size:.9em">Seçilmeyen kullanıcılar bu hesabı GÖRMEZ (yöneticiler hariç).</p>
            <?php foreach ($kullanicilar as $k): ?>
            <div class="form-group"><label><input type="checkbox" name="kullanicilar[]" value="<?= (int)$k['id'] ?>"<?= in_array((int)$k['id'], $atananlar, true) ? ' checked' : '' ?>>
                <?= h($k['display_name'] ?: $k['username']) ?></label></div>
            <?php endforeach; ?>

            <button class="btn btn-primary" type="submit">Kaydet</button>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
