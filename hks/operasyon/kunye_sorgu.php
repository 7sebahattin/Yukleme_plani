<?php
// HKS Operasyon — Künye Sorgulama
declare(strict_types=1);
require_once __DIR__ . '/../includes/hks_bootstrap.php';
$auth_user = require_login();
if (function_exists('can') && !can('hks.read') && !can('records.write')) {
    http_response_code(403); die('Erişim yetkiniz yok.');
}

require __DIR__ . '/views/_op_init.php';
$urunler = $op_repo->getReferences('urun');

$op_page_title  = 'HKS Künye Sorgulama';
$op_active_tab  = 'bildirimci';
$op_active_menu = 'kunye_sorgu.php';
include __DIR__ . '/views/_layout_start.php';
?>

<?php if (!$op_queries_enabled): ?>
<div class="hks-op-note warn">⚠️ Sorgular için <strong>HKS Teknik → Ayarlar</strong> tamamlanmalı ve PHP SOAP eklentisi aktif olmalıdır.</div>
<?php else: ?>
<div class="hks-op-note info">📡 Ortam: <strong><?= hks_h(strtoupper($op_env)) ?></strong> — Sorgular HKS web servisine gönderilir.</div>
<?php endif; ?>

<p class="hks-op-section-title">Referans Künye Sorgu</p>
<div class="hks-op-card" style="margin-bottom:16px">
    <div class="hks-op-row">
        <div class="hks-op-field">
            <label>Ürün <span style="color:var(--danger)">*</span></label>
            <?php if ($urunler): ?>
            <select id="rkUrun"><?= hks_ref_options($urunler, '') ?></select>
            <?php else: ?>
            <input type="text" id="rkUrun" placeholder="Ürün (referansları senkronize edin)">
            <?php endif; ?>
        </div>
        <div class="hks-op-field">
            <label>Künye No <small class="muted">(boş = tümü)</small></label>
            <input type="text" id="rkKunye" placeholder="Künye numarası">
        </div>
        <div class="hks-op-field">
            <label>Mal Sahibi TC / VKN <span style="color:var(--danger)">*</span></label>
            <input type="text" id="rkTc" maxlength="11" placeholder="11 / 10 haneli">
            <small class="muted" style="font-size:.72rem;display:block;margin-top:3px">Bu alan, künyenin <strong>mal sahibi</strong> TC/VKN bilgisidir. Firma VKN veya üretici TC/VKN farklı olabilir.</small>
        </div>
    </div>
    <div class="hks-op-note info" style="margin-bottom:10px">ℹ️ HKS referans künye sorgusu için <strong>Mal Sahibi TC/VKN zorunludur</strong>.</div>
    <button type="button" class="hks-op-btn" data-q="referans" <?= $op_queries_enabled ? '' : 'disabled' ?>>🔎 Künye Sorgula</button>
    <div class="hks-op-result" data-r="referans" style="display:none"></div>
</div>

<p class="hks-op-section-title">Kayıtlı Kişi / Firma Sorgu</p>
<div class="hks-op-card" style="margin-bottom:16px">
    <div class="hks-op-note info" style="margin-bottom:10px">ℹ️ HKS kişi sorgusu <strong>ad/ünvan döndürmez</strong>; yalnızca kişinin HKS'de <strong>kayıtlı olup olmadığını (KayitliKisiMi)</strong> ve <strong>sıfatlarını</strong> verir.</div>
    <div class="hks-op-row">
        <div class="hks-op-field">
            <label>TC / VKN</label>
            <input type="text" id="kisiTc" maxlength="11" placeholder="11 haneli TC veya 10 haneli VKN">
        </div>
    </div>
    <button type="button" class="hks-op-btn" data-q="kisi" <?= $op_queries_enabled ? '' : 'disabled' ?>>🔎 Kişi Sorgula</button>
    <div class="hks-op-result" data-r="kisi" style="display:none"></div>
</div>

<p class="hks-op-section-title">Toplu Künye (HKS TopluKunyeIstek)</p>
<div class="hks-op-card">
    <div class="hks-op-note info" style="margin-bottom:10px">ℹ️ HKS Toplu Künye <strong>tarih aralığı değil</strong>; araç plakası, belge no ve bildirim tarihiyle sorgular.</div>
    <div class="hks-op-row">
        <div class="hks-op-field">
            <label>Araç Plaka</label>
            <input type="text" id="tkPlaka" placeholder="34ABC123">
        </div>
        <div class="hks-op-field">
            <label>Belge No</label>
            <input type="text" id="tkBelge" placeholder="İrsaliye / fatura no">
        </div>
        <div class="hks-op-field">
            <label>Bildirim Tarihi</label>
            <input type="date" id="tkTarih" value="<?= date('Y-m-d') ?>">
        </div>
    </div>
    <button type="button" class="hks-op-btn" data-q="toplu" <?= $op_queries_enabled ? '' : 'disabled' ?>>🔎 Toplu Künye Getir</button>
    <div class="hks-op-result" data-r="toplu" style="display:none"></div>
</div>

<script>
(function(){
    var csrf = document.querySelector('meta[name="csrf-token"]').content;
    function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
    function hksErrHtml(d){
        var code = d.error_code || '';
        var codeTxt = code + (d.error_code_aciklama ? (' ('+d.error_code_aciklama+')') : '');
        var expl = d.user_message || d.message || 'HKS hata mesajı döndürmedi. Teknik detay servis loglarında görülebilir.';
        var html = '❌ ' + (code ? ('HKS hata kodu: '+esc(codeTxt)+'<br>') : '') + 'Açıklama: '+esc(expl);
        if (d.data != null) html += '<details style="margin-top:6px"><summary style="cursor:pointer;font-size:.8rem">Teknik detay (ham yanıt)</summary><pre style="white-space:pre-wrap;font-size:.76rem;max-height:240px;overflow:auto">'+esc(JSON.stringify(d.data,null,2))+'</pre></details>';
        return html;
    }
    // "Bu sorguda HKS'ye gönderilen kriterler" teşhis kutusu (referans künye vb.)
    function critHtml(d){
        if (!d || !d.request_criteria) return '';
        var rows = '';
        Object.keys(d.request_criteria).forEach(function(k){
            rows += '<tr><td style="padding:2px 8px;color:var(--muted)">'+esc(k)+'</td><td style="padding:2px 8px;font-family:monospace">'+esc(String(d.request_criteria[k]))+'</td></tr>';
        });
        return '<div style="margin-top:8px;background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:8px 10px">'+
            '<div style="font-weight:600;font-size:.8rem;margin-bottom:4px">🔎 Bu sorguda HKS\'ye gönderilen kriterler</div>'+
            '<table style="font-size:.78rem;border-collapse:collapse">'+rows+'</table>'+
            '<div style="font-size:.72rem;color:var(--muted);margin-top:4px">"(gönderilmedi)" olan alanlar HKS\'ye 0/null gider; şifre/kullanıcı bilgileri maskelidir.</div></div>';
    }
    function run(action, payload, res, btn){
        btn.disabled = true; var orig = btn.textContent; btn.textContent = '⏳ Sorgulanıyor...';
        res.style.display='none'; res.className='hks-op-result';
        var body = 'csrf='+encodeURIComponent(csrf)+'&'+Object.entries(payload).map(function(kv){return encodeURIComponent(kv[0])+'='+encodeURIComponent(kv[1]);}).join('&');
        fetch('../ajax.php?action='+action, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
        .then(function(r){return r.json();}).then(function(d){
            res.style.display='block';
            // Kişi sorgu: sonuç dizi uzunluğuna DEĞİL, KayitliKisiMi'ye (d.kayitli) bağlıdır.
            if (d.ok && typeof d.kayitli !== 'undefined') {
                res.className='hks-op-result ' + (d.kayitli ? 'ok' : 'err');
                var sf = (d.sifat_labels && d.sifat_labels.length) ? '<br>Sıfatlar: '+esc(d.sifat_labels.join(', ')) : '<br>Sıfat bilgisi yok.';
                res.innerHTML = (d.kayitli?'✅ ':'❌ ')+esc(d.message||'')+(d.kayitli?sf:'')+
                    (d.data!=null?'<details style="margin-top:6px"><summary style="cursor:pointer">Teknik detay (ham yanıt)</summary><pre style="white-space:pre-wrap;font-size:.78rem;max-height:260px;overflow:auto">'+esc(JSON.stringify(d.data,null,2))+'</pre></details>':'');
                btn.disabled=false; btn.textContent=orig; return;
            }
            res.classList.add(d.ok?'ok':'err');
            if (d.ok) {
                var arr = d.data != null ? d.data : d;
                res.innerHTML = '✅ '+(Array.isArray(arr)? arr.length+' kayıt bulundu':'Sonuç alındı')+
                    (Array.isArray(arr) && arr.length===0 ? ' — bu kriterlere uygun kayıt bulunamadı.' : '')+
                    '<details style="margin-top:6px"><summary style="cursor:pointer">Teknik detay</summary><pre style="white-space:pre-wrap;font-size:.78rem;max-height:260px;overflow:auto">'+esc(JSON.stringify(arr,null,2))+'</pre></details>';
            } else {
                res.innerHTML = hksErrHtml(d);
            }
            res.innerHTML += critHtml(d);  // gönderilen kriter teşhis kutusu (varsa)
        }).catch(function(){ res.style.display='block'; res.classList.add('err'); res.textContent='İstek gönderilemedi.'; })
        .finally(function(){ btn.disabled=false; btn.textContent=orig; });
    }
    document.querySelectorAll('[data-q]').forEach(function(btn){
        btn.addEventListener('click', function(){
            var q = btn.dataset.q;
            var res = document.querySelector('[data-r="'+q+'"]');
            if (q === 'referans') {
                var urun = (document.getElementById('rkUrun').value||'').trim();
                if (!urun) { alert('Önce ürün seçin.'); return; }
                var rkTcVal = document.getElementById('rkTc').value.trim();
                if (!rkTcVal) { alert('Mal sahibi TC/VKN zorunludur.'); return; }
                run('query_referans_kunye', {urun_id:urun, kunye_no:document.getElementById('rkKunye').value.trim(), tc_vkn:rkTcVal}, res, btn);
            } else if (q === 'kisi') {
                var tc = document.getElementById('kisiTc').value.trim();
                if (!tc) { alert('TC veya VKN girin.'); return; }
                run('query_kisi_sorgu', {tc_vkn:tc}, res, btn);
            } else if (q === 'toplu') {
                var plaka=document.getElementById('tkPlaka').value.trim();
                var belge=document.getElementById('tkBelge').value.trim();
                var tarih=document.getElementById('tkTarih').value;
                if (!plaka && !belge && !tarih) { alert('Araç plakası, belge no veya bildirim tarihinden en az biri gerekli.'); return; }
                run('query_toplu_kunye', {arac_plaka:plaka, belge_no:belge, bildirim_tarihi:tarih}, res, btn);
            }
        });
    });
})();
</script>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
