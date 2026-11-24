<?php
// =========================================================
// kullanici_tercihleri.php — Kullanıcı Tercihleri (v574): kişinin KENDİ ayarları. Mantık config/kullanici_ayar.php.
//   Bölümler: Profil (Ad Soyad + e-posta) · Görünüm (tema, bu cihaz) · Çalışma deposu · Tablo düzenleri (sıfırla) · Güvenlik.
// POST (PRG): profil_kaydet · tablo_sifirla — CSRF; kullanıcı id'si oturumdan. Şema değişikliği YOK
// (tablo düzenleri mevcut kullanici_tercihleri tablosunu kullanır; tablo yoksa tarayıcı yedeği temizlenir).
// Kapı: muhasebe_sayfa_can (Roller; varsayılan yalnız yönetici).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/kullanici_tercih.php';
require_once __DIR__ . '/config/kullanici_ayar.php';
$auth_user = require_login();
if (!muhasebe_sayfa_can('kullanici_tercihleri')) { forbidden(); }
header('Cache-Control: no-store');
$pdo = db();
$uid = (int)$auth_user['id'];
$TABLOLAR = ka_tablo_tercihleri();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $islem = is_string($_POST['islem'] ?? null) ? $_POST['islem'] : '';
    if ($islem === 'profil_kaydet') {
        $r = ka_profil_kaydet($pdo, $uid, is_string($_POST['display_name'] ?? null) ? $_POST['display_name'] : '', is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
    } elseif ($islem === 'tablo_sifirla') {
        $anahtar = is_string($_POST['anahtar'] ?? null) ? $_POST['anahtar'] : '';
        if (!isset($TABLOLAR[$anahtar])) {
            $r = ['ok' => false, 'mesaj' => 'Geçersiz tablo.'];
        } elseif (!kt_hazir($pdo)) {
            $r = ['ok' => true, 'mesaj' => $TABLOLAR[$anahtar][0] . ' düzeni bu tarayıcıda varsayılana döndü.'];   // sunucu kaydı yok; yedek JS ile silinir
        } elseif (kt_sil($pdo, $uid, $anahtar)) {
            audit_log_event('update', 'kullanici_tercihleri', $uid, null, ['islem' => 'tablo_sifirla', 'anahtar' => $anahtar]);
            $r = ['ok' => true, 'mesaj' => $TABLOLAR[$anahtar][0] . ' tablo düzeni varsayılana döndü.'];
        } else {
            $r = ['ok' => false, 'mesaj' => 'Tablo düzeni sıfırlanamadı. Tekrar deneyin.'];
        }
    } else {
        $r = ['ok' => false, 'mesaj' => 'Geçersiz işlem.'];
    }
    set_flash($r['ok'] ? 'success' : 'error', $r['mesaj']);
    header('Location: kullanici_tercihleri.php');
    exit;
}

// Profil / roller / son giriş (yalnız okuma; mevcut kolonlar)
$profil = ['display_name' => (string)($auth_user['display_name'] ?? ''), 'email' => (string)($auth_user['email'] ?? '')];
$roller = []; $sonGiris = '';
try {
    $st = $pdo->prepare('SELECT r.label FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.label');
    $st->execute([$uid]);
    $roller = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    $sg = $pdo->prepare('SELECT last_login_at FROM users WHERE id = ?');
    $sg->execute([$uid]);
    $sonGiris = (string)($sg->fetchColumn() ?: '');
} catch (Throwable $e) {
    error_log('[kullanici_tercihleri] ' . get_class($e));
}
$ktHazir = kt_hazir($pdo);
$aktifDepo = function_exists('active_depot') ? active_depot() : null;
$sifreSayfasiAcik = muhasebe_sayfa_can('sifre_degistirme');

render_header('Kullanıcı Tercihleri');
echo '<link rel="stylesheet" href="assets/kullanici_ayar.css?v=' . (int)@filemtime(__DIR__ . '/assets/kullanici_ayar.css') . '">' . "\n";
?>
<div class="ka-sayfa">
    <h1 class="ka-baslik">🎛️ Kullanıcı Tercihleri</h1>
    <p class="ka-alt">Yalnız sizi etkileyen kişisel ayarlar. Başka kullanıcıların ekranı değişmez.</p>

    <?php render_flash(); ?>

    <div class="ka-izgara">
        <div>
            <!-- Profil -->
            <section class="ka-bolum">
                <div class="card">
                    <h2 class="ka-kart-baslik">👤 Profil</h2>
                    <form method="post" autocomplete="off">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="islem" value="profil_kaydet">
                        <div class="ka-alan">
                            <label class="form-label" for="kt_ad">Ad Soyad</label>
                            <input type="text" id="kt_ad" name="display_name" value="<?= h($profil['display_name']) ?>" maxlength="<?= KA_AD_MAX ?>" autocomplete="name">
                        </div>
                        <div class="ka-alan">
                            <label class="form-label" for="kt_eposta">E-posta</label>
                            <input type="email" id="kt_eposta" name="email" value="<?= h($profil['email']) ?>" maxlength="<?= KA_EPOSTA_MAX ?>" autocomplete="email">
                            <div class="ka-ipucu">Girişte kullanıcı adı yerine e-posta da kullanılabilir; başka bir kullanıcıda kayıtlı adres kabul edilmez.</div>
                        </div>
                        <dl class="ka-bilgi-ikili">
                            <dt>Kullanıcı adı</dt><dd><?= h((string)$auth_user['username']) ?> <span class="ka-ipucu">(yönetici değiştirir)</span></dd>
                            <dt>Rol</dt><dd><?= $roller ? h(implode(', ', $roller)) : '—' ?></dd>
                            <?php if ($sonGiris !== ''): ?><dt>Son giriş</dt><dd><?= h(date('d.m.Y H:i', (int)strtotime($sonGiris))) ?></dd><?php endif; ?>
                        </dl>
                        <div class="ka-eylem" style="margin-top:14px">
                            <button type="submit" class="btn btn-primary">Profili Kaydet</button>
                        </div>
                    </form>
                </div>
            </section>

            <!-- Tablo düzenleri -->
            <section class="ka-bolum">
                <div class="card">
                    <h2 class="ka-kart-baslik">📐 Tablo düzenleri</h2>
                    <p class="ka-ipucu" style="margin-top:0">Sütun genişliği, sırası ve gizli sütunlar ilgili sayfada ayarlanır; burada kayıtlı düzeni varsayılana döndürebilirsiniz.
                        <?= $ktHazir ? '' : 'Bu kurulumda düzenler yalnız bu tarayıcıda saklanır.' ?></p>
                    <?php foreach ($TABLOLAR as $anahtar => [$etiket, $yerel]):
                        $ozel = $ktHazir ? (kt_oku($pdo, $uid, $anahtar) !== null) : null; ?>
                        <div class="ka-satir">
                            <div>
                                <span class="ka-satir-yazi"><?= h($etiket) ?></span>
                                <?php if ($ozel === true): ?><span class="ka-rozet ozel">Özel düzen kayıtlı</span>
                                <?php elseif ($ozel === false): ?><span class="ka-rozet">Varsayılan</span><?php endif; ?>
                            </div>
                            <form method="post" data-ka-yerel="<?= h($yerel) ?>" data-ka-onay="<?= h($etiket) ?> tablo düzeni varsayılana döndürülsün mü?">
                                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                                <input type="hidden" name="islem" value="tablo_sifirla">
                                <input type="hidden" name="anahtar" value="<?= h($anahtar) ?>">
                                <button type="submit" class="btn btn-ghost btn-sm"<?= $ozel === false ? ' disabled' : '' ?>>Varsayılana dön</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <div>
            <!-- Görünüm -->
            <section class="ka-bolum">
                <div class="card">
                    <h2 class="ka-kart-baslik">🎨 Görünüm</h2>
                    <div class="form-label">Tema <span class="ka-ipucu">(bu cihaz için)</span></div>
                    <div class="ka-secenekler" role="group" aria-label="Tema">
                        <button type="button" class="ka-secenek" data-tema="acik">☀️ Açık</button>
                        <button type="button" class="ka-secenek" data-tema="koyu">🌙 Koyu</button>
                        <button type="button" class="ka-secenek" data-tema="sistem">🖥️ Sistem</button>
                    </div>
                    <div class="ka-ipucu" style="margin-top:8px">«Sistem» cihazınızın açık/koyu ayarını izler. Menüdeki tema düğmesiyle aynı ayardır.</div>
                </div>
            </section>

            <!-- Çalışma deposu -->
            <section class="ka-bolum">
                <div class="card">
                    <h2 class="ka-kart-baslik">🏭 Çalışma deposu</h2>
                    <?php if ($aktifDepo !== null && $aktifDepo !== ''): ?>
                        <p style="margin:0 0 10px">Aktif depo: <strong><?= h($aktifDepo) ?></strong></p>
                    <?php else: ?>
                        <p style="margin:0 0 10px" class="ka-ipucu">Aktif depo seçili değil.</p>
                    <?php endif; ?>
                    <a class="btn btn-ghost" href="depo_sec.php?next=<?= urlencode($_SERVER['REQUEST_URI'] ?? '/kullanici_tercihleri.php') ?>">Depo değiştir</a>
                    <div class="ka-ipucu" style="margin-top:8px">Listeler ve yeni kayıtlar seçili depoya göre çalışır.</div>
                </div>
            </section>

            <!-- Güvenlik -->
            <section class="ka-bolum">
                <div class="card">
                    <h2 class="ka-kart-baslik">🔐 Güvenlik</h2>
                    <div class="ka-eylem" style="margin-top:0">
                        <?php if ($sifreSayfasiAcik): ?><a class="btn btn-ghost" href="sifre_degistirme.php">Şifre değiştir</a><?php endif; ?>
                        <button type="button" class="btn btn-ghost" data-ka-pin>Ekran kilidi PIN'i</button>
                    </div>
                    <div class="ka-ipucu" style="margin-top:8px">PIN, masaüstünde 5 dakika hareketsizlikte ekranı kilitler; giriş şifrenizden ayrıdır.</div>
                    <div class="ka-not" data-ka-pin-not hidden>PIN yalnız masaüstü ekranında (fare ile kullanılan geniş ekran) tanımlanır ve değiştirilir.</div>
                </div>
            </section>
        </div>
    </div>
</div>
<script src="assets/kullanici_ayar.js?v=<?= (int)@filemtime(__DIR__ . '/assets/kullanici_ayar.js') ?>"></script>
<?php render_footer();
