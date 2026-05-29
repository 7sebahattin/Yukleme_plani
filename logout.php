<?php
// =========================================================
// logout.php — Çıkış
// =========================================================

declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

logout_user();
header('Location: login.php');
exit;
