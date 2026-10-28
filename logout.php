<?php
// =========================================================
// logout.php — Çıkış
// =========================================================

declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

audit_log_event('logout', 'auth');
logout_user();
if (function_exists('ekran_kilit_temizle')) ekran_kilit_temizle();   // v454
header('Location: login.php');
exit;
