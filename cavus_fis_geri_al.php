<?php
// =========================================================
// cavus_fis_geri_al.php — Çavuşun Fiş Yüklemelerini Geri Al (v464)
//
// Bir çavuşun, seçilen tarih aralığındaki FİŞ EXCEL'İ kaynaklı kayıtlarını (+ fiş etiketli servisleri) SOFT-iptal eder.
// YALNIZ yönetici + oturumdaki kullanıcının GİRİŞ ŞİFRESİ (config/sifre_onay.php; ekran kilidi/PIN'den bağımsız). HARD DELETE YOK:
// dönemler pdks_faz8j_toplu_geri_al, servisler pdks_servis_iptal ile iptal edilir (silinmez). Kiosk / elle / ters / tanımlı
// kayıtlara DOKUNULMAZ; kesinleşmiş hakedişli ve başka depodaki mesailer atlanır ve raporlanır.
//
// Akış (PRG): GET form → POST eylem=onizle (salt okunur döküm → ?onizle=TOKEN) → POST eylem=uygula (token + şifre + çavuş kodu
// + CSRF → ?sonuc=TOKEN) · POST eylem=iptal. Tüm mantık config/pdks_fis.php "J. DÜZELTME".
// Kesinleşmiş/ödemeli günler için "ek hakediş / düzeltme" akışı bu sürümde YOK.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/pdks_faz8j.php';
require_once __DIR__ . '/config/pdks_fis.php';
require_once __DIR__ . '/config/sifre_onay.php';
$auth_user = require_login();
require_pdks_gunluk('daily_reports');
if (!function_exists('is_admin') || !is_admin()) forbidden('Fiş yüklemelerini geri alma yalnız yönetici içindir.');
header('Cache-Control: no-store');   // kişisel/ticari veri: tarayıcı ve SW önbelleğine yazılmaz
$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_faz8b_sayfa_kapisi($pdo);
$uid = (int)$auth_user['id'];
$depo = function_exists('active_depot') ? (string)(active_depot() ?? '') : '';
$self = 'cavus_fis_geri_al.php';
$cavuslar = pdks_fis_cavuslar($pdo);
$cavusAd = static function (int $id) use ($cavuslar): ?array { foreach ($cavuslar as $c) if ($c['id'] === $id) return $c; return null; };
$sifirlaHazir = pdks_faz8j_sema_hazir($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $eylem = (string)($_POST['eylem'] ?? '');

    if ($eylem === 'iptal') {
        pdks_fis_sifirla_token_sil((string)($_POST['token'] ?? ''));
        set_flash('success', 'İşlem kapatıldı; hiçbir kayıt değiştirilmedi.');
        header('Location: ' . $self);
        exit;
    }

    if ($eylem === 'onizle') {
        $cid = (int)($_POST['cavus_id'] ?? 0);
        $bas = trim((string)($_POST['bas'] ?? '')); $bit = trim((string)($_POST['bit'] ?? ''));
        $gerekce = pdks_fis_metin_duz($_POST['gerekce'] ?? '', PDKS_FIS_USTUNE_GEREKCE_MAX);
        $geri = $self . ($cid > 0 ? '?cavus=' . $cid : '');
        if ($depo === '') { set_flash('error', 'Önce bir depo seçmelisiniz.'); header('Location: ' . $self); exit; }
        if ($cavusAd($cid) === null) { set_flash('error', 'Çavuş seçin.'); header('Location: ' . $self); exit; }
        if ($gerekce === '') { set_flash('error', 'Gerekçe zorunludur.'); header('Location: ' . $geri); exit; }
        $on = pdks_fis_sifirla_onizle($pdo, $cid, $bas, $bit, $depo);
        if ($on['hata'] !== null) { set_flash('error', (string)$on['hata']); header('Location: ' . $geri); exit; }
        $token = pdks_fis_sifirla_token_olustur(['foreman_id' => $cid, 'bas' => $bas, 'bit' => $bit, 'gerekce' => $gerekce,
                                                 'istek_id' => bin2hex(random_bytes(16)), 'hash' => (string)$on['hash']]);
        session_write_close();   // yönlendirmeden ÖNCE: sonraki GET oturum yazımını beklemeden token'ı görsün
        header('Location: ' . $self . '?onizle=' . $token);
        exit;
    }

    if ($eylem === 'uygula') {
        $token = (string)($_POST['token'] ?? '');
        $yuk = pdks_fis_sifirla_token_al($token, $tokenNeden);
        if ($yuk === null) { error_log('[fis_sifirla] uygula: token ' . ($tokenNeden ?? '?')); set_flash('error', pdks_fis_token_hata_metni($tokenNeden)); header('Location: ' . $self); exit; }
        $onizleUrl = $self . '?onizle=' . $token;
        $cid = (int)$yuk['foreman_id'];
        $c = $cavusAd($cid);
        if ($c === null) { set_flash('error', 'Çavuş bulunamadı.'); header('Location: ' . $self); exit; }
        if (pdks_fis_fold(trim((string)($_POST['cavus_kod'] ?? ''))) !== pdks_fis_fold($c['code'])) {
            set_flash('error', 'Onay için çavuş kodunu aynen yazın.'); header('Location: ' . $onizleUrl); exit;
        }
        // Önizleme = uygulama: kullanıcının gördüğü döküm ile şimdiki durum aynı olmalı.
        $on = pdks_fis_sifirla_onizle($pdo, $cid, (string)$yuk['bas'], (string)$yuk['bit'], $depo);
        if ($on['hata'] !== null || !hash_equals((string)$yuk['hash'], (string)$on['hash'])) {
            pdks_fis_sifirla_token_sil($token);
            set_flash('error', 'Önizlemeden sonra mesai kayıtları değişti — hiçbir şey yapılmadı. Yeniden önizleyin.');
            header('Location: ' . $self . '?cavus=' . $cid); exit;
        }
        if (!$on['mesailer']) { set_flash('error', 'Geri alınacak fiş kaydı kalmadı.'); header('Location: ' . $onizleUrl); exit; }
        // Şifre: oturumdaki kullanıcının giriş şifresi (kullanıcı kimliği oturumdan; istemciden ALINMAZ). Yanlışsa HİÇBİR ŞEY yapılmaz.
        $sf = sifre_onay_dogrula($pdo, $uid, (string)($_POST['sifre'] ?? ''), 'fis_sifirla');
        if (empty($sf['ok'])) { set_flash('error', (string)$sf['mesaj']); session_write_close(); header('Location: ' . $onizleUrl); exit; }
        $sonuc = pdks_fis_sifirla_uygula($pdo, $cid, (string)$yuk['bas'], (string)$yuk['bit'], (string)$yuk['gerekce'], (string)$yuk['istek_id'], $uid, $depo);
        pdks_fis_sifirla_token_sil($token);
        if (!empty($sonuc['tekrar'])) { set_flash('error', 'Bu işlem zaten yapılmış (tekrar gönderim).'); header('Location: ' . $self); exit; }
        if (!$sonuc['ok'] && $sonuc['mesailer'] === []) { set_flash('error', (string)($sonuc['hata'] ?? 'İşlem yapılamadı.')); header('Location: ' . $self . '?cavus=' . $cid); exit; }
        $sk = bin2hex(random_bytes(16));
        $skKayit = ['t' => time(), 'uid' => $uid, 'depo' => $depo, 'sonuc' => $sonuc, 'cavus' => $c['code'] . ' · ' . $c['name'], 'bas' => (string)$yuk['bas'], 'bit' => (string)$yuk['bit'], 'cavus_id' => $cid];
        $_SESSION['fis_sifirla_sonuc'] = [$sk => $skKayit];
        pdks_fis_kv_yaz(PDKS_FIS_KV_SIFIRLA_SONUC, $sk, $skKayit);   // kayıtlar DEĞİŞTİ; sonuç ekranı oturum kaybında da gösterilsin
        session_write_close();
        header('Location: ' . $self . '?sonuc=' . $sk);
        exit;
    }
    set_flash('error', 'Geçersiz istek.');
    header('Location: ' . $self);
    exit;
}

// ── GET ──────────────────────────────────────────────────────────────
$mod = 'form'; $on = null; $yuk = null; $sk = null; $onizleToken = (string)($_GET['onizle'] ?? ''); $sonucToken = (string)($_GET['sonuc'] ?? '');
if ($onizleToken !== '') {
    $yuk = pdks_fis_sifirla_token_al($onizleToken, $tokenNeden);
    if ($yuk === null) { error_log('[fis_sifirla] onizle: token ' . ($tokenNeden ?? '?')); set_flash('error', pdks_fis_token_hata_metni($tokenNeden)); header('Location: ' . $self); exit; }
    $on = pdks_fis_sifirla_onizle($pdo, (int)$yuk['foreman_id'], (string)$yuk['bas'], (string)$yuk['bit'], $depo);
    $mod = 'onizle';
} elseif ($sonucToken !== '') {
    $sk = $_SESSION['fis_sifirla_sonuc'][$sonucToken] ?? pdks_fis_kv_oku(PDKS_FIS_KV_SIFIRLA_SONUC, $sonucToken);
    if (!preg_match('/^[a-f0-9]{32}$/D', $sonucToken) || !is_array($sk) || (int)$sk['uid'] !== $uid || (string)$sk['depo'] !== $depo || time() - (int)$sk['t'] > PDKS_FIS_TOKEN_TTL) {
        set_flash('error', 'Sonuç süresi doldu.'); header('Location: ' . $self); exit;
    }
    $mod = 'sonuc';
}
$cavusOnsec = (int)($_GET['cavus'] ?? 0);
$bugun = date('Y-m-d');
$varBas = date('Y-m-d', strtotime('-30 day')); $varBit = date('Y-m-d', strtotime('-1 day'));
$fd_tarih = static fn($t) => $t ? date('d.m.Y', strtotime((string)$t)) : '—';

render_header('Fiş Yüklemelerini Geri Al');
$base = base_url();
echo '<link rel="stylesheet" href="' . $base . 'assets/pdks.css?v=' . @filemtime(__DIR__ . '/assets/pdks.css') . '">';
render_flash();
?>
<div class="page-head">
    <h1>↩ Fiş Yüklemelerini Geri Al</h1>
    <div class="page-head-actions">
        <a href="cavus_fis_rapor.php" class="btn">📋 Etkilenen fişler</a>
        <a href="gunluk_fis_yukle.php" class="btn btn-geri">← Fiş Excel'i Yükle</a>
    </div>
</div>

<div class="fy-sayfa fd-sayfa" id="fisGeriAl">
<?php if ($depo === ''): ?>
    <div class="card"><p>Önce bir <b>depo</b> seçin (üst menüdeki depo göstergesi).</p></div>
<?php elseif (!$sifirlaHazir): ?>
    <div class="card"><p>Faz 8J şeması hazır değil (migrate.php).</p></div>
<?php elseif ($mod === 'sonuc'): $s = $sk['sonuc']; $oz = $s['ozet']; ?>
    <div class="fy-ozet" role="status">
        <span><b><?= h((string)$sk['cavus']) ?></b> · <?= h($fd_tarih($sk['bas'])) ?> – <?= h($fd_tarih($sk['bit'])) ?></span>
        <span class="fy-b-hazir"><b><?= (int)$oz['mesai'] ?></b> mesai işlendi</span>
        <span><b><?= (int)$oz['donem'] ?></b> dönem iptal edildi</span>
        <span><b><?= (int)$oz['servis'] ?></b> servis iptal edildi</span>
        <span class="fy-b-hata"><b><?= (int)$oz['hatali'] ?></b> hatalı</span>
        <span class="fy-b-uyari"><b><?= count($s['atlanan']) ?></b> atlandı</span>
    </div>
    <p class="fy-yonerge">Kayıtlar <b>silinmedi</b>: iptal edildi (Mesai Detayı'nda «iptal» olarak görünür, gerekçe ve kullanıcı audit'te). Kiosk / elle / ters kayıtlara dokunulmadı.</p>
    <div class="table-wrap">
    <table class="fy-tablo fd-tablo">
        <thead><tr><th>Tarih</th><th>Fiş</th><th>İptal dönem</th><th>İptal servis</th><th>Durum</th><th>Mesai</th></tr></thead>
        <tbody>
        <?php foreach ($s['mesailer'] as $r): ?>
            <tr class="<?= $r['hata'] !== '' ? 'fy-s-hata' : '' ?>">
                <td><?= h($fd_tarih($r['tarih'])) ?></td>
                <td><?= h(implode(', ', $r['fis_nolari']) ?: '—') ?></td>
                <td><?= (int)$r['iptal_donem'] ?></td>
                <td><?= (int)$r['iptal_servis'] ?></td>
                <td><?php if ($r['hata'] === ''): ?><span class="fy-rozet fy-b-hazir">✅ Tamam</span><?php else: ?><span class="fy-rozet fy-b-hata">❌ Hata</span><div class="fy-not fy-not-hata"><?= h((string)$r['hata']) ?></div><?php endif; ?></td>
                <td><a class="btn" href="gunluk_isci_puantaj_detay.php?id=<?= (int)$r['session_id'] ?>">Mesai Detayı</a></td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($s['atlanan'] as $r): ?>
            <tr class="fy-s-engel">
                <td><?= h($fd_tarih($r['tarih'])) ?></td>
                <td><?= h(implode(', ', $r['fis_nolari']) ?: '—') ?></td>
                <td>—</td><td>—</td>
                <td><span class="fy-rozet fy-b-engel">⛔ Atlandı</span><div class="fy-not fy-not-engel"><?= h((string)$r['neden']) ?></div></td>
                <td><a class="btn" href="gunluk_isci_puantaj_detay.php?id=<?= (int)$r['session_id'] ?>">Mesai Detayı</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if ((int)$oz['hatali'] > 0): ?><p class="fy-yonerge">Hatalı mesailer için aynı işlemi yeniden çalıştırın: iptal edilmiş kayıtlar tekrar işlenmez, kalan kısım tamamlanır.</p><?php endif; ?>
    <p class="fy-yonerge">Yeniden yükleme: aynı fiş numarası için Excel'de <b>Revizyon</b>'u artırın ya da Fiş Excel'i Yükle'de <b>«Üstüne yaz»</b> kullanın (revizyon otomatik artar).</p>
    <p><a class="btn btn-primary" href="<?= h($self) ?>">↩ Yeni işlem</a> <a class="btn" href="cavus_fis_rapor.php">📋 Etkilenen fişler</a></p>

<?php elseif ($mod === 'onizle'): $c = $cavusAd((int)$yuk['foreman_id']); ?>
    <div class="fy-ust" role="note">
        <span>Çavuş: <b><?= h($c ? $c['code'] . ' · ' . $c['name'] : '#' . (int)$yuk['foreman_id']) ?></b></span>
        <span>Aralık: <b><?= h($fd_tarih($yuk['bas'])) ?> – <?= h($fd_tarih($yuk['bit'])) ?></b></span>
        <span>Depo: <b><?= h($depo) ?></b></span>
        <span>Gerekçe: <b><?= h((string)$yuk['gerekce']) ?></b></span>
    </div>
    <div class="fy-ozet" role="status">
        <span class="fy-b-hazir"><b><?= (int)$on['toplam']['mesai'] ?></b> mesai</span>
        <span><b><?= (int)$on['toplam']['toplu'] ?></b> fiş yüklemesi</span>
        <span><b><?= (int)$on['toplam']['donem'] ?></b> dönem iptal edilecek</span>
        <span><b><?= (int)$on['toplam']['servis'] ?></b> servis iptal edilecek</span>
        <span class="fy-b-uyari"><b><?= count($on['atlanan']) ?></b> atlanacak</span>
    </div>
    <?php if ($on['odeme_var']): ?><div class="fy-not fy-not-uyari">⚠ Bu çavuşun geçerli ödemesi var: <b>kesinleşmiş hakedişli günler DEĞİŞTİRİLEMEZ</b> (aşağıda «atlanacak» olarak görünür). Ek hakediş / düzeltme akışı bu sürümde yok.</div><?php endif; ?>
    <div class="fy-not fy-not-uyari">ℹ Yalnız <b>Fiş Excel'i</b> ile yazılmış kayıtlar iptal edilir. Kiosk / elle / ters / tanımlı girişlere <b>dokunulmaz</b>. Kayıtlar silinmez (soft iptal).</div>

    <?php if ($on['mesailer']): ?>
    <div class="table-wrap">
    <table class="fy-tablo fd-tablo">
        <thead><tr><th>Tarih</th><th>Fiş no</th><th>Yükleme</th><th>İptal dönem</th><th>İptal servis</th><th>Fiş dışı (korunur)</th><th>Mesai</th></tr></thead>
        <tbody>
        <?php foreach ($on['mesailer'] as $r): ?>
            <tr>
                <td><?= h($fd_tarih($r['tarih'])) ?></td>
                <td><?= h(implode(', ', $r['fis_nolari']) ?: '—') ?></td>
                <td><?= (int)$r['toplu'] ?></td>
                <td><?= (int)$r['donem'] ?></td>
                <td><?= (int)$r['servis'] ?></td>
                <td><?= (int)$r['fis_disi'] ?></td>
                <td><a class="btn" href="gunluk_isci_puantaj_detay.php?id=<?= (int)$r['session_id'] ?>">Mesai Detayı</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php else: ?>
    <div class="card"><p>Bu aralıkta geri alınacak fiş kaydı yok.</p></div>
    <?php endif; ?>

    <?php if ($on['atlanan']): ?>
    <h2 class="fd-alt-baslik">Atlanacak mesailer</h2>
    <div class="table-wrap">
    <table class="fy-tablo fd-tablo">
        <thead><tr><th>Tarih</th><th>Fiş no</th><th>Neden</th><th>Mesai</th></tr></thead>
        <tbody>
        <?php foreach ($on['atlanan'] as $r): ?>
            <tr class="fy-s-engel">
                <td><?= h($fd_tarih($r['tarih'])) ?></td>
                <td><?= h(implode(', ', $r['fis_nolari']) ?: '—') ?></td>
                <td><?= h((string)$r['neden']) ?></td>
                <td><a class="btn" href="gunluk_isci_puantaj_detay.php?id=<?= (int)$r['session_id'] ?>">Mesai Detayı</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>

    <?php if ($on['mesailer']): ?>
    <form method="post" action="<?= h($self) ?>" class="fy-form fd-onay" autocomplete="off" onsubmit="return confirm('Seçili aralıktaki fiş kayıtları iptal edilecek. Emin misiniz?')">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="eylem" value="uygula">
        <input type="hidden" name="token" value="<?= h($onizleToken) ?>">
        <h2 class="fd-alt-baslik">Onay</h2>
        <div class="form-group">
            <label for="fdKod">Çavuş kodunu yazın: <b><?= h($c ? $c['code'] : '') ?></b></label>
            <input type="text" id="fdKod" name="cavus_kod" required autocomplete="off" autocapitalize="characters" spellcheck="false">
        </div>
        <div class="form-group">
            <label for="fdSifre">Giriş şifreniz</label>
            <input type="password" id="fdSifre" name="sifre" required autocomplete="current-password">
        </div>
        <div class="fy-eylem">
            <button type="submit" class="btn btn-primary" id="fdUygula">↩ Fiş yüklemelerini geri al</button>
        </div>
    </form>
    <?php endif; ?>
    <form method="post" action="<?= h($self) ?>" class="fy-vazgec">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="eylem" value="iptal">
        <input type="hidden" name="token" value="<?= h($onizleToken) ?>">
        <button type="submit" class="btn">Vazgeç</button>
    </form>

<?php else: ?>
    <div class="card fy-kart-adim">
        <h2>Çavuş ve tarih aralığı</h2>
        <p>Yanlış yorumlanmış / hatalı yüklenmiş fişleri toptan geri almak içindir. Önce <b>döküm</b> gösterilir; hiçbir şey değişmez. Uygulamak için <b>giriş şifreniz</b> istenir.
           Çoğu düzeltme için Fiş Excel'i Yükle'deki <b>«Üstüne yaz»</b> yeterlidir.</p>
        <form method="post" action="<?= h($self) ?>" class="fy-form" id="fdForm">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="eylem" value="onizle">
            <div class="fy-secimler">
                <div class="form-group">
                    <label for="fdCavus">Çavuş</label>
                    <select id="fdCavus" name="cavus_id" required>
                        <option value="">— Çavuş seçin —</option>
                        <?php foreach ($cavuslar as $c): ?><option value="<?= (int)$c['id'] ?>"<?= $c['id'] === $cavusOnsec ? ' selected' : '' ?>><?= h($c['code'] . ' · ' . $c['name']) ?><?= $c['is_active'] ? '' : ' (pasif)' ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="fdBas">Başlangıç</label>
                    <input type="date" id="fdBas" name="bas" value="<?= h($varBas) ?>" max="<?= h($varBit) ?>" required>
                </div>
                <div class="form-group">
                    <label for="fdBit">Bitiş</label>
                    <input type="date" id="fdBit" name="bit" value="<?= h($varBit) ?>" max="<?= h($varBit) ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label for="fdGerekce">Gerekçe (zorunlu)</label>
                <textarea id="fdGerekce" name="gerekce" rows="3" maxlength="<?= (int)PDKS_FIS_USTUNE_GEREKCE_MAX ?>" required placeholder="Örn. «12+1» eski kuralla yanlış yorumlandı"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">🔍 Dökümü göster</button>
        </form>
        <p class="fy-yonerge">Aralık en çok <?= (int)PDKS_FIS_SIFIRLA_GUN_UST ?> gün; yalnız geçmiş günler. Kesinleşmiş hakedişli günler her zaman atlanır.</p>
    </div>
<?php endif; ?>
</div>
<?php render_footer(); ?>
