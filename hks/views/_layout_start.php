<?php
// HKS modül layout başlangıcı — sayfa başlığı $hks_page_title ile set edilmeli
declare(strict_types=1);
if (!defined('HKS_MODULE_VERSION')) {
    http_response_code(403); exit('Direct access not allowed.');
}
render_header($hks_page_title ?? 'HKS Paneli');
render_flash();
?>
<style>
/* ── HKS Modülü Ortak Stilleri ─────────────────────────────────── */
.hks-page { max-width: 1100px; margin: 0 auto; }

/* Sekmeler */
.hks-tabs { display: flex; flex-wrap: wrap; gap: 4px; padding: 12px 0 0; margin-bottom: 16px; border-bottom: 2px solid var(--border); }
.hks-tab  { display: flex; align-items: center; gap: 5px; padding: 8px 14px; border-radius: 8px 8px 0 0; text-decoration: none; color: var(--muted); font-size: .88rem; border: 1px solid transparent; border-bottom: none; transition: background .15s; }
.hks-tab:hover  { background: var(--bg); color: var(--text); }
.hks-tab.active { background: var(--card); color: var(--primary); font-weight: 600; border-color: var(--border); margin-bottom: -2px; }
.hks-tab-label { display: none; }
@media (min-width: 600px) { .hks-tab-label { display: inline; } }

/* Kartlar */
.hks-dashboard { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px,1fr)); gap: 14px; margin-top: 16px; }
.hks-card       { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 16px; }
.hks-card-title { font-size: .78rem; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; margin-bottom: 8px; }
.hks-card-value { font-size: 2rem; font-weight: 700; color: var(--text); line-height: 1.1; }
.hks-card-sub   { font-size: .82rem; color: var(--muted); margin-top: 4px; }
.hks-card-ok    { border-left: 3px solid var(--success); }
.hks-card-warn  { border-left: 3px solid #f59e0b; }
.hks-card-err   { border-left: 3px solid var(--danger); }
.hks-card-info  { border-left: 3px solid var(--primary); }

/* Durum badge */
.hks-badge           { display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: .78rem; font-weight: 600; }
.hks-badge-draft     { background: #e5e7eb; color: #374151; }
.hks-badge-ready     { background: #dbeafe; color: #1e40af; }
.hks-badge-sent      { background: #d1fae5; color: #065f46; }
.hks-badge-failed    { background: #fee2e2; color: #991b1b; }
.hks-badge-cancelled { background: #f3f4f6; color: #6b7280; }

/* Durum göstergeleri */
.hks-status      { display: inline-flex; align-items: center; gap: 5px; font-size: .85rem; padding: 3px 9px; border-radius: 20px; font-weight: 600; }
.hks-status-ok   { background: #d1fae5; color: #065f46; }
.hks-status-warn { background: #fef3c7; color: #92400e; }
.hks-status-err  { background: #fee2e2; color: #991b1b; }

/* Bildirim kutuları */
.hks-warning-box { background: #fffbeb; border: 1px solid #f59e0b; border-radius: 8px; padding: 12px 16px; font-size: .88rem; color: #92400e; margin-bottom: 12px; }
.hks-error-box   { background: #fef2f2; border: 1px solid var(--danger); border-radius: 8px; padding: 12px 16px; font-size: .88rem; color: #991b1b; margin-bottom: 12px; }
.hks-success-box { background: #f0fdf4; border: 1px solid var(--success); border-radius: 8px; padding: 12px 16px; font-size: .88rem; color: #065f46; margin-bottom: 12px; }
.hks-info-box    { background: #eff6ff; border: 1px solid #93c5fd; border-radius: 8px; padding: 12px 16px; font-size: .88rem; color: #1e40af; margin-bottom: 12px; }

/* Tablo */
.hks-table      { width: 100%; border-collapse: collapse; font-size: .88rem; }
.hks-table th   { background: var(--bg); text-align: left; padding: 8px 10px; font-weight: 600; border-bottom: 2px solid var(--border); }
.hks-table td   { padding: 8px 10px; border-bottom: 1px solid var(--border); vertical-align: top; }
.hks-table tr:hover td { background: var(--bg); }
.table-wrap { overflow-x: auto; }

/* Form */
.hks-form .form-group { margin-bottom: 14px; }
.hks-form .form-group label { display: block; font-weight: 600; font-size: .85rem; margin-bottom: 4px; }
.hks-form .form-group input,
.hks-form .form-group select,
.hks-form .form-group textarea {
    width: 100%; box-sizing: border-box; padding: 9px 11px;
    border: 1px solid var(--border); border-radius: 7px;
    font-size: .95rem; background: #fff;
}
@media (max-width: 767px) {
    .hks-form .form-group input,
    .hks-form .form-group select,
    .hks-form .form-group textarea { font-size: 16px; }
}
.form-section { background: var(--card); border: 1px solid var(--border); border-radius: 10px; padding: 18px 20px; margin-bottom: 16px; }
.form-section-title { font-size: .95rem; font-weight: 700; margin-bottom: 14px; color: var(--text); border-bottom: 1px solid var(--border); padding-bottom: 8px; }
</style>
<div class="hks-page">
