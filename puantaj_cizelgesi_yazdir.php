<?php
// =========================================================
// puantaj_cizelgesi_yazdir.php — Aylık Puantaj Çizelgesi (yazdırma, v552)
//
// puantaj_cizelgesi.php'nin SALT OKUNUR yatay A4 çıktısı. render_header ÇAĞIRMAZ
// (sidebar/bottomnav yok). AYNI kapılar: role_pdks_require_request() (rota kayıtlı) +
// monthly_timesheet + sekmenin kaynağı Gör. Veri config/puantaj_cizelgesi.php'den;
// ekranla aynı filtreler (sekme/ay/q/pasif). GET-only, CSRF/POST yok.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_cari.php';
require_once __DIR__ . '/config/pdks_rapor.php';
require_once __DIR__ . '/config/pdks_faz8b_cavus_b.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/print_helpers.php';
require_once __DIR__ . '/config/puantaj_cizelgesi.php';

$auth_user = require_login();
role_pdks_require_request();
if (function_exists('enforce_active_depot')) enforce_active_depot();

$sekmeler = pc_sekmeler();
if (!$sekmeler) forbidden('Bu sayfaya erişim yetkiniz yok.');
$sekme = (string)pc_sekme_sec((string)($_GET['sekme'] ?? ''), $sekmeler);
$ay = pc_ay_dogrula((string)($_GET['ay'] ?? ''));
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 60);
$pasif = $sekme === 'personel' && !empty($_GET['pasif']);
$depo = function_exists('active_depot') ? (active_depot() ?? '') : '';
$pdo = db();

$tablo = $sekme === 'personel'
    ? pc_tablo_personel($pdo, $ay, $q, $pasif)
    : pc_tablo_gunluk($pdo, $ay, $depo, $q);
$hata = $tablo['hata'] ?? null;
$tur = $sekme === 'gunluk' ? 'gunluk' : 'personel';
$gunluk = $tur === 'gunluk';

$geriUrl = pc_url('puantaj_cizelgesi.php', ['sekme' => $sekme, 'ay' => $ay, 'q' => $q, 'pasif' => $pasif ? '1' : '']);
render_print_page_start('Aylık Puantaj Çizelgesi', 'daily', 'detail', 'landscape', ['print_pdks.css', 'print_puantaj_cizelgesi.css']);
?>
<div class="print-sheet" style="max-width:none">
    <div class="pr-actions no-print">
        <button type="button" onclick="window.print()" class="pr-btn pr-btn-primary">🖨️ Yazdır</button>
        <a href="<?= h($geriUrl) ?>" class="pr-btn">← Çizelgeye Dön</a>
    </div>

    <?= render_print_header_html(
        'AYLIK PUANTAJ ÇİZELGESİ — ' . ($gunluk ? 'GÜNLÜK İŞÇİ' : 'PERSONEL'),
        pc_ay_basligi($ay) . ' · ' . ($depo !== '' ? $depo : 'Tüm depolar') . ($q !== '' ? ' · Arama: ' . $q : ''),
        'Yazdırma: ' . date('d.m.Y H:i')
    ) ?>

    <?php if ($hata !== null): ?>
    <p><?= h($hata) ?></p>
    <?php elseif (!$tablo['satirlar']): ?>
    <p>Seçilen ay için kayıt bulunamadı.</p>
    <?php else:
        $gunler = $tablo['gunler']; $genel = $tablo['genel']; ?>
    <p class="pcy-lejant"><?= h(pc_lejant_metni($tur)) ?></p>
    <?php if ($gunluk): ?><p class="pcy-not">Günlük işçide kişi değil kart / çavuş bazında sayılır.</p><?php endif; ?>
    <table class="pcy-tablo">
        <thead>
        <tr>
            <th class="pcy-ad"><?= $gunluk ? 'Çavuş' : 'Personel' ?> (<?= count($tablo['satirlar']) ?>)</th>
            <?php foreach ($gunler as $g): ?><th class="<?= $g['hs'] ? 'pcy-hs' : '' ?>"><?= $g['n'] ?><span class="pcy-gk"><?= h($g['ad']) ?></span></th><?php endforeach; ?>
            <th class="pcy-oz">Gün</th><th class="pcy-oz"><?= $gunluk ? 'İşçi-gün' : 'Saat' ?></th><th class="pcy-oz">Eksik</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($tablo['satirlar'] as $s): ?>
        <tr>
            <td class="pcy-ad"><?= h($s['ad']) ?><?php if ($s['alt'] !== ''): ?><small><?= h($s['alt']) ?></small><?php endif; ?></td>
            <?php foreach ($gunler as $g): [$cls, $ic] = pc_hucre_goster($tur, $s['hucre'][$g['tarih']] ?? null); ?>
            <td class="<?= h(str_replace('pc-', 'pcy-', $cls)) ?><?= $g['hs'] ? ' pcy-hs' : '' ?>"><?= $ic ?></td>
            <?php endforeach; ?>
            <td class="pcy-oz"><?= (int)$s['ozet']['gun'] ?></td>
            <td class="pcy-oz"><?= $gunluk ? (int)$s['ozet']['toplam'] : h(pc_sayi_fmt($s['ozet']['toplam'])) ?></td>
            <td class="pcy-oz<?= $s['ozet']['eksik'] >= 1 ? ' pcy-uyari' : '' ?>"><?= (int)$s['ozet']['eksik'] >= 1 ? '! ' . (int)$s['ozet']['eksik'] : '0' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
        <tr>
            <td class="pcy-ad"><?= $gunluk ? 'O gün toplam işçi' : 'O gün çalışan' ?></td>
            <?php foreach ($gunler as $g): $n = (int)($tablo['gun_say'][$g['tarih']] ?? 0); ?><td><?= $n > 0 ? $n : '·' ?></td><?php endforeach; ?>
            <td colspan="3"></td>
        </tr>
        <tr>
            <td class="pcy-ad">GENEL TOPLAM (<?= (int)$genel['kisi'] ?>)</td>
            <td colspan="<?= count($gunler) ?>"></td>
            <td class="pcy-oz"><?= (int)$genel['gun'] ?></td>
            <td class="pcy-oz"><?= $gunluk ? (int)$genel['toplam'] : h(pc_sayi_fmt($genel['toplam'])) ?></td>
            <td class="pcy-oz"><?= (int)$genel['eksik'] ?></td>
        </tr>
        </tfoot>
    </table>
    <?php endif; ?>
</div>
<?php render_print_page_end(); ?>
