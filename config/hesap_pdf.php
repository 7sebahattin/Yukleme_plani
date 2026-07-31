<?php
// =========================================================
// config/hesap_pdf.php — Hesap dönem raporu (Faz 5)
//   · Veri toplama        → hesap_report_data()
//   · HTML üretimi        → hesap_report_html()
//   · PDF üretimi         → hesap_report_pdf()
//
// dompdf yapılandırması BİLEREK kısıtlı:
//   isRemoteEnabled = false  → uzak kaynak çekilmez (SSRF yok)
//   isPhpEnabled    = false  → HTML içinde PHP çalışmaz
// Görseller data: URI olarak gömülür, sayfa numarası canvas API'siyle basılır.
// =========================================================
declare(strict_types=1);

/** Rapora eklenecek azami fiş görseli (3×3 → 10 sayfa). Aşılırsa rapora not düşülür. */
const HESAP_PDF_MAX_FIS = 90;

/** Gömülen görsellerin uzun kenar sınırı (px) — PDF boyutunu ve belleği dengeler. */
const HESAP_PDF_IMG_MAX = 900;

/**
 * Yerel bir görseli küçültüp data: URI'ye çevirir.
 * Başarısızlıkta null döner — rapor görselsiz devam eder, hata vermez.
 */
function hesap_pdf_image_uri(string $path, int $max = HESAP_PDF_IMG_MAX): ?string
{
    if (!is_file($path) || !is_readable($path)) return null;

    $info = @getimagesize($path);
    if ($info === false) return null;
    [$w, $h, $type] = $info;
    if ($w < 1 || $h < 1) return null;

    // GD yoksa küçük dosyaları olduğu gibi göm
    if (!function_exists('imagecreatetruecolor')) {
        $raw = @file_get_contents($path);
        if ($raw === false || strlen($raw) > 2 * 1024 * 1024) return null;
        return 'data:' . ($info['mime'] ?? 'image/jpeg') . ';base64,' . base64_encode($raw);
    }

    $src = match ($type) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => @imagecreatefrompng($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default        => false,
    };
    if (!$src) return null;

    $scale = min(1.0, $max / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));

    $dst = imagecreatetruecolor($nw, $nh);
    // Şeffaflığı beyaza düzleştir — JPEG'e çevrileceği için
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    ob_start();
    imagejpeg($dst, null, 72);
    $data = ob_get_clean();
    imagedestroy($dst);

    if ($data === false || $data === '') return null;
    return 'data:image/jpeg;base64,' . base64_encode($data);
}

/**
 * Rapor verisini toplar. Görünürlük (sahiplik + depo) her sorguda uygulanır.
 *
 * @param array $f  ['tarih_bas','tarih_son','type','durum','kisi']
 */
function hesap_report_data(array $f): array
{
    $tarih_b = $f['tarih_bas'] ?? date('Y-m-01');
    $tarih_s = $f['tarih_son'] ?? date('Y-m-t');
    $type_f  = $f['type']  ?? '';
    $durum_f = $f['durum'] ?? '';

    // WHERE parçaları baştan "at." önekiyle kurulur — sonradan string değiştirmek kırılgan olur
    $where  = ['at.transaction_date >= ?', 'at.transaction_date <= ?'];
    $params = [$tarih_b, $tarih_s];

    if ($type_f !== '') { $where[] = 'at.type = ?'; $params[] = $type_f; }

    if ($durum_f === 'bekleyen') {
        $ph = implode(',', array_fill(0, count(hesap_pending_statuses()), '?'));
        $where[] = "at.status IN ($ph)";
        $params  = array_merge($params, hesap_pending_statuses());
    } elseif ($durum_f !== '' && hesap_status_valid($durum_f)) {
        $where[]  = 'at.status = ?';
        $params[] = $durum_f;
    }

    [$osql, $oparams] = hesap_owner_sql('at.user_id');
    if ($osql !== '') { $where[] = $osql; $params = array_merge($params, $oparams); }
    [$dsql, $dparams] = depo_sql_in('at.depo');
    if ($dsql !== '') { $where[] = $dsql; $params = array_merge($params, $dparams); }

    $wstr = implode(' AND ', $where);

    $st = db()->prepare("SELECT at.*, COALESCE(u.display_name, u.username, '') AS personel
                         FROM account_transactions at
                         LEFT JOIN users u ON u.id = at.user_id
                         WHERE $wstr
                         ORDER BY at.transaction_date ASC, at.id ASC");
    $st->execute($params);
    $rows = $st->fetchAll();

    // ── Para birimi bazında toplam (asla karıştırılmaz) ──
    $toplamlar = [];
    foreach ($rows as $r) {
        $cur = $r['currency'] ?: 'TRY';
        $toplamlar[$cur] ??= ['gelir' => 0.0, 'gider' => 0.0, 'adet' => 0];
        if ($r['type'] === 'gelir') $toplamlar[$cur]['gelir'] += (float)$r['amount'];
        else                        $toplamlar[$cur]['gider'] += (float)$r['amount'];
        $toplamlar[$cur]['adet']++;
    }
    foreach ($toplamlar as $cur => &$t) { $t['net'] = $t['gelir'] - $t['gider']; }
    unset($t);
    uksort($toplamlar, fn($a, $b) => ($b === 'TRY' ? 1 : 0) <=> ($a === 'TRY' ? 1 : 0) ?: strcmp($a, $b));

    // ── Personel kırılımı (yalnız bakiyeye giren durumlar, TRY) ──
    $personel = [];
    foreach ($rows as $r) {
        if (($r['currency'] ?: 'TRY') !== 'TRY') continue;
        if (!in_array($r['status'] ?? '', hesap_balance_statuses(), true)) continue;
        $ad = $r['personel'] !== '' ? $r['personel'] : 'Atanmamış';
        $personel[$ad] ??= ['gelir' => 0.0, 'gider' => 0.0, 'adet' => 0];
        if ($r['type'] === 'gelir') $personel[$ad]['gelir'] += (float)$r['amount'];
        else                        $personel[$ad]['gider'] += (float)$r['amount'];
        $personel[$ad]['adet']++;
    }
    foreach ($personel as &$p) { $p['net'] = $p['gelir'] - $p['gider']; }
    unset($p);
    ksort($personel);

    // ── Kategori kırılımı (gider tarafı, TRY) ──
    $kategoriler = [];
    $kat_toplam = 0.0;
    foreach ($rows as $r) {
        if (($r['currency'] ?: 'TRY') !== 'TRY') continue;
        if ($r['type'] === 'gelir') continue;
        $ad = $r['category'] !== '' ? $r['category'] : hesap_type_label($r['type']);
        $kategoriler[$ad] ??= ['tutar' => 0.0, 'adet' => 0];
        $kategoriler[$ad]['tutar'] += (float)$r['amount'];
        $kategoriler[$ad]['adet']++;
        $kat_toplam += (float)$r['amount'];
    }
    arsort($kategoriler);
    foreach ($kategoriler as &$k) {
        $k['yuzde'] = $kat_toplam > 0 ? ($k['tutar'] / $kat_toplam * 100) : 0.0;
    }
    unset($k);

    // ── Fiş görselleri (rapor sonuna eklenecek) ──
    $fisler = [];
    $kirpildi = 0;
    foreach ($rows as $r) {
        foreach (hesap_get_image_files((int)$r['id']) as $img) {
            if (count($fisler) >= HESAP_PDF_MAX_FIS) { $kirpildi++; continue; }
            $fisler[] = ['tx' => $r, 'file' => $img['file_name']];
        }
    }

    return [
        'rows'        => $rows,
        'toplamlar'   => $toplamlar,
        'personel'    => $personel,
        'kategoriler' => $kategoriler,
        'kat_toplam'  => $kat_toplam,
        'fisler'      => $fisler,
        'kirpildi'    => $kirpildi,
        'meta'        => [
            'tarih_bas' => $tarih_b,
            'tarih_son' => $tarih_s,
            'type'      => $type_f,
            'durum'     => $durum_f,
            'depo'      => function_exists('active_depot') ? (active_depot() ?? '') : '',
            'hazirlayan'=> (function () {
                $u = function_exists('current_user') ? current_user() : null;
                return $u ? (string)($u['display_name'] ?: $u['username']) : '';
            })(),
            'uretim'    => date('d.m.Y H:i'),
            'adet'      => count($rows),
        ],
    ];
}

/** Durum rozeti — PDF'te satır içi renkli etiket. */
function hesap_pdf_badge(?string $status): string
{
    $renkler = [
        'draft'           => ['#f1f5f9', '#334155'],
        'submitted'       => ['#eff6ff', '#1e40af'],
        'approved'        => ['#ecfdf5', '#065f46'],
        'pending_payment' => ['#fff7ed', '#9a3412'],
        'paid'            => ['#f0fdf4', '#166534'],
        'rejected'        => ['#fef2f2', '#991b1b'],
    ];
    $s = (string)($status ?: 'submitted');
    [$bg, $fg] = $renkler[$s] ?? ['#f1f5f9', '#334155'];
    return '<span style="background:' . $bg . ';color:' . $fg . ';padding:1px 5px;'
         . 'border-radius:7px;font-size:6.5pt;white-space:nowrap">'
         . h(hesap_status_label($s)) . '</span>';
}

/**
 * Rapor HTML'i. $for_pdf=false ise tarayıcıda yazdırma görünümü olarak da kullanılır.
 * Tüm kullanıcı verisi h() ile kaçırılır.
 */
function hesap_report_html(array $d, bool $for_pdf = true): string
{
    $m = $d['meta'];

    $logo = hesap_pdf_image_uri(__DIR__ . '/../assets/logo.jpg', 260);

    $kapsam = [];
    $kapsam[] = 'Tarih: ' . date('d.m.Y', strtotime($m['tarih_bas'])) . ' — ' . date('d.m.Y', strtotime($m['tarih_son']));
    if ($m['depo']  !== '') $kapsam[] = 'Depo: ' . $m['depo'];
    if ($m['type']  !== '') $kapsam[] = 'Tür: ' . hesap_type_label($m['type']);
    if ($m['durum'] !== '') {
        $kapsam[] = 'Durum: ' . ($m['durum'] === 'bekleyen' ? 'Onay bekleyenler' : hesap_status_label($m['durum']));
    }

    ob_start();
    ?>
<!doctype html>
<html lang="tr"><head><meta charset="utf-8">
<title>Asya Fresh — Hesap Raporu <?= h($m['tarih_bas']) ?></title>
<style>
    @page { margin: 16mm 11mm 18mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 8pt; color: #1c2230; margin: 0; }
    h1 { font-size: 15pt; margin: 0 0 2px; }
    h2 { font-size: 10pt; margin: 0 0 6px; padding-bottom: 3px; border-bottom: 1.5px solid #1d6cf0; color: #1d6cf0; }
    table { border-collapse: collapse; width: 100%; }
    .num { text-align: right; }
    .muted { color: #6b7385; }
    .pos { color: #15803d; }
    .neg { color: #b91c1c; }
    .b { font-weight: bold; }

    /* ── Başlık ── */
    .head { width: 100%; margin-bottom: 12px; }
    .head td { vertical-align: top; border: none; padding: 0; }
    .logo { width: 62px; }
    .head-title { padding-left: 10px; }
    .scope { font-size: 7.5pt; color: #6b7385; line-height: 1.6; }
    .head-meta { text-align: right; font-size: 7.5pt; color: #6b7385; line-height: 1.6; }

    /* ── Özet kutuları ── */
    .sum { width: 100%; margin-bottom: 14px; }
    .sum td {
        border: 1px solid #e3e7ee; border-radius: 4px;
        padding: 7px 9px; width: 33%; vertical-align: top;
    }
    /* text-transform: uppercase KULLANMA — CSS Türkçe i/İ eşlemesini bilmez,
       "Gelir" → "GELIR", "Şirkete" → "ŞIRKETE" olur. Etiketler doğrudan
       istenen büyük/küçük harfle yazılır. */
    .sum .lbl { font-size: 6.5pt; color: #6b7385; letter-spacing: .04em; font-weight: bold; }
    .sum .val { font-size: 12pt; font-weight: bold; }

    /* ── Veri tabloları ── */
    .data th {
        background: #1d6cf0; color: #fff; padding: 5px 6px;
        text-align: left; font-size: 7pt; font-weight: bold;
    }
    .data td { padding: 4px 6px; border-bottom: .5px solid #e3e7ee; font-size: 7.5pt; }
    .data td.num { white-space: nowrap; }   /* tutar iki satıra bölünmesin */
    .data tr.total td { background: #f4f6fa; font-weight: bold; border-top: 1px solid #c7cdd9; }
    .sec { margin-bottom: 16px; }

    /* ── Kategori çubuğu ── */
    .bar-wrap { background: #eef1f6; height: 7px; border-radius: 3px; width: 100%; }
    .bar { background: #1d6cf0; height: 7px; border-radius: 3px; }

    /* ── Fiş galerisi 3×3 ── */
    .gal { width: 100%; margin: 0; }
    .gal td {
        width: 33.33%; border: .5px solid #c7cdd9;
        padding: 4px; vertical-align: top; height: 78mm;
    }
    .gal .cap { font-size: 6pt; line-height: 1.45; margin-bottom: 3px; color: #1c2230; }
    .gal .cap b { font-size: 6.5pt; }
    .gal img { width: 100%; max-height: 62mm; }
    .gal td.empty { border: none; }
    .page-break { page-break-after: always; }
</style>
</head><body>

<!-- ═══ Başlık ═══ -->
<table class="head"><tr>
    <?php if ($logo): ?><td class="logo"><img src="<?= $logo ?>" style="width:58px"></td><?php endif; ?>
    <td class="head-title">
        <h1>Asya Fresh</h1>
        <div class="b" style="font-size:10pt;margin-bottom:3px">Hesap Dönem Raporu</div>
        <div class="scope"><?= h(implode(' · ', $kapsam)) ?></div>
    </td>
    <td class="head-meta">
        <?= (int)$m['adet'] ?> kayıt<br>
        <?php if ($m['hazirlayan'] !== ''): ?>Hazırlayan: <?= h($m['hazirlayan']) ?><br><?php endif; ?>
        <?= h($m['uretim']) ?>
    </td>
</tr></table>

<!-- ═══ Para birimi özeti ═══ -->
<div class="sec">
    <h2>Dönem Özeti</h2>
    <?php foreach ($d['toplamlar'] as $cur => $t): $bi = hesap_balance_label($t['net']); ?>
    <table class="sum"><tr>
        <td>
            <div class="lbl">Toplam Gelir<?= count($d['toplamlar']) > 1 ? ' (' . h($cur) . ')' : '' ?></div>
            <div class="val pos"><?= fmt_para($t['gelir'], $cur) ?></div>
        </td>
        <td>
            <div class="lbl">Toplam Gider<?= count($d['toplamlar']) > 1 ? ' (' . h($cur) . ')' : '' ?></div>
            <div class="val neg"><?= fmt_para($t['gider'], $cur) ?></div>
        </td>
        <td>
            <div class="lbl">Net · <?= h($bi['label']) ?></div>
            <div class="val <?= $bi['yon'] === 'borc' ? 'neg' : 'pos' ?>"><?= fmt_para($bi['tutar'], $cur) ?></div>
        </td>
    </tr></table>
    <?php endforeach; ?>
    <?php if (empty($d['toplamlar'])): ?>
    <p class="muted">Bu dönemde kayıt bulunmuyor.</p>
    <?php elseif (count($d['toplamlar']) > 1): ?>
    <p class="muted" style="font-size:6.5pt;margin:2px 0 0">
        Para birimleri ayrı toplanır — farklı kurlar birbirine eklenmez.
    </p>
    <?php endif; ?>
</div>

<!-- ═══ Personel özeti ═══ -->
<?php if (!empty($d['personel'])): ?>
<div class="sec">
    <h2>Personel Özeti <span class="muted" style="font-weight:normal">(TRY · onaylanan ve ödenen kayıtlar)</span></h2>
    <table class="data">
    <thead><tr>
        <th>Personel</th><th class="num">Gelir</th><th class="num">Gider</th>
        <th class="num">Net</th><th>Yön</th><th class="num">Kayıt</th>
    </tr></thead>
    <tbody>
    <?php $p_g = 0.0; $p_gd = 0.0; $p_a = 0;
    foreach ($d['personel'] as $ad => $p): $bi = hesap_balance_label($p['net']);
        $p_g += $p['gelir']; $p_gd += $p['gider']; $p_a += $p['adet']; ?>
    <tr>
        <td><?= h($ad) ?></td>
        <td class="num"><?= fmt_para($p['gelir']) ?></td>
        <td class="num"><?= fmt_para($p['gider']) ?></td>
        <td class="num b <?= $bi['yon'] === 'borc' ? 'neg' : 'pos' ?>"><?= fmt_para($bi['tutar']) ?></td>
        <td class="muted"><?= h($bi['label']) ?></td>
        <td class="num muted"><?= (int)$p['adet'] ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total">
        <td>TOPLAM</td>
        <td class="num"><?= fmt_para($p_g) ?></td>
        <td class="num"><?= fmt_para($p_gd) ?></td>
        <td class="num"><?= fmt_para(abs($p_g - $p_gd)) ?></td>
        <td class="muted"><?= h(hesap_balance_label($p_g - $p_gd)['label']) ?></td>
        <td class="num"><?= $p_a ?></td>
    </tr>
    </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ═══ Kategori kırılımı ═══ -->
<?php if (!empty($d['kategoriler'])): ?>
<div class="sec">
    <h2>Kategori Kırılımı <span class="muted" style="font-weight:normal">(TRY · gider tarafı)</span></h2>
    <table class="data">
    <thead><tr>
        <th>Kategori</th><th class="num">Tutar</th><th class="num">Pay</th>
        <th style="width:34%">Dağılım</th><th class="num">Kayıt</th>
    </tr></thead>
    <tbody>
    <?php foreach ($d['kategoriler'] as $ad => $k): ?>
    <tr>
        <td><?= h($ad) ?></td>
        <td class="num b"><?= fmt_para($k['tutar']) ?></td>
        <td class="num"><?= number_format($k['yuzde'], 1, ',', '.') ?>%</td>
        <td>
            <div class="bar-wrap"><div class="bar" style="width:<?= max(1, (int)round($k['yuzde'])) ?>%">&nbsp;</div></div>
        </td>
        <td class="num muted"><?= (int)$k['adet'] ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total">
        <td>TOPLAM</td>
        <td class="num"><?= fmt_para($d['kat_toplam']) ?></td>
        <td class="num">100,0%</td><td></td><td></td>
    </tr>
    </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ═══ Detaylı işlem listesi ═══ -->
<?php if (!empty($d['rows'])): ?>
<div class="sec">
    <h2>İşlem Listesi</h2>
    <table class="data">
    <thead><tr>
        <th style="width:4%">#</th>
        <th style="width:10%">Tarih</th>
        <th style="width:8%">Tür</th>
        <th style="width:16%">Kategori</th>
        <th style="width:14%">Personel</th>
        <th style="width:16%">Kişi / Firma</th>
        <th style="width:9%">Belge</th>
        <th class="num" style="width:11%">Tutar</th>
        <th style="width:8%">Fiş</th>
        <th style="width:12%">Durum</th>
    </tr></thead>
    <tbody>
    <?php foreach ($d['rows'] as $i => $r): $fd = hesap_fis_durumu($r); ?>
    <tr>
        <td class="muted"><?= $i + 1 ?></td>
        <td><?= h(date('d.m.Y', strtotime($r['transaction_date']))) ?></td>
        <td><?= h(hesap_type_label($r['type'])) ?></td>
        <td><?= h($r['category']) ?></td>
        <td class="muted"><?= h($r['personel'] ?: 'Atanmamış') ?></td>
        <td><?= h($r['person_company']) ?></td>
        <td class="muted"><?= h($r['document_no']) ?></td>
        <td class="num b <?= $r['type'] === 'gelir' ? 'pos' : 'neg' ?>">
            <?= ($r['type'] === 'gelir' ? '+' : '−') . fmt_para((float)$r['amount'], $r['currency']) ?>
        </td>
        <td class="<?= $fd['var'] ? '' : 'neg' ?>"><?= $fd['var'] ? 'Var' : 'YOK' ?></td>
        <td><?= hesap_pdf_badge($r['status'] ?? null) ?></td>
    </tr>
    <?php endforeach; ?>
    <?php foreach ($d['toplamlar'] as $cur => $t): ?>
    <tr class="total">
        <td colspan="7">TOPLAM<?= count($d['toplamlar']) > 1 ? ' (' . h($cur) . ')' : '' ?>
            — Gelir <?= fmt_para($t['gelir'], $cur) ?> · Gider <?= fmt_para($t['gider'], $cur) ?></td>
        <td class="num"><?= fmt_para($t['net'], $cur) ?></td>
        <td colspan="2"></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    </table>
</div>
<?php endif; ?>

<!-- ═══ İmza alanları ═══ -->
<table style="width:100%;margin-top:18px">
<tr>
    <td style="width:45%;border-top:.5px solid #6b7385;padding-top:4px;font-size:7pt" class="muted">
        Hazırlayan / Personel<?= $m['hazirlayan'] !== '' ? ' — ' . h($m['hazirlayan']) : '' ?>
    </td>
    <td style="width:10%"></td>
    <td style="width:45%;border-top:.5px solid #6b7385;padding-top:4px;font-size:7pt" class="muted">
        Muhasebe Onayı
    </td>
</tr>
</table>

<!-- ═══ Fiş görselleri — 3×3, rapor sonunda ═══ -->
<?php if (!empty($d['fisler'])):
    $sayfalar = array_chunk($d['fisler'], 9);
    foreach ($sayfalar as $sno => $sayfa): ?>
    <div class="page-break"></div>
    <h2>Fiş Görselleri <span class="muted" style="font-weight:normal">
        (sayfa <?= $sno + 1 ?> / <?= count($sayfalar) ?><?= $d['kirpildi'] > 0 ? ' · ' . (int)$d['kirpildi'] . ' görsel sınır nedeniyle eklenmedi' : '' ?>)
    </span></h2>
    <table class="gal">
    <?php foreach (array_chunk($sayfa, 3) as $satir): ?>
    <tr>
        <?php foreach ($satir as $box):
            $t = $box['tx'];
            $uri = hesap_pdf_image_uri(HESAP_UPLOAD_DIR . $box['file']);
        ?>
        <td>
            <div class="cap">
                <b><?= h(date('d.m.Y', strtotime($t['transaction_date']))) ?>
                   · <?= ($t['type'] === 'gelir' ? '+' : '−') . fmt_para((float)$t['amount'], $t['currency']) ?></b><br>
                <?= h($t['category'] ?: hesap_type_label($t['type'])) ?>
                <?php if ($t['person_company']): ?><br><?= h($t['person_company']) ?><?php endif; ?>
                <?php if ($t['document_no']): ?><br>Belge: <?= h($t['document_no']) ?><?php endif; ?>
            </div>
            <?php if ($uri): ?>
            <img src="<?= $uri ?>">
            <?php else: ?>
            <div class="muted" style="font-size:6pt">[görsel okunamadı]</div>
            <?php endif; ?>
        </td>
        <?php endforeach; ?>
        <?php for ($i = count($satir); $i < 3; $i++): ?><td class="empty"></td><?php endfor; ?>
    </tr>
    <?php endforeach; ?>
    </table>
    <?php endforeach; ?>
<?php endif; ?>

</body></html>
    <?php
    return (string)ob_get_clean();
}

/**
 * PDF üretir ve ham içeriği döndürür.
 * @return string|null  dompdf yoksa null (çağıran HTML'e düşer)
 */
function hesap_report_pdf(array $d): ?string
{
    if (!class_exists(\Dompdf\Dompdf::class)) return null;

    $opt = new \Dompdf\Options();
    $opt->set('defaultFont', 'DejaVu Sans');   // Türkçe + ₺ glif desteği
    $opt->set('isRemoteEnabled', false);       // uzak kaynak çekme kapalı (SSRF yok)
    $opt->set('isPhpEnabled', false);          // HTML içinde PHP çalıştırma kapalı
    $opt->set('isHtml5ParserEnabled', true);
    $opt->set('chroot', realpath(__DIR__ . '/..') ?: __DIR__);

    $dompdf = new \Dompdf\Dompdf($opt);
    $dompdf->loadHtml(hesap_report_html($d, true), 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // Sayfa altbilgisi — counter(pages) dompdf'te CSS ile çözülmediği için canvas API'si
    $canvas = $dompdf->getCanvas();
    $font   = $dompdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
    $w = $canvas->get_width();
    $h = $canvas->get_height();
    $canvas->page_text(30, $h - 28, 'Asya Fresh · Hesap Dönem Raporu', $font, 7, [0.42, 0.45, 0.52]);
    $canvas->page_text($w - 150, $h - 28, 'Sayfa {PAGE_NUM} / {PAGE_COUNT} · ' . $d['meta']['uretim'],
                       $font, 7, [0.42, 0.45, 0.52]);

    return $dompdf->output();
}

/** Rapor dosya adı. */
function hesap_report_filename(array $m): string
{
    return 'hesap_raporu_' . date('Ymd', strtotime($m['tarih_bas']))
         . '_' . date('Ymd', strtotime($m['tarih_son'])) . '.pdf';
}
