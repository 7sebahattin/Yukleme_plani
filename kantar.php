<?php
// =========================================================
// kantar.php - Mal Kabul Fişleri listesi (Excel görünümlü; Palet Fişleri ile aynı yapı)
// Satıra tıklayınca fiş açılır. Raporlama mantığı bu listede YOKTUR (kantar_raporu.php ayrı sayfa olarak durur).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/kullanici_tercih.php';
require_once __DIR__ . '/config/kantar_alanlari_core.php';
$auth_user = require_login();
if (!function_exists('muhasebe_sayfa_can') || !muhasebe_sayfa_can('mal_kabul_fisleri')) { require_perm('kantar.read'); }

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

// Ek alanlar (şoför / malın sahibi / müstahsil / bahçe): kolonlar kuruluysa sorguya ve tabloya girer
$ek = kantar_alanlari_durum($pdo);
$ek_sel = '';
foreach ($ek as $kolon => $var) { if ($var) $ek_sel .= ", k.`$kolon`"; }

$kf_per_page = 250;
$kf_page = max(1, (int)($_GET['sayfa'] ?? 1));
$kf_total = 0;
$kf_total_pages = 1;
$rows = [];
try {
    $stCnt = $pdo->prepare("SELECT COUNT(*) FROM kantar_fisleri k WHERE 1=1{$_depo_sql}");
    $stCnt->execute($_depo_params);
    $kf_total = (int)$stCnt->fetchColumn();
    $kf_total_pages = max(1, (int)ceil($kf_total / $kf_per_page));
    $kf_page = min($kf_page, $kf_total_pages);
    $kf_offset = ($kf_page - 1) * $kf_per_page;

    $st = $pdo->prepare(
        "SELECT k.id, k.fis_no, k.giris_tarih, k.plaka, k.firma_adi, k.malin_cinsi, k.geldigi_yer, k.depo,
                k.palet_sayisi, k.palet_cinsi, k.kasa_sayisi, k.kasa_cinsi, k.tartim1, k.tartim2,
                k.kasa_dara, k.palet_dara, k.kasa_dara_total, k.palet_dara_total, k.aciklama, k.created_at{$ek_sel}
         FROM kantar_fisleri k
         WHERE 1=1{$_depo_sql}
         ORDER BY CAST(k.fis_no AS UNSIGNED) DESC, k.id DESC
         LIMIT {$kf_per_page} OFFSET {$kf_offset}"
    );
    $st->execute($_depo_params);
    $rows = $st->fetchAll();

    // B-28: Son işlem bilgisini yalnız bu sayfadaki kayıtlar için tek batch sorguyla çek
    if ($rows !== []) {
        $ids = array_values(array_unique(array_map(static fn(array $r): int => (int)$r['id'], $rows)));
        $amap = [];
        try {
            foreach (array_chunk($ids, 500) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $stA = $pdo->prepare(
                    "SELECT al.record_id, al.action AS islem_kod, al.created_at AS islem_zaman,
                            COALESCE(NULLIF(u.display_name, ''), u.username, '') AS islem_kullanici
                     FROM audit_log al
                     INNER JOIN (
                         SELECT record_id, MAX(id) AS max_id
                         FROM audit_log
                         WHERE module = 'kantar' AND action IN ('create', 'update') AND record_id IN ($ph)
                         GROUP BY record_id
                     ) mx ON mx.max_id = al.id
                     LEFT JOIN users u ON u.id = al.user_id"
                );
                $stA->execute($chunk);
                foreach ($stA->fetchAll(PDO::FETCH_ASSOC) as $a) {
                    $amap[(int)$a['record_id']] = $a;
                }
            }
        } catch (PDOException $e) {}
        foreach ($rows as &$r) {
            $a = $amap[(int)$r['id']] ?? null;
            $r['islem_kod']       = $a['islem_kod'] ?? null;
            $r['islem_zaman']     = $a['islem_zaman'] ?? null;
            $r['islem_kullanici'] = (string)($a['islem_kullanici'] ?? '');
        }
        unset($r);
    }
} catch (PDOException $e) {}

function kf_liste_tarih(?string $v, bool $saat = false): string {
    $t = $v ? strtotime($v) : false;
    return $t ? date($saat ? 'd.m.Y H:i' : 'd.m.Y', $t) : '';
}
function kf_liste_kg($v): string { return number_format((int)round((float)$v), 0, ',', '.'); }

$can_create = can('kantar.create');
$TERCIH_ANAHTAR = 'kantar.tablo';

// Sütun sırası (başlık, süzgeç satırı ve hücreler aynı listeden): [anahtar, başlık, tür]
$KOLONLAR = [['fis_no', 'Fiş No', ''], ['tarih', 'Tarih', ''], ['plaka', 'Plaka', '']];
if (!empty($ek['sofor'])) $KOLONLAR[] = ['sofor', 'Şoför', ''];
array_push($KOLONLAR, ['firma', 'Firma', ''], ['malin', 'Malın Cinsi', '']);
if (!empty($ek['mal_sahibi'])) $KOLONLAR[] = ['mal_sahibi', 'Malın Sahibi', ''];
if (!empty($ek['mustahsil'])) $KOLONLAR[] = ['mustahsil', 'Müstahsil', ''];
if (!empty($ek['bahce'])) $KOLONLAR[] = ['bahce', 'Bahçe', ''];
array_push($KOLONLAR, ['geldigi', 'Geldiği Yer', ''], ['depo', 'Depo', ''], ['palet', 'Palet', 'sayi'], ['palet_cinsi', 'Palet Cinsi', ''],
    ['kasa', 'Kasa', 'sayi'], ['kasa_cinsi', 'Kasa Cinsi', ''], ['t1', '1. Tartım', 'sayi'], ['t2', '2. Tartım', 'sayi'],
    ['brut', 'Tartım Net', 'sayi'], ['dara', 'Dara', 'sayi'], ['net', 'Net KG', 'sayi'], ['aciklama', 'Açıklama', ''],
    ['islem', 'İşlem', ''], ['islem_yapan', 'İşlem Yapan', ''], ['islem_zaman', 'İşlem Tarihi', 'tarih']);

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
                <button type="button" class="pf-tb" id="kfYazdirBtn" title="Görünen listeyi (süzgeç ve sütun ayarıyla) yazdırır">⎙ Yazdır</button>
                <button type="button" class="pf-tb" id="kfFiltreTemizle" title="Tüm süzgeçleri kaldırır" hidden>✕ Süzgeçleri temizle</button>
                <?php if ($can_create): ?><a href="kantar_create.php" class="pf-tb pf-tb-ekle" title="Yeni mal kabul fişi">＋ Yeni Mal Kabul Fişi</a><?php endif; ?>
            </div>
        </div>
    </div>
    <div class="pf-table-wrap" id="kfWrap" tabindex="0" aria-label="Mal kabul fişleri tablosu">
        <table class="pf-table kf-table" id="kfTable">
            <thead>
            <tr id="kfHead">
                <?php foreach ($KOLONLAR as [$ck, $cl, $ct]): ?>
                <th data-col="<?= $ck ?>"<?= $ct ? ' data-tip="' . $ct . '"' : '' ?><?= $ck === 'islem_zaman' ? ' title="İşlem tarihi ve saati"' : '' ?>><?= h($cl) ?></th>
                <?php endforeach; ?>
            </tr>
            <tr id="kfFiltreSatiri" class="kf-filtre-satir">
                <?php foreach ($KOLONLAR as [$ck]): ?>
                <th data-col="<?= $ck ?>"><input type="search" data-f="<?= $ck ?>" placeholder="süz" aria-label="<?= $ck ?> süz" autocomplete="off"></th>
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
                    ['plaka', (string)$r['plaka'], ''], ['sofor', (string)($r['sofor'] ?? ''), ''], ['firma', (string)$r['firma_adi'], ''],
                    ['malin', (string)$r['malin_cinsi'], ''], ['mal_sahibi', (string)($r['mal_sahibi'] ?? ''), ''], ['mustahsil', (string)($r['mustahsil'] ?? ''), ''], ['bahce', (string)($r['bahce'] ?? ''), ''],
                    ['geldigi', (string)$r['geldigi_yer'], ''], ['depo', (string)$r['depo'], ''],
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
                <?php foreach ($cells as [$col, $text, $cls]): if (in_array($col, ['sofor', 'mal_sahibi', 'mustahsil', 'bahce'], true) && empty($ek[$col])) continue; ?>
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
    <div class="pf-foot"><span>T.Kayıt: <strong id="kfCount"><?= count($rows) ?></strong><?php if ($kf_total > count($rows)): ?> / <?= $kf_total ?><?php endif; ?></span><?php if ($kf_total_pages > 1): ?><span class="pf-sayfalama" style="display:inline-flex;align-items:center;gap:6px;margin-left:8px"><?php if ($kf_page > 1): ?><a class="btn btn-sm btn-ghost" href="kantar.php<?= $kf_page - 1 > 1 ? '?sayfa=' . ($kf_page - 1) : '' ?>" aria-label="Önceki sayfa">‹ Önceki</a><?php endif; ?><span>Sayfa <?= $kf_page ?> / <?= $kf_total_pages ?></span><?php if ($kf_page < $kf_total_pages): ?><a class="btn btn-sm btn-ghost" href="kantar.php?sayfa=<?= $kf_page + 1 ?>" aria-label="Sonraki sayfa">Sonraki ›</a><?php endif; ?></span><?php endif; ?><span class="pf-status" id="kfStatus" role="status" aria-live="polite"></span></div>
</section>
<dialog class="pf-dialog pf-sutun-dialog" id="kfSutunDialog">
    <div class="pf-dialog-head"><div><h2>Sütunlar</h2></div><button type="button" class="pf-close" id="kfSutunKapat" aria-label="Kapat">×</button></div>
    <div class="pf-sutun-liste" id="kfSutunListe"></div>
    <div class="pf-dialog-foot"><button type="button" class="btn btn-ghost" id="kfSutunHepsi" style="margin-right:auto">Tümünü göster</button><button type="button" class="btn btn-primary" id="kfSutunTamam">Tamam</button></div>
</dialog>
<script>window.KANTAR_LISTE = <?= json_encode(['tercihSunucu' => kt_hazir($pdo), 'tercih' => kt_oku($pdo, (int)($auth_user['id'] ?? 0), $TERCIH_ANAHTAR), 'anahtar' => $TERCIH_ANAHTAR], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="assets/kantar-liste.js?v=<?= (int)@filemtime(__DIR__ . '/assets/kantar-liste.js') ?>" defer></script>
<?php render_footer(); ?>
