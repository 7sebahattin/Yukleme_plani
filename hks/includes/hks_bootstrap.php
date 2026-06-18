<?php
declare(strict_types=1);
// HKS modülü bootstrap — tüm HKS sayfaları bu dosyayı include eder

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/hks_config.php';
require_once __DIR__ . '/hks_security.php';
require_once __DIR__ . '/hks_helpers.php';
require_once __DIR__ . '/hks_repository.php';
require_once __DIR__ . '/hks_client.php';
require_once __DIR__ . '/../../config/auth.php';
