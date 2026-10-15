<?php
// =========================================================
// ters_kart_havuz.php — Ters Kart Takip · A) KART HAVUZU VE AYARLAR (v354)
//
// Çavuş başına KALICI kart havuzu (bir kez okutulur, her gün yeniden
// okutulmaz) + sabit giriş saati (tarihli) + kart durumları (kayıp/bozuk/
// kullanım dışı/kullanıma al) + çıkar/aktar/sıfırla + geçmiş.
// Yazmaların hepsi config/pdks_ters.php'de (transaction + kart kilidi + audit).
// Yetki: attendance.worker_cards (Kart Havuzu ile aynı kapı; yeni yetki YOK).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_ters.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
$y = pdks_ters_require(['havuz']);

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
$base = base_url();
$hazir = pdks_ters_sema_hazir($pdo);
$depo = pdks_ters_aktif_depo();
$user = (int)$auth_user['id'];
$m = 'pdks_ters_metin';

// ── AJAX (POST JSON + CSRF) ──
$ajax = (string)($_GET['ajax'] ?? '');
if ($ajax !== '') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') pdks_ters_json(['ok' => false, 'hata' => 'Yalnız POST.'], 405);
    $g = pdks_ters_json_govde();
    if (!$hazir) pdks_ters_json(pdks_ters_red('sema_hazir_degil', 'Giriş Tanımlı kurulmamış.'));
    $fid = (int)$m($g['foreman_id'] ?? 0);
    if ($ajax === 'ekle') {
        pdks_ters_json(pdks_ters_havuz_ekle($m($g['ham_uid'] ?? ''), $m($g['kaynak'] ?? ''), $fid, (int)$m($g['worker_type_id'] ?? 0), $user, $pdo));
    }
    if ($ajax === 'islem') {
        $kid = (int)$m($g['kart_id'] ?? 0); $islem = $m($g['islem'] ?? ''); $ger = $m($g['gerekce'] ?? '');
        $r = match (true) {
            $islem === 'cikar' => pdks_ters_havuz_cikar($kid, $fid, $ger, $user, $pdo),
            $islem === 'aktar' => pdks_ters_havuz_aktar($kid, $fid, (int)$m($g['hedef_foreman_id'] ?? 0), $ger, $user, $pdo),
            isset(pdks_ters_kart_islemleri()[$islem]) => pdks_ters_kart_durum($kid, $fid, $islem, $ger, $user, $pdo),
            default => ['ok' => false, 'hata' => 'Bilinmeyen işlem.'],
        };
        pdks_ters_json($r);
    }
    if ($ajax === 'sifirla') pdks_ters_json(pdks_ters_havuz_sifirla($fid, $m($g['gerekce'] ?? ''), $user, $pdo));
    pdks_ters_json(['ok' => false, 'hata' => 'Bilinmeyen işlem.']);
}

$cavusId = (int)($_GET['cavus'] ?? $_POST['cavus'] ?? 0);

// ── Sabit giriş saati (POST form + CSRF, PRG) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form'] ?? '') === 'saat') {
    csrf_check($_POST['csrf'] ?? null);
    $r = $hazir ? pdks_ters_giris_saati_kaydet($cavusId, $m($_POST['giris_saati'] ?? ''), $m($_POST['valid_from'] ?? ''), $m($_POST['reason'] ?? ''), $user, $pdo)
               : pdks_ters_red('sema_hazir_degil', 'Giriş Tanımlı kurulmamış.');
    set_flash(empty($r['ok']) ? 'error' : 'success', empty($r['ok']) ? (string)$r['hata'] : 'Giriş saati kaydedildi: ' . $r['giris_saati'] . ' (' . date('d.m.Y', strtotime($r['valid_from'])) . ' itibarıyla).');
    header('Location: ters_kart_havuz.php?cavus=' . $cavusId);
    exit;
}

$cavuslar = $hazir ? pdks_ters_cavus_listesi($pdo) : [];
$cavus = ($hazir && $cavusId > 0) ? pdks_ters_cavus($pdo, $cavusId) : null;
$uyeler = $cavus ? pdks_ters_havuz_uyeleri($pdo, $cavusId, $depo) : [];
$oz = pdks_ters_havuz_ozet($uyeler);
$tipler = $hazir ? pdks_gunluk_desteklenen_tip_listele($pdo) : [];
$ayar = $cavus ? pdks_ters_giris_saati($pdo, $cavusId, $depo, date('Y-m-d')) : null;
$durumSinif = ['lost' => 'hata', 'disabled' => 'uyari', 'available' => 'ok'];

render_header('Kart Havuzu — Giriş Tanımlı');
pdks_ters_varliklar($base);
render_flash();
?>
<div class="tk-sayfa" data-tk-sayfa="havuz">
<div class="page-head">
    <h1>🗂 Giriş Tanımlı Havuzu</h1>
    <div class="page-head-actions">
        <a href="ters_kart.php" class="btn btn-geri">← Giriş Tanımlı</a>
    </div>
</div>
<?php pdks_ters_sekmeler('havuz', $y); ?>

<?php if (!pdks_ters_kurulum_uyarisi($pdo)): ?>
<form method="get" class="tk-filtre">
    <label class="tk-etiket">Çavuş
        <select name="cavus" data-tk-oto>
            <option value="">— Çavuş seçin —</option>
            <?php foreach ($cavuslar as $c): ?>
            <option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === $cavusId ? ' selected' : '' ?>><?= h($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <noscript><button type="submit" class="btn">Göster</button></noscript>
    <span class="muted">Depo: <strong><?= h($depo) ?></strong></span>
</form>

<?php if ($cavus): ?>
<div class="tk-sayaclar">
    <div class="tk-sayac"><span class="tk-sayac-n" data-tk-sayac="toplam"><?= $oz['toplam'] ?></span><span class="tk-sayac-et">Toplam envanter</span></div>
    <div class="tk-sayac tk-sayac-yesil"><span class="tk-sayac-n" data-tk-sayac="aktif"><?= $oz['aktif'] ?></span><span class="tk-sayac-et">Aktif</span></div>
    <div class="tk-sayac tk-sayac-kirmizi"><span class="tk-sayac-n" data-tk-sayac="kayip"><?= $oz['kayip'] ?></span><span class="tk-sayac-et">Kayıp</span></div>
    <div class="tk-sayac tk-sayac-turuncu"><span class="tk-sayac-n" data-tk-sayac="bozuk"><?= $oz['bozuk'] ?></span><span class="tk-sayac-et">Bozuk</span></div>
    <div class="tk-sayac tk-sayac-soluk"><span class="tk-sayac-n" data-tk-sayac="kullanim_disi"><?= $oz['kullanim_disi'] ?></span><span class="tk-sayac-et">Kullanım dışı</span></div>
    <?php
    // Tip bazında aktif kart sayısı — sayaç kartlarıyla aynı sırada (eski küçük "Aktif: Kadın N · Erkek M" satırının yerine).
    $tkTipKart = [];
    foreach ($tipler as $t) $tkTipKart[(string)$t['name']] = strtolower((string)$t['code']);
    foreach (array_keys($oz['tipler']) as $ad) if (!isset($tkTipKart[$ad])) $tkTipKart[$ad] = '';
    foreach ($tkTipKart as $ad => $kod): ?>
    <div class="tk-sayac tk-sayac-tip<?= $kod !== '' ? ' tk-sayac-tip-' . h($kod) : '' ?>"><span class="tk-sayac-n" data-tk-tip-sayac="<?= h($ad) ?>"><?= (int)($oz['tipler'][$ad] ?? 0) ?></span><span class="tk-sayac-et">Aktif <?= h($ad) ?></span></div>
    <?php endforeach; ?>
</div>

<div class="tk-iki-kolon">
<section class="tk-blok">
    <h3>Kart ekle (okut)</h3>
    <p class="muted tk-kucuk">Tip seçin, kartları art arda okutun. Her okutma anında kaydedilir; tanımsız kart havuza yeni kart olarak girer. Başka çavuşa ait ya da tanımlı kart reddedilir.</p>
    <div class="tk-tip-sec" role="group" aria-label="İşçi tipi">
        <?php foreach ($tipler as $t): ?>
        <button type="button" class="btn tk-tip-btn tk-tip-<?= h(strtolower((string)$t['code'])) ?>" data-tk-tip="<?= (int)$t['id'] ?>" aria-pressed="false"><?= h($t['name']) ?></button>
        <?php endforeach; ?>
    </div>
    <div class="tk-tara tk-tara-kucuk" role="group" aria-label="Kart okutma alanı">
        <div class="tk-tara-ikon" aria-hidden="true">💳</div>
        <div class="tk-tara-metin"><strong>Kartı okutun</strong><br><span class="muted" id="tkTipDurum"></span></div>
        <input type="text" id="tkScanInput" class="tk-gizli-input" inputmode="none" autocomplete="off" aria-label="Kart okuyucu girişi">
        <button type="button" id="tkNfcBtn" class="btn" hidden>📶 NFC ile oku</button>
        <div id="tkNfcDurum" class="muted tk-kucuk"></div>
    </div>
    <div id="tkSonuc" class="tk-sonuc" role="status" aria-live="polite" hidden></div>
    <ul id="tkEkleLog" class="tk-log"></ul>
    <button type="button" id="tkYenileBtn" class="btn btn-sm" hidden>↻ Listeyi yenile</button>
</section>

<section class="tk-blok">
    <h3>Sabit giriş saati</h3>
    <p>Bugün geçerli: <strong class="tk-saat"><?= $ayar ? h((string)$ayar['giris_saati']) : '—' ?></strong>
        <?= $ayar ? '<span class="muted tk-kucuk">(' . h(date('d.m.Y', strtotime((string)$ayar['valid_from']))) . ' itibarıyla)</span>' : '<span class="muted tk-kucuk">tanımsız — sabah onayında elle girilir</span>' ?></p>
    <form method="post" class="tk-form-satir">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="form" value="saat">
        <input type="hidden" name="cavus" value="<?= (int)$cavusId ?>">
        <label class="tk-etiket">Giriş saati <input type="time" name="giris_saati" step="60" required value="<?= $ayar ? h((string)$ayar['giris_saati']) : '' ?>"></label>
        <label class="tk-etiket">Geçerlilik başlangıcı <input type="date" name="valid_from" required min="<?= h(date('Y-m-d')) ?>" value="<?= h(date('Y-m-d')) ?>"></label>
        <label class="tk-etiket tk-genis">Açıklama <input type="text" name="reason" maxlength="255" placeholder="İsteğe bağlı"></label>
        <button type="submit" class="btn btn-primary">Kaydet</button>
    </form>
    <p class="muted tk-kucuk">Yeni saat seçilen günden itibaren geçerlidir; onaylanmış geçmiş sayımlar kendi saatini korur. Sabah onayında o gün için gerekçeyle değiştirilebilir.</p>
    <?php $gecmis = pdks_ters_giris_saati_gecmis($pdo, $cavusId, $depo); if ($gecmis): ?>
    <details><summary>Saat geçmişi (<?= count($gecmis) ?>)</summary>
        <ul class="tk-liste-sade">
        <?php foreach ($gecmis as $g): ?>
            <li><strong><?= h((string)$g['giris_saati']) ?></strong> · <?= h(date('d.m.Y', strtotime((string)$g['valid_from']))) ?> itibarıyla<?= !empty($g['reason']) ? ' · ' . h((string)$g['reason']) : '' ?><?= !empty($g['kullanici']) ? ' · ' . h((string)$g['kullanici']) : '' ?></li>
        <?php endforeach; ?>
        </ul>
    </details>
    <?php endif; ?>
</section>
</div>

<section class="tk-blok">
    <div class="tk-baslik-satir">
        <h3>Havuzdaki kartlar (<?= count($uyeler) ?>)</h3>
        <?php if ($uyeler): ?><button type="button" id="tkSifirlaBtn" class="btn btn-sm btn-danger">⟲ Havuzu sıfırla</button><?php endif; ?>
    </div>
    <?php if (!$uyeler): ?>
        <p class="muted">Havuz boş — yukarıdan kartları okutun.</p>
    <?php else: ?>
    <div class="tk-kart-liste">
        <?php foreach ($uyeler as $u): $kid = (int)$u['worker_card_id']; $no = (string)$u['card_no']; $durumAd = pdks_ters_kart_durum_adi((string)$u['status'], $u['durum_etiketi']); ?>
        <div class="tk-kart-satir">
            <span class="tk-kart-no"><?= h($no) ?></span>
            <span class="tk-kart-tip"><?= h((string)$u['tip_adi']) ?></span>
            <span class="tk-rozet tk-rozet-<?= h($durumSinif[(string)$u['status']] ?? 'ok') ?>" title="<?= h((string)($u['durum_notu'] ?? '')) ?>"><?= h($durumAd) ?></span>
            <span class="tk-kart-eylem">
                <?php if ($u['status'] === 'available'): ?>
                <button type="button" class="btn btn-sm" data-tk-islem="kayip" data-kart="<?= $kid ?>" data-no="<?= h($no) ?>">Kayıp</button>
                <button type="button" class="btn btn-sm" data-tk-islem="bozuk" data-kart="<?= $kid ?>" data-no="<?= h($no) ?>">Bozuk</button>
                <button type="button" class="btn btn-sm" data-tk-islem="kullanim_disi" data-kart="<?= $kid ?>" data-no="<?= h($no) ?>">Pasif</button>
                <?php else: ?>
                <button type="button" class="btn btn-sm btn-primary" data-tk-islem="kullanima_al" data-kart="<?= $kid ?>" data-no="<?= h($no) ?>">Kullanıma Al</button>
                <?php endif; ?>
                <button type="button" class="btn btn-sm" data-tk-islem="aktar" data-kart="<?= $kid ?>" data-no="<?= h($no) ?>">Aktar</button>
                <button type="button" class="btn btn-sm" data-tk-islem="cikar" data-kart="<?= $kid ?>" data-no="<?= h($no) ?>">Çıkar</button>
            </span>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<?php $hg = pdks_ters_havuz_gecmis($pdo, $cavusId, $depo, 100); if ($hg): ?>
<details class="tk-blok">
    <summary>Havuz geçmişi (son <?= count($hg) ?> kayıt)</summary>
    <div class="table-wrap">
    <table class="tk-tablo">
        <thead><tr><th>Kart</th><th>Tip</th><th>Eklendi</th><th>Çıktı</th><th>Neden</th></tr></thead>
        <tbody>
        <?php foreach ($hg as $g): ?>
            <tr><td><?= h((string)$g['card_no']) ?></td><td><?= h((string)$g['tip_adi']) ?></td>
                <td><?= h(date('d.m.Y H:i', strtotime((string)$g['valid_from']))) ?></td>
                <td><?= $g['valid_to'] ? h(date('d.m.Y H:i', strtotime((string)$g['valid_to']))) : '<span class="tk-rozet tk-rozet-ok">Aktif</span>' ?></td>
                <td><?= h((string)($g['end_reason'] ?? '')) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</details>
<?php endif; ?>

<script type="application/json" id="tkHavuzVeri"><?= json_encode(['foreman_id' => $cavusId], JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php
$aktarSecenek = '<div id="tkAktarAlan" hidden><label class="tk-etiket">Hedef çavuş <select id="tkAktarHedef">';
foreach ($cavuslar as $c) if ((int)$c['id'] !== $cavusId) $aktarSecenek .= '<option value="' . (int)$c['id'] . '">' . h($c['name']) . '</option>';
$aktarSecenek .= '</select></label></div>';
pdks_ters_dialog($aktarSecenek);
?>
<?php elseif ($cavusId > 0): ?>
    <div class="tk-kutu tk-kutu-hata">Çavuş bulunamadı.</div>
<?php endif; ?>
<?php endif; ?>
</div>
<?php
pdks_nfc_oku_js();
pdks_ters_script($base);
render_footer();
