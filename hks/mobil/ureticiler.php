<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/hks_bootstrap.php';
$auth_user = require_login();
if (!can('hks.read') && !is_admin()) {
    http_response_code(403); die('Erişim yetkiniz yok (hks.read).');
}

$op_repo         = new HksRepository(db());
$op_settings     = $op_repo->getSettings();
$op_company_name = $op_settings['firma_adi'] ?? 'AGRONATURAL';
$op_env          = $op_settings['environment'] ?? 'test';

$company_id = isset($_SESSION['hks_company_id']) ? (int)$_SESSION['hks_company_id'] : 0;
if ($company_id === 0) {
    $all_cos    = $op_repo->getAllCompanies();
    $company_id = !empty($all_cos) ? (int)$all_cos[0]['id'] : 0;
}

$producers  = $op_repo->listMobileContacts('producer', $company_id);
$csrf_token = csrf_token();

include __DIR__ . '/_layout.php';
hks_mob_start('Üretici', 'ureticiler');
?>

<?php if (empty($producers)): ?>
<div class="mob-list-empty">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
        <circle cx="9" cy="7" r="4"/>
        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
    </svg>
    <p>Üretici listesi boş</p>
</div>
<?php else: ?>
<?php foreach ($producers as $p): ?>
<div class="mob-contact-card">
    <div class="mob-contact-name"><?= hks_mob_h($p['display_name']) ?></div>
    <div class="mob-contact-meta">
        <span><?= hks_mob_h($p['tc_vkn']) ?></span>
        <?php if (!empty($p['phone'])): ?><span>· <?= hks_mob_h($p['phone']) ?></span><?php endif; ?>
        <?php if (!empty($p['plate'])): ?><span>· <?= hks_mob_h($p['plate']) ?></span><?php endif; ?>
    </div>
    <div class="mob-contact-footer">
        <?php if ($p['hks_registered']): ?>
            <span class="mob-hks-badge ok">HKS Kayıtlı</span>
        <?php else: ?>
            <span class="mob-hks-badge na">Doğrulanmamış</span>
        <?php endif; ?>
        <?php if (!empty($p['last_verified_at'])): ?>
        <span class="mob-contact-date"><?= hks_mob_h(date('d.m.Y', strtotime($p['last_verified_at']))) ?></span>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- Bottom actions -->
<div class="mob-bottom-actions">
    <button class="mob-import-btn disabled" disabled>İçeri aktar</button>
</div>

<!-- FAB -->
<button class="mob-fab" id="fabBtn" onclick="mobOpenForm()">+</button>

<!-- Slide-up form modal -->
<div id="mobFormOverlay" class="mob-modal-overlay" onclick="if(event.target===this)mobCloseForm()">
    <div class="mob-modal-sheet" id="mobFormSheet">
        <div class="mob-modal-handle"></div>
        <div class="mob-modal-title">Üretici Ekle</div>

        <div id="formResult" class="mob-form-result" style="display:none;"></div>

        <div class="mob-input-group">
            <input class="mob-input" id="inp_tc"    type="text" inputmode="numeric" placeholder="TC / VKN" maxlength="11" autocomplete="off">
        </div>
        <div class="mob-input-group">
            <input class="mob-input" id="inp_name"  type="text" placeholder="Ad / Ünvan" autocomplete="off">
        </div>
        <div class="mob-input-group">
            <input class="mob-input" id="inp_phone" type="tel"  placeholder="Telefon (opsiyonel)">
        </div>
        <div class="mob-input-group">
            <input class="mob-input" id="inp_plate" type="text" placeholder="Plaka (opsiyonel)">
        </div>

        <div id="sifatArea" style="display:none;" class="mob-sifat-area">
            <div class="mob-sifat-label">HKS Sıfatları:</div>
            <div id="sifatList"></div>
        </div>

        <div style="display:flex;gap:10px;margin-top:18px;">
            <button class="mob-btn mob-btn-outline" style="flex:0 0 80px;" onclick="mobCloseForm()">İptal</button>
            <button class="mob-btn mob-btn-solid" id="saveBtn" onclick="mobSaveContact()" style="flex:1;">
                HKS'de Sorgula ve Kaydet
            </button>
        </div>
        <p class="mob-sprint-note" style="margin-top:8px;">Kayıt için HKS bağlantısı ve hks.read yetkisi gereklidir.</p>
    </div>
</div>

<style>
.mob-contact-card {
    background:#fff; border-radius:12px; padding:14px 16px; margin-bottom:10px;
    box-shadow: 0 1px 3px rgba(21,101,192,.06);
}
.mob-contact-name { font-weight:600; font-size:15px; margin-bottom:4px; color:#1a2236; }
.mob-contact-meta { font-size:13px; color:#7b8fa8; margin-bottom:6px; }
.mob-contact-meta span { margin-right:4px; }
.mob-contact-footer { display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.mob-hks-badge { font-size:11px; font-weight:700; padding:2px 8px; border-radius:8px; }
.mob-hks-badge.ok { background:#d1fae5; color:#065f46; }
.mob-hks-badge.na { background:#e5e7eb; color:#374151; }
.mob-contact-date { font-size:11px; color:#9eaabf; }

.mob-modal-overlay {
    display:none; position:fixed; inset:0; z-index:700;
    background:rgba(0,0,0,.45); align-items:flex-end; justify-content:center;
}
.mob-modal-overlay.open { display:flex; }
.mob-modal-sheet {
    background:#fff; border-radius:20px 20px 0 0;
    padding:12px 20px 32px; width:100%; max-width:480px;
    animation: slideUp .25s ease;
    max-height:90dvh; overflow-y:auto;
}
@keyframes slideUp { from { transform:translateY(100%); } to { transform:translateY(0); } }
.mob-modal-handle {
    width:40px; height:4px; border-radius:2px; background:#d0d8e4;
    margin:0 auto 16px;
}
.mob-modal-title {
    font-size:17px; font-weight:700; color:#1a2236; margin-bottom:16px;
}
.mob-form-result {
    padding:12px 14px; border-radius:10px; font-size:14px; margin-bottom:12px;
}
.mob-form-result.ok  { background:#d1fae5; color:#065f46; }
.mob-form-result.err { background:#fee2e2; color:#991b1b; }
.mob-sifat-area { margin-top:10px; padding:10px 12px; background:#f0f5fd; border-radius:8px; }
.mob-sifat-label { font-size:12px; font-weight:700; color:#1565c0; margin-bottom:6px; }
.mob-sifat-tag {
    display:inline-block; background:#dbeafe; color:#1e40af;
    padding:2px 10px; border-radius:12px; font-size:12px; margin:2px;
}
</style>

<script>
var MOB_CSRF = <?= json_encode($csrf_token) ?>;

function mobOpenForm() {
    document.getElementById('mobFormOverlay').classList.add('open');
    document.getElementById('formResult').style.display = 'none';
    document.getElementById('sifatArea').style.display = 'none';
    document.getElementById('inp_tc').focus();
}
function mobCloseForm() {
    document.getElementById('mobFormOverlay').classList.remove('open');
    ['inp_tc','inp_name','inp_phone','inp_plate'].forEach(function(id){
        document.getElementById(id).value = '';
    });
}

function mobSaveContact() {
    var btn = document.getElementById('saveBtn');
    var res = document.getElementById('formResult');
    var tc   = document.getElementById('inp_tc').value.replace(/\D/g,'');
    var name = document.getElementById('inp_name').value.trim();

    res.style.display = 'none';
    res.className = 'mob-form-result';

    if (tc.length !== 10 && tc.length !== 11) {
        showFormResult('err','Geçerli bir TC (11 hane) veya VKN (10 hane) girin.'); return;
    }
    if (!name) {
        showFormResult('err','Ad/ünvan girilmelidir.'); return;
    }

    btn.disabled = true;
    btn.textContent = 'Sorgulanıyor…';

    fetch('../ajax.php?action=mobile_verify_contact', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({
            csrf: MOB_CSRF,
            type: 'producer',
            tc_vkn: tc,
            display_name: name,
            phone: document.getElementById('inp_phone').value.trim(),
            plate: document.getElementById('inp_plate').value.trim()
        })
    })
    .then(function(r){ return r.json(); })
    .then(function(d){
        btn.disabled = false;
        btn.textContent = 'HKS\'de Sorgula ve Kaydet';

        if (d.saved) {
            showFormResult('ok', d.message || 'Bu kişi HKS\'de kayıtlı. Listeye eklendi.');
            if (d.sifat_labels && d.sifat_labels.length > 0) {
                document.getElementById('sifatArea').style.display = '';
                document.getElementById('sifatList').innerHTML =
                    d.sifat_labels.map(function(s){
                        return '<span class="mob-sifat-tag">'+escH(s)+'</span>';
                    }).join('');
            }
            setTimeout(function(){ location.reload(); }, 1600);
        } else {
            showFormResult('err', d.message || (d.ok ? 'Kayıt yapılamadı.' : 'Hata oluştu.'));
        }
    })
    .catch(function(){
        btn.disabled = false;
        btn.textContent = 'HKS\'de Sorgula ve Kaydet';
        showFormResult('err','Bağlantı hatası. Lütfen tekrar deneyin.');
    });
}

function showFormResult(cls, msg) {
    var el = document.getElementById('formResult');
    el.className = 'mob-form-result ' + cls;
    el.textContent = msg;
    el.style.display = '';
}
function escH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
</script>

<?php hks_mob_end(); ?>
