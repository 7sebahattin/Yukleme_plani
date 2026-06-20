<?php
declare(strict_types=1);
// HKS Operasyon layout başlangıcı.
// Beklenen değişkenler:
//   $op_page_title  — sayfa başlığı
//   $op_active_tab  — 'bildirimci' | 'raporlama' | 'teknik'
//   $op_active_menu — sol menüde aktif dosya adı (basename)
//   $op_dir, $op_root, $op_company_name, $op_env  (_op_init.php sağlar)
if (!defined('HKS_MODULE_VERSION')) {
    http_response_code(403); exit('Direct access not allowed.');
}
$op_dir         = $op_dir  ?? '';
$op_root        = $op_root ?? '../';
$op_active_tab  = $op_active_tab  ?? 'bildirimci';
$op_active_menu = $op_active_menu ?? '';
$op_company_name = $op_company_name ?? 'Firma';
$op_env          = $op_env ?? 'test';

render_header($op_page_title ?? 'HKS Operasyon');
render_flash();
?>
<style>
/* ── HKS Operasyon — resmi HKS mantığına yakın sade arayüz ───────── */
.hks-op-page { max-width: 1180px; margin: 0 auto; }

/* Koyu üst başlık bandı */
.hks-op-header {
    background: #1f2937; color: #fff;
    border-radius: 10px 10px 0 0;
    padding: 12px 16px;
    display: flex; align-items: center; justify-content: space-between; gap: 10px;
    flex-wrap: wrap;
}
.hks-op-header-title { font-size: 1.02rem; font-weight: 700; display: flex; align-items: center; gap: 8px; }
.hks-op-header-meta  { display: flex; align-items: center; gap: 10px; font-size: .82rem; flex-wrap: wrap; }
.hks-op-firma  { background: rgba(255,255,255,.14); padding: 4px 10px; border-radius: 20px; white-space: nowrap; }
.hks-op-env    { padding: 4px 10px; border-radius: 20px; white-space: nowrap; font-weight: 600; }
.hks-op-env.test { background: #fde68a; color: #78350f; }
.hks-op-env.live { background: #fecaca; color: #7f1d1d; }
.hks-op-switch { color: #fff; text-decoration: underline; font-size: .8rem; white-space: nowrap; }
.hks-op-switch:hover { color: #fff; opacity: .85; }

/* Kırmızı/beyaz üst sekmeler */
.hks-op-tabs {
    display: flex; flex-wrap: nowrap; gap: 0;
    background: #b91c1c;
    overflow-x: auto; -webkit-overflow-scrolling: touch;
    scrollbar-width: none; -ms-overflow-style: none;
}
.hks-op-tabs::-webkit-scrollbar { display: none; }
.hks-op-tab {
    padding: 11px 18px; color: #fee2e2; text-decoration: none;
    font-size: .9rem; font-weight: 600; white-space: nowrap; flex-shrink: 0;
    border-bottom: 3px solid transparent;
}
.hks-op-tab:hover  { background: #991b1b; color: #fff; text-decoration: none; }
.hks-op-tab.active { background: #fff; color: #b91c1c; border-bottom-color: #fff; }

/* Gövde: solda menü + ortada beyaz işlem alanı */
.hks-op-body { display: flex; gap: 0; align-items: stretch; border: 1px solid var(--border); border-top: none; border-radius: 0 0 10px 10px; overflow: hidden; }
.hks-op-menu {
    flex: 0 0 210px; background: var(--bg);
    border-right: 1px solid var(--border); padding: 10px 0;
}
.hks-op-menu-group { font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); padding: 8px 16px 4px; font-weight: 700; }
.hks-op-menu a {
    display: flex; align-items: center; gap: 8px;
    padding: 9px 16px; color: var(--text); text-decoration: none; font-size: .88rem;
    border-left: 3px solid transparent;
}
.hks-op-menu a:hover  { background: var(--primary-soft); text-decoration: none; }
.hks-op-menu a.active { background: #fff; border-left-color: #b91c1c; color: #b91c1c; font-weight: 700; }
.hks-op-menu a.disabled { color: var(--muted); cursor: not-allowed; opacity: .65; }
.hks-op-menu .soon { margin-left: auto; font-size: .64rem; background: #e5e7eb; color: #6b7280; border-radius: 8px; padding: 1px 6px; }

.hks-op-content { flex: 1 1 auto; background: #fff; padding: 18px 20px; min-width: 0; }

/* İçerik bileşenleri */
.hks-op-section-title { font-size: .76rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; background: var(--bg); padding: 7px 12px; border-radius: 6px; margin: 0 0 14px; }
.hks-op-card    { border: 1px solid var(--border); border-radius: 9px; padding: 16px; background: #fff; }
.hks-op-grid    { display: grid; grid-template-columns: repeat(auto-fill,minmax(220px,1fr)); gap: 12px; }

/* Adım göstergesi */
.hks-op-steps { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 16px; }
.hks-op-step  { display: flex; align-items: center; gap: 7px; padding: 6px 12px; border-radius: 20px; background: var(--bg); border: 1px solid var(--border); font-size: .82rem; color: var(--muted); }
.hks-op-step .n { width: 20px; height: 20px; border-radius: 50%; background: #d1d5db; color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: .74rem; font-weight: 700; }
.hks-op-step.active { background: #fef2f2; border-color: #fca5a5; color: #b91c1c; font-weight: 600; }
.hks-op-step.active .n { background: #b91c1c; }

/* Form */
.hks-op-fieldset { border: 1px solid var(--border); border-radius: 9px; padding: 14px 16px; margin-bottom: 16px; }
.hks-op-fieldset > legend { font-weight: 700; font-size: .92rem; padding: 0 8px; color: var(--text); }
.hks-op-row { display: grid; grid-template-columns: repeat(auto-fit,minmax(220px,1fr)); gap: 12px; }
.hks-op-field { margin-bottom: 10px; }
.hks-op-field label { display: block; font-weight: 600; font-size: .82rem; margin-bottom: 4px; }
.hks-op-field input, .hks-op-field select, .hks-op-field textarea {
    width: 100%; box-sizing: border-box; padding: 9px 11px;
    border: 1px solid var(--border); border-radius: 7px; font-size: .92rem; background: #fff;
}

/* Tablo */
.hks-op-table { width: 100%; border-collapse: collapse; font-size: .86rem; }
.hks-op-table th { background: var(--bg); text-align: left; padding: 8px 10px; font-weight: 700; border-bottom: 2px solid var(--border); white-space: nowrap; }
.hks-op-table td { padding: 7px 10px; border-bottom: 1px solid var(--border); vertical-align: top; }
.hks-op-table tr:hover td { background: var(--bg); }

/* Butonlar */
.hks-op-btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; border-radius: 7px; border: 1px solid #b91c1c; background: #b91c1c; color: #fff; font-size: .9rem; font-weight: 600; cursor: pointer; text-decoration: none; }
.hks-op-btn:hover { background: #991b1b; color: #fff; text-decoration: none; }
.hks-op-btn:disabled { opacity: .55; cursor: not-allowed; }
.hks-op-btn-ghost { background: #fff; color: var(--text); border-color: var(--border); }
.hks-op-btn-ghost:hover { background: var(--bg); color: var(--text); }

/* Bilgi kutuları */
.hks-op-note { border-radius: 8px; padding: 11px 14px; font-size: .85rem; margin-bottom: 14px; }
.hks-op-note.info { background: #eff6ff; border: 1px solid #93c5fd; color: #1e40af; }
.hks-op-note.warn { background: #fffbeb; border: 1px solid #f59e0b; color: #92400e; }
.hks-op-note.empty { background: var(--bg); border: 1px dashed var(--border); color: var(--muted); text-align: center; padding: 26px 14px; }

/* Durum rozetleri (hks_status_badge çıktısı) */
.hks-badge { display:inline-block; padding:2px 8px; border-radius:12px; font-size:.74rem; font-weight:600; white-space:nowrap; }
.hks-badge-draft     { background:#e5e7eb; color:#374151; }
.hks-badge-ready     { background:#dbeafe; color:#1e40af; }
.hks-badge-checked   { background:#ede9fe; color:#5b21b6; }
.hks-badge-pending   { background:#fef9c3; color:#854d0e; }
.hks-badge-sent      { background:#d1fae5; color:#065f46; }
.hks-badge-failed    { background:#fee2e2; color:#991b1b; }
.hks-badge-cancelled { background:#f3f4f6; color:#6b7280; }
.hks-badge-query     { background:#dbeafe; color:#1e40af; }

/* Sonuç alanı (AJAX) */
.hks-op-result { font-size: .85rem; padding: 10px 12px; border-radius: 7px; margin-top: 10px; }
.hks-op-result.ok  { background: #f0fdf4; border: 1px solid var(--success); color: #065f46; }
.hks-op-result.err { background: #fef2f2; border: 1px solid var(--danger);  color: #991b1b; }

/* Mobil */
@media (max-width: 859px) {
    .hks-op-body { flex-direction: column; }
    .hks-op-menu {
        flex-basis: auto; display: flex; gap: 4px; overflow-x: auto; padding: 8px;
        border-right: none; border-bottom: 1px solid var(--border);
        -webkit-overflow-scrolling: touch; scrollbar-width: none;
    }
    .hks-op-menu::-webkit-scrollbar { display: none; }
    .hks-op-menu-group { display: none; }
    .hks-op-menu a { white-space: nowrap; border-left: none; border-bottom: 3px solid transparent; border-radius: 6px; padding: 8px 12px; }
    .hks-op-menu a.active { border-left: none; border-bottom-color: #b91c1c; }
    .hks-op-menu .soon { display: none; }
    .hks-op-content { padding: 14px; }
}
@media (max-width: 767px) {
    .hks-op-field input, .hks-op-field select, .hks-op-field textarea { font-size: 16px; }
}
</style>

<div class="hks-op-page">
    <div class="hks-op-header">
        <div class="hks-op-header-title">🏛 Hal Kayıt Sistemi / HKS</div>
        <div class="hks-op-header-meta">
            <span class="hks-op-firma">🏢 <?= hks_h($op_company_name) ?></span>
            <span class="hks-op-env <?= $op_env === 'live' ? 'live' : 'test' ?>"><?= $op_env === 'live' ? '🔴 Canlı' : '🟡 Test' ?></span>
            <a href="<?= $op_root ?>index.php?switch=1" class="hks-op-switch">Firma Değiştir ⇄</a>
        </div>
    </div>

    <?php include __DIR__ . '/_tabs.php'; ?>

    <div class="hks-op-body">
        <?php include __DIR__ . '/_left_menu.php'; ?>
        <div class="hks-op-content">
