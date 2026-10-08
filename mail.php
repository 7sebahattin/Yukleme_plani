<?php
// =========================================================
// mail.php — Mail Merkezi: gelen kutusu + okuyucu (M3)
//
// Kapı: can_mail('read') — sidebar / alt çubuk / index kartı / first_allowed_page ile AYNI fonksiyon.
// Her sorgu mail_gorunur_hesap_idleri() ile sınırlıdır (hesap ACL, fail-closed).
// Sayfa sunucuda çizilir; JS yalnız kolaylıktır (okundu işareti, odak). HTML mail,
// sandbox'lı iframe'de (script yok, same-origin yok, CSP default-src none) gösterilir.
// Durum değişiklikleri POST + CSRF; GET hiçbir şeyi değiştirmez.
// Cevap yazma / gönderme M5'tedir (burada düğme pasif).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/mail_core.php';
require_once __DIR__ . '/config/mail_imap.php';
require_once __DIR__ . '/config/mail_mime.php';
require_once __DIR__ . '/config/mail_sync.php';
require_once __DIR__ . '/config/mail_view.php';
require_once __DIR__ . '/config/mail_translate.php';
require_once __DIR__ . '/config/mail_smtp.php';
require_once __DIR__ . '/config/mail_outbox.php';
$auth_user = require_login();
require_mail('read');
mail_no_store();

$pdo      = db();
$uid      = (int)$auth_user['id'];
$hazir    = mail_sema_hazir($pdo);
$yonetici = can_mail('admin');
$cevapYetki = can_mail('reply');
$sendYetki = can_mail('send');
$hesapIds = $hazir ? mail_gorunur_hesap_idleri($uid, $pdo) : [];
$ceviriHazir = mail_ceviri_saglayici() !== null;   // sağlayıcı yapılandırılmış mı (kapalıysa hiçbir şey dışarı gitmez)

// ── Parametreler (hepsi doğrulanır; yabancı hesap id'si SESSİZCE yok sayılır) ──
$aSecili = (int)($_GET['a'] ?? $_POST['a'] ?? 0);
if ($aSecili !== 0 && !in_array($aSecili, $hesapIds, true)) $aSecili = 0;
$kapsam  = $aSecili ? [$aSecili] : $hesapIds;
$filtre  = mail_filtre_gecerli((string)($_GET['f'] ?? $_POST['f'] ?? 'gelen'));
$q       = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$sayfa   = max(1, (int)($_GET['p'] ?? 1));
$mId     = (int)($_GET['m'] ?? $_POST['m'] ?? 0);
$oId     = (int)($_GET['o'] ?? $_POST['o'] ?? 0);   // giden kaydı (cevap taslağı/durumu)
$cevapMod = $cevapYetki && ($_GET['cevap'] ?? '') === '1';
$sekme   = ($_GET['v'] ?? '') === 'orj' ? 'orj' : (($_GET['v'] ?? '') === 'tr' ? 'tr' : '');
$uzakGorsel = ($_GET['img'] ?? '') === '1';

$url = static function (array $ek = []) use ($aSecili, $filtre, $q, $sayfa, $mId, $oId): string {
    $p = array_merge(['a' => $aSecili ?: null, 'f' => $filtre !== 'gelen' ? $filtre : null, 'q' => $q !== '' ? $q : null, 'p' => $sayfa > 1 ? $sayfa : null, 'm' => $mId ?: null, 'o' => $oId ?: null], $ek);
    $p = array_filter($p, static fn($v) => $v !== null && $v !== '' && $v !== 0);
    return 'mail.php' . ($p ? '?' . http_build_query($p) : '');
};

// ── POST (CSRF + yetki; sonra PRG) ──
if ($hazir && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    $islem = (string)($_POST['islem'] ?? '');
    $ajax  = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    $sn = mail_post_isle($pdo, ['uid' => $uid, 'hesapIds' => $hesapIds, 'yonetici' => $yonetici, 'cevap' => $cevapYetki, 'send' => $sendYetki,
        'a' => $aSecili, 'm' => $mId, 'o' => $oId], $islem, $_POST);
    if ($sn['yasak'] !== null) forbidden($sn['yasak']);
    $ok = $sn['ok']; $mesaj = $sn['mesaj'];
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'mesaj' => $mesaj], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($mesaj !== '') set_flash($ok ? 'success' : 'error', $mesaj);
    header('Location: ' . $url($sn['o'] ? ['o' => $sn['o'], 'cevap' => null] : ['o' => null, 'cevap' => null]));
    exit;
}

// ── Veri ──
$liste = ['satirlar' => [], 'toplam' => 0]; $hataliMesajlar = []; $sayilar = array_fill_keys(array_keys(mail_filtreler()), 0);
$hesapSatirlari = []; $okunmamis = []; $durumlar = []; $m = null; $thread = []; $oPanel = null;
if ($hazir && $hesapIds) {
    $in = implode(',', array_fill(0, count($hesapIds), '?'));
    $st = $pdo->prepare("SELECT id, label, email, is_active FROM mail_accounts WHERE id IN ($in) ORDER BY label");
    $st->execute($hesapIds);
    $hesapSatirlari = $st->fetchAll(PDO::FETCH_ASSOC);
    $okunmamis = mail_okunmamis_sayilari($uid, $pdo);
    $durumlar  = mail_senkron_durumlari($pdo, $hesapIds);
    $sayilar   = mail_filtre_sayilari($pdo, $kapsam);
    if (mail_filtre_outbox_mu($filtre)) {
        $liste = mail_outbox_listele($pdo, $kapsam, $filtre, $q, $sayfa);
        if ($filtre === 'hatali') $hataliMesajlar = mail_ceviri_hatalilari($pdo, $kapsam);
    } else {
        $liste = mail_mesaj_listele($pdo, $kapsam, $filtre, $q, $sayfa);
    }
    if ($mId > 0) {
        $m = mail_mesaj_getir($pdo, $mId, $hesapIds);   // ACL: görünür değilse null
        if ($m) $thread = mail_thread_mesajlari($pdo, $m);
    }
    if ($oId > 0) {
        mail_outbox_takili_isaretle($pdo);               // 'sending'de takılı kalanlar → 'unknown' (otomatik tekrar YOK)
        $oPanel = mail_outbox_getir($pdo, $oId, $hesapIds);   // ACL: görünür hesap değilse null
    }
}
$etiketler = [];
foreach ($hesapSatirlari as $h) $etiketler[(int)$h['id']] = $h['label'];
$sayfaSayisi = max(1, (int)ceil($liste['toplam'] / MAIL_SAYFA_BOYUTU));
$outboxModu = mail_filtre_outbox_mu($filtre);
$durumEtiket = mail_outbox_durumlari();

render_header('Mail Merkezi');
mail_assets();
?>
<div class="mail-kap">
<?php render_flash(); ?>
<div class="mail <?= ($m || $oPanel) ? 'mail--detay' : 'mail--liste' ?>" data-mail>

<?php if (!$hazir): ?>
    <div class="card" style="padding:16px">
        <p><strong>Mail Merkezi henüz kurulmadı.</strong> Veritabanı tabloları oluşturulmamış.</p>
        <?php if (is_admin()): ?><p><a class="btn btn-primary" href="migrate.php">Şema Migrasyonu (migrate.php)</a></p>
        <?php else: ?><p>Sistem yöneticisinden kurulumu istemeniz gerekir.</p><?php endif; ?>
    </div>
<?php elseif (!$hesapIds): ?>
    <div class="card" style="padding:16px">
        <?php if ($yonetici): ?>
            <p>Henüz mail hesabı tanımlı değil.</p>
            <p><a class="btn btn-primary" href="mail_hesaplar.php">Hesap Ekle</a></p>
        <?php else: ?>
            <p>Size atanmış bir mail hesabı yok. Sistem yöneticisinden hesap ataması isteyin.</p>
        <?php endif; ?>
    </div>
<?php else: ?>

    <?php if (!mail_crypto_hazir() && $yonetici): ?>
    <div class="mail-uyari"><strong>Şifreleme anahtarı tanımlı değil.</strong> Senkron çalışmaz. <code>config/local.php</code> içine <code>MAIL_MASTER_KEY</code> ekleyin.</div>
    <?php endif; ?>

    <div class="mail-ust">
        <h1 class="mail-baslik">📧 Mail Merkezi</h1>
        <div class="mail-ust-eylem">
            <?php if ($yonetici): ?>
            <?php if ($aSecili): ?>
            <form method="post" class="mail-satir-form">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="islem" value="senkron">
                <input type="hidden" name="a" value="<?= $aSecili ?>"><input type="hidden" name="f" value="<?= h($filtre) ?>">
                <button class="btn" type="submit" title="Seçili hesabı şimdi senkronla">⟳ Şimdi senkronla</button>
            </form>
            <?php endif; ?>
            <a class="btn" href="mail_hesaplar.php">Hesaplar</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="mail-sol">
    <nav class="mail-hesaplar" aria-label="Hesaplar">
        <a class="mail-chip<?= $aSecili === 0 ? ' aktif' : '' ?>" href="mail.php<?= $filtre !== 'gelen' ? '?f=' . h($filtre) : '' ?>">Tümü
            <?php if (array_sum($okunmamis) > 0): ?><span class="mail-rozet"><?= (int)array_sum($okunmamis) ?></span><?php endif; ?></a>
        <?php foreach ($hesapSatirlari as $h): $hid = (int)$h['id']; $du = $durumlar[$hid] ?? null; ?>
        <a class="mail-chip<?= $aSecili === $hid ? ' aktif' : '' ?><?= (int)$h['is_active'] !== 1 ? ' pasif' : '' ?>"
           href="mail.php?a=<?= $hid ?><?= $filtre !== 'gelen' ? '&amp;f=' . h($filtre) : '' ?>" title="<?= h($h['email']) ?>">
            <?= h($h['label']) ?>
            <?php if (!empty($okunmamis[$hid])): ?><span class="mail-rozet"><?= (int)$okunmamis[$hid] ?></span><?php endif; ?>
            <?php if ($yonetici && $du && !empty($du['last_error'])): ?><span class="mail-hata-nokta" title="Son senkron hatası: <?= h($du['last_error']) ?>">!</span><?php endif; ?>
        </a>
        <?php endforeach; ?>
    </nav>
    <?php if ($aSecili && isset($durumlar[$aSecili])): $du = $durumlar[$aSecili]; ?>
    <div class="mail-senkron-not">Son başarılı senkron: <?= $du['last_ok_at'] ? h(mail_zaman_fmt($du['last_ok_at'])) : 'henüz yok' ?>
        <?php if ($yonetici && !empty($du['last_error'])): ?> · <span class="mail-hata-metin">Hata: <?= h($du['last_error']) ?></span><?php endif; ?></div>
    <?php endif; ?>

    <nav class="mail-filtreler" aria-label="Klasörler">
        <?php foreach (mail_filtreler() as $fk => $fe): ?>
        <a class="mail-chip<?= $filtre === $fk ? ' aktif' : '' ?>" href="<?= h($url(['f' => $fk !== 'gelen' ? $fk : null, 'p' => null, 'm' => null])) ?>">
            <?= h($fe) ?><?php if ($sayilar[$fk] > 0): ?><span class="mail-say"><?= (int)$sayilar[$fk] ?></span><?php endif; ?></a>
        <?php endforeach; ?>
    </nav>
    </div>

    <form class="mail-ara" method="get" action="mail.php" role="search">
        <?php if ($aSecili): ?><input type="hidden" name="a" value="<?= $aSecili ?>"><?php endif; ?>
        <?php if ($filtre !== 'gelen'): ?><input type="hidden" name="f" value="<?= h($filtre) ?>"><?php endif; ?>
        <input type="search" name="q" value="<?= h($q) ?>" maxlength="100" placeholder="Konu veya gönderen ara…" aria-label="Ara">
        <button class="btn" type="submit">Ara</button>
        <?php if ($q !== ''): ?><a class="btn" href="<?= h($url(['q' => null, 'p' => null])) ?>">Temizle</a><?php endif; ?>
    </form>

    <div class="mail-panes">
        <section class="mail-liste" aria-label="Mesajlar">
        <?php if ($outboxModu): ?>
            <?php foreach ($liste['satirlar'] as $o): ?>
            <a class="mail-oge mail-oge--giden<?= $oPanel && (int)$oPanel['id'] === (int)$o['id'] ? ' secili' : '' ?>" href="<?= h($url(['o' => (int)$o['id'], 'm' => null, 'cevap' => null])) ?>">
                <div class="mail-oge-ust"><span class="mail-kim">→ <?= h($o['to_addr']) ?></span><span class="mail-zaman"><?= h(mail_zaman_fmt($o['zaman'])) ?></span></div>
                <div class="mail-konu"><?= h($o['subject']) ?></div>
                <div class="mail-oz"><span class="mail-durum mail-durum--<?= h($o['status']) ?>"><?= h($durumEtiket[$o['status']] ?? $o['status']) ?></span>
                    <?php if (!empty($o['last_error'])): ?> <span class="mail-hata-metin"><?= h($o['last_error']) ?></span><?php endif; ?>
                    <?php if (count($etiketler) > 1): ?> · <?= h($etiketler[(int)$o['account_id']] ?? '') ?><?php endif; ?></div>
            </a>
            <?php endforeach; ?>
            <?php if ($filtre === 'hatali' && $hataliMesajlar): ?>
            <h3 class="mail-alt-baslik">Çevirisi başarısız gelen mailler</h3>
            <?php foreach ($hataliMesajlar as $hm): ?>
            <a class="mail-oge" href="<?= h($url(['m' => (int)$hm['id'], 'f' => 'gelen'])) ?>">
                <div class="mail-oge-ust"><span class="mail-kim"><?= h(mail_gonderen_adi($hm)) ?></span><span class="mail-zaman"><?= h(mail_zaman_fmt($hm['received_at'])) ?></span></div>
                <div class="mail-konu"><?= h($hm['subject'] ?: '(konu yok)') ?></div>
                <div class="mail-oz"><span class="mail-hata-metin"><?= h($hm['tr_error'] ?: 'Çeviri başarısız') ?></span></div>
            </a>
            <?php endforeach; endif; ?>
            <?php if (!$liste['satirlar'] && !$hataliMesajlar): ?><p class="mail-bos">Bu klasörde kayıt yok.</p><?php endif; ?>
        <?php else: ?>
            <?php foreach ($liste['satirlar'] as $r): ?>
            <a class="mail-oge<?= (int)$r['is_read'] === 0 ? ' okunmamis' : '' ?><?= $m && (int)$m['id'] === (int)$r['id'] ? ' secili' : '' ?>"
               href="<?= h($url(['m' => (int)$r['id']])) ?>"<?= $m && (int)$m['id'] === (int)$r['id'] ? ' aria-current="true"' : '' ?>>
                <div class="mail-oge-ust">
                    <span class="mail-kim"><?php if ((int)$r['is_read'] === 0): ?><i class="mail-nokta" aria-label="Okunmamış"></i><?php endif; ?><?= h(mail_gonderen_adi($r)) ?></span>
                    <span class="mail-zaman"><?= h(mail_zaman_fmt($r['received_at'])) ?></span>
                </div>
                <div class="mail-konu"><?= h($r['subject'] ?: '(konu yok)') ?><?php if ((int)$r['has_attachments'] === 1): ?> <span class="mail-ek-ikon" title="Ek var">📎</span><?php endif; ?></div>
                <div class="mail-oz"><?= h(mail_oz($r['ozet'])) ?>
                    <?php if (count($etiketler) > 1): ?><span class="mail-hesap-etiket"><?= h($etiketler[(int)$r['account_id']] ?? '') ?></span><?php endif; ?>
                    <?php if ($r['tr_status'] === 'failed'): ?><span class="mail-durum mail-durum--failed">çeviri hatası</span><?php endif; ?>
                    <?php if ($r['tr_status'] === 'pending'): ?><span class="mail-durum mail-durum--pending">çeviri bekliyor</span><?php endif; ?></div>
            </a>
            <?php endforeach; ?>
            <?php if (!$liste['satirlar']): ?><p class="mail-bos"><?= $q !== '' ? 'Aramayla eşleşen mail yok.' : 'Bu klasörde mail yok.' ?></p><?php endif; ?>
        <?php endif; ?>
        <?php if ($sayfaSayisi > 1): ?>
            <nav class="mail-sayfalar" aria-label="Sayfalar">
                <?php if ($sayfa > 1): ?><a class="btn" href="<?= h($url(['p' => $sayfa - 1, 'm' => null])) ?>">‹ Yeni</a><?php endif; ?>
                <span><?= $sayfa ?> / <?= $sayfaSayisi ?></span>
                <?php if ($sayfa < $sayfaSayisi): ?><a class="btn" href="<?= h($url(['p' => $sayfa + 1, 'm' => null])) ?>">Eski ›</a><?php endif; ?>
            </nav>
        <?php endif; ?>
        </section>

        <section class="mail-okuyucu" aria-label="Mesaj" <?= $m && !$oPanel && !$cevapMod && (int)$m['is_read'] === 0 ? 'data-okundu-gonder="' . (int)$m['id'] . '"' : '' ?>>
        <?php if ($oPanel): ?>
            <?php
            $os = (string)$oPanel['status']; $ebeveyn = $oPanel['in_reply_to_msg_id'] ? mail_mesaj_getir($pdo, (int)$oPanel['in_reply_to_msg_id'], $hesapIds) : null;
            $dilAd = mail_diller()[(string)$oPanel['target_lang']] ?? strtoupper((string)$oPanel['target_lang']);
            $duzenlenebilir = in_array($os, ['draft', 'translated'], true);
            $gKimlik = mail_outbox_hesap_kimligi($pdo, (int)$oPanel['account_id']);
            $rtFarkli = $ebeveyn && trim((string)$ebeveyn['reply_to_addr']) !== '' && strcasecmp(trim((string)$ebeveyn['reply_to_addr']), trim((string)$ebeveyn['from_addr'])) !== 0 && strcasecmp(trim((string)$oPanel['to_addr']), trim((string)$ebeveyn['reply_to_addr'])) === 0;
            $ortakAlan = static function (int $oid) use ($aSecili, $filtre): string {
                return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '"><input type="hidden" name="o" value="' . $oid . '"><input type="hidden" name="a" value="' . $aSecili . '"><input type="hidden" name="f" value="' . h($filtre) . '">';
            };
            ?>
            <div class="mail-okuyucu-arac"><a class="btn btn-geri mail-geri" href="<?= h($url(['o' => null, 'm' => null])) ?>">← Liste</a>
                <?php if ($ebeveyn): ?><a class="btn" href="<?= h($url(['o' => null, 'm' => (int)$ebeveyn['id'], 'cevap' => null])) ?>">Cevaplanan maili aç</a><?php endif; ?></div>
            <h2 class="mail-okuyucu-konu">Cevap: <?= h($oPanel['subject']) ?></h2>
            <p><span class="mail-durum mail-durum--<?= h($os) ?>"><?= h($durumEtiket[$os] ?? $os) ?></span></p>
            <dl class="mail-meta">
                <dt>Gönderen</dt><dd><?= h(trim(($gKimlik['display_name'] !== '' ? $gKimlik['display_name'] . ' ' : '') . '<' . $gKimlik['email'] . '>')) ?></dd>
                <?php if (trim((string)$gKimlik['reply_to']) !== ''): ?><dt>Reply-To</dt><dd><?= h($gKimlik['reply_to']) ?> <span class="mail-bilgi">(müşterinin yanıtı bu adrese gelir)</span></dd><?php endif; ?>
                <dt>Alıcı</dt><dd><?= h($oPanel['to_addr']) ?></dd>
                <dt>Hedef dil</dt><dd><?= h($dilAd) ?><?php if ($oPanel['tr_provider']): ?> · çeviri: <?= h($oPanel['tr_provider'] === 'manual' ? 'elle girildi' : ($oPanel['tr_provider'] === 'none' ? 'çeviri yok' : $oPanel['tr_provider'] . ' (üçüncü taraf servis)')) ?><?php endif; ?></dd>
                <?php if ($oPanel['out_message_id'] && in_array($os, ['sent', 'unknown', 'sending', 'approved'], true)): ?><dt>Message-ID</dt><dd><code><?= h($oPanel['out_message_id']) ?></code></dd><?php endif; ?>
            </dl>
            <?php if ($rtFarkli): ?><div class="mail-uyari"><strong>Dikkat — Reply-To farklı:</strong> müşterinin mesajı <code><?= h($ebeveyn['from_addr']) ?></code> adresinden geldi ama <code>Reply-To</code> başlığı <code><?= h($ebeveyn['reply_to_addr']) ?></code> gösteriyor; cevap <strong><?= h($oPanel['to_addr']) ?></strong> adresine gidecek. Göndermeden önce adresin doğru kişiye ait olduğundan emin olun.</div><?php endif; ?>
            <?php if (!empty($oPanel['last_error']) && $os !== 'translated'): ?><div class="mail-uyari"><?= h($oPanel['last_error']) ?></div><?php endif; ?>

            <div class="mail-onay-kutular">
                <div class="mail-onay-kutu"><h3>TÜRKÇE ORİJİNAL CEVAP</h3><div class="mail-metin mail-onay-metin"><?= nl2br(h((string)$oPanel['body_tr'])) ?></div></div>
                <div class="mail-onay-kutu mail-onay-kutu--cikis"><h3>GÖNDERİLECEK ÇEVİRİ (<?= h($dilAd) ?>)</h3>
                    <?php if (trim((string)$oPanel['body_out']) !== ''): ?>
                    <div class="mail-metin mail-onay-metin" lang="<?= h($oPanel['target_lang']) ?>"><?= nl2br(h((string)$oPanel['body_out'])) ?></div>
                    <?php if ($oPanel['quote_text']): ?><details class="mail-thread" <?= $os === 'translated' ? 'open' : '' ?>><summary>Altına eklenecek alıntı (müşterinin orijinal yazısı)</summary><pre class="mail-metin mail-metin--ham"><?= h((string)$oPanel['quote_text']) ?></pre></details><?php endif; ?>
                    <?php else: ?><p class="mail-bos">Henüz çeviri yok.</p><?php endif; ?>
                </div>
            </div>

            <?php if ($os === 'translated'): ?>
                <?php if ($sendYetki): ?>
                <form method="post" class="mail-onay-form" data-tek-gonderim>
                    <?= $ortakAlan((int)$oPanel['id']) ?><input type="hidden" name="hash" value="<?= h((string)$oPanel['content_hash']) ?>">
                    <p class="mail-onay-not">Onayladığınızda yukarıdaki <strong>GÖNDERİLECEK ÇEVİRİ</strong>, <strong><?= h($oPanel['to_addr']) ?></strong> adresine hesabın kendi adresinden gönderilir. Bu işlem geri alınamaz.</p>
                    <input type="hidden" name="islem" value="cevap_onayla">
                    <button class="btn btn-primary mail-onayla" type="submit">✅ Onayla ve Gönder</button>
                </form>
                <?php else: ?>
                <div class="mail-bilgi">Göndermek için <code>mail.send</code> yetkisi gerekir. Bu taslak <strong>Taslak / Bekleyen</strong> klasöründe yetkili birinin onayını bekler.</div>
                <?php endif; ?>
            <?php elseif ($os === 'approved' && $sendYetki): ?>
                <form method="post" class="mail-satir-form" data-tek-gonderim><?= $ortakAlan((int)$oPanel['id']) ?>
                    <p class="mail-onay-not">Onaylanmış ama gönderim tamamlanmamış. Gönder'e basmak güvenlidir (kayıt atomik sahiplenilir, en fazla bir kez gider).</p>
                    <input type="hidden" name="islem" value="cevap_gonder_onayli"><button class="btn btn-primary" type="submit">Gönder</button></form>
            <?php elseif ($os === 'failed' && $sendYetki): ?>
                <form method="post" class="mail-satir-form" data-tek-gonderim><?= $ortakAlan((int)$oPanel['id']) ?>
                    <p class="mail-onay-not">Mesaj sunucu tarafından kabul EDİLMEDİ; aynı onaylı içerik ve aynı Message-ID ile yeniden denenebilir.</p>
                    <input type="hidden" name="islem" value="cevap_tekrar"><button class="btn btn-primary" type="submit">Tekrar dene</button></form>
            <?php elseif ($os === 'unknown'): ?>
                <div class="mail-uyari"><strong>Belirsiz durum:</strong> mesaj sunucuya iletilmiş olabilir. Sistem OTOMATİK TEKRAR GÖNDERMEZ. Gönderilenler klasörünü ya da müşteriyi kontrol edip karar verin.</div>
                <?php if ($sendYetki): ?>
                <form method="post" class="mail-satir-form"><?= $ortakAlan((int)$oPanel['id']) ?>
                    <button class="btn" name="islem" value="cevap_belirsiz_gonderildi" type="submit">Gönderildi (doğruladım)</button>
                    <button class="btn" name="islem" value="cevap_belirsiz_gonderilmedi" type="submit">Gönderilmedi — tekrar denemeye izin ver</button></form>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($duzenlenebilir && $cevapYetki): ?>
            <details class="mail-thread" <?= $os === 'draft' ? 'open' : '' ?>><summary>Metni düzenle / çeviriyi yeniden üret</summary>
                <form method="post" class="mail-yaz">
                    <?= $ortakAlan((int)$oPanel['id']) ?><input type="hidden" name="quote" value="0">
                    <label for="mail-tr-duzenle">Türkçe cevap</label>
                    <textarea id="mail-tr-duzenle" name="body_tr" rows="6" maxlength="<?= MAIL_CEVAP_MAX ?>" required><?= h((string)$oPanel['body_tr']) ?></textarea>
                    <label for="mail-dil-duzenle">Hedef dil</label>
                    <select id="mail-dil-duzenle" name="target_lang"><?php foreach (mail_diller() as $kd => $ad): ?><option value="<?= h($kd) ?>"<?= $kd === $oPanel['target_lang'] ? ' selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select>
                    <label for="mail-cikis-duzenle">Gönderilecek çeviri (elle düzeltmek isterseniz yazın)</label>
                    <textarea id="mail-cikis-duzenle" name="body_out_manual" rows="6" maxlength="<?= MAIL_CEVAP_MAX ?>"><?= h((string)$oPanel['body_out']) ?></textarea>
                    <label class="mail-onay-secenek"><input type="checkbox" name="quote" value="1"<?= (int)$oPanel['quote_original'] === 1 ? ' checked' : '' ?>> Müşterinin orijinal yazısını altına alıntı olarak ekle</label>
                    <input type="hidden" name="islem" value="cevap_onizle">
                    <div class="mail-satir-form">
                        <button class="btn" name="mod" value="ceviri" type="submit">Çeviriyi yeniden üret</button>
                        <button class="btn" name="mod" value="manuel" type="submit">Düzenlediğim çeviriyi kullan</button>
                    </div>
                </form>
            </details>
            <?php endif; ?>
            <?php if (in_array($os, ['draft', 'translated', 'approved', 'failed'], true) && ($cevapYetki || $sendYetki)): ?>
            <form method="post" class="mail-satir-form" style="margin-top:10px"><?= $ortakAlan((int)$oPanel['id']) ?><button class="btn" name="islem" value="cevap_iptal" type="submit">Cevabı iptal et</button></form>
            <?php endif; ?>
        <?php elseif ($m && $cevapMod): ?>
            <div class="mail-okuyucu-arac"><a class="btn btn-geri" href="<?= h($url(['cevap' => null])) ?>">← Mesaja dön</a></div>
            <h2 class="mail-okuyucu-konu">Cevap yaz: <?= h(mail_yanit_konusu((string)$m['subject'])) ?></h2>
            <p class="mail-bilgi">Cevabınızı <strong>Türkçe</strong> yazın. Bir sonraki adımda çeviriyi görüp onaylayacaksınız; <strong>onaylamadan hiçbir şey gönderilmez</strong>. Alıcı: <?= h($m['reply_to_addr'] ?: $m['from_addr']) ?></p>
            <form method="post" class="mail-yaz" data-tek-gonderim>
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="m" value="<?= (int)$m['id'] ?>"><input type="hidden" name="a" value="<?= $aSecili ?>"><input type="hidden" name="f" value="<?= h($filtre) ?>">
                <input type="hidden" name="idem" value="<?= h(bin2hex(random_bytes(16))) ?>"><input type="hidden" name="quote" value="0">
                <?php if ($ceviriHazir): ?>
                <p class="mail-ceviri-not">Çeviriyi Önizle dediğinizde Türkçe cevap metniniz <strong><?= h(mail_ceviri_saglayici()->ad()) ?></strong> adlı üçüncü taraf servise çeviri için gönderilir; müşteriye e-posta yalnız ayrıca onaylarsanız gönderilir.</p>
                <?php endif; ?>
                <label for="mail-tr">Türkçe cevap</label>
                <textarea id="mail-tr" name="body_tr" rows="8" maxlength="<?= MAIL_CEVAP_MAX ?>" required autofocus></textarea>
                <label for="mail-dil">Hedef dil</label>
                <select id="mail-dil" name="target_lang"><?php $vd = ($m['lang'] && isset(mail_diller()[$m['lang']])) ? $m['lang'] : 'en'; foreach (mail_diller() as $kd => $ad): ?><option value="<?= h($kd) ?>"<?= $kd === $vd ? ' selected' : '' ?>><?= h($ad) ?></option><?php endforeach; ?></select>
                <label class="mail-onay-secenek"><input type="checkbox" name="quote" value="1" checked> Müşterinin orijinal yazısını altına alıntı olarak ekle</label>
                <input type="hidden" name="islem" value="cevap_onizle">
                <button class="btn btn-primary" type="submit">Çeviriyi Önizle</button>
            </form>
        <?php elseif (!$m): ?>
            <div class="mail-okuyucu-bos"><p>Okumak için soldan bir mail seçin.</p></div>
        <?php else:
            $trVar = $m['tr_status'] === 'translated' && trim((string)$m['body_tr']) !== '';
            $aktif = $sekme !== '' ? $sekme : ($trVar ? 'tr' : 'orj');
            $uzakVar = $m['body_html_safe'] !== null && str_contains((string)$m['body_html_safe'], 'data-blocked-src');
        ?>
            <div class="mail-okuyucu-arac">
                <a class="btn btn-geri mail-geri" href="<?= h($url(['m' => null])) ?>">← Liste</a>
                <form method="post" class="mail-satir-form">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="m" value="<?= (int)$m['id'] ?>">
                    <input type="hidden" name="a" value="<?= $aSecili ?>"><input type="hidden" name="f" value="<?= h($filtre) ?>">
                    <button class="btn" name="islem" value="<?= (int)$m['is_read'] === 1 ? 'okunmadi' : 'oku' ?>" type="submit"><?= (int)$m['is_read'] === 1 ? 'Okunmadı yap' : 'Okundu yap' ?></button>
                    <?php if ($cevapYetki): ?>
                    <button class="btn" name="islem" value="<?= $m['replied_at'] === null && (int)$m['needs_reply'] === 1 ? 'cevaplandi' : 'cevap_bekliyor' ?>" type="submit">
                        <?= $m['replied_at'] === null && (int)$m['needs_reply'] === 1 ? 'Cevaplandı say' : 'Cevap bekliyor say' ?></button>
                    <?php endif; ?>
                </form>
            </div>

            <h2 class="mail-okuyucu-konu"><?= h($m['subject'] ?: '(konu yok)') ?></h2>
            <dl class="mail-meta">
                <dt>Kimden</dt><dd><?= h(mail_gonderen_adi($m)) ?><?php if ($m['from_name'] !== '' && $m['from_addr'] !== ''): ?> &lt;<?= h($m['from_addr']) ?>&gt;<?php endif; ?></dd>
                <dt>Kime</dt><dd><?= h(implode(', ', array_map(static fn($a) => ($a['name'] !== '' ? $a['name'] . ' ' : '') . '<' . $a['email'] . '>', $m['to_list']))) ?></dd>
                <?php if ($m['cc_list']): ?><dt>Cc</dt><dd><?= h(implode(', ', array_map(static fn($a) => $a['email'], $m['cc_list']))) ?></dd><?php endif; ?>
                <dt>Tarih</dt><dd><?= h(date('d.m.Y H:i', strtotime((string)$m['received_at']))) ?> · <?= h($etiketler[(int)$m['account_id']] ?? '') ?></dd>
            </dl>

            <?php if ($m['ekler']): ?>
            <ul class="mail-ekler" aria-label="Ekler">
                <?php foreach ($m['ekler'] as $e): $teh = mail_ek_tehlikeli((string)$e['filename'], (string)$e['mime']); ?>
                <li>📎 <a href="mail_ek.php?m=<?= (int)$m['id'] ?>&amp;p=<?= h((string)$e['part']) ?>"<?= $teh ? ' data-tehlikeli="1"' : '' ?>><?= h($e['filename']) ?></a>
                    <span class="mail-ek-boyut"><?= h(mail_boyut_fmt((int)$e['size'])) ?></span>
                    <?php if ($teh): ?><span class="mail-durum mail-durum--failed" title="Çalıştırılabilir ya da aktif içerik olabilir">⚠ riskli tür</span><?php endif; ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <div class="mail-sekmeler" role="tablist">
                <a role="tab" aria-selected="<?= $aktif === 'tr' ? 'true' : 'false' ?>" class="<?= $aktif === 'tr' ? 'aktif' : '' ?>" href="<?= h($url(['v' => 'tr'])) ?>">Türkçe</a>
                <a role="tab" aria-selected="<?= $aktif === 'orj' ? 'true' : 'false' ?>" class="<?= $aktif === 'orj' ? 'aktif' : '' ?>" href="<?= h($url(['v' => 'orj'])) ?>">Orijinal<?= $m['lang'] ? ' (' . h(strtoupper((string)$m['lang'])) . ')' : '' ?></a>
            </div>

            <?php if ((int)$m['body_truncated'] === 1): ?><div class="mail-uyari">Bu mail çok büyük olduğu için içeriğin yalnız bir kısmı alındı.</div><?php endif; ?>

            <?php if ($aktif === 'tr'): ?>
                <?php if ($trVar): ?>
                <?php if ($ceviriHazir): ?><div class="mail-ceviri-not">Çeviri <?= h(mail_ceviri_saglayici()->ad()) ?> (üçüncü taraf servis) ile üretildi — mail metni bu servise gönderildi.</div><?php endif; ?>
                <div class="mail-metin"><?php if (trim((string)$m['subject_tr']) !== ''): ?><strong><?= h($m['subject_tr']) ?></strong><br><br><?php endif; ?><?= nl2br(h($m['body_tr'])) ?></div>
                <?php else: ?>
                <div class="mail-bilgi"><?= h(mail_ceviri_durum_etiketi((string)$m['tr_status'])) ?>.
                    <?php if ($m['tr_status'] === 'failed' && $m['tr_error']): ?><span class="mail-hata-metin"><?= h($m['tr_error']) ?></span><?php endif; ?>
                    Orijinal metin <a href="<?= h($url(['v' => 'orj'])) ?>">Orijinal</a> sekmesinde okunabilir.
                    <?php if ($ceviriHazir && mail_hesap_ceviri_acik($pdo, (int)$m['account_id']) && in_array($m['tr_status'], ['skipped', 'failed', 'pending'], true)): ?>
                    <span class="mail-uclu-taraf">⚠ Çevirirseniz mail metni (adres/başlık/ek hariç) <strong><?= h(mail_ceviri_saglayici()->ad()) ?></strong> adlı ÜÇÜNCÜ TARAF çeviri servisine gönderilir.</span>
                    <form method="post" class="mail-satir-form">
                        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="m" value="<?= (int)$m['id'] ?>">
                        <input type="hidden" name="a" value="<?= $aSecili ?>"><input type="hidden" name="f" value="<?= h($filtre) ?>">
                        <button class="btn" name="islem" value="ceviri_simdi" type="submit" title="Mail metni yapılandırılmış çeviri servisine gönderilir"><?= $m['tr_status'] === 'failed' ? 'Tekrar dene' : 'Şimdi çevir' ?></button>
                    </form>
                    <?php endif; ?></div>
                <?php endif; ?>
            <?php else: ?>
                <?php if ($m['body_html_safe'] !== null && $m['body_html_safe'] !== ''): ?>
                    <?php if ($uzakVar && !$uzakGorsel): ?>
                    <div class="mail-bilgi">Uzak görseller engellendi (izleme pikseli olabilir).
                        <a class="btn" href="<?= h($url(['v' => 'orj', 'img' => 1])) ?>">Görselleri göster</a></div>
                    <?php endif; ?>
                    <iframe class="mail-govde" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" loading="lazy"
                            title="Mail içeriği" srcdoc="<?= h(mail_html_iframe_srcdoc((string)$m['body_html_safe'], $uzakGorsel)) ?>"></iframe>
                <?php else: ?>
                    <pre class="mail-metin mail-metin--ham"><?= h($m['body_text']) ?></pre>
                <?php endif; ?>
            <?php endif; ?>

            <?php if ($thread): ?>
            <details class="mail-thread"><summary>Konuşmadaki diğer mailler (<?= count($thread) ?>)</summary>
                <ul><?php foreach ($thread as $t): ?>
                    <li><a href="<?= h($url(['m' => (int)$t['id'], 'v' => null])) ?>"><?= h(mail_gonderen_adi($t)) ?> — <?= h($t['subject'] ?: '(konu yok)') ?></a>
                        <span class="mail-zaman"><?= h(mail_zaman_fmt($t['received_at'])) ?></span></li>
                <?php endforeach; ?></ul>
            </details>
            <?php endif; ?>

            <div class="mail-cevapbar">
                <?php if ($cevapYetki): ?>
                <a class="btn btn-primary mail-cevapla" href="<?= h($url(['cevap' => 1, 'o' => null])) ?>">✍ Cevapla</a>
                <span class="mail-cevapbar-not">Türkçe yaz → çeviriyi görüp onayla → gönder</span>
                <?php else: ?>
                <span class="mail-cevapbar-not">Cevap yazmak için mail.reply yetkisi gerekir.</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        </section>
    </div>
<?php endif; ?>

</div>
</div>
<?php mail_scripts(); render_footer(); ?>
