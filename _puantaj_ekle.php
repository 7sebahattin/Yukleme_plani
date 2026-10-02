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
// =========================================================
if (!isset($ekleWorkDate, $ekleKartlar, $ekleTipler)) { http_response_code(404); exit; }
$ekleSabitCavus = $ekleSabitCavus ?? null;
$ekleCavuslar   = $ekleCavuslar ?? [];
$ekleTrTarih    = date('d.m.Y', strtotime($ekleWorkDate));
?>
<dialog id="ekle" class="pm-dialog isk-card-modal">
<div class="pm-header"><h2 class="pm-title">Geçmişe Dönük Çalışma Ekle</h2><button type="button" class="pm-close" onclick="this.closest('dialog').close()">✕</button></div>
<form method="post" class="isk-card-modal-body">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="action" value="puantaj_ekle">
    <input type="hidden" name="work_date" value="<?= h($ekleWorkDate) ?>">
    <input type="hidden" name="entry_date" value="<?= h($ekleWorkDate) ?>">
    <p class="muted" style="margin:0 0 12px;font-size:.88rem">
        Mesai günü: <strong><?= h($ekleTrTarih) ?></strong>. Kayıt, kart okutulmuş gibi puantaj, hakediş ve raporlarda sayılır;
        listede <strong>✍ Elle eklendi</strong> olarak işaretlenir ve işlem geçmişine yazılır.
    </p>
    <div class="pdks-form-grid">
        <?php if ($ekleSabitCavus): ?>
        <input type="hidden" name="foreman_id" value="<?= (int)$ekleSabitCavus['id'] ?>">
        <div class="span-2"><span class="form-label">Çavuş</span><div><strong><?= h($ekleSabitCavus['name']) ?></strong></div></div>
        <?php else: ?>
        <label class="span-2"><span class="form-label">Çavuş *</span>
            <select name="foreman_id" required>
                <option value="">— Çavuş seçin —</option>
                <?php foreach ($ekleCavuslar as $c): ?>
                <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?><?= empty($c['is_active']) ? ' (pasif)' : '' ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <?php endif; ?>
        <label class="span-2"><span class="form-label">Kart *</span>
            <select name="worker_card_id" required>
                <option value="">— Boş kart seçin —</option>
                <?php foreach ($ekleKartlar as $k): ?>
                <option value="<?= (int)$k['id'] ?>"><?= h($k['card_no']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="span-2"><span class="form-label">İşçi tipi *</span>
            <select name="worker_type_id" required>
                <option value="">— Seçin —</option>
                <?php foreach ($ekleTipler as $t): ?>
                <option value="<?= (int)$t['id'] ?>"><?= h($t['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label><span class="form-label">Giriş saati *</span><input name="entry_clock" type="time" required></label>
        <div><span class="form-label">Giriş günü</span><div class="muted" style="padding-top:8px"><?= h($ekleTrTarih) ?></div></div>
        <label><span class="form-label">Çıkış günü *</span><input name="exit_date" type="date" value="<?= h($ekleWorkDate) ?>" required></label>
        <label><span class="form-label">Çıkış saati *</span><input name="exit_clock" type="time" required></label>
        <label class="span-2"><span class="form-label">Ekleme nedeni *</span><textarea name="reason" maxlength="500" required placeholder="Örn. Dün kartı okutmayı unuttu"></textarea></label>
        <label class="span-2"><span class="form-label">Açıklama</span><textarea name="note" maxlength="1000"></textarea></label>
    </div>
    <div class="isk-card-form-actions"><button class="btn btn-primary">Ekle</button><button type="button" class="btn" onclick="this.closest('dialog').close()">Vazgeç</button></div>
</form>
</dialog>
