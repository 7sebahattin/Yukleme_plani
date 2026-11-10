<?php
// =========================================================
// scripts/pdks_toplu_onay_render.php — Toplu Mesai Onayı ekranını (gerçek _toplu_onay_ekran.php + gerçek
// config/pdks_toplu_onay.php, bellek içi SQLite) HTML'e basar. Tarayıcı testi girdisi; canlı DB'ye dokunmaz.
//   TO_ONIZLE=1  → seçili satırlarla önizleme durumunu basar
//   php scripts/pdks_toplu_onay_render.php > _test_toplu_onay.html
// =========================================================
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');
if (PHP_SAPI !== 'cli') { http_response_code(403); exit(1); }

$ROOT = dirname(__DIR__);
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function csrf_token(): string { return 'testcsrf'; }
function render_flash(): void {}
function base_url(): string { return ''; }
function render_header(string $t, bool $p = false): void {
    echo "<!doctype html><html lang=\"tr\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
       . "<title>" . h($t) . "</title><link rel=\"stylesheet\" href=\"assets/style.css\"></head><body><main class=\"container\">";
}
function render_footer(bool $p = false): void { echo "</main></body></html>"; }
function audit_log_event(...$a): void {}
require_once $ROOT . '/config/pdks_faz8b.php';
require_once $ROOT . '/config/pdks_toplu_onay.php';

$db->exec("CREATE TABLE foremen (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(20) NOT NULL, name VARCHAR(150) NOT NULL, is_active INT NOT NULL DEFAULT 1)");
$db->exec("CREATE TABLE worker_types (id INTEGER PRIMARY KEY AUTOINCREMENT, code VARCHAR(30) NOT NULL, name VARCHAR(80) NOT NULL, is_active INTEGER NOT NULL DEFAULT 1)");
$db->exec("CREATE TABLE worker_cards (id INTEGER PRIMARY KEY AUTOINCREMENT, card_no VARCHAR(30) NOT NULL)");
$db->exec("CREATE TABLE daily_work_sessions (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INT NOT NULL, foreman_name_snapshot VARCHAR(150) NOT NULL DEFAULT '', foreman_code_snapshot VARCHAR(20) NOT NULL DEFAULT '', normal_work_minutes_snapshot INT NOT NULL DEFAULT 540, work_date DATE NOT NULL, depo VARCHAR(150) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'closed')");
$db->exec("CREATE TABLE foreman_worker_rates (id INTEGER PRIMARY KEY AUTOINCREMENT, foreman_id INT NOT NULL, worker_type_id INT NOT NULL, daily_rate DECIMAL(12,2) NOT NULL, currency VARCHAR(10) NOT NULL DEFAULT 'TRY', valid_from DATE NOT NULL, valid_to DATE NULL, is_active INT NOT NULL DEFAULT 1, created_by_user_id INT NULL)");
$db->exec("CREATE TABLE daily_worker_work_periods (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INT NOT NULL, worker_card_id INT NOT NULL, worker_type_id_snapshot INT NULL, worker_type_name_snapshot VARCHAR(80) NOT NULL DEFAULT '', entry_time DATETIME NOT NULL, exit_time DATETIME NULL, declared_attendance_class VARCHAR(10) NOT NULL DEFAULT 'tam', approved_attendance_class VARCHAR(10) NULL, source VARCHAR(20) NOT NULL DEFAULT 'scan', is_voided INT NOT NULL DEFAULT 0, voided_at DATETIME NULL, voided_by_user_id INT NULL, void_reason VARCHAR(500) NULL)");
$db->exec("CREATE TABLE foreman_daily_entitlements (id INTEGER PRIMARY KEY AUTOINCREMENT, session_id INT NOT NULL UNIQUE, foreman_id INT NOT NULL, foreman_name_snapshot VARCHAR(150) NOT NULL DEFAULT '', foreman_code_snapshot VARCHAR(20) NOT NULL DEFAULT '', work_date DATE NOT NULL, depo VARCHAR(150) NOT NULL DEFAULT '', status VARCHAR(20) NOT NULL DEFAULT 'draft', currency VARCHAR(10) NOT NULL DEFAULT 'TRY', total_amount DECIMAL(14,2) NOT NULL DEFAULT 0, calculated_at DATETIME NULL, calculated_by_user_id INT NULL, finalized_at DATETIME NULL, finalized_by_user_id INT NULL, missing_exit_ack INT NOT NULL DEFAULT 0, notes TEXT NULL, updated_at DATETIME NULL)");
$db->exec("CREATE TABLE foreman_daily_entitlement_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, entitlement_id INT NOT NULL, worker_type_id INT NULL, worker_type_code_snapshot VARCHAR(30) NOT NULL DEFAULT '', worker_type_name_snapshot VARCHAR(80) NOT NULL DEFAULT '', worker_count INT NOT NULL, unit_rate DECIMAL(12,2) NOT NULL, line_total DECIMAL(14,2) NOT NULL)");
pdks_faz8b_migrate($db);
$db->exec("INSERT INTO foremen (id,code,name) VALUES (1,'C001','Ayşe Çavuş Uzun Bir Ad Soyad'),(2,'C002','Mehmet Çavuş')");
$db->exec("INSERT INTO worker_types (id,code,name) VALUES (1,'KADIN','Kadın'),(2,'ERKEK','Erkek')");
$db->exec("INSERT INTO daily_work_sessions (id,foreman_id,foreman_name_snapshot,foreman_code_snapshot,work_date,depo) VALUES (1,1,'Ayşe Çavuş Uzun Bir Ad Soyad','C001','2026-10-24','FİNİKE'),(2,2,'Mehmet Çavuş','C002','2026-10-24','FİNİKE')");
for ($i = 1; $i <= 12; $i++) $db->prepare("INSERT INTO worker_cards (id,card_no) VALUES (?,?)")->execute([$i, sprintf('K%03d', $i)]);
pdks_faz8b_oran_ekle(1, 1, '1500', '900', 'hourly', '200', '2026-09-01', 'TRY', 1, $db);
$per = function (int $s, int $c, int $tip, string $ad, string $g, string $x, string $src) use ($db) {
    $db->prepare("INSERT INTO daily_worker_work_periods (session_id,worker_card_id,worker_type_id_snapshot,worker_type_name_snapshot,entry_time,exit_time,declared_attendance_class,source) VALUES (?,?,?,?,?,?, 'tam', ?)")
       ->execute([$s, $c, $tip, $ad, "2026-10-24 $g:00", $x === '' ? null : "2026-10-24 $x:00", $src]);
    return (int)$db->lastInsertId();
};
$ids = [];
$ids[] = $per(1, 1, 1, 'Kadın', '08:00', '17:16', 'manual');
$ids[] = $per(1, 2, 1, 'Kadın', '08:00', '18:20', 'manual');
$ids[] = $per(1, 3, 1, 'Kadın', '08:00', '12:00', 'manual');
$ids[] = $per(1, 4, 2, 'Erkek', '08:00', '17:16', 'manual');   // fiyatsız
$ids[] = $per(2, 5, 1, 'Kadın', '08:00', '', 'scan');          // çıkışsız (çavuş 2 fiyatsız da)

$auth_user = ['id' => 1];
$depo = 'FİNİKE'; $tarih = '2026-10-24'; $cavusId = null; $yalnizElle = false;
$errors = []; $sinifKarari = ''; $fmKarar = ''; $topluId = []; $onizleme = null;
if (getenv('TO_ONIZLE') === '1') {
    $sinifKarari = 'tam'; $fmKarar = 'onayla'; $topluId = $ids;
    $onizleme = pdks_toplu_onay_calistir($topluId, $sinifKarari, $fmKarar, 1, $depo, false, $db);
}
$cavuslar = $db->query("SELECT id, name, is_active FROM foremen")->fetchAll();
$bekleyen = pdks_toplu_onay_bekleyenler($tarih, $depo, $cavusId, $yalnizElle, $db);
$topluGuvenli = array_flip($topluId);
define('TOPLU_ONAY_EKRAN', 1);
require $ROOT . '/_toplu_onay_ekran.php';
