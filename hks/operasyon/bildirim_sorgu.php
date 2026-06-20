<?php
// HKS Operasyon — Bildirim Sorgulama / İptal
declare(strict_types=1);
require_once __DIR__ . '/../includes/hks_bootstrap.php';
$auth_user = require_login();
if (function_exists('can') && !can('hks.read') && !can('records.write')) {
    http_response_code(403); die('Erişim yetkiniz yok.');
}

require __DIR__ . '/views/_op_init.php';

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

<p class="hks-op-section-title">Tek Bildirim Sorgu</p>
<div class="hks-op-card" style="margin-bottom:16px">
    <div class="hks-op-row">
        <div class="hks-op-field">
            <label>Bildirim No</label>
            <input type="text" id="bNo" placeholder="HKS bildirim numarası">
        </div>
    </div>
    <button type="button" class="hks-op-btn" id="btnTek" <?= $op_queries_enabled ? '' : 'disabled' ?>>🔎 Sorgula</button>
    <button type="button" class="hks-op-btn hks-op-btn-ghost" disabled title="İptal işlemi sonraki sprintte eklenecek">🚫 İptal Et</button>
    <small class="muted" style="margin-left:6px">İptal işlemi sonraki sprintte eklenecek.</small>
    <div class="hks-op-result" id="rTek" style="display:none"></div>
</div>

<p class="hks-op-section-title">Bildirim Listeleri (Tarih Aralığı)</p>
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
    document.getElementById('btnTek').addEventListener('click', function(){
        var no = document.getElementById('bNo').value.trim();
        if (!no) { alert('Bildirim numarası girin.'); return; }
        run('query_bildirim', {bildirim_no:no}, document.getElementById('rTek'), this);
    });
    function listeArgs(){ return {baslangic:document.getElementById('blBas').value, bitis:document.getElementById('blBit').value, kunye_no:document.getElementById('blKunye').value.trim()}; }
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
