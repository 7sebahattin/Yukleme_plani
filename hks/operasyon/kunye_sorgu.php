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
    <div class="hks-op-note info" style="margin-bottom:10px">ℹ️ HKS Toplu Künye sorgusu <strong>künye no ile değil</strong>; araç plakası, belge no ve bildirim tarihiyle çalışır. <strong>Belge No, künye numarası değildir.</strong> Künye No ile arama için <strong>Bildirim Sorgulama</strong> ekranını kullanın.</div>
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
    function gf(o, names){ for (var i=0;i<names.length;i++){ for (var k in o){ if (k.toLowerCase()===names[i].toLowerCase() && o[k]!=null && o[k]!==''){ return o[k]; } } } return ''; }
    function fmtNum(v){ var n=parseFloat(String(v).replace(',','.')); return isNaN(n)?esc(v):n.toLocaleString('tr-TR'); }
    function rawDetails(rawData){ if (rawData==null) return ''; return '<details style="margin-top:8px"><summary style="cursor:pointer;font-size:.8rem;color:var(--muted)">🔧 Teknik Detay (ham JSON)</summary><pre style="white-space:pre-wrap;font-size:.76rem;max-height:280px;overflow:auto">'+esc(JSON.stringify(rawData,null,2))+'</pre></details>'; }
    function renderRecords(records, rawData){
        if (!records || !records.length){ return '✅ 0 kayıt — bu kriterlerle kayıt bulunamadı.'+rawDetails(rawData); }
        var html = '✅ '+records.length+' kayıt bulundu.<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px;margin-top:8px">';
        records.forEach(function(o){
            var rows = [
                ['Künye No', gf(o,['KunyeNo','MalinKunyeNo','ReferansKunyeNo'])],
                ['Tarih', gf(o,['BildirimTarihi','KayitTarihi','Tarih'])],
                ['Araç Plaka', gf(o,['AracPlakaNo','AracPlaka','Plaka'])],
                ['Ürün', gf(o,['MalinAdi','UrunAdi','Urun'])],
                ['Ürün Cinsi', gf(o,['MalinCinsi','UrunCinsi','Cins'])],
                ['Malın Türü', gf(o,['MalinTuru','UrunTuru','Tur'])],
                ['Miktar', (function(){ var m=gf(o,['MalinMiktar','MalinMiktari','Miktar']); var b=gf(o,['MiktarBirimAd','MiktarBirimiAd','BirimAdi','Birim']); return m!==''?(fmtNum(m)+' '+esc(b)):''; })()],
                ['Kalan', (function(){ var m=gf(o,['KalanMiktar','Kalan']); return m!==''?fmtNum(m):''; })()],
                ['Mal Sahibi', gf(o,['MalinSahibAdi','MalinSahibiTcVergiNo','MalinSahibiTcKimlikVergiNo','MalinSahibi'])],
                ['Bildirimci', gf(o,['Bildirimci','BildirimciAdi'])],
                ['Belge No / Tipi', (function(){ var bn=gf(o,['BelgeNo']); var bt=gf(o,['BelgeTipiAdi','BelgeTipi']); return (esc(bn)+(bt?(' / '+esc(bt)):'')).replace(/^ \/ /,''); })()],
                ['Nereden → Nereye', (function(){ var a=gf(o,['Nereden']); var b=gf(o,['Nereye']); return (a||b)?(esc(a)+' → '+esc(b)):''; })()]
            ];
            var inner='';
            rows.forEach(function(r){ if (r[1]!=='' && r[1]!=null) inner+='<tr><td style="padding:2px 6px;color:var(--muted);white-space:nowrap">'+esc(r[0])+'</td><td style="padding:2px 6px;font-weight:600">'+(typeof r[1]==='string'&&r[1].indexOf('<')===-1?esc(r[1]):r[1])+'</td></tr>'; });
            html += '<div class="hks-op-card" style="padding:10px"><table style="width:100%;border-collapse:collapse;font-size:.8rem">'+inner+'</table></div>';
        });
        return html + '</div>' + rawDetails(rawData);
    }
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
                if (typeof d.records !== 'undefined') {
                    // Toplu künye: Kunyeler {}/null → 0; sayım kayıt listesinden gelir.
                    res.innerHTML = renderRecords(d.records || [], d.data != null ? d.data : null);
                } else {
                    var arr = d.data != null ? d.data : d;
                    res.innerHTML = '✅ '+(Array.isArray(arr)? arr.length+' kayıt bulundu':'Sonuç alındı')+
                        rawDetails(arr);
                }
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
                // Plaka normalize: büyük harf + boşluksuz (55asy19 → 55ASY19)
                var plakaEl=document.getElementById('tkPlaka');
                var plaka=plakaEl.value.toUpperCase().replace(/\s+/g,'');
                plakaEl.value=plaka;
                var belge=document.getElementById('tkBelge').value.trim();
                var tarih=document.getElementById('tkTarih').value;
                // Belge No alanına künye no benzeri (15+ hane) yazılmışsa uyar
                if (/^\d{15,}$/.test(belge)) {
                    alert('Bu değer künye numarasına benziyor. Künye No ile arama için "Bildirim Sorgulama" ekranını kullanın. Toplu Künye, belge no/plaka/tarih ile çalışır.');
                    return;
                }
                if (!plaka && !belge && !tarih) { alert('Araç plakası, belge no veya bildirim tarihinden en az biri gerekli.'); return; }
                run('query_toplu_kunye', {arac_plaka:plaka, belge_no:belge, bildirim_tarihi:tarih}, res, btn);
            }
        });
    });
})();
</script>

<?php include __DIR__ . '/views/_layout_end.php'; ?>
