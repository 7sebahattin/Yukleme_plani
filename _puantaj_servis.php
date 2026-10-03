<?php
// =========================================================
// _puantaj_servis.php — "🚌 Servis Ücreti" penceresi + Servisler listesi (v299)
//
// gunluk_isci_puantaj_detay.php include eder. Partial fonksiyon TANIMLAMAZ,
// DB'ye dokunmaz; yalnız aşağıdaki değişkenleri çizer. Yazma işi
// pdks_servis_ekle() / pdks_servis_iptal()'dedir (kurallar orada).
//
// Beklenen değişkenler:
//   $id            — mesai id
//   $oturum        — mesai satırı (foreman_id, foreman_name_snapshot, work_date)
//   $servisGoster  — pencere + İptal düğmeleri (yalnız yönetici, aktif depo = mesai deposu)
//   $servisListe   — pdks_servis_listele() (iptaller dahil)
//   $servisFiyat   — o günün geçerli foreman_service_rates satırı ya da null
//   $servisKesin   — kesin hakediş var mı (giriş/iptal kapalı)
//   $servisFiyatLink — fiyat sayfasına bağlantı gösterilsin mi (rates yetkisi)
// Düzenleme YOK: yanlış kayıt gerekçeyle iptal edilir (soft, listede soluk kalır).
// =========================================================
if (!isset($id, $oturum, $servisGoster, $servisListe)) { http_response_code(404); exit; }
$servisFiyat = $servisFiyat ?? null;
$servisKesin = !empty($servisKesin);
$servisFiyatLink = !empty($servisFiyatLink);
$svTurler = ['BUYUK' => ['ad' => 'Büyük', 'kolon' => 'big_rate', 'input' => 'buyuk', 'ik' => '🚌'],
             'KUCUK' => ['ad' => 'Küçük', 'kolon' => 'small_rate', 'input' => 'kucuk', 'ik' => '🚐']];
$svPara = $servisFiyat ? (trim((string)($servisFiyat['currency'] ?? '')) ?: 'TRY') : '';
$svFiyatVar = false;
foreach ($svTurler as $svT) {
    if ($servisFiyat && ($servisFiyat[$svT['kolon']] ?? null) !== null && (float)$servisFiyat[$svT['kolon']] > 0) $svFiyatVar = true;
}
$svToplam = ['BUYUK' => 0, 'KUCUK' => 0];
foreach ($servisListe as $sv) {
    if ((int)$sv['is_voided'] === 0 && isset($svToplam[(string)$sv['service_type']])) $svToplam[(string)$sv['service_type']] += (int)$sv['quantity'];
}
?>
<?php if ($servisGoster): ?>
<dialog id="servis" class="pm-dialog isk-card-modal sv-dialog" aria-labelledby="servisBaslik">
<div class="pm-header sv-head">
    <span class="sv-rozet" aria-hidden="true">🚌</span>
    <h2 class="pm-title" id="servisBaslik">Servis Ücreti</h2>
    <button type="button" class="pm-close" aria-label="Kapat" onclick="this.closest('dialog').close()">✕</button>
</div>
<form method="post" class="sv-form" id="servisForm">
    <div class="sv-body">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="servis_ekle">
    <input type="hidden" name="istek_id" value="<?= h(bin2hex(random_bytes(16))) ?>"><?php /* tekrar gönderim (çift tıklama/F5) koruması */ ?>
    <p class="sv-bilgi"><strong><?= h($oturum['foreman_name_snapshot']) ?></strong> · <?= h(date('d.m.Y', strtotime((string)$oturum['work_date']))) ?><br>
        <span class="muted">Servis adedi hakedişe tür başına <b>adet × fiyat</b> olarak eklenir. Aynı mesaiye birden çok kez eklenebilir; yanlış kayıt listeden iptal edilir.</span></p>
    <?php if ($servisKesin): ?>
    <p class="sv-uyari" role="alert">Bu mesainin kesinleşmiş hakedişi var — servis eklemek için önce hakedişi yeniden açın.</p>
    <?php elseif (!$svFiyatVar): ?>
    <p class="sv-bilgi-kutu" role="status">Bu çavuş için <?= h(date('d.m.Y', strtotime((string)$oturum['work_date']))) ?> tarihinde servis fiyatı henüz tanımlı değil. Servis <b>yine de kaydedilir</b>; fiyat tanımlanınca hakedişte kendiliğinden hesaplanır (fiyat tanımlanana kadar bu mesainin hakedişi hesaplanamaz). Fiyatı tanımlarken <b>geçerlilik başlangıcını bu tarih ya da öncesi</b> girin.<?php if ($servisFiyatLink): ?> <a href="cavus_fiyatlari.php?cavus=<?= (int)$oturum['foreman_id'] ?>#cfServisUcreti">Çavuş Ücretleri →</a><?php endif; ?></p>
    <?php endif; ?>
    <div class="sv-sayaclar">
    <?php foreach ($svTurler as $tur => $t):
        $svF = $servisFiyat ? ($servisFiyat[$t['kolon']] ?? null) : null;
        $svFiyatli = $svF !== null && (float)$svF > 0;
        $svAcik = !$servisKesin;                       // v300: fiyat olmasa da servis girilebilir
        $svKurus = ($svAcik && $svFiyatli) ? (int)round((float)$svF * 100) : 0; ?>
        <div class="sv-sayac<?= $svAcik ? '' : ' sv-kapali' ?>" data-sv-tur="<?= h($tur) ?>" data-fiyat-kurus="<?= $svKurus ?>">
            <div class="sv-sayac-ust">
                <span class="sv-ad"><span aria-hidden="true"><?= $t['ik'] ?></span> <?= h($t['ad']) ?></span>
                <span class="sv-fiyat"><?= $svFiyatli ? h(number_format((float)$svF, 2, ',', '.') . ' ' . $svPara) : 'fiyat henüz yok' ?></span>
            </div>
            <div class="sv-kontrol">
                <button type="button" class="btn sv-eksi" aria-label="<?= h($t['ad']) ?> azalt"<?= $svAcik ? '' : ' disabled' ?>>−</button>
                <input type="number" name="<?= h($t['input']) ?>" id="sv<?= h(ucfirst($t['input'])) ?>" min="0" max="<?= (int)PDKS_SERVIS_MAX_ADET ?>" step="1" value="0" inputmode="numeric" aria-label="<?= h($t['ad']) ?> servis adedi"<?= $svAcik ? '' : ' readonly' ?>>
                <button type="button" class="btn sv-arti" aria-label="<?= h($t['ad']) ?> artır"<?= $svAcik ? '' : ' disabled' ?>>+</button>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <div class="sv-ozet" id="svOzet" aria-live="polite">Toplam 0 servis</div>
    <label class="sv-not"><span class="form-label">Not</span><textarea name="note" maxlength="500" rows="2" placeholder="İsteğe bağlı (ör. akşam dönüşü)"></textarea></label>
    </div>
    <div class="sv-foot">
        <button type="submit" class="btn btn-primary" id="svKaydet" disabled>🚌 Servisi Ekle</button>
        <button type="button" class="btn" onclick="this.closest('dialog').close()">Vazgeç</button>
    </div>
</form>
</dialog>
<dialog id="servisIptal" class="pm-dialog isk-card-modal sv-dialog">
<div class="pm-header"><h2 class="pm-title">Servis Kaydını İptal Et</h2><button type="button" class="pm-close" aria-label="Kapat" onclick="this.closest('dialog').close()">✕</button></div>
<form method="post" class="isk-card-modal-body">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="servis_iptal">
    <input type="hidden" name="servis_id" id="svIptalId" value="">
    <p style="margin:0 0 10px"><strong id="svIptalEtiket"></strong> servis kaydı iptal edilir; kayıt silinmez, listede iptal olarak kalır ve hakediş yeniden hesaplanmalıdır.</p>
    <label><span class="form-label">İptal nedeni *</span><textarea name="reason" maxlength="500" required rows="3"></textarea></label>
    <div class="isk-card-form-actions"><button class="btn btn-danger">İptal Et</button><button type="button" class="btn" onclick="this.closest('dialog').close()">Vazgeç</button></div>
</form>
</dialog>
<?php endif; ?>

<?php if ($servisListe): ?>
<h2 style="font-size:1.05rem;margin-top:22px" id="servisler">🚌 Servisler <span class="muted sv-baslik-ozet">· <?= (int)$svToplam['BUYUK'] ?> Büyük, <?= (int)$svToplam['KUCUK'] ?> Küçük</span></h2>
<div class="table-wrap pc-only" style="margin-bottom:18px">
<table class="data-table sv-liste">
<thead><tr><th>Tarih / Saat</th><th>Tür</th><th>Adet</th><th>Not</th><th>Ekleyen</th><?php if ($servisGoster): ?><th class="actions-col">İşlem</th><?php endif; ?></tr></thead>
<tbody>
<?php foreach ($servisListe as $sv): $svIptal = (int)$sv['is_voided'] === 1; ?>
<tr class="<?= $svIptal ? 'sv-iptal' : '' ?>">
    <td><?= h(date('d.m.Y H:i', strtotime((string)$sv['created_at']))) ?></td>
    <td><?= h($sv['tur_ad']) ?></td>
    <td><strong><?= (int)$sv['quantity'] ?></strong></td>
    <td><?= h((string)($sv['note'] ?? '')) ?><?php if ($svIptal): ?><div class="sv-iptal-not">İptal: <?= h((string)$sv['void_reason']) ?> — <?= h($sv['iptal_eden']) ?></div><?php endif; ?></td>
    <td><?= h($sv['ekleyen']) ?></td>
    <?php if ($servisGoster): ?><td class="actions-col"><?php if (!$svIptal && !$servisKesin): ?><button type="button" class="btn btn-sm btn-danger" data-sv-iptal="<?= (int)$sv['id'] ?>" data-sv-etiket="<?= h((int)$sv['quantity'] . ' ' . $sv['tur_ad']) ?>">İptal</button><?php elseif ($svIptal): ?><span class="muted">İptal edildi</span><?php else: ?>—<?php endif; ?></td><?php endif; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<div class="pdks-cards mobile-only" style="margin-bottom:18px">
<?php foreach ($servisListe as $sv): $svIptal = (int)$sv['is_voided'] === 1; ?>
<div class="pdks-card-item<?= $svIptal ? ' sv-iptal' : '' ?>">
    <div class="pdks-row-sub"><?= h(date('d.m.Y H:i', strtotime((string)$sv['created_at']))) ?> · <?= h($sv['ekleyen']) ?></div>
    <div class="pdks-row-name" style="font-size:.95rem"><?= (int)$sv['quantity'] ?> <?= h($sv['tur_ad']) ?><?= $svIptal ? ' <span class="muted">(iptal)</span>' : '' ?></div>
    <?php if ((string)($sv['note'] ?? '') !== ''): ?><div class="pdks-row-sub"><?= h((string)$sv['note']) ?></div><?php endif; ?>
    <?php if ($svIptal): ?><div class="pdks-row-sub sv-iptal-not">İptal: <?= h((string)$sv['void_reason']) ?> — <?= h($sv['iptal_eden']) ?></div><?php endif; ?>
    <?php if ($servisGoster && !$svIptal && !$servisKesin): ?><div class="isk-card-form-actions"><button type="button" class="btn btn-sm btn-danger" data-sv-iptal="<?= (int)$sv['id'] ?>" data-sv-etiket="<?= h((int)$sv['quantity'] . ' ' . $sv['tur_ad']) ?>">İptal</button></div><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($servisGoster): ?>
<script>
(function () {
    // Detay sayfasının pdksPuantajDialogAc() tanımı kart listesi boşken çizilmez — yedek.
    if (typeof window.pdksPuantajDialogAc !== 'function') {
        window.pdksPuantajDialogAc = function (id) {
            document.querySelectorAll('dialog[open]').forEach(function (d) { d.close(); });
            document.getElementById(id).showModal();
        };
    }
    var f = document.getElementById('servisForm'); if (!f) return;
    var ozet = document.getElementById('svOzet'), btn = document.getElementById('svKaydet');
    var MAX = <?= (int)PDKS_SERVIS_MAX_ADET ?>, para = <?= json_encode($svPara, JSON_UNESCAPED_UNICODE) ?>;
    var sayaclar = Array.prototype.slice.call(f.querySelectorAll('.sv-sayac'));
    function deger(inp) { var v = parseInt(inp.value, 10); return isNaN(v) || v < 0 ? 0 : Math.min(v, MAX); }
    function tl(k) { return (k / 100).toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function guncelle() {
        var adet = 0, kurus = 0, parca = [];
        sayaclar.forEach(function (s) {
            var inp = s.querySelector('input'), n = deger(inp);
            if (s.classList.contains('sv-kapali')) n = 0;
            adet += n; kurus += n * (parseInt(s.getAttribute('data-fiyat-kurus'), 10) || 0);
            if (n > 0) parca.push(n + ' ' + s.querySelector('.sv-ad').textContent.replace(/^\S+\s/, ''));
        });
        ozet.textContent = adet > 0 ? ('Toplam ' + adet + ' servis (' + parca.join(', ') + ')' + (kurus > 0 ? ' · ' + tl(kurus) + ' ' + para : '')) : 'Toplam 0 servis';
        btn.disabled = adet < 1;
    }
    sayaclar.forEach(function (s) {
        var inp = s.querySelector('input');
        s.querySelector('.sv-eksi').addEventListener('click', function () { inp.value = Math.max(0, deger(inp) - 1); guncelle(); });
        s.querySelector('.sv-arti').addEventListener('click', function () { inp.value = Math.min(MAX, deger(inp) + 1); guncelle(); });
        inp.addEventListener('input', guncelle);
    });
    f.addEventListener('submit', function (e) {
        if (f.getAttribute('data-gonderildi') === '1' || btn.disabled) { e.preventDefault(); return; }
        f.setAttribute('data-gonderildi', '1');   // çift tıklama — sunucu istek_id ile ayrıca korur
        btn.disabled = true; btn.textContent = 'Ekleniyor…';
    });
    guncelle();
    document.querySelectorAll('[data-sv-iptal]').forEach(function (b) {
        b.addEventListener('click', function () {
            document.getElementById('svIptalId').value = b.getAttribute('data-sv-iptal');
            document.getElementById('svIptalEtiket').textContent = b.getAttribute('data-sv-etiket');
            window.pdksPuantajDialogAc('servisIptal');
        });
    });
})();
</script>
<?php endif; ?>
