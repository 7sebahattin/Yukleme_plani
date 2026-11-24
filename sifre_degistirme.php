<?php
// =========================================================
// sifre_degistirme.php — Şifre Değiştirme: oturumdaki kullanıcının KENDİ giriş şifresi (v574).
// Mantık config/kullanici_ayar.php ka_sifre_degistir(): mevcut şifre doğrulanır (5 hata → 60 sn bekleme), yeni şifre
// bcrypt'lenir, bu oturum dışındaki oturumlar kapanır, audit'e yalnız olay yazılır (şifre ASLA). Şema değişikliği YOK.
// Kapı: oturumu açık HER kullanıcı (v575; muhasebe_herkese_acik_sayfalar — rol/yetki gerekmez, Roller'de satırı yok).
// Başkasının şifresi burada DEĞİŞMEZ (users.php › Şifre Sıfırla).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/kullanici_ayar.php';
$auth_user = require_login();
if (!muhasebe_sayfa_can('sifre_degistirme')) { forbidden(); }
header('Cache-Control: no-store');
$pdo = db();
$uid = (int)$auth_user['id'];

$hata = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $s = fn(string $k): string => is_string($_POST[$k] ?? null) ? $_POST[$k] : '';
    $r = ka_sifre_degistir($pdo, $uid, $s('mevcut'), $s('yeni'), $s('yeni2'), (string)($_COOKIE[AUTH_COOKIE_NAME] ?? ''));
    if ($r['ok']) {
        set_flash('success', $r['mesaj']);
        header('Location: sifre_degistirme.php');
        exit;
    }
    $hata = $r['mesaj'];   // alanlar geri doldurulmaz (şifre sayfaya geri basılmaz)
}

render_header('Şifre Değiştirme');
echo '<link rel="stylesheet" href="assets/kullanici_ayar.css?v=' . (int)@filemtime(__DIR__ . '/assets/kullanici_ayar.css') . '">' . "\n";
?>
<div class="ka-sayfa">
    <h1 class="ka-baslik">🔒 Şifre Değiştirme</h1>
    <p class="ka-alt">Oturum açtığınız hesabın (<strong><?= h((string)$auth_user['username']) ?></strong>) giriş şifresini değiştirin.</p>

    <?php render_flash(); ?>
    <?php if ($hata !== ''): ?><div class="flash flash-error" role="alert"><?= h($hata) ?></div><?php endif; ?>

    <div class="ka-izgara">
        <div class="card">
            <h2 class="ka-kart-baslik">Yeni şifre belirle</h2>
            <form method="post" autocomplete="off" data-ka-sifre>
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">

                <div class="ka-alan">
                    <label class="form-label" for="ka_mevcut">Mevcut şifre</label>
                    <div class="ka-sifre-sar">
                        <input type="password" id="ka_mevcut" name="mevcut" autocomplete="current-password" required>
                        <button type="button" class="ka-goz" data-ka-goz="ka_mevcut" aria-label="Mevcut şifreyi göster/gizle" aria-pressed="false">👁️</button>
                    </div>
                </div>

                <div class="ka-alan">
                    <label class="form-label" for="ka_yeni">Yeni şifre</label>
                    <div class="ka-sifre-sar">
                        <input type="password" id="ka_yeni" name="yeni" autocomplete="new-password" minlength="<?= KA_SIFRE_MIN ?>" required>
                        <button type="button" class="ka-goz" data-ka-goz="ka_yeni" aria-label="Yeni şifreyi göster/gizle" aria-pressed="false">👁️</button>
                    </div>
                    <div class="ka-guc" data-seviye="0" aria-hidden="true"><i></i><i></i><i></i><i></i></div>
                    <div class="ka-ipucu" data-ka-guc-yazi>En az <?= KA_SIFRE_MIN ?> karakter.</div>
                </div>

                <div class="ka-alan">
                    <label class="form-label" for="ka_yeni2">Yeni şifre (tekrar)</label>
                    <div class="ka-sifre-sar">
                        <input type="password" id="ka_yeni2" name="yeni2" autocomplete="new-password" minlength="<?= KA_SIFRE_MIN ?>" required>
                        <button type="button" class="ka-goz" data-ka-goz="ka_yeni2" aria-label="Tekrarı göster/gizle" aria-pressed="false">👁️</button>
                    </div>
                    <div class="ka-ipucu" data-ka-eslesme aria-live="polite"></div>
                </div>

                <div class="ka-eylem">
                    <button type="submit" class="btn btn-primary">Şifreyi Değiştir</button>
                    <a class="btn btn-ghost" href="index.php">Vazgeç</a>
                </div>
            </form>
        </div>

        <aside class="card">
            <h2 class="ka-kart-baslik">Bilmeniz gerekenler</h2>
            <ul class="ka-liste">
                <li>Şifre en az <?= KA_SIFRE_MIN ?> karakter olmalı; harf, rakam ve işaret karıştırmak güçlendirir.</li>
                <li>Mevcut şifrenizle aynı olamaz, kullanıcı adınızı içeren basit şifrelerden kaçının.</li>
                <li>Değişiklikten sonra <strong>diğer cihazlardaki oturumlarınız kapanır</strong>; bu cihazda oturumunuz açık kalır.</li>
                <li>Mevcut şifreyi art arda 5 kez yanlış girerseniz 60 saniye beklemeniz gerekir.</li>
            </ul>
            <div class="ka-not">
                Masaüstü <strong>ekran kilidi PIN'i</strong> giriş şifrenizden ayrıdır; bu sayfa onu değiştirmez
                (Kullanıcı Tercihleri › Güvenlik).
            </div>
            <div class="ka-not">Şifrenizi unuttuysanız bir yöneticiden <em>Kullanıcılar › Şifre Sıfırla</em> işlemini isteyin.</div>
        </aside>
    </div>
</div>
<script src="assets/kullanici_ayar.js?v=<?= (int)@filemtime(__DIR__ . '/assets/kullanici_ayar.js') ?>"></script>
<?php render_footer();
