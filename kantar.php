<?php
// =========================================================
// kantar.php - Mal Kabul Fişleri listesi (Excel görünümlü; Palet Fişleri ile aynı yapı)
// Satıra tıklayınca fiş açılır. Raporlama mantığı bu listede YOKTUR (kantar_raporu.php ayrı sayfa olarak durur).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/kullanici_tercih.php';
$auth_user = require_login();
require_perm('kantar.read');

$pdo = db();

// Sütun genişliği/sırası/gizli: KULLANICI BAZINDA (kullanici_tercihleri). Tablo kurulu değilse istemci tarayıcıya düşer.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'tercih_kaydet') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check($_POST['csrf'] ?? null);
    $anahtar = (string)($_POST['anahtar'] ?? '');
    $deger = json_decode((string)($_POST['deger'] ?? ''), true);
    if (!kt_hazir($pdo)) { echo json_encode(['ok' => true, 'sunucu' => false]); exit; }
    $ok = $anahtar === 'kantar.tablo' && kt_yaz($pdo, (int)($auth_user['id'] ?? 0), $anahtar, $deger);
    if (!$ok) http_response_code(400);
    echo json_encode(['ok' => $ok, 'sunucu' => true, 'hata' => $ok ? '' : 'Tercih kaydedilemedi.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Depo sorumluluğu kısıtı (kantar_fisleri.depo)
[$_depo_sql, $_depo_params] = depo_sql_column('k.depo');

// Son işlem (Eklendi / Düzeltildi + kim + ne zaman) audit_log'tan okunur; şema değişmez.
// audit_log / users okunamazsa sütunlar boş döner, liste yine çalışır.
$islem_sel = "NULL AS islem_kod, NULL AS islem_zaman, '' AS islem_kullanici";
$islem_join = '';
try {
    $pdo->query('SELECT 1 FROM audit_log LIMIT 0'); $pdo->query('SELECT 1 FROM users LIMIT 0');
    $islem_sel = "al.action AS islem_kod, al.created_at AS islem_zaman, COALESCE(NULLIF(u.display_name, ''), u.username, '') AS islem_kullanici";
    $islem_join = "LEFT JOIN audit_log al ON al.id = (SELECT MAX(a2.id) FROM audit_log a2 WHERE a2.module = 'kantar' AND a2.record_id = k.id AND a2.action IN ('create', 'update'))
                   LEFT JOIN users u ON u.id = al.user_id";
} catch (PDOException $e) {}

$rows = [];
try {
    $st = $pdo->prepare(
        "SELECT k.id, k.fis_no, k.giris_tarih, k.plaka, k.firma_adi, k.malin_cinsi, k.geldigi_yer, k.depo,
                k.palet_sayisi, k.palet_cinsi, k.kasa_sayisi, k.kasa_cinsi, k.tartim1, k.tartim2,
                k.kasa_dara, k.palet_dara, k.kasa_dara_total, k.palet_dara_total, k.aciklama, k.created_at, {$islem_sel}
         FROM kantar_fisleri k
         {$islem_join}
         WHERE 1=1{$_depo_sql}
         ORDER BY CAST(k.fis_no AS UNSIGNED) DESC, k.id DESC
         LIMIT 2000"
    );
    $st->execute($_depo_params);
    $rows = $st->fetchAll();
} catch (PDOException $e) {}

function kf_liste_tarih(?string $v, bool $saat = false): string {
    $t = $v ? strtotime($v) : false;
    return $t ? date($saat ? 'd.m.Y H:i' : 'd.m.Y', $t) : '';
}
function kf_liste_kg($v): string { return number_format((int)round((float)$v), 0, ',', '.'); }

$can_create = can('kantar.create');
$TERCIH_ANAHTAR = 'kantar.tablo';

render_header('Mal Kabul Fişleri');
render_flash();
?>
<link rel="stylesheet" href="assets/palet-fisleri.css?v=<?= (int)@filemtime(__DIR__ . '/assets/palet-fisleri.css') ?>">
<section class="pf-page" id="kfPage" data-csrf="<?= h(csrf_token()) ?>">
    <div class="pf-top">
        <div class="pf-title">
            <div>
                <h1>⚖️ Mal Kabul Fişleri</h1>
                <p>Satıra tıklayınca fiş açılır. Başlığa çift tık: sütunları göster/gizle · kenardan sürükle: genişlik · başlığa tık: sırala.</p>
            </div>
            <div class="pf-tb-grup">
                <button type="button" class="pf-tb" id="kfSutunBtn" title="Sütunları göster / gizle">▦ Sütunlar</button>
                <button type="button" class="pf-tb" id="kfFiltreTemizle" title="Tüm süzgeçleri kaldırır" hidden>✕ Süzgeçleri temizle</button>
                <?php if ($can_create): ?><a href="kantar_create.php" class="pf-tb pf-tb-ekle" title="Yeni mal kabul fişi">＋ Yeni Mal Kabul Fişi</a><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="pf-table-wrap" id="kfWrap" tabindex="0" aria-label="Mal kabul fişleri tablosu">
        <table class="pf-table kf-table" id="kfTable">
            <thead>
            <tr id="kfHead">
                <th data-col="fis_no">Fiş No</th><th data-col="tarih">Tarih</th><th data-col="plaka">Plaka</th><th data-col="firma">Firma</th>
                <th data-col="malin">Malın Cinsi</th><th data-col="geldigi">Geldiği Yer</th><th data-col="depo">Depo</th>
                <th data-col="palet" data-tip="sayi">Palet</th><th data-col="palet_cinsi">Palet Cinsi</th>
                <th data-col="kasa" data-tip="sayi">Kasa</th><th data-col="kasa_cinsi">Kasa Cinsi</th>
                <th data-col="t1" data-tip="sayi">1. Tartım</th><th data-col="t2" data-tip="sayi">2. Tartım</th>
                <th data-col="brut" data-tip="sayi">Tartım Net</th><th data-col="dara" data-tip="sayi">Dara</th><th data-col="net" data-tip="sayi">Net KG</th>
                <th data-col="aciklama">Açıklama</th>
                <th data-col="islem">İşlem</th><th data-col="islem_yapan">İşlem Yapan</th><th data-col="islem_zaman" data-tip="tarih" title="İşlem tarihi ve saati">İşlem Tarihi</th>
            </tr>
            <tr id="kfFiltreSatiri" class="kf-filtre-satir">
                <?php foreach (['fis_no','tarih','plaka','firma','malin','geldigi','depo','palet','palet_cinsi','kasa','kasa_cinsi','t1','t2','brut','dara','net','aciklama','islem','islem_yapan','islem_zaman'] as $c): ?>
                <th data-col="<?= $c ?>"><input type="search" data-f="<?= $c ?>" placeholder="süz" aria-label="<?= $c ?> süz" autocomplete="off"></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody id="kfBody">
            <?php foreach ($rows as $r):
                $t1 = (float)$r['tartim1']; $t2 = (float)$r['tartim2'];
                $brut = max(0.0, $t1 - $t2);
                $kasa_say = (int)$r['kasa_sayisi']; $palet_say = (int)$r['palet_sayisi'];
                $dara = ((float)$r['kasa_dara_total'] > 0 || (float)$r['palet_dara_total'] > 0)
                    ? (float)$r['kasa_dara_total'] + (float)$r['palet_dara_total']
                    : $kasa_say * (float)$r['kasa_dara'] + $palet_say * (float)$r['palet_dara'];
                $net = max(0.0, $brut - $dara);
                $tarih_ham = $r['giris_tarih'] ?: $r['created_at'];
                // İşlem: audit kaydı yoksa (eski fişler) oluşturulma zamanı "Eklendi" olarak gösterilir
                $kod = (string)($r['islem_kod'] ?? '');
                $islem = $kod === 'update' ? 'Düzeltildi' : 'Eklendi';
                $islem_zaman = $kod !== '' ? (string)$r['islem_zaman'] : (string)$r['created_at'];
                $cells = [
                    ['fis_no', (string)($r['fis_no'] ?: $r['id']), ''],
                    ['tarih', kf_liste_tarih($tarih_ham), ''],
                    ['plaka', (string)$r['plaka'], ''], ['firma', (string)$r['firma_adi'], ''],
                    ['malin', (string)$r['malin_cinsi'], ''], ['geldigi', (string)$r['geldigi_yer'], ''], ['depo', (string)$r['depo'], ''],
                    ['palet', $palet_say ? (string)$palet_say : '', 'pf-num'], ['palet_cinsi', (string)$r['palet_cinsi'], ''],
                    ['kasa', $kasa_say ? (string)$kasa_say : '', 'pf-num'], ['kasa_cinsi', (string)$r['kasa_cinsi'], ''],
                    ['t1', $t1 > 0 ? kf_liste_kg($t1) : '', 'pf-num'], ['t2', $t2 > 0 ? kf_liste_kg($t2) : '', 'pf-num'],
                    ['brut', $brut > 0 ? kf_liste_kg($brut) : '', 'pf-num'], ['dara', $dara > 0 ? kf_liste_kg($dara) : '', 'pf-num'], ['net', $net > 0 ? kf_liste_kg($net) : '', 'pf-num'],
                    ['aciklama', (string)$r['aciklama'], ''],
                    ['islem', $islem, ''], ['islem_yapan', (string)($r['islem_kullanici'] ?? ''), ''], ['islem_zaman', kf_liste_tarih($islem_zaman, true), ''],
                ];
                // Sıralama değerleri (sayısal/tarih sütunlarında metin sırası yanıltmasın)
                $sirala = ['tarih' => (string)(strtotime((string)$tarih_ham) ?: 0), 'islem_zaman' => (string)(strtotime($islem_zaman) ?: 0),
                           'palet' => (string)$palet_say, 'kasa' => (string)$kasa_say, 't1' => (string)$t1, 't2' => (string)$t2, 'brut' => (string)$brut, 'dara' => (string)$dara, 'net' => (string)$net];
            ?>
                <tr data-id="<?= (int)$r['id'] ?>" tabindex="0" title="Fişi aç">
                <?php foreach ($cells as [$col, $text, $cls]): ?>
                    <td class="pf-cell <?= $cls ?>" data-col="<?= $col ?>" data-value="<?= h($text) ?>"<?= isset($sirala[$col]) ? ' data-sort="' . h($sirala[$col]) . '"' : '' ?>><?= h($text) ?></td>
                <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="pf-empty" id="kfEmpty" <?= $rows ? 'hidden' : '' ?>><?= $rows ? 'Süzgeçlerle eşleşen fiş yok.' : 'Henüz mal kabul fişi yok.' ?><?php if (!$rows && $can_create): ?> <a href="kantar_create.php">İlk fişi oluşturun</a><?php endif; ?></div>
    </div>
    <div class="pf-bottom" id="kfOzet">
        <div class="pf-kart"><i aria-hidden="true">▤</i><div><span><em class="pf-u">FİŞ</em><em class="pf-k">FİŞ</em></span><strong data-sum="fis">0</strong></div></div>
        <div class="pf-kart"><i aria-hidden="true">▥</i><div><span><em class="pf-u">PALET</em><em class="pf-k">PALET</em></span><strong data-sum="palet">0</strong></div></div>
        <div class="pf-kart"><i aria-hidden="true">▢</i><div><span><em class="pf-u">KASA</em><em class="pf-k">KASA</em></span><strong data-sum="kasa">0</strong></div></div>
        <div class="pf-kart"><i aria-hidden="true">⚖</i><div><span><em class="pf-u">TARTIM NET</em><em class="pf-k">T.NET</em></span><strong data-sum="brut">0</strong></div></div>
        <div class="pf-kart"><i aria-hidden="true">⚖</i><div><span><em class="pf-u">DARA</em><em class="pf-k">DARA</em></span><strong data-sum="dara">0</strong></div></div>
        <div class="pf-kart"><i aria-hidden="true">⚖</i><div><span><em class="pf-u">NET KG</em><em class="pf-k">NET</em></span><strong data-sum="net">0</strong></div></div>
    </div>
    <div class="pf-foot"><span>T.Kayıt: <strong id="kfCount"><?= count($rows) ?></strong></span><span class="pf-status" id="kfStatus" role="status" aria-live="polite"></span></div>
</section>
<dialog class="pf-dialog pf-sutun-dialog" id="kfSutunDialog">
    <div class="pf-dialog-head"><div><h2>Sütunlar</h2></div><button type="button" class="pf-close" id="kfSutunKapat" aria-label="Kapat">×</button></div>
    <div class="pf-sutun-liste" id="kfSutunListe"></div>
    <div class="pf-dialog-foot"><button type="button" class="btn btn-ghost" id="kfSutunHepsi" style="margin-right:auto">Tümünü göster</button><button type="button" class="btn btn-primary" id="kfSutunTamam">Tamam</button></div>
</dialog>
<script>window.KANTAR_LISTE = <?= json_encode(['tercihSunucu' => kt_hazir($pdo), 'tercih' => kt_oku($pdo, (int)($auth_user['id'] ?? 0), $TERCIH_ANAHTAR), 'anahtar' => $TERCIH_ANAHTAR], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="assets/kantar-liste.js?v=<?= (int)@filemtime(__DIR__ . '/assets/kantar-liste.js') ?>" defer></script>
<?php render_footer(); ?>
