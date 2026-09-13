<?php
/**
 * =====================================================================
 *  deploy_webhook.php — GitHub push webhook alıcısı (İMZA DOĞRULAMALI)
 * =====================================================================
 *
 *  ⚠️ BU DOSYA BİR ŞABLONDUR. Burada durduğu yerde ÇALIŞMAZ:
 *     scripts/ klasörü .htaccess ile web'e kapalıdır.
 *
 *  KURULUM (hosting dosya yöneticisinden, elle):
 *    1. GitHub → repo → Settings → Webhooks → hook → "Secret" alanına uzun
 *       rastgele bir değer yaz, Update webhook. (Eski deploy.php imzayı
 *       kontrol etmediği için bu adım deploy'u BOZMAZ.)
 *    2. Sitedeki mevcut  deploy.php  dosyasını  deploy.php.yedek  olarak
 *       yeniden adlandır — geri dönmek gerekirse diye.
 *    3. Bu dosyanın içeriğini site köküne  deploy.php  olarak kaydet.
 *    4. Aşağıdaki DEPLOY_SECRET'e 1. adımdaki değerin AYNISINI yaz.
 *    5. GitHub → webhook → Recent Deliveries → son teslimat → "Redeliver".
 *       Yanıt 200 ve "Deploy tamamlandı: N güncellendi" olmalı.
 *
 *  NEDEN: Webhook'ta Secret tanımsızken bu uç nokta kimliksizdi — adresi
 *  bilen herkes POST atıp deploy tetikleyebiliyordu. Burada istek,
 *  GitHub'ın X-Hub-Signature-256 başlığıyla doğrulanır.
 *
 *  Bu dosya sitenin köküne kurulunca deploy'lar tarafından EZİLMEZ:
 *  koruma listesinde 'deploy.php' var (aşağıda) ve zaten depoda kök
 *  deploy.php bulunmuyor. Değiştirmek gerekirse yine elle yüklenmeli.
 *
 *  Yedek yol: SSH varsa  php scripts/deploy.php 7sebahattin/Yukleme_plani main
 * =====================================================================
 */

declare(strict_types=1);

// ── AYARLAR ──────────────────────────────────────────────────────────
// GitHub webhook'undaki Secret ile BİREBİR aynı olmalı.
const DEPLOY_SECRET = '35dfa34f-b914-4d37-b036-f3617c0736ae';

// Repo ve branch BİLEREK sabit. Webhook payload'ından OKUNMAZ: saldırgan
// sahte bir payload'la kendi deposunu kurdurabilirdi.
const DEPLOY_REPO   = '7sebahattin/Yukleme_plani';
const DEPLOY_BRANCH = 'main';

// Deploy'un ÜZERİNE YAZMAYACAĞI dosyalar (depo köküne göre).
// config/db.php listede değil; o smart_merge_db() ile birleştirilir.
const DEPLOY_PROTECTED = ['deploy.php', 'deploy.log', '.htaccess', 'scripts/.htaccess'];
// ─────────────────────────────────────────────────────────────────────

$work_dir = __DIR__;
$logfile  = $work_dir . '/deploy.log';

function log_msg(string $msg): void {
    global $logfile;
    @file_put_contents($logfile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

/** Yanıtı yaz, logla ve bitir. Gövde GitHub'ın "Response" sekmesinde görünür. */
function cikis(int $kod, string $mesaj, bool $logla = true): never {
    if ($logla) log_msg("[$kod] $mesaj");
    http_response_code($kod);
    header('Content-Type: text/plain; charset=utf-8');
    echo $mesaj . "\n";
    exit;
}

/** config/db.php'yi günceller ama DB kimlik bilgilerini KORUR. */
function smart_merge_db(string $dest_path, string $new_content): void {
    if (!file_exists($dest_path)) {
        file_put_contents($dest_path, $new_content);
        log_msg('  config/db.php oluşturuldu (yeni)');
        return;
    }
    $old = file_get_contents($dest_path);
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $k) {
        if (preg_match('/const\s+' . $k . '\s*=\s*\'([^\']*)\';/', $old, $m)) {
            $new_content = preg_replace(
                '/const\s+' . $k . '\s*=\s*\'[^\']*\';/',
                "const $k = '" . addslashes($m[1]) . "';",
                $new_content
            );
        }
    }
    file_put_contents($dest_path, $new_content);
    log_msg('  config/db.php güncellendi (DB bilgileri korundu)');
}

// ── 1) Sadece web POST ───────────────────────────────────────────────
if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "Bu dosya webhook alıcısıdır. Elle deploy için:\n"
                 . "  php scripts/deploy.php " . DEPLOY_REPO . " " . DEPLOY_BRANCH . "\n");
    exit(1);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    cikis(405, 'Yalnızca POST kabul edilir.', false);
}

// ── 2) İMZA DOĞRULAMA — kapı burası ──────────────────────────────────
// Secret tanımsızsa FAIL-CLOSED: kimliksiz deploy yapmaktansa hiç yapma.
if (DEPLOY_SECRET === '') {
    cikis(500, 'Yapılandırma hatası: DEPLOY_SECRET boş. Kurulum adımlarına bakın.');
}
$imza = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$govde = file_get_contents('php://input');
if ($govde === false) $govde = '';
$beklenen = 'sha256=' . hash_hmac('sha256', $govde, DEPLOY_SECRET);
// hash_equals: zamanlama saldırısına kapalı karşılaştırma.
if ($imza === '' || !hash_equals($beklenen, $imza)) {
    cikis(401, 'İmza doğrulanamadı.');
}

// ── 3) Olay ve branch süzgeci ────────────────────────────────────────
$olay = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($olay === 'ping') {
    cikis(200, 'pong — webhook bağlantısı çalışıyor.', false);
}
if ($olay !== 'push') {
    cikis(200, "Olay '$olay' yoksayıldı (yalnız push).", false);
}
$payload = json_decode($govde, true);
$ref     = is_array($payload) ? (string)($payload['ref'] ?? '') : '';
if ($ref !== 'refs/heads/' . DEPLOY_BRANCH) {
    cikis(200, "Branch '$ref' yoksayıldı (yalnız " . DEPLOY_BRANCH . ").", false);
}

// ── 4) Eşzamanlılık kilidi ───────────────────────────────────────────
// İki teslimat aynı anda gelirse dosyalar iç içe yazılır ve site yarım
// kalmış bir karışıma döner. İkincisi beklemez, reddedilir.
$kilit = @fopen($work_dir . '/.deploy.lock', 'c');
if ($kilit === false || !flock($kilit, LOCK_EX | LOCK_NB)) {
    cikis(409, 'Başka bir deploy sürüyor, bu istek atlandı.');
}

$tetikleyen = is_array($payload) ? (string)($payload['head_commit']['id'] ?? '') : '';
log_msg('=== Webhook deploy === repo=' . DEPLOY_REPO . ' branch=' . DEPLOY_BRANCH
        . ($tetikleyen !== '' ? ' commit=' . substr($tetikleyen, 0, 7) : ''));

// ── 5) ZIP indir ─────────────────────────────────────────────────────
$zip_url = 'https://github.com/' . DEPLOY_REPO . '/archive/refs/heads/' . DEPLOY_BRANCH . '.zip';
$zip_content = false;
if (function_exists('curl_init')) {
    $ch = curl_init($zip_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Deploy-Webhook/1.0',
        CURLOPT_TIMEOUT        => 120,
    ]);
    $zip_content = curl_exec($ch);
    $curl_err    = curl_error($ch);
    curl_close($ch);
    if (!$zip_content) { log_msg("curl ERROR: $curl_err"); $zip_content = false; }
}
if (!$zip_content) cikis(502, 'ZIP indirilemedi.');
log_msg('ZIP indirildi: ' . strlen($zip_content) . ' bayt');

$tmp = sys_get_temp_dir() . '/deploy_' . getmypid() . '_' . time() . '.zip';
if (file_put_contents($tmp, $zip_content) === false) cikis(500, 'Geçici dosya yazılamadı.');
unset($zip_content);

if (!class_exists('ZipArchive')) { @unlink($tmp); cikis(500, 'ZipArchive eklentisi yok.'); }
$zip = new ZipArchive();
if ($zip->open($tmp) !== true) { @unlink($tmp); cikis(500, 'ZIP açılamadı.'); }

// ── 6) Dosyaları yaz ─────────────────────────────────────────────────
$prefix  = basename(DEPLOY_REPO) . '-' . DEPLOY_BRANCH . '/';
$updated = 0;
$skipped = 0;

for ($i = 0; $i < $zip->numFiles; $i++) {
    $entry = $zip->getNameIndex($i);
    if (strpos($entry, $prefix) !== 0) continue;
    $rel = substr($entry, strlen($prefix));
    if ($rel === '') continue;

    // Zip-slip koruması: arşivdeki bir yol site kökünün DIŞINA çıkamaz.
    if (strpos($rel, '..') !== false || $rel[0] === '/' || strpos($rel, "\0") !== false) {
        log_msg("  ŞÜPHELİ YOL atlandı: $rel");
        $skipped++;
        continue;
    }

    if (substr($rel, -1) === '/') {
        $dir = $work_dir . '/' . rtrim($rel, '/');
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        continue;
    }

    if (in_array($rel, DEPLOY_PROTECTED, true)) {
        log_msg("  KORUMA: $rel atlandı");
        $skipped++;
        continue;
    }
    if ($rel === 'config/db.php') {
        smart_merge_db($work_dir . '/config/db.php', $zip->getFromIndex($i));
        $updated++;
        continue;
    }

    $dest     = $work_dir . '/' . $rel;
    $dest_dir = dirname($dest);
    if (!is_dir($dest_dir)) @mkdir($dest_dir, 0755, true);
    if (file_put_contents($dest, $zip->getFromIndex($i)) !== false) {
        $updated++;
    } else {
        log_msg("  HATA: $rel yazılamadı");
    }
}

$zip->close();
@unlink($tmp);
flock($kilit, LOCK_UN);
fclose($kilit);

// Bu cümle docs/DEPLOY_WORKFLOW.md'de "parmak izi" olarak geçiyor —
// GitHub'ın Response sekmesinde bunu arıyoruz. Metni değiştirirsen orayı da güncelle.
cikis(200, "Deploy tamamlandı: $updated güncellendi, $skipped atlandı");
