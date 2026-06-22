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
<div class="hks-op-note info" style="margin-bottom:10px">
    🔖 <strong>Künye No ile arama için bu alanı kullanın.</strong> Toplu Künye ekranındaki "Belge No", künye numarası değildir.
</div>
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
    function esc(s){ return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];}); }
    // HKS DTO alanları büyük/küçük harf karışık gelir → case-insensitive arama.
    function gf(o, names){ for (var i=0;i<names.length;i++){ for (var k in o){ if (k.toLowerCase()===names[i].toLowerCase() && o[k]!=null && o[k]!==''){ return o[k]; } } } return ''; }
    function fmtNum(v){ var n=parseFloat(String(v).replace(',','.')); return isNaN(n)?esc(v):n.toLocaleString('tr-TR'); }
    // Bildirim kayıtlarını kart/tablo olarak göster (ham JSON yalnız Teknik Detay'da).
    function renderRecords(records, rawData){
        if (!records || !records.length){
            return '✅ 0 kayıt — bu kriterlerle kayıt bulunamadı.'+rawDetails(rawData);
        }
        var html = '✅ '+records.length+' kayıt bulundu.<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px;margin-top:8px">';
        records.forEach(function(o){
            var rows = [
                ['Künye No', gf(o,['KunyeNo','MalinKunyeNo','ReferansKunyeNo'])],
                ['Bildirim Tarihi', gf(o,['BildirimTarihi','KayitTarihi','Tarih'])],
                ['Araç Plaka', gf(o,['AracPlakaNo','AracPlaka','Plaka'])],
                ['Ürün', gf(o,['MalinAdi','UrunAdi','Urun'])],
                ['Ürün Cinsi', gf(o,['MalinCinsi','UrunCinsi','Cins'])],
                ['Malın Türü', gf(o,['MalinTuru','UrunTuru','Tur'])],
                ['Miktar', (function(){ var m=gf(o,['MalinMiktari','MalinMiktar','Miktar']); var b=gf(o,['MiktarBirimiAd','MiktarBirimAd','BirimAdi','Birim']); return m!==''?(fmtNum(m)+' '+esc(b)):''; })()],
                ['Kalan Miktar', (function(){ var m=gf(o,['KalanMiktar','Kalan']); return m!==''?fmtNum(m):''; })()],
                ['Mal Sahibi', gf(o,['MalinSahibiTcKimlikVergiNo','MalinSahibAdi','MalinSahibiTcVergiNo','MalinSahibi'])],
                ['Üretici', gf(o,['UreticiTcKimlikVergiNo','UreticisininAdUnvani'])],
                ['Bildirim Türü', gf(o,['BildirimTuru','BildirimTuruAdi'])],
                ['Sıfat', gf(o,['Sifat','SifatAdi'])],
                ['UniqueId', gf(o,['UniqueId'])],
                ['Belge No / Tipi', (function(){ var bn=gf(o,['BelgeNo']); var bt=gf(o,['BelgeTipiAdi','BelgeTipi']); return (esc(bn)+(bt?(' / '+esc(bt)):'')).replace(/^ \/ /,''); })()]
            ];
            var inner='';
            rows.forEach(function(r){ if (r[1]!=='' && r[1]!=null) inner+='<tr><td style="padding:2px 6px;color:var(--muted);white-space:nowrap">'+esc(r[0])+'</td><td style="padding:2px 6px;font-weight:600">'+(typeof r[1]==='string'&&r[1].indexOf('<')===-1?esc(r[1]):r[1])+'</td></tr>'; });
            html += '<div class="hks-op-card" style="padding:10px"><table style="width:100%;border-collapse:collapse;font-size:.8rem">'+inner+'</table></div>';
        });
        html += '</div>'+rawDetails(rawData);
        return html;
    }
    function rawDetails(rawData){
        if (rawData==null) return '';
        return '<details style="margin-top:8px"><summary style="cursor:pointer;font-size:.8rem;color:var(--muted)">🔧 Teknik Detay (ham JSON)</summary><pre style="white-space:pre-wrap;font-size:.76rem;max-height:280px;overflow:auto">'+esc(JSON.stringify(rawData,null,2))+'</pre></details>';
    }
    // Hata kodunun yanında HKS'nin gerçek mesajını + ham yanıt accordion'unu gösterir.
    function hksErrHtml(d){
        var code = d.error_code || '';
        var codeTxt = code + (d.error_code_aciklama ? (' ('+d.error_code_aciklama+')') : '');
        var expl = d.user_message || d.message || 'HKS hata mesajı döndürmedi. Teknik detay servis loglarında görülebilir.';
        var html = '❌ ' + (code ? ('HKS hata kodu: '+esc(codeTxt)+'<br>') : '') + 'Açıklama: '+esc(expl);
        if (d.data != null) html += '<details style="margin-top:6px"><summary style="cursor:pointer;font-size:.8rem">Teknik detay (ham yanıt)</summary><pre style="white-space:pre-wrap;font-size:.76rem;max-height:240px;overflow:auto">'+esc(JSON.stringify(d.data,null,2))+'</pre></details>';
        return html;
    }
    function run(action, payload, res, btn){
        btn.disabled = true; var orig = btn.textContent; btn.textContent = '⏳ Sorgulanıyor...';
        res.style.display='none'; res.className='hks-op-result';
        var body = 'csrf='+encodeURIComponent(csrf)+'&'+Object.entries(payload).map(function(kv){return encodeURIComponent(kv[0])+'='+encodeURIComponent(kv[1]);}).join('&');
        fetch('../ajax.php?action='+action, {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'}, body:body})
        .then(function(r){return r.json();}).then(function(d){
            res.style.display='block'; res.classList.add(d.ok?'ok':'err');
            if (d.ok) {
                // Kayıtlar kart/tablo olarak; ham JSON yalnız Teknik Detay'da.
                res.innerHTML = renderRecords(d.records || [], d.data != null ? d.data : null);
            } else {
                res.innerHTML = hksErrHtml(d);
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
