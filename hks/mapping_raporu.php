<?php
// HKS BildirimKaydet Mapping Raporu — PDF (resmi kılavuz) + WSDL karşılaştırması.
// SALT OKUMA / RAPOR. Hiçbir alanı otomatik onaylamaz, gönderim açmaz.
// KESİN KURAL: isim benzerliğiyle otomatik mapping yok; her satır PDF + WSDL kaynaklı.
declare(strict_types=1);
require_once __DIR__ . '/includes/hks_bootstrap.php';
$auth_user = require_login();
if (!is_admin() && !(function_exists('can') && can('hks.settings'))) {
    http_response_code(403); die('Bu sayfa yalnızca yöneticiler içindir.');
}

$spec        = hks_payload_field_spec();
$confirmed   = hks_wsdl_all_confirmed_field_names();   // WSDL introspeksiyon set'i (cache)
$introspected = !empty($confirmed);
$approved    = hks_mapping_approved();
$gate_open   = hks_payload_fields_confirmed();

// DTO gruplama (rapor sırası)
$dto_order = [
    'BildirimKayitIstek'         => '📨 BildirimKayitIstek (üst seviye)',
    'BildirimciBilgileri'        => '👤 BildirimciBilgileri',
    'IkinciKisiBilgileri'        => '🧑‍🤝‍🧑 IkinciKisiBilgileri (karşı taraf)',
    'BildirimMalBilgileri'       => '📦 BildirimMalBilgileri',
    'MalinGidecekYerBilgileri'   => '🚚 MalinGidecekYerBilgileri',
];

// Durum sayaçları
$counts = ['pdf_wsdl'=>0, 'pdf'=>0, 'wsdl'=>0, 'belirsiz'=>0, 'eslesmedi'=>0];
foreach ($spec as $row) { $counts[hks_payload_field_match_status($row)]++; }

$status_meta = [
    'pdf_wsdl'  => ['PDF + WSDL', '#065f46', '#d1fae5'],
    'pdf'       => ['PDF',        '#1e40af', '#dbeafe'],
    'wsdl'      => ['WSDL',       '#5b21b6', '#ede9fe'],
    'belirsiz'  => ['Belirsiz',   '#854d0e', '#fef9c3'],
    'eslesmedi' => ['Eşleşmedi',  '#991b1b', '#fee2e2'],
];

render_header('HKS Mapping Raporu');
render_flash();
?>
<div class="hks-page" style="max-width:1180px;margin:0 auto">
<?php $hks_active_tab = 'mapping_raporu.php'; @include __DIR__ . '/views/_tabs.php'; ?>

<div class="page-head" style="margin-top:16px">
    <div>
        <h1>🗺 BildirimKaydet Mapping Raporu</h1>
        <p class="muted">Resmi GTB kılavuzu (Sürüm 0.1.7) + canlı WSDL karşılaştırması — yalnız rapor, otomatik onay yok.</p>
    </div>
    <div class="page-head-actions">
        <a href="teknik.php" class="btn btn-ghost">← Teknik Panel</a>
        <a href="wsdl_tipleri.php?service=bildirim&run=1" class="btn btn-ghost">🧩 WSDL Tipleri</a>
    </div>
</div>

<!-- Gönderim kilidi durumu -->
<div class="card" style="padding:14px 16px;margin-bottom:14px;border-left:4px solid <?= $gate_open ? 'var(--success)' : 'var(--danger)' ?>">
    <div style="font-weight:700;font-size:1rem">
        <?= $gate_open ? '🔓 Mapping onaylı — alan kilidi AÇIK' : '🔒 Mapping onaysız — alan kilidi KAPALI' ?>
    </div>
    <div class="muted" style="font-size:.86rem;margin-top:4px">
        WSDL introspeksiyonu: <strong><?= $introspected ? '✅ yapıldı ('.count($confirmed).' alan)' : '❌ yapılmadı' ?></strong> ·
        Kullanıcı onayı: <strong><?= $approved ? '✅ verildi' : '❌ verilmedi' ?></strong> ·
        HKS_PAYLOAD_FIELDS_CONFIRMED: <strong><?= HKS_PAYLOAD_FIELDS_CONFIRMED ? 'true' : 'false' ?></strong>
    </div>
    <div class="muted" style="font-size:.82rem;margin-top:6px">
        ⚠️ Kilit yalnızca açık onayla açılır. WSDL alan adlarının görülmesi tek başına onay sayılmaz.
        Onay ayrı bir sprintte, bu rapor incelendikten sonra verilecektir.
    </div>
</div>

<!-- Özet -->
<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px">
<?php foreach ($status_meta as $key => [$lbl, $fg, $bg]): ?>
    <span style="background:<?= $bg ?>;color:<?= $fg ?>;padding:6px 12px;border-radius:8px;font-size:.84rem;font-weight:600">
        <?= $lbl ?>: <?= (int)$counts[$key] ?>
    </span>
<?php endforeach; ?>
</div>

<?php if (!$introspected): ?>
<div style="background:#fffbeb;border:1px solid #f59e0b;border-radius:8px;padding:11px 14px;margin-bottom:14px;font-size:.88rem;color:#92400e">
    ℹ️ WSDL henüz introspeksiyonla okunmadı; "WSDL" sütunu kılavuz beklentisini gösterir ama canlı doğrulanmadı.
    <a href="wsdl_tipleri.php?service=bildirim&run=1">WSDL Tipleri → WSDL Oku</a> ile doğrulayın.
</div>
<?php endif; ?>

<!-- Mapping tabloları (DTO bazlı) -->
<?php foreach ($dto_order as $dto_key => $dto_title):
    $rows = array_values(array_filter($spec, fn($r) => $r['dto'] === $dto_key));
    if (!$rows) continue;
?>
<h3 style="margin-top:20px"><?= hks_h($dto_title) ?></h3>
<div class="table-wrap"><table style="width:100%;border-collapse:collapse;font-size:.8rem">
    <thead><tr style="background:var(--bg)">
        <th style="padding:6px 7px;text-align:left">Bizim Alan</th>
        <th style="padding:6px 7px;text-align:left">PDF Alanı</th>
        <th style="padding:6px 7px;text-align:left">WSDL Alanı</th>
        <th style="padding:6px 7px;text-align:left">Tip</th>
        <th style="padding:6px 7px;text-align:left">Zor.</th>
        <th style="padding:6px 7px;text-align:left">Yardımcı Servis</th>
        <th style="padding:6px 7px;text-align:left">Dönüşüm</th>
        <th style="padding:6px 7px;text-align:left">Durum</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r):
        $st = hks_payload_field_match_status($r);
        [$slbl, $sfg, $sbg] = $status_meta[$st];
        $wsdl_seen = trim((string)$r['wsdl_field']) !== '' && hks_payload_field_is_confirmed($r['wsdl_field']);
        $local = trim((string)$r['local']);
        $ref_sync = $r['ref_type'] ? hks_reference_type_synced($r['ref_type']) : null;
    ?>
        <tr>
            <td style="padding:5px 7px;border-bottom:1px solid var(--border)">
                <?php if ($local !== ''): ?><code><?= hks_h($local) ?></code>
                <?php else: ?><span style="color:#b91c1c" title="Formda karşılığı yok">— yok —</span><?php endif; ?>
            </td>
            <td style="padding:5px 7px;border-bottom:1px solid var(--border);font-family:monospace">
                <?= $r['pdf_field'] !== '' ? hks_h($r['pdf_field']) : '<span class="muted">—</span>' ?>
            </td>
            <td style="padding:5px 7px;border-bottom:1px solid var(--border);font-family:monospace">
                <?= $r['wsdl_field'] !== '' ? hks_h($r['wsdl_field']) : '<span class="muted">—</span>' ?>
                <?php if ($r['wsdl_field'] !== ''): ?>
                    <?= $wsdl_seen ? ' <span title="introspeksiyonla görüldü" style="color:#065f46">✓</span>'
                                   : ' <span title="introspeksiyonla doğrulanmadı" style="color:#9ca3af">○</span>' ?>
                <?php endif; ?>
            </td>
            <td style="padding:5px 7px;border-bottom:1px solid var(--border);color:var(--muted)"><?= hks_h($r['hks_type']) ?></td>
            <td style="padding:5px 7px;border-bottom:1px solid var(--border);text-align:center"><?= $r['required'] ? '<strong style="color:#b91c1c">E</strong>' : '<span class="muted">—</span>' ?></td>
            <td style="padding:5px 7px;border-bottom:1px solid var(--border);font-size:.74rem">
                <?php if ($r['helper'] !== ''): ?>
                    <?= hks_h($r['helper']) ?>
                    <?php if ($ref_sync !== null): ?>
                        <br><span style="font-size:.7rem;color:<?= $ref_sync ? '#065f46' : '#854d0e' ?>">
                            <?= $ref_sync ? '✓ '.$r['ref_type'].' senkron' : '⚠ '.$r['ref_type'].' senkron değil' ?>
                        </span>
                    <?php endif; ?>
                <?php else: ?><span class="muted">—</span><?php endif; ?>
            </td>
            <td style="padding:5px 7px;border-bottom:1px solid var(--border);font-size:.74rem;color:var(--muted)"><?= hks_h($r['transform']) ?></td>
            <td style="padding:5px 7px;border-bottom:1px solid var(--border)">
                <span style="background:<?= $sbg ?>;color:<?= $sfg ?>;padding:2px 7px;border-radius:8px;font-size:.72rem;font-weight:600;white-space:nowrap"><?= $slbl ?></span>
            </td>
        </tr>
        <?php if (trim((string)$r['note']) !== ''): ?>
        <tr><td colspan="8" style="padding:2px 7px 7px;border-bottom:1px solid var(--border);font-size:.72rem;color:#6b7280">↳ <?= hks_h($r['note']) ?></td></tr>
        <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endforeach; ?>

<!-- Payload'a girmeyen / eksik local alanlar -->
<h3 style="margin-top:24px">⚠️ Bilinen Boşluklar &amp; Notlar</h3>
<div class="card" style="padding:14px 16px;font-size:.86rem;line-height:1.7">
    <p style="margin:0 0 8px"><strong>İstek gövdesine GİRMEYEN local alanlar</strong> (mapping dışı, gönderimi engellemez):</p>
    <ul style="margin:0 0 12px;padding-left:20px;color:#4b5563">
        <li><code>bildirimci_tc_vkn</code>, <code>firma</code> → HKS hesabı (UserName) üzerinden örtük gelir; istekte alan yok.</li>
        <li><code>uretici_tc_vkn</code>, <code>uretici_ad</code> → BildirimKayitIstek'te üretici alanı yok. (Çalışma prensiplerinde "İthalat değilse UreticiTcKimlikVergiNo boş olamaz" kuralı, "Üreticiden Sevk Alım" senaryosunda <em>IkinciKisi.TcKimlikVergiNo</em> ile karşılanır.)</li>
        <li><code>gidecek_sahibi_tc</code> → istekte TC ile değil <code>GidecekIsyeriId</code> (id) ile gider.</li>
        <li><code>sevk_tarihi</code> → istekte tarih alanı yok; HKS kayıt anında <code>KayitTarihi</code> atar.</li>
        <li><code>para_birimi</code> → istekte para birimi alanı yok (fiyat double, TL varsayılır).</li>
    </ul>
    <p style="margin:0 0 8px"><strong>FORM ALANI EKSİK olan zorunlu/koşullu HKS alanları</strong> (eklenmesi gerekebilir):</p>
    <ul style="margin:0 0 12px;padding-left:20px;color:#4b5563">
        <li><code>GelenUlkeId</code> — İthalat sıfatında zorunlu. Formda <code>gelen_ulke</code> alanı yok.</li>
        <li><code>GidecekYerIlId / GidecekYerIlceId / GidecekYerBeldeId</code> — kayıtsız ikinci kişide zorunlu. Form yalnızca <em>üretim</em> il/ilçe/belde tutuyor; gideceği yer için ayrı alan yok.</li>
    </ul>
    <p style="margin:0 0 8px"><strong>Belirsiz alanlar</strong> (WSDL'de var, kılavuzda açıklama yok — gönderim için kullanılmadan netleştirilmeli):</p>
    <ul style="margin:0;padding-left:20px;color:#4b5563">
        <li><code>DogumTarihi</code> (IkinciKisi), <code>BelgeNo</code> / <code>BelgeTipi</code> (MalinGidecekYer).</li>
    </ul>
</div>

<div style="height:30px"></div>
</div>
<?php render_footer(); ?>
