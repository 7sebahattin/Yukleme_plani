<?php
// =========================================================
// config/sirket_yapisi_ui.php — Şirket · Şube · Depo tanım sayfalarının ortak form parçaları (v505; include-only)
// program_tanimlari.php · sube_tanimlari.php · depo_tanimlari.php kullanır. Çıktı yalnız h() ile kaçışlı basılır.
// =========================================================
declare(strict_types=1);
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

/** Tek form alanı: etiket + input. $o: type, req, max, ph, hint, pattern, inputmode, wide (tam satır). */
function sy_alan(string $ad, string $etiket, mixed $deger, array $o = []): void
{
    $tip = (string)($o['type'] ?? 'text');
    $id  = 'sy_' . $ad;
    echo '<label class="sy-alan' . (!empty($o['wide']) ? ' sy-genis' : '') . '" for="' . h($id) . '"><span class="sy-etiket">' . h($etiket) . (!empty($o['req']) ? ' <b title="Zorunlu">*</b>' : '') . '</span>';
    echo '<input type="' . h($tip) . '" id="' . h($id) . '" name="' . h($ad) . '" value="' . h((string)($deger ?? '')) . '"'
        . (!empty($o['req']) ? ' required' : '')
        . (isset($o['max']) ? ' maxlength="' . (int)$o['max'] . '"' : '')
        . (isset($o['ph']) ? ' placeholder="' . h((string)$o['ph']) . '"' : '')
        . (isset($o['pattern']) ? ' pattern="' . h((string)$o['pattern']) . '"' : '')
        . (isset($o['inputmode']) ? ' inputmode="' . h((string)$o['inputmode']) . '"' : '')
        . (!empty($o['upper']) ? ' style="text-transform:uppercase"' : '')
        . ' autocomplete="off">';
    if (!empty($o['hint'])) echo '<span class="sy-ipucu">' . h((string)$o['hint']) . '</span>';
    echo '</label>';
}

/** Doğrulama hataları kutusu. */
function sy_hatalar(array $hatalar): void
{
    if (!$hatalar) return;
    echo '<div class="flash flash-error" role="alert"><b>Kaydedilemedi:</b><ul style="margin:6px 0 0 18px">';
    foreach ($hatalar as $x) echo '<li>' . h((string)$x) . '</li>';
    echo '</ul></div>';
}

/** Tablolar henüz kurulmamışsa gösterilen kart (sayfa başka bir şey çizmez). */
function sy_tablo_yok(): void
{
    echo '<div class="card"><h2>Şirket · Şube · Depo tabloları kurulmadı</h2>'
       . '<p>Bu ekran, veritabanında <code>sirketler</code>, <code>subeler</code>, <code>depo_detay</code> ve <code>depo_sirket</code> tabloları olduğunda çalışır.</p>'
       . '<p><a class="btn btn-primary" href="migrate.php#sirket-yapisi">Şema Migrasyon Paneli → Şirket · Şube · Depo kartı</a></p></div>';
}

/** Üç sayfa arası sekme çubuğu. $aktif: sirket|sube|depo */
function sy_sekmeler(string $aktif): void
{
    $s = ['sirket' => ['program_tanimlari.php', '🏢 Şirketler'], 'sube' => ['sube_tanimlari.php', '🏬 Şubeler'], 'depo' => ['depo_tanimlari.php', '🏭 Depolar']];
    echo '<nav class="sy-sekme" aria-label="Şirket yapısı">';
    foreach ($s as $k => [$href, $ad]) echo '<a href="' . h($href) . '" class="' . ($k === $aktif ? 'aktif' : '') . '"' . ($k === $aktif ? ' aria-current="page"' : '') . '>' . h($ad) . '</a>';
    echo '</nav>';
}

/** Şirket renk noktası + kod. */
function sy_kod_rozet(?string $renk, string $kod): string
{
    $r = preg_match('/^#[0-9a-f]{6}$/i', (string)$renk) ? $renk : '#94a3b8';
    return '<span class="sy-nokta" style="background:' . h($r) . '" aria-hidden="true"></span><b>' . h($kod) . '</b>';
}
