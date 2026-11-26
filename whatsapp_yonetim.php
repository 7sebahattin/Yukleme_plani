<?php
// =========================================================
// whatsapp_yonetim.php — WhatsApp Yükleme Planı: Bağlantı · Grup seçimi · AI test · Canlı mesaj günlüğü
// Yalnız yönetici (Yönetim grubu). Çekirdek: config/whatsapp_core.php · Evolution istemcisi: config/WhatsAppService.php ·
// AI: config/AIParserService.php · Şema: docs/sql/whatsapp_migration.sql (açık GO ile; yoksa kurulum notu gösterilir).
//
// Tüm POST'lar CSRF'lidir. AJAX uçları aynı dosyada (`ajax=` alanı) JSON döner; Evolution/AI hataları yakalanıp
// kullanıcıya mesaj olarak verilir (API anahtarı / ham yanıt SIZMAZ).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/whatsapp_core.php';
$auth_user = require_login();
if (!is_admin()) { forbidden('Bu sayfa yalnızca sistem yöneticilerine açıktır.'); }

$pdo    = db();
$uid    = (int)($auth_user['id'] ?? 0);
$hazir  = whatsapp_tablolar_hazir($pdo);
$ayar   = $hazir ? whatsapp_ayarlar_oku($pdo) : whatsapp_ayarlar_oku_yok();

/** Tablo yokken de sayfa açılsın diye sabitlerden türeyen varsayılan ayar. */
function whatsapp_ayarlar_oku_yok(): array
{
    $g = [];
    foreach ((array)ALLOWED_GROUP_JIDS as $jid) if (is_string($jid)) $g[] = ['jid' => $jid, 'ad' => $jid];
    return ['instance_adi' => (string)EVOLUTION_INSTANCE_NAME, 'secili_gruplar' => $g, 'ai_saglayici' => (string)AI_PROVIDER, 'otomatik_taslak' => true, 'kayitli' => false];
}

function wa_json(array $d, int $kod = 200): void
{
    http_response_code($kod);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Durum rozeti için etiket + sınıf. */
function wa_durum_etiket(string $d, ?string $planDurum): array
{
    return match (true) {
        $d === 'aktarildi' && $planDurum === 'taslak' => ['Taslak (onay bekliyor)', 'bekliyor'],
        $d === 'aktarildi'  => ['Plana aktarıldı', 'tamam'],
        $d === 'hazir'      => ['Hazır', 'hazir'],
        $d === 'eksik_bilgi'=> ['Eksik bilgi', 'eksik'],
        $d === 'alakasiz'   => ['Alakasız', 'pasif'],
        $d === 'ai_hata'    => ['AI hatası', 'hata'],
        $d === 'isleniyor'  => ['İşleniyor…', 'bekliyor'],
        default             => [$d, 'pasif'],
    };
}

/** Son mesajlar tablosu (sunucuda üretilir, AJAX yenilemesi de bunu döner; tüm değerler h() ile). */
function wa_log_tablosu(array $rows, array $ayar): string
{
    if ($rows === []) return '<div class="wa-bos">Henüz kayıtlı mesaj yok. Bir WhatsApp grubunda araç/yükleme içeren bir mesaj yazıldığında burada görünür.</div>';
    $grupAd = [];
    foreach ($ayar['secili_gruplar'] as $g) $grupAd[$g['jid']] = $g['ad'];
    ob_start();
    ?>
    <div class="table-wrap"><table class="data-table wa-log">
        <thead><tr>
            <th>Zaman</th><th>Gönderen</th><th>Mesaj</th><th>Plaka</th><th>Firma / Yer</th><th>Tonaj</th><th>Tarih</th><th class="num">Güven</th><th>Durum</th><th>İşlem</th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $r):
            $p = $r['parsed'] ?? [];
            [$et, $sn] = wa_durum_etiket((string)$r['durum'], $r['plan_durum'] ?? null);
            $yer = trim(implode(' → ', array_filter([(string)($p['yukleme_yeri'] ?? ''), (string)($p['bosaltma_yeri'] ?? '')], 'strlen')));
            $grup = $grupAd[$r['remote_jid']] ?? '';
            $eksik = is_array($p['eksik_bilgiler'] ?? null) ? implode(', ', $p['eksik_bilgiler']) : '';
        ?>
        <tr data-log="<?= (int)$r['id'] ?>">
            <td data-et="Zaman" class="wa-zaman"><?= h(date('d.m H:i', strtotime((string)$r['created_at']) ?: 0)) ?></td>
            <td data-et="Gönderen"><strong><?= h($r['sender_name'] !== '' ? $r['sender_name'] : '—') ?></strong>
                <?php if ($r['sender_phone'] !== ''): ?><div class="wa-ikincil"><?= h($r['sender_phone']) ?></div><?php endif; ?>
                <?php if ($grup !== ''): ?><div class="wa-ikincil">👥 <?= h($grup) ?></div><?php endif; ?></td>
            <td data-et="Mesaj" class="wa-mesaj" title="<?= h($r['raw_message']) ?>"><?= h(mb_strimwidth((string)$r['raw_message'], 0, 220, '…', 'UTF-8')) ?></td>
            <td data-et="Plaka"><?php if (!empty($p['plaka'])): ?><span class="wa-plaka"><?= h($p['plaka']) ?></span><?php else: ?>—<?php endif; ?>
                <?php if (!empty($p['dorse_plaka'])): ?><div class="wa-ikincil">Dorse: <?= h($p['dorse_plaka']) ?></div><?php endif; ?>
                <?php if (!empty($p['sofor_adi'])): ?><div class="wa-ikincil"><?= h($p['sofor_adi']) ?></div><?php endif; ?></td>
            <td data-et="Firma / Yer"><?= h((string)($p['firma_adi'] ?? '') !== '' ? $p['firma_adi'] : '—') ?>
                <?php if ($yer !== ''): ?><div class="wa-ikincil"><?= h($yer) ?></div><?php endif; ?>
                <?php if (!empty($p['malzeme_cinsi'])): ?><div class="wa-ikincil"><?= h($p['malzeme_cinsi']) ?></div><?php endif; ?></td>
            <td data-et="Tonaj"><?= isset($p['tonaj']) && $p['tonaj'] !== null ? h(rtrim(rtrim(number_format((float)$p['tonaj'], 3, ',', '.'), '0'), ',')) . ' t' : '—' ?></td>
            <td data-et="Tarih"><?= !empty($p['yukleme_tarihi']) ? h(date('d.m.Y', strtotime((string)$p['yukleme_tarihi']))) : '—' ?></td>
            <td data-et="Güven" class="num"><?= $p ? (int)$r['guven_skoru'] . '%' : '—' ?></td>
            <td data-et="Durum"><span class="wa-rozet wa-<?= h($sn) ?>"><?= h($et) ?></span>
                <?php if ($r['durum'] === 'ai_hata' && (string)$r['hata_mesaji'] !== ''): ?><div class="wa-ikincil wa-hata-not"><?= h($r['hata_mesaji']) ?></div><?php endif; ?>
                <?php if ($eksik !== '' && in_array($r['durum'], ['eksik_bilgi', 'hazir'], true)): ?><div class="wa-ikincil">Eksik: <?= h($eksik) ?></div><?php endif; ?></td>
            <td data-et="İşlem" class="wa-islem">
                <?php if (empty($r['loading_record_id']) && in_array($r['durum'], ['eksik_bilgi', 'hazir'], true)): ?>
                    <button type="button" class="btn btn-sm btn-primary" data-wa-eylem="aktar" data-id="<?= (int)$r['id'] ?>">Yükleme Planına Aktar</button>
                <?php endif; ?>
                <?php if (in_array($r['durum'], ['ai_hata', 'isleniyor', 'eksik_bilgi'], true)): ?>
                    <button type="button" class="btn btn-sm btn-ghost" data-wa-eylem="yeniden" data-id="<?= (int)$r['id'] ?>">Yeniden ayrıştır</button>
                <?php endif; ?>
                <?php if (!empty($r['loading_record_id'])): ?>
                    <?php if (($r['plan_durum'] ?? null) === 'taslak'): ?>
                        <button type="button" class="btn btn-sm btn-primary" data-wa-eylem="onayla" data-id="<?= (int)$r['id'] ?>">Onayla</button>
                    <?php endif; ?>
                    <a class="btn btn-sm btn-ghost" href="record_view.php?id=<?= (int)$r['loading_record_id'] ?>">Plana git</a>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php
    return (string)ob_get_clean();
}

// ── AJAX uçları (POST + CSRF) ─────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');   // csrf_check JSON hata dönsün
    csrf_check($_POST['csrf'] ?? null);
    $islem = (string)$_POST['ajax'];

    try {
        $svc = WhatsAppService::fromConfig($ayar['instance_adi']);

        if ($islem === 'durum') {                       // 5 sn'de bir: ucuz durum sorgusu (+ istenirse QR)
            $durum = $svc->getConnectionState();
            $qr = null;
            if ($durum !== 'open' && !empty($_POST['qr'])) $qr = $svc->getQrCode();
            wa_json(['ok' => true, 'durum' => $durum, 'qr' => $qr]);
        }

        if ($islem === 'kur') {                         // oturum yoksa oluştur + QR
            $once = $svc->getConnectionState();
            $qr = $once === 'open' ? null : $svc->getQrCode();
            whatsapp_denetle('whatsapp_oturum', 'whatsapp', null, null, ['instance' => $ayar['instance_adi'], 'onceki_durum' => $once]);
            wa_json(['ok' => true, 'durum' => $svc->getConnectionState(), 'qr' => $qr]);
        }

        if ($islem === 'gruplar') {
            wa_json(['ok' => true, 'gruplar' => $svc->fetchGroups()]);
        }

        if ($islem === 'test') {                        // WhatsApp'a dokunmadan AI ayrıştırma simülasyonu (DB'ye YAZMAZ)
            $metin = trim((string)($_POST['metin'] ?? ''));
            if ($metin === '') wa_json(['ok' => false, 'hata' => 'Test metni boş.'], 422);
            $ai = AIParserService::fromConfig($ayar['ai_saglayici']);
            $gurultu = isNoiseMessage($metin);
            $p = $ai->parseMessage($metin);
            $p['plaka_ham']    = $p['plaka'];
            $p['plaka']        = normalizeTurkishPlate($p['plaka']);
            $p['dorse_plaka']  = normalizeTurkishPlate($p['dorse_plaka']);
            $esik = (int)AI_MIN_CONFIDENCE;
            $karar = !$p['is_loading_plan'] ? 'alakasiz' : (whatsapp_kritik_dolu($p) && $p['guven_skoru'] >= $esik ? 'hazir' : 'eksik_bilgi');
            wa_json(['ok' => true, 'sonuc' => $p, 'gurultu' => $gurultu, 'karar' => $karar, 'esik' => $esik, 'ai' => $ai->saglayici(), 'model' => $ai->model()]);
        }

        if (!$hazir) wa_json(['ok' => false, 'hata' => 'WhatsApp tabloları kurulmamış (docs/sql/whatsapp_migration.sql).'], 503);

        if ($islem === 'loglar') {
            wa_json(['ok' => true, 'html' => wa_log_tablosu(whatsapp_son_mesajlar($pdo, 25), $ayar)]);
        }

        $logId = (int)($_POST['id'] ?? 0);
        if ($logId <= 0) wa_json(['ok' => false, 'hata' => 'Geçersiz kayıt.'], 422);

        if ($islem === 'aktar') {
            $r = whatsapp_plana_aktar($pdo, $logId, $uid, false);
            wa_json($r['ok'] ? ['ok' => true, 'mesaj' => !empty($r['mukerrer']) ? 'Aynı plaka/tarih için plan zaten vardı; mevcut plana bağlandı.' : 'Yükleme planına TASLAK olarak aktarıldı.'] : ['ok' => false, 'hata' => $r['hata']], $r['ok'] ? 200 : 409);
        }
        if ($islem === 'onayla') {
            $r = whatsapp_taslak_onayla($pdo, $logId, $uid);
            wa_json($r['ok'] ? ['ok' => true, 'mesaj' => 'Taslak onaylandı; plan artık normal kayıt.'] : ['ok' => false, 'hata' => $r['hata']], $r['ok'] ? 200 : 409);
        }
        if ($islem === 'yeniden') {
            $st = $pdo->prepare('SELECT raw_message, created_at, durum, loading_record_id FROM whatsapp_mesaj_loglari WHERE id = ?');
            $st->execute([$logId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) wa_json(['ok' => false, 'hata' => 'Kayıt bulunamadı.'], 404);
            if (!empty($row['loading_record_id'])) wa_json(['ok' => false, 'hata' => 'Plana aktarılmış kayıt yeniden ayrıştırılamaz.'], 409);
            $son = whatsapp_kaydi_ayristir($pdo, $logId, (string)$row['raw_message'], substr((string)$row['created_at'], 0, 10), $ayar);
            whatsapp_denetle('whatsapp_yeniden_ayristir', 'whatsapp', $logId, ['durum' => $row['durum']], ['durum' => $son]);
            wa_json(['ok' => true, 'mesaj' => 'Yeniden ayrıştırıldı: ' . wa_durum_etiket($son, null)[0]]);
        }
        wa_json(['ok' => false, 'hata' => 'Bilinmeyen işlem.'], 400);
    } catch (WhatsAppException $e) {
        wa_json(['ok' => false, 'hata' => 'WhatsApp bağlantısı: ' . $e->getMessage()], 502);
    } catch (AIParserException $e) {
        wa_json(['ok' => false, 'hata' => 'AI: ' . $e->getMessage()], 502);
    } catch (Throwable $e) {
        error_log('[whatsapp_yonetim] ' . $e->getMessage());
        wa_json(['ok' => false, 'hata' => 'Beklenmeyen hata; ayrıntı sunucu günlüğünde.'], 500);
    }
}

// ── Ayar kaydı (klasik form POST) ─────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['islem'] ?? '') === 'ayar_kaydet') {
    csrf_check($_POST['csrf'] ?? null);
    if (!$hazir) { set_flash('error', 'WhatsApp tabloları kurulmamış.'); header('Location: whatsapp_yonetim.php'); exit; }
    $adlar = is_array($_POST['grup_ad'] ?? null) ? $_POST['grup_ad'] : [];
    $gruplar = [];
    foreach ((array)($_POST['gruplar'] ?? []) as $jid) {
        if (!is_string($jid)) continue;
        $gruplar[] = ['jid' => $jid, 'ad' => is_string($adlar[$jid] ?? null) ? $adlar[$jid] : $jid];
    }
    $sag = (string)($_POST['ai_saglayici'] ?? 'groq');
    $oto = !empty($_POST['otomatik_taslak']);
    $eski = ['gruplar' => array_column($ayar['secili_gruplar'], 'jid'), 'ai' => $ayar['ai_saglayici'], 'otomatik' => $ayar['otomatik_taslak']];
    whatsapp_ayarlar_kaydet($pdo, $gruplar, $sag, $oto, $uid);
    $yeni = whatsapp_ayarlar_oku($pdo);
    whatsapp_denetle('whatsapp_ayar', 'whatsapp', 1, $eski, ['gruplar' => array_column($yeni['secili_gruplar'], 'jid'), 'ai' => $yeni['ai_saglayici'], 'otomatik' => $yeni['otomatik_taslak']]);
    set_flash('success', 'WhatsApp ayarları kaydedildi' . ($yeni['secili_gruplar'] === [] ? ' (grup seçili değil: TÜM gruplar dinlenir).' : '.'));
    header('Location: whatsapp_yonetim.php');
    exit;
}

// ── Sayfa ─────────────────────────────────────────────────────
$loglar = $hazir ? whatsapp_son_mesajlar($pdo, 25) : [];
$eksikYapilandirma = [];
if ((string)EVOLUTION_API_KEY === '') $eksikYapilandirma[] = 'EVOLUTION_API_KEY';
if ((string)WEBHOOK_SECRET_TOKEN === '') $eksikYapilandirma[] = 'WEBHOOK_SECRET_TOKEN';
if ((string)AI_API_KEY === '') $eksikYapilandirma[] = 'AI_API_KEY';

render_header('WhatsApp Yükleme Planı');
echo '<link rel="stylesheet" href="assets/whatsapp.css?v=' . (int)@filemtime(__DIR__ . '/assets/whatsapp.css') . '">' . "\n";
render_flash();
?>
<div class="wa-sayfa" id="waSayfa" data-csrf="<?= h(csrf_token()) ?>">
    <div class="page-head">
        <h1>WhatsApp Yükleme Planı</h1>
    </div>
    <p class="wa-giris">Lojistik WhatsApp gruplarındaki dağınık mesajlardan plaka, şoför, firma, yer, tonaj ve tarihi yapay zekâ ile ayıklar; güveni yüksek olanları
        <strong>taslak</strong> yükleme planı olarak ekler. Taslaklar siz <em>Onayla</em>ya basana kadar normal plan sayılmaz.</p>

    <?php if (!$hazir): ?>
    <div class="card wa-not wa-not-uyari">
        <strong>WhatsApp tabloları kurulmamış.</strong>
        <p><code>docs/sql/whatsapp_migration.sql</code> dosyasını (phpMyAdmin › SQL) çalıştırın. Tablolar yokken webhook gelen mesajları <em>kaydetmeden</em> yok sayar.</p>
    </div>
    <?php endif; ?>
    <?php if ($eksikYapilandirma): ?>
    <div class="card wa-not wa-not-uyari">
        <strong>Eksik yapılandırma:</strong> <?= h(implode(', ', $eksikYapilandirma)) ?>.
        <p><code>config/local.php</code> içine <code>define('SABİT', 'değer');</code> olarak ekleyin (sabit listesi: <code>config/whatsapp_config.php</code>).
        Webhook gizli anahtarı tanımlı değilken webhook hiçbir isteği kabul etmez.</p>
    </div>
    <?php endif; ?>

    <!-- 1) Bağlantı & QR -->
    <section class="card wa-kart" id="waBaglanti">
        <h2 class="wa-kart-baslik">1 · Bağlantı <span class="wa-rozet wa-pasif" id="waDurumRozet">Kontrol ediliyor…</span></h2>
        <div class="wa-baglanti">
            <div class="wa-baglanti-bilgi">
                <p class="wa-ikincil">Oturum: <code><?= h($ayar['instance_adi']) ?></code> · Evolution: <code><?= h((string)EVOLUTION_API_URL) ?></code></p>
                <p id="waDurumNot" class="wa-ikincil"></p>
                <div class="wa-eylem">
                    <button type="button" class="btn btn-primary" id="waQrIste">QR kod al / oturum oluştur</button>
                    <button type="button" class="btn btn-ghost" id="waDurumYenile">Durumu yenile</button>
                </div>
                <p class="wa-ikincil">WhatsApp › Bağlı cihazlar › Cihaz bağla ile QR'ı okutun. QR süresi dolarsa otomatik yenilenir; bağlanınca bu alan kendiliğinden kapanır.</p>
            </div>
            <div class="wa-qr" id="waQrKutu" hidden><img id="waQrImg" alt="WhatsApp bağlantı QR kodu" width="240" height="240"></div>
        </div>
    </section>

    <!-- 2) Grup seçimi + ayarlar -->
    <section class="card wa-kart" id="waGruplar">
        <h2 class="wa-kart-baslik">2 · Dinlenecek gruplar &amp; ayarlar</h2>
        <form method="post" id="waAyarForm" autocomplete="off">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="islem" value="ayar_kaydet">
            <ul class="wa-grup-liste" id="waGrupListe">
                <?php foreach ($ayar['secili_gruplar'] as $g): ?>
                <li><label class="wa-onay"><input type="checkbox" name="gruplar[]" value="<?= h($g['jid']) ?>" checked>
                    <span><?= h($g['ad']) ?></span><small><?= h($g['jid']) ?></small></label>
                    <input type="hidden" name="grup_ad[<?= h($g['jid']) ?>]" value="<?= h($g['ad']) ?>"></li>
                <?php endforeach; ?>
            </ul>
            <p class="wa-ikincil" id="waGrupNot"><?= $ayar['secili_gruplar'] ? 'Yalnız işaretli gruplar dinlenir.' : 'Hiç grup seçili değil: bağlı hattın TÜM grupları dinlenir. Kişisel sohbetler hiçbir zaman okunmaz.' ?></p>
            <div class="wa-eylem">
                <button type="button" class="btn btn-ghost" id="waGrupGetir">Gruplarımı getir</button>
            </div>

            <div class="wa-ayar-izgara">
                <div>
                    <label class="form-label" for="waAi">AI sağlayıcı</label>
                    <select name="ai_saglayici" id="waAi">
                        <option value="groq"<?= $ayar['ai_saglayici'] === 'groq' ? ' selected' : '' ?>>Groq (<?= h(wa_etkin_model('groq')) ?>)</option>
                        <option value="gemini"<?= $ayar['ai_saglayici'] === 'gemini' ? ' selected' : '' ?>>Google Gemini (<?= h(wa_etkin_model('gemini')) ?>)</option>
                    </select>
                    <p class="wa-ikincil">Anahtar (<code>AI_API_KEY</code>) seçilen sağlayıcıya ait olmalı.</p>
                </div>
                <div>
                    <label class="wa-onay wa-onay-buyuk"><input type="checkbox" name="otomatik_taslak" value="1"<?= $ayar['otomatik_taslak'] ? ' checked' : '' ?>>
                        <span>Güveni yüksek planları otomatik <strong>taslak</strong> olarak ekle</span></label>
                    <p class="wa-ikincil">Koşul: plaka + (firma ya da yükleme yeri) dolu ve güven ≥ <?= (int)AI_MIN_CONFIDENCE ?>. Kapalıysa mesajlar "Hazır" kalır, tek tıkla aktarırsınız.</p>
                </div>
            </div>
            <div class="wa-eylem"><button type="submit" class="btn btn-primary"<?= $hazir ? '' : ' disabled' ?>>Ayarları kaydet</button></div>
        </form>
    </section>

    <!-- 3) AI test simülatörü -->
    <section class="card wa-kart" id="waTest">
        <h2 class="wa-kart-baslik">3 · AI ayrıştırma testi</h2>
        <p class="wa-ikincil">WhatsApp'a mesaj atmadan örnek bir mesajı yapıştırın; çıktı veritabanına <strong>yazılmaz</strong>.</p>
        <textarea id="waTestMetin" rows="4" maxlength="3000" placeholder="Örn: yarın 34 KL 4589 dorse 06 ABC 123 mersin depodan 24.500 kg limon yüklenecek, şoför Ali 0532 111 22 33"></textarea>
        <div class="wa-eylem"><button type="button" class="btn btn-primary" id="waTestGonder">Ayrıştır</button><span class="wa-ikincil" id="waTestDurum"></span></div>
        <div id="waTestSonuc" class="wa-test-sonuc" hidden>
            <div id="waTestOzet" class="wa-test-ozet"></div>
            <pre id="waTestJson" class="wa-json"></pre>
        </div>
    </section>

    <!-- 4) Canlı mesaj günlüğü -->
    <section class="card wa-kart" id="waGunluk">
        <h2 class="wa-kart-baslik">4 · Son 25 mesaj <button type="button" class="btn btn-sm btn-ghost" id="waLogYenile">Yenile</button></h2>
        <div id="waLogKutu"><?= $hazir ? wa_log_tablosu($loglar, $ayar) : '<div class="wa-bos">Tablolar kurulduktan sonra mesajlar burada görünür.</div>' ?></div>
    </section>
</div>
<script src="assets/whatsapp.js?v=<?= (int)@filemtime(__DIR__ . '/assets/whatsapp.js') ?>"></script>
<?php render_footer();
