<?php
// HKS Operasyon — e-Bildirim (adım adım iskelet)
declare(strict_types=1);
require_once __DIR__ . '/../includes/hks_bootstrap.php';
$auth_user = require_login();
if (function_exists('can') && !can('hks.write') && !can('records.write')) {
    http_response_code(403); die('Erişim yetkiniz yok.');
}

require __DIR__ . '/views/_op_init.php';   // çıktıdan önce — guard + $op_* + $op_repo

// ── Taslak kaydet (POST) — mevcut repo akışını yeniden kullanır ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);

    $data = [
        'notification_type' => trim($_POST['notification_type'] ?? ''),
        'sifat'             => trim($_POST['sifat'] ?? '') ?: null,
        'direction'         => trim($_POST['direction'] ?? '') ?: null,
        'firma'             => trim($_POST['firma'] ?? ''),
        'urun'              => trim($_POST['urun'] ?? ''),
        'urun_cinsi'        => trim($_POST['urun_cinsi'] ?? '') ?: null,
        'miktar'            => hks_qty($_POST['miktar'] ?? 0),
        'birim'             => trim($_POST['birim'] ?? 'KG') ?: 'KG',
        'depo'              => trim($_POST['depo'] ?? '') ?: null,
        'il'                => trim($_POST['il'] ?? '') ?: null,
        'ilce'              => trim($_POST['ilce'] ?? '') ?: null,
        'belde'             => trim($_POST['belde'] ?? '') ?: null,
        'uretici_ad'        => trim($_POST['uretici_ad'] ?? '') ?: null,
        'uretici_tc_vkn'    => trim($_POST['uretici_tc_vkn'] ?? '') ?: null,
        'alici_ad'          => trim($_POST['alici_ad'] ?? '') ?: null,
        'alici_tc_vkn'      => trim($_POST['alici_tc_vkn'] ?? '') ?: null,
        'sevk_tarihi'       => trim($_POST['sevk_tarihi'] ?? '') ?: null,
        'arac_plaka'        => hks_plate(trim($_POST['arac_plaka'] ?? '')),
        'belge_no'          => trim($_POST['belge_no'] ?? '') ?: null,
        'reference_kunye_no'=> trim($_POST['reference_kunye_no'] ?? '') ?: null,
        'created_by'        => $auth_user['id'] ?? null,
    ];

    $new_id = $op_repo->createNotification($data);
    $op_repo->updateNotification($new_id, $data);   // doğrulama → draft/ready
    $created = $op_repo->getNotification($new_id);
    audit_log_event('hks_notification_draft_created', 'hks_notifications', $new_id, null,
        ['firma' => $data['firma'], 'urun' => $data['urun'], 'status' => $created['status'] ?? 'draft']);

    // İsteğe bağlı "kontrol edildi" işaretleme
    if (($_POST['mark_checked'] ?? '') === '1' && (($created['status'] ?? '') === 'ready')) {
        if ($op_repo->markChecked($new_id, $auth_user['id'] ?? null)) {
            audit_log_event('hks_notification_checked', 'hks_notifications', $new_id, null, ['status' => 'checked']);
        }
    }

    set_flash('success', 'Bildirim taslağı kaydedildi (' . hks_h($created['local_no'] ?? '') . ').');
    header('Location: ../bildirim_view.php?id=' . $new_id); exit;
}

// Referans verileri (dropdown)
$bildirim_turleri = $op_repo->getReferences('bildirim_turu');
$sifatlar         = $op_repo->getReferences('sifat');
$iller            = $op_repo->getReferences('il');
$depolar          = $op_repo->getReferences('depo');
$birimler         = $op_repo->getReferences('urun_birim');
$urun_cinsleri    = $op_repo->getReferences('urun_cins');
$urunler          = $op_repo->getReferences('urun');
$refs_missing     = empty($bildirim_turleri) || empty($urunler) || empty($iller);

$op_page_title  = 'e-Bildirim Oluştur';
$op_active_tab  = 'bildirimci';
$op_active_menu = 'bildirim_yeni.php';
include __DIR__ . '/views/_layout_start.php';
?>

<div class="hks-op-steps" id="opSteps">
    <div class="hks-op-step active" data-s="1"><span class="n">1</span> Bildirimci</div>
    <div class="hks-op-step" data-s="2"><span class="n">2</span> Referans Künye</div>
    <div class="hks-op-step" data-s="3"><span class="n">3</span> Mal Bilgisi</div>
    <div class="hks-op-step" data-s="4"><span class="n">4</span> Gideceği Yer</div>
    <div class="hks-op-step" data-s="5"><span class="n">5</span> Kontrol &amp; Kaydet</div>
</div>

<?php if ($refs_missing): ?>
<div class="hks-op-note warn">
    ⚠️ Referans listeleri eksik — bazı seçenekler boş görünebilir. <strong>HKS Teknik → Referanslar</strong> bölümünden senkronize edebilirsiniz.
</div>
<?php endif; ?>
<div class="hks-op-note info">
    ℹ️ Bu ekran bildirim <strong>taslağı</strong> oluşturur. HKS'ye canlı gönderim bu sprintte kapalıdır; gönderim ayrı bir adımda yapılır.
</div>

<form method="post" id="opForm" autocomplete="off">
    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="mark_checked" id="markChecked" value="0">

    <!-- ADIM 1 — Bildirimciye Ait Bilgiler -->
    <fieldset class="hks-op-fieldset op-pane" data-pane="1">
        <legend>Adım 1 — Bildirimciye Ait Bilgiler</legend>
        <div class="hks-op-row">
            <div class="hks-op-field">
                <label>TC / VKN</label>
                <input type="text" name="bildirimci_tc_vkn" maxlength="11" placeholder="11 haneli TC veya 10 haneli VKN">
            </div>
            <div class="hks-op-field">
                <label>Sıfat <span style="color:var(--danger)">*</span></label>
                <?php if ($sifatlar): ?>
                <select name="sifat"><?= hks_ref_options($sifatlar, '') ?></select>
                <?php else: ?>
                <input type="text" name="sifat" placeholder="Sıfat">
                <?php endif; ?>
            </div>
            <div class="hks-op-field">
                <label>Ad Soyad / Ünvan <span style="color:var(--danger)">*</span></label>
                <input type="text" name="firma" placeholder="Bildirimci ad soyad veya firma ünvanı">
            </div>
            <div class="hks-op-field">
                <label>Bildirim Türü <span style="color:var(--danger)">*</span></label>
                <?php if ($bildirim_turleri): ?>
                <select name="notification_type"><?= hks_ref_options($bildirim_turleri, '') ?></select>
                <?php else: ?>
                <input type="text" name="notification_type" placeholder="Bildirim türü">
                <?php endif; ?>
            </div>
            <div class="hks-op-field">
                <label>Yön</label>
                <select name="direction">
                    <option value="">— Seçin —</option>
                    <option value="giris">Giriş</option>
                    <option value="cikis">Çıkış</option>
                </select>
            </div>
            <div class="hks-op-field">
                <label>Yurt Dışı</label>
                <select name="yurt_disi">
                    <option value="0">Hayır</option>
                    <option value="1">Evet</option>
                </select>
            </div>
        </div>
    </fieldset>

    <!-- ADIM 2 — Referans Künye -->
    <fieldset class="hks-op-fieldset op-pane" data-pane="2" style="display:none">
        <legend>Adım 2 — Referans Künye</legend>
        <div class="hks-op-row">
            <div class="hks-op-field">
                <label>Künye No</label>
                <input type="text" name="reference_kunye_no" id="refKunyeNo" placeholder="Referans künye numarası (çıkışta zorunlu)">
            </div>
            <div class="hks-op-field">
                <label>Referans Künyede Kullanılan Ürün</label>
                <?php if ($urunler): ?>
                <select id="refUrunId"><?= hks_ref_options($urunler, '') ?></select>
                <?php else: ?>
                <input type="text" id="refUrunId" placeholder="Ürün">
                <?php endif; ?>
            </div>
            <div class="hks-op-field">
                <label>İşyeri Türü</label>
                <input type="text" name="isyeri_turu" placeholder="İşyeri türü (opsiyonel)">
            </div>
        </div>
        <button type="button" class="hks-op-btn hks-op-btn-ghost" id="btnRefKunye" <?= $op_queries_enabled ? '' : 'disabled' ?>>🔎 Künye Sorgula</button>
        <?php if (!$op_queries_enabled): ?><small class="muted" style="margin-left:8px">HKS bağlantısı yapılandırılmadığı için sorgu kapalı.</small><?php endif; ?>
        <div id="refKunyeResult" class="hks-op-result" style="display:none"></div>
    </fieldset>

    <!-- ADIM 3 — Mala İlişkin Bilgiler -->
    <fieldset class="hks-op-fieldset op-pane" data-pane="3" style="display:none">
        <legend>Adım 3 — Mala İlişkin Bilgiler</legend>
        <div class="hks-op-row">
            <div class="hks-op-field">
                <label>Malın Adı <span style="color:var(--danger)">*</span></label>
                <input type="text" name="urun" placeholder="Malın adı">
            </div>
            <div class="hks-op-field">
                <label>Malın Cinsi</label>
                <?php if ($urun_cinsleri): ?>
                <select name="urun_cinsi"><?= hks_ref_options($urun_cinsleri, '') ?></select>
                <?php else: ?>
                <input type="text" name="urun_cinsi" placeholder="Malın cinsi">
                <?php endif; ?>
            </div>
            <div class="hks-op-field">
                <label>Malın Niteliği</label>
                <input type="text" name="malin_niteligi" placeholder="Yaş / kuru vb.">
            </div>
            <div class="hks-op-field">
                <label>Mal Kaynağı</label>
                <select name="mal_kaynak">
                    <option value="">— Seçin —</option>
                    <option value="yerli">Yerli</option>
                    <option value="ithal">İthal</option>
                    <option value="toplama">Toplama Mal</option>
                </select>
            </div>
            <div class="hks-op-field">
                <label>Mal Miktarı <span style="color:var(--danger)">*</span></label>
                <input type="text" name="miktar" inputmode="decimal" placeholder="0">
            </div>
            <div class="hks-op-field">
                <label>Birim</label>
                <?php if ($birimler): ?>
                <select name="birim"><?= hks_ref_options($birimler, 'KG', false) ?></select>
                <?php else: ?>
                <input type="text" name="birim" value="KG">
                <?php endif; ?>
            </div>
            <div class="hks-op-field">
                <label>Birim Fiyatı</label>
                <input type="text" name="birim_fiyat" inputmode="decimal" placeholder="0">
            </div>
            <div class="hks-op-field">
                <label>Üretici Adı / Ticari Unvan</label>
                <input type="text" name="uretici_ad" placeholder="Üretici">
            </div>
            <div class="hks-op-field">
                <label>Üretici TC / VKN</label>
                <input type="text" name="uretici_tc_vkn" maxlength="11" placeholder="Üretici TC/VKN">
            </div>
            <div class="hks-op-field">
                <label>Depo / Şube</label>
                <?php if ($depolar): ?>
                <select name="depo"><?= hks_ref_options($depolar, '') ?></select>
                <?php else: ?>
                <input type="text" name="depo" placeholder="Depo / şube">
                <?php endif; ?>
            </div>
            <div class="hks-op-field">
                <label>İl</label>
                <?php if ($iller): ?>
                <select name="il"><?= hks_ref_options($iller, '') ?></select>
                <?php else: ?>
                <input type="text" name="il" placeholder="İl">
                <?php endif; ?>
            </div>
            <div class="hks-op-field">
                <label>İlçe</label>
                <input type="text" name="ilce" placeholder="İlçe">
            </div>
            <div class="hks-op-field">
                <label>Belde</label>
                <input type="text" name="belde" placeholder="Belde">
            </div>
        </div>
    </fieldset>

    <!-- ADIM 4 — Gideceği / Tüketime Sunulduğu Yer -->
    <fieldset class="hks-op-fieldset op-pane" data-pane="4" style="display:none">
        <legend>Adım 4 — Gideceği / Tüketime Sunulduğu Yer</legend>
        <div class="hks-op-row">
            <div class="hks-op-field">
                <label>Gideceği Yer Sahibi TC / VKN <span style="color:var(--danger)">*</span></label>
                <input type="text" name="alici_tc_vkn" maxlength="11" placeholder="Alıcı TC/VKN">
            </div>
            <div class="hks-op-field">
                <label>Gideceği Yer / Alıcı Adı <span style="color:var(--danger)">*</span></label>
                <input type="text" name="alici_ad" placeholder="Alıcı ad / ünvan">
            </div>
            <div class="hks-op-field">
                <label>Ülke</label>
                <input type="text" name="gidecek_ulke" value="Türkiye">
            </div>
            <div class="hks-op-field">
                <label>İl</label>
                <input type="text" name="gidecek_il" placeholder="Gideceği il">
            </div>
            <div class="hks-op-field">
                <label>İlçe</label>
                <input type="text" name="gidecek_ilce" placeholder="Gideceği ilçe">
            </div>
            <div class="hks-op-field">
                <label>Araç Plaka <span style="color:var(--danger)">*</span></label>
                <input type="text" name="arac_plaka" placeholder="34ABC123">
            </div>
            <div class="hks-op-field">
                <label>Belge No</label>
                <input type="text" name="belge_no" placeholder="İrsaliye / belge no">
            </div>
            <div class="hks-op-field">
                <label>Belge Tipi</label>
                <input type="text" name="belge_tipi" placeholder="İrsaliye vb.">
            </div>
            <div class="hks-op-field">
                <label>Sevk Tarihi <span style="color:var(--danger)">*</span></label>
                <input type="date" name="sevk_tarihi" value="<?= date('Y-m-d') ?>">
            </div>
        </div>
    </fieldset>

    <!-- ADIM 5 — Kontrol ve Taslak Kaydet -->
    <fieldset class="hks-op-fieldset op-pane" data-pane="5" style="display:none">
        <legend>Adım 5 — Kontrol &amp; Taslak Kaydet</legend>
        <div class="hks-op-note info" id="opSummaryNote">
            Bilgileri kontrol edin. Zorunlu (*) alanlar eksikse kayıt <strong>taslak</strong> olarak kalır; tümü tamamsa <strong>gönderime hazır</strong> olur.
        </div>
        <div id="opSummary" style="font-size:.88rem"></div>
        <label style="display:flex;align-items:center;gap:8px;margin:14px 0;font-size:.9rem">
            <input type="checkbox" id="cbChecked" style="width:auto"> Eksiksizse "Kontrol Edildi" olarak işaretle
        </label>
    </fieldset>

    <!-- Navigasyon -->
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px">
        <button type="button" class="hks-op-btn hks-op-btn-ghost" id="btnPrev" style="display:none">← Geri</button>
        <button type="button" class="hks-op-btn" id="btnNext">İleri →</button>
        <button type="submit" class="hks-op-btn" id="btnSave" style="display:none">💾 Taslak Kaydet</button>
        <a href="index.php" class="hks-op-btn hks-op-btn-ghost">Vazgeç</a>
    </div>
</form>

<script>
(function() {
    var cur = 1, max = 5;
    var panes = document.querySelectorAll('.op-pane');
    var steps = document.querySelectorAll('#opSteps .hks-op-step');
    var btnPrev = document.getElementById('btnPrev');
    var btnNext = document.getElementById('btnNext');
    var btnSave = document.getElementById('btnSave');
    var form    = document.getElementById('opForm');

    function show(n) {
        cur = Math.max(1, Math.min(max, n));
        panes.forEach(function(p){ p.style.display = (+p.dataset.pane === cur) ? '' : 'none'; });
        steps.forEach(function(s){ s.classList.toggle('active', +s.dataset.s <= cur); });
        btnPrev.style.display = cur > 1 ? '' : 'none';
        btnNext.style.display = cur < max ? '' : 'none';
        btnSave.style.display = cur === max ? '' : 'none';
        if (cur === max) buildSummary();
        window.scrollTo({top:0, behavior:'smooth'});
    }
    function val(name){ var el = form.querySelector('[name="'+name+'"]'); return el ? el.value.trim() : ''; }
    function buildSummary() {
        var rows = [
            ['Bildirim Türü', val('notification_type')], ['Sıfat', val('sifat')],
            ['Bildirimci', val('firma')], ['Yön', val('direction')],
            ['Referans Künye', val('reference_kunye_no')],
            ['Mal', val('urun')], ['Miktar', val('miktar') + ' ' + val('birim')],
            ['Üretici', val('uretici_ad')], ['Depo/Şube', val('depo')],
            ['İl / İlçe', (val('il') + ' / ' + val('ilce')).replace(/^ \/ | \/ $/,'')],
            ['Alıcı', val('alici_ad')], ['Alıcı TC/VKN', val('alici_tc_vkn')],
            ['Araç Plaka', val('arac_plaka')], ['Sevk Tarihi', val('sevk_tarihi')]
        ];
        var html = '<table class="hks-op-table"><tbody>';
        var missing = [];
        var req = {'Bildirim Türü':1,'Sıfat':1,'Bildirimci':1,'Mal':1,'Alıcı':1,'Alıcı TC/VKN':1,'Araç Plaka':1,'Sevk Tarihi':1};
        rows.forEach(function(r){
            var empty = !r[1] || r[1] === ' ';
            if (req[r[0]] && empty) missing.push(r[0]);
            html += '<tr><th style="width:42%">'+r[0]+'</th><td>'+(empty?'<span class="muted">—</span>':r[1].replace(/</g,'&lt;'))+'</td></tr>';
        });
        html += '</tbody></table>';
        if (missing.length) {
            html = '<div class="hks-op-note warn">Eksik zorunlu alan: '+missing.join(', ')+'</div>' + html;
        }
        document.getElementById('opSummary').innerHTML = html;
    }
    btnNext.addEventListener('click', function(){ show(cur+1); });
    btnPrev.addEventListener('click', function(){ show(cur-1); });
    document.getElementById('cbChecked').addEventListener('change', function(){
        document.getElementById('markChecked').value = this.checked ? '1' : '0';
    });

    // Künye Sorgula (AJAX) — mevcut query_referans_kunye aksiyonu
    var btnRef = document.getElementById('btnRefKunye');
    if (btnRef) {
        btnRef.addEventListener('click', function(){
            var urunEl = document.getElementById('refUrunId');
            var urunId = urunEl ? urunEl.value.trim() : '';
            var res = document.getElementById('refKunyeResult');
            if (!urunId) { alert('Önce ürün seçin.'); return; }
            btnRef.disabled = true; btnRef.textContent = '⏳ Sorgulanıyor...';
            res.style.display = 'none'; res.className = 'hks-op-result';
            var csrf = document.querySelector('meta[name="csrf-token"]').content;
            fetch('../ajax.php?action=query_referans_kunye', {
                method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
                body:'csrf='+encodeURIComponent(csrf)+'&urun_id='+encodeURIComponent(urunId)+'&kunye_no='+encodeURIComponent(document.getElementById('refKunyeNo').value.trim())
            }).then(function(r){return r.json();}).then(function(d){
                res.style.display='block'; res.classList.add(d.ok?'ok':'err');
                if (d.ok) {
                    var arr = d.data || [];
                    res.innerHTML = '✅ '+(Array.isArray(arr)?arr.length+' kayıt bulundu':'Sonuç alındı')+
                        '<details style="margin-top:6px"><summary style="cursor:pointer">Teknik detay</summary><pre style="white-space:pre-wrap;font-size:.78rem;max-height:240px;overflow:auto">'+JSON.stringify(arr,null,2)+'</pre></details>';
                } else {
                    res.innerHTML = '❌ '+(d.message || 'Künye bilgisi okunamadı.');
                }
            }).catch(function(){ res.style.display='block'; res.classList.add('err'); res.textContent='İstek gönderilemedi.'; })
            .finally(function(){ btnRef.disabled=false; btnRef.textContent='🔎 Künye Sorgula'; });
        });
    }
    show(1);
})();
</script>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
