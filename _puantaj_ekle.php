<?php
// =========================================================
// _puantaj_ekle.php — "Geçmişe Dönük Çalışma Ekle" penceresi (v291, YALNIZ yönetici)
//
// gunluk_isci_puantaj_detay.php (sabit çavuş + mesai günü) ve
// gunluk_isci_puantaj.php (çavuş seçilir, gün = filtredeki geçmiş gün) bu
// partial'ı include eder — iki kopya ayrışmasın diye. Partial fonksiyon
// TANIMLAMAZ, DB'ye dokunmaz; yalnız aşağıdaki değişkenleri çizer. Yazma
// işi pdks_faz8j_gecmis_ekle()'dedir (kurallar orada, UI yalnız sunar).
//
// Beklenen değişkenler:
//   $ekleWorkDate     — 'Y-m-d' mesai günü (sabit)
//   $ekleKartlar      — [['id'=>,'card_no'=>], …] o gün BOŞ kartlar
//   $ekleTipler       — [['id'=>,'name'=>], …] KADIN/ERKEK (tek politika)
//   $ekleSabitCavus   — ['id'=>,'name'=>] (detay) YA DA null
//   $ekleCavuslar     — [['id'=>,'name'=>,'is_active'=>], …] ($ekleSabitCavus null iken)
// v294: "— Kartsız mesai —" seçeneği (değer 'kartsiz'; sayfa işleyicisi bunu
// kartsiz=1 alanına çevirir, (int) dönüşümüne GÜVENİLMEZ) + bugün için çıkış opsiyonel.
// =========================================================
if (!isset($ekleWorkDate, $ekleKartlar, $ekleTipler)) { http_response_code(404); exit; }
$ekleSabitCavus = $ekleSabitCavus ?? null;
$ekleCavuslar   = $ekleCavuslar ?? [];
$ekleTrTarih    = date('d.m.Y', strtotime($ekleWorkDate));
$ekleBugun      = ($ekleWorkDate === date('Y-m-d'));
?>
<?php
// v296-B: satır içi SVG simgeleri (stroke currentColor, harici varlık YOK). Fonksiyon tanımlamaz — kapanış.
$ekleIk = function (string $ad): string {
    static $y = [
        'takvim-arti' => '<rect x="3" y="4.5" width="18" height="16" rx="3"/><path d="M8 2.5v4M16 2.5v4M3 9.5h18M12 12.5v5M9.5 15h5"/>',
        'takvim'      => '<rect x="3" y="4.5" width="18" height="16" rx="3"/><path d="M8 2.5v4M16 2.5v4M3 9.5h18"/>',
        'kart'        => '<rect x="2.5" y="5" width="19" height="14" rx="3"/><path d="M2.5 10h19M6.5 15h4"/>',
        'kisiler'     => '<circle cx="9" cy="8" r="3.2"/><path d="M3 20c0-3.3 2.7-6 6-6s6 2.7 6 6"/><path d="M16 5.2a3.2 3.2 0 0 1 0 5.6M18.5 14.4c1.6.9 2.5 2.7 2.5 5.6"/>',
        'kisi'        => '<circle cx="12" cy="8" r="3.6"/><path d="M4.5 20.5c0-4 3.4-7 7.5-7s7.5 3 7.5 7"/>',
        'saat'        => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.2 2"/>',
        'belge'       => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
        'balon'       => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3h11A2.5 2.5 0 0 1 20 5.5v8a2.5 2.5 0 0 1-2.5 2.5H11l-4.5 4v-4h0A2.5 2.5 0 0 1 4 13.5z"/>',
        'bilgi'       => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.6v.2"/>',
        'kapat'       => '<path d="M6 6l12 12M18 6L6 18"/>',
        'arti-daire'  => '<circle cx="12" cy="12" r="9"/><path d="M12 8v8M8 12h8"/>',
    ];
    return '<svg class="ekle-ik" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($y[$ad] ?? '') . '</svg>';
};
?>
<dialog id="ekle" class="pm-dialog isk-card-modal ekle-v2" aria-labelledby="ekleBaslik">
<div class="pm-header ekle-head">
    <span class="ekle-rozet"><?= $ekleIk('takvim-arti') ?></span>
    <h2 class="pm-title" id="ekleBaslik">Çalışma Ekle</h2>
    <button type="button" class="pm-close ekle-kapat" aria-label="Kapat" onclick="this.closest('dialog').close()"><?= $ekleIk('kapat') ?></button>
</div>
<form method="post" class="ekle-form" data-bugun="<?= $ekleBugun ? '1' : '0' ?>">
    <div class="ekle-body">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="puantaj_ekle">
    <input type="hidden" name="istek_id" value="<?= h(bin2hex(random_bytes(16))) ?>"><?php /* tekrar gönderim (çift tıklama/F5) koruması */ ?>
    <input type="hidden" name="work_date" value="<?= h($ekleWorkDate) ?>">
    <input type="hidden" name="entry_date" value="<?= h($ekleWorkDate) ?>">
    <div class="ekle-bilgi">
        <?= $ekleIk('bilgi') ?>
        <p>
            Mesai günü: <strong><?= h($ekleTrTarih) ?></strong>. Kayıt, kart okutulmuş gibi puantaj, hakediş ve raporlarda sayılır;
            listede <strong>✍ Elle eklendi</strong> olarak işaretlenir ve işlem geçmişine yazılır.
            <?php if ($ekleBugun): ?>Bugün için çıkış saati boş bırakılabilir.<?php endif; ?>
        </p>
    </div>
    <div class="pdks-form-grid ekle-grid">
        <?php if ($ekleSabitCavus): ?>
        <input type="hidden" name="foreman_id" value="<?= (int)$ekleSabitCavus['id'] ?>">
        <div class="span-2 ekle-cavus"><span class="ekle-avatar"><?= $ekleIk('kisi') ?></span><div><span class="ekle-cavus-et">Çavuş</span><strong><?= h($ekleSabitCavus['name']) ?></strong></div></div>
        <?php else: ?>
        <label class="span-2 ekle-cavus"><span class="ekle-avatar"><?= $ekleIk('kisi') ?></span>
            <span class="ekle-cavus-kap"><span class="ekle-cavus-et">Çavuş <span class="ekle-gerekli">*</span></span>
            <select name="foreman_id" required>
                <option value="">— Çavuş seçin —</option>
                <?php foreach ($ekleCavuslar as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?><?= empty($c['is_active']) ? ' (pasif)' : '' ?></option>
                <?php endforeach; ?>
            </select></span>
        </label>
        <?php endif; ?>
        <label class="span-2"><span class="form-label"><?= $ekleIk('kart') ?>Kart <span class="ekle-gerekli">*</span></span>
            <span class="ekle-sel"><?= $ekleIk('kart') ?>
            <select name="worker_card_id" required>
                <option value="">— Boş kart seçin —</option>
                <option value="kartsiz">— Kartsız mesai —</option>
                <?php foreach ($ekleKartlar as $k): ?>
                <option value="<?= (int)$k['id'] ?>"><?= h($k['card_no']) ?></option>
                <?php endforeach; ?>
            </select></span>
        </label>
        <label class="span-2"><span class="form-label"><?= $ekleIk('kisiler') ?>İşçi tipi <span class="ekle-gerekli">*</span></span>
            <select name="worker_type_id" required>
                <option value="">— Seçin —</option>
                <?php foreach ($ekleTipler as $t): ?>
                <option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span class="form-label"><?= $ekleIk('saat') ?>Giriş saati <span class="ekle-gerekli">*</span></span><input name="entry_clock" type="time" required></label>
        <label><span class="form-label"><?= $ekleIk('saat') ?>Çıkış saati <span class="ekle-gerekli ekle-zorunlu">*</span></span><input name="exit_clock" type="time" required></label>
        <div><span class="form-label"><?= $ekleIk('takvim') ?>Giriş günü</span><div class="ekle-ro"><?= h($ekleTrTarih) ?></div></div>
        <label><span class="form-label"><?= $ekleIk('takvim') ?>Çıkış günü <span class="ekle-gerekli ekle-zorunlu">*</span></span><input name="exit_date" type="date" value="<?= h($ekleWorkDate) ?>" required></label>
        <div class="span-2 ekle-bilgi ekle-cikis-ipucu" hidden><?= $ekleIk('bilgi') ?><p></p></div>
        <label class="span-2"><span class="form-label"><?= $ekleIk('belge') ?>Ekleme nedeni <span class="ekle-gerekli">*</span></span><textarea name="reason" maxlength="500" required rows="2" placeholder="Örn. Dün kartı okutmayı unuttu"></textarea></label>
        <label class="span-2"><span class="form-label"><?= $ekleIk('balon') ?>Açıklama</span><textarea name="note" maxlength="1000" rows="2" placeholder="Varsa eklemek istediğiniz açıklama..."></textarea></label>
    </div>
    </div>
    <div class="ekle-foot"><button class="btn btn-primary ekle-btn-ekle" type="submit"><?= $ekleIk('arti-daire') ?><span>Ekle</span></button><button type="button" class="btn ekle-btn-vazgec" onclick="this.closest('dialog').close()"><?= $ekleIk('kapat') ?><span>Vazgeç</span></button></div>
</form>
<script>
// v294: kart seçimine göre çıkış alanlarının zorunluluğu. Kartsız = çıkış ZORUNLU;
// bugün + kartlı = çıkış opsiyonel (boşsa kişi "içeride" yazılır, çıkışta kartını okutur);
// geçmiş gün = çıkış zorunlu. Sunucu aynı kuralı yeniden doğrular.
(function () {
    var f = document.currentScript.previousElementSibling;
    if (!f || f.tagName !== 'FORM') return;
    var bugun = f.getAttribute('data-bugun') === '1';
    var kart = f.elements['worker_card_id'], ed = f.elements['exit_date'], ec = f.elements['exit_clock'];
    var ipucu = f.querySelector('.ekle-cikis-ipucu'), yildiz = f.querySelectorAll('.ekle-zorunlu');
    function guncelle() {
        var kartsiz = kart.value === 'kartsiz';
        var zorunlu = kartsiz || !bugun;
        ed.required = ec.required = zorunlu;
        for (var i = 0; i < yildiz.length; i++) yildiz[i].hidden = !zorunlu;
        ipucu.hidden = !(kartsiz || bugun);
        ipucu.lastElementChild.textContent = kartsiz ? 'Kartsız mesaide çıkış tarihi ve saati zorunludur (kayıt kapalı yazılır).'
                                    : 'Çıkış saati boş bırakılırsa kişi içeride yazılır, çıkışta kartını okutur.';
    }
    kart.addEventListener('change', guncelle);
    f.addEventListener('submit', function (e) {
        // Çift tıklama: ikinci gönderim engellenir (sunucu istek_id ile ayrıca korur).
        if (f.getAttribute('data-gonderildi') === '1') { e.preventDefault(); return; }
        // Çıkış saati boşsa (yalnız bugün + kartlı) varsayılan çıkış gününü de boşalt: tarih+saat birlikte gelmeli.
        if (!ec.required && !ec.value) ed.value = '';
        f.setAttribute('data-gonderildi', '1');
        var b = f.querySelector('button.btn-primary'); if (b) { b.disabled = true; var sp = b.querySelector('span'); (sp || b).textContent = 'Ekleniyor…'; }
    });
    guncelle();
})();
</script>
</dialog>
