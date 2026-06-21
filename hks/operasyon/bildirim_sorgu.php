<?php
// HKS Operasyon — Bildirim Sorgulama / İptal
declare(strict_types=1);
require_once __DIR__ . '/../includes/hks_bootstrap.php';
$auth_user = require_login();
if (function_exists('can') && !can('hks.read') && !can('records.write')) {
    http_response_code(403); die('Erişim yetkiniz yok.');
}

require __DIR__ . '/views/_op_init.php';

// BildirimSorguIstek.Sifat filtresi için sıfat listesi (referans, yoksa statik)
$sifatlar = $op_repo->getReferences('sifat');
$sifat_opts_q = '<option value="">Tümü</option>';
if ($sifatlar && !hks_refs_labels_numeric($sifatlar)) {
    $sifat_opts_q .= hks_ref_options($sifatlar, '', false);
} else {
    foreach (hks_sifat_list() as $s) {
        $sifat_opts_q .= '<option value="' . hks_h($s['code']) . '">' . hks_h($s['name']) . '</option>';
    }
}

$op_page_title  = 'HKS Bildirim Sorgulama';
$op_active_tab  = 'bildirimci';
$op_active_menu = 'bildirim_sorgu.php';
include __DIR__ . '/views/_layout_start.php';
?>

<?php if (!$op_queries_enabled): ?>
<div class="hks-op-note warn">⚠️ Sorgular için <strong>HKS Teknik → Ayarlar</strong> tamamlanmalı ve PHP SOAP eklentisi aktif olmalıdır.</div>
<?php else: ?>
<div class="hks-op-note info">📡 Ortam: <strong><?= hks_h(strtoupper($op_env)) ?></strong> — Sorgular HKS web servisine gönderilir.</div>
<?php endif; ?>

<div class="hks-op-note info" style="margin-bottom:16px">
    ℹ️ HKS'de <strong>tek bildirim numarası</strong> için ayrı bir sorgu servisi yoktur. Bir bildirimi
    bulmak için aşağıda <strong>Künye No + Künye Türü</strong> ile listeden sorgulayın.
</div>

<p class="hks-op-section-title">Bildirim Listeleri (HKS BildirimSorguIstek)</p>
<div class="hks-op-card">
    <div class="hks-op-row">
        <div class="hks-op-field">
            <label>Başlangıç Tarihi</label>
            <input type="date" id="blBas" value="<?= date('Y-m-01') ?>">
        </div>
        <div class="hks-op-field">
            <label>Bitiş Tarihi</label>
            <input type="date" id="blBit" value="<?= date('Y-m-d') ?>">
        </div>
        <div class="hks-op-field">
            <label>Künye No <small class="muted">(boş = tümü)</small></label>
            <input type="text" id="blKunye" placeholder="Boş = tümü">
        </div>
        <div class="hks-op-field">
            <label>Künye Türü</label>
            <select id="blKunyeTuru">
                <option value="">Tümü</option>
                <option value="1">1 — Referans</option>
                <option value="2">2 — Nihai Tüketim</option>
            </select>
        </div>
        <div class="hks-op-field">
            <label>Sıfat <small class="muted">(opsiyonel)</small></label>
            <select id="blSifat"><?= $sifat_opts_q ?></select>
        </div>
        <div class="hks-op-field" style="align-self:end">
            <label style="display:flex;align-items:center;gap:7px;font-weight:400">
                <input type="checkbox" id="blKalanPozitif" style="width:auto"> Yalnız kalan miktarı 0'dan büyük olanlar
            </label>
        </div>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="button" class="hks-op-btn" id="btnYaptigim" <?= $op_queries_enabled ? '' : 'disabled' ?>>📤 Yaptığım Bildirimler</button>
        <button type="button" class="hks-op-btn hks-op-btn-ghost" id="btnBana" <?= $op_queries_enabled ? '' : 'disabled' ?>>📥 Bana Yapılanlar</button>
        <button type="button" class="hks-op-btn hks-op-btn-ghost" id="btnSon30">🗓 Son 30 Günü Getir</button>
    </div>
    <div class="hks-op-result" id="rListe" style="display:none"></div>
</div>

<script>
(function(){
    var csrf = document.querySelector('meta[name="csrf-token"]').content;
    function run(action, payload, res, btn){
        btn.disabled = true; var orig = btn.textContent; btn.textContent = '⏳ Sorgulanıyor...';
        res.style.display='none'; res.className='hks-op-result';
        var body = 'csrf='+encodeURIComponent(csrf)+'&'+Object.entries(payload).map(function(kv){return encodeURIComponent(kv[0])+'='+encodeURIComponent(kv[1]);}).join('&');
        fetch('../ajax.php?action='+action, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
        .then(function(r){return r.json();}).then(function(d){
            res.style.display='block'; res.classList.add(d.ok?'ok':'err');
            if (d.ok) {
                var arr = d.data != null ? d.data : d;
                res.innerHTML = '✅ '+(Array.isArray(arr)? arr.length+' kayıt':'Sonuç alındı')+
                    (Array.isArray(arr) && arr.length===0 ? ' — bu kriterlere uygun kayıt bulunamadı.' : '')+
                    '<details style="margin-top:6px"><summary style="cursor:pointer">Teknik detay</summary><pre style="white-space:pre-wrap;font-size:.78rem;max-height:280px;overflow:auto">'+JSON.stringify(arr,null,2)+'</pre></details>';
            } else {
                res.innerHTML = '❌ '+(d.message || 'HKS’den gelen cevap beklenen formatta değil. Teknik logu kontrol edin.');
            }
        }).catch(function(){ res.style.display='block'; res.classList.add('err'); res.textContent='İstek gönderilemedi.'; })
        .finally(function(){ btn.disabled=false; btn.textContent=orig; });
    }
    function listeArgs(){
        return {
            baslangic: document.getElementById('blBas').value,
            bitis:     document.getElementById('blBit').value,
            kunye_no:  document.getElementById('blKunye').value.trim(),
            kunye_turu:document.getElementById('blKunyeTuru').value,
            sifat:     document.getElementById('blSifat').value,
            kalan_pozitif: document.getElementById('blKalanPozitif').checked ? '1' : ''
        };
    }
    document.getElementById('btnYaptigim').addEventListener('click', function(){ run('query_yaptigim_bildirimler', listeArgs(), document.getElementById('rListe'), this); });
    document.getElementById('btnBana').addEventListener('click', function(){ run('query_bana_yapilan_bildirimler', listeArgs(), document.getElementById('rListe'), this); });
    document.getElementById('btnSon30').addEventListener('click', function(){
        var d = new Date(); var b = new Date(); b.setDate(b.getDate()-30);
        document.getElementById('blBas').value = b.toISOString().slice(0,10);
        document.getElementById('blBit').value = d.toISOString().slice(0,10);
        run('query_yaptigim_bildirimler', listeArgs(), document.getElementById('rListe'), this);
    });
})();
</script>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
