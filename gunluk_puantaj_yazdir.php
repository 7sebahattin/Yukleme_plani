<?php
// =========================================================
// gunluk_puantaj_yazdir.php — Çavuş Gün Sonu Puantaj Fişi (Faz 7)
//
// gunluk_isci_puantaj_detay.php'nin YAZDIRILABİLİR görünümü. Sprint
// Print-Arch-01'in KENDİ, zaten var olan mimarisini (config/print_helpers.php
// + assets/print_base.css) REUSE eder — bu dosya "consistent with the repo"
// (kullanıcının açık talimatı) olacak şekilde YENİ bir print altyapısı
// İCAT ETMEZ; malzeme_stok_rapor.php İLE AYNI desen (ayrı, sade bir
// yazdırma sayfası — chrome/sidebar hiç render edilmez).
//
// SALT OKUNUR — kendi SQL'ini YAZMAZ. Veri gunluk_isci_puantaj_detay.php
// İLE BİREBİR AYNI paylaşılan fonksiyonlardan gelir (pdks_gunluk_oturum_ozet/
// pdks_gunluk_oturum_kartlari) — ikinci bir yoklama hesabı YOK.
//
// ⚠ Güvenlik (görev talimatı madde 21): yetki kontrolü require_pdks_gunluk()
// İLE, kaynak sayfayla AYNI ('daily_reports'). Faz 9A / M-01: yetki tek
// başına YETERLİ DEĞİLDİ — ?id= elle BAŞKA BİR DEPONUN mesaisine
// değiştirilebiliyordu (aynı yetkiye sahip her kullanıcı okuyabiliyordu).
// Artık pdks_gunluk_depo_kontrol() ile oturumun aktif depoya ait olduğu da
// ayrıca doğrulanıyor (bkz. aşağı).
//
// ⚠ Kart No basılır, KANONİK NFC UID BASILMAZ (görev talimatı: "Do NOT
// print canonical NFC UID unless there is a genuine operational need") —
// pdks_gunluk_oturum_kartlari() zaten yalnız card_no döner, UID hiç yok.
//
// ⚠ Eksik çıkışlar UYDURULMAZ — "Çıkış Saati" boşsa boş kalır, "⚠️ Eksik
// Çıkış" rozetiyle AÇIKÇA işaretlenir (görev talimatı madde 11 CRITICAL).
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/print_helpers.php';
$auth_user = require_login();
require_pdks_gunluk('daily_reports');

$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if (!$id) { set_flash('error', 'Geçersiz mesai.'); header('Location: gunluk_isci_puantaj.php'); exit; }

$st = $pdo->prepare("SELECT * FROM daily_work_sessions WHERE id = ?");
$st->execute([$id]);
$oturum = $st->fetch();
if (!$oturum) { set_flash('error', 'Mesai bulunamadı.'); header('Location: gunluk_isci_puantaj.php'); exit; }

// ⚠ Faz 9A / M-01 düzeltmesi: yukarıdaki eski yorum yalnız YETKİYE
// ('daily_reports') değiniyordu — DEPOYA değil. ?id= elle başka bir
// depoya ait bir mesaiye değiştirilerek onun puantaj fişi
// yazdırılabiliyordu. SUNUCU tarafında doğrulanır.
if ($depoHata = pdks_gunluk_depo_kontrol((string)$oturum['depo'])) {
    forbidden($depoHata);
}

$ozet = pdks_gunluk_oturum_ozet($id, $pdo);
$durum = pdks_gunluk_oturum_durumu((string)$oturum['status'], (int)$ozet['eksik_toplam']);
$kartlar = pdks_gunluk_oturum_kartlari($id, $pdo);

// Tüm işçi tipi anahtarları — GİRİŞ VEYA ÇIKIŞ kırılımında (dinamik,
// yalnız Kadın/Erkek HARDCODE edilmedi — görev talimatı madde 11).
$tumTipler = array_values(array_unique(array_merge(array_keys($ozet['giris']), array_keys($ozet['cikis']))));
sort($tumTipler);

render_print_page_start('Günlük İşçi Puantaj Fişi', 'daily', 'detail', 'portrait', ['print_pdks.css']);
?>
<div class="print-sheet">
    <div class="pr-actions no-print">
        <button type="button" onclick="window.print()" class="pr-btn pr-btn-primary">🖨️ Yazdır</button>
        <a href="gunluk_isci_puantaj_detay.php?id=<?= (int)$id ?>" class="pr-btn">← Mesai Detayına Dön</a>
    </div>

    <?= render_print_header_html(
        'GÜNLÜK İŞÇİ PUANTAJ FİŞİ',
        h($oturum['foreman_name_snapshot']) . ' (' . h($oturum['foreman_code_snapshot']) . ')' . ($oturum['depo'] ? ' · ' . h($oturum['depo']) : ''),
        'Tarih: ' . h(date('d.m.Y', strtotime($oturum['work_date']))) . ' · Yazdırma: ' . date('d.m.Y H:i')
    ) ?>

    <p style="margin:0 0 10px;font-size:.85rem">Mesai Durumu: <strong><?= h($durum['etiket']) ?></strong></p>

    <table class="print-table" style="margin-bottom:14px">
        <thead><tr>
            <th>Toplam İşçi</th>
            <?php foreach ($tumTipler as $tip): ?><th><?= h($tip) ?></th><?php endforeach; ?>
            <th>Tam Giriş/Çıkış</th>
            <th>Eksik Çıkış</th>
        </tr></thead>
        <tbody><tr>
            <td><strong><?= (int)$ozet['giris_toplam'] ?></strong></td>
            <?php foreach ($tumTipler as $tip): ?><td class="num"><?= (int)($ozet['giris'][$tip] ?? 0) ?></td><?php endforeach; ?>
            <td class="num"><?= (int)$ozet['cikis_toplam'] ?></td>
            <td<?= $ozet['eksik_toplam'] > 0 ? ' style="font-weight:800"' : '' ?>><?= (int)$ozet['eksik_toplam'] ?></td>
        </tr></tbody>
    </table>

    <table class="print-table">
        <thead><tr>
            <th>Kart No</th>
            <th>İşçi Tipi</th>
            <th>Giriş Saati</th>
            <th>Çıkış Saati</th>
            <th>Süre</th>
            <th>Mesai</th>
            <th>Durum</th>
        </tr></thead>
        <tbody>
        <?php foreach ($kartlar as $k): ?>
        <tr>
            <td><?= h($k['card_no']) ?></td>
            <td><?= h($k['tip']) ?></td>
            <td><?= h(date('H:i', strtotime($k['giris_saat']))) ?></td>
            <td><?= $k['cikis_saat'] ? h(date('H:i', strtotime($k['cikis_saat']))) : '' ?></td>
            <td><?= $k['cikis_saat'] ? h(pdks_gunluk_sure_etiketi($k['giris_saat'], $k['cikis_saat'])) : '' ?></td>
            <td><?= isset($k['mesai_sinifi_etiket']) ? h($k['mesai_sinifi_etiket']) : '—' ?></td>
            <td><?= $k['cikis_saat'] ? 'Tam' : '⚠️ Eksik Çıkış' ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($kartlar)): ?>
        <tr><td colspan="7" class="pr-empty">Bu mesaide kart hareketi yok.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($oturum['notes']): ?>
    <p style="margin-top:10px;font-size:.85rem"><strong>Not / Açıklama:</strong> <?= h($oturum['notes']) ?></p>
    <?php endif; ?>

    <div class="print-signatures">
        <div class="print-sig-box">Çavuş<br><br><br>Ad Soyad / İmza</div>
        <div class="print-sig-box">Kontrol Eden<br><br><br>Ad Soyad / İmza</div>
    </div>
</div>
<?php render_print_page_end(); ?>
