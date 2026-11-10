<?php
// =========================================================
// mesai_toplu_onay.php — v507: Mesai Değerlendirme TOPLU ONAY (gün + çavuş + aktif depo kapsamı).
// Onay bekleyen dönemleri listeler, seçilenleri ÖNİZLER, sonra uygular. Gerçek yazma TEK yoldan
// (pdks_faz8b_degerlendirme_kaydet) geçer — bkz. config/pdks_toplu_onay.php. Hiçbir şey otomatik onaylanmaz.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/pdks_gunluk.php';
require_once __DIR__ . '/config/pdks_hakedis.php';
require_once __DIR__ . '/config/pdks_faz8b.php';
require_once __DIR__ . '/config/pdks_toplu_onay.php';
require_once __DIR__ . '/config/auth.php';

$auth_user = require_login();
require_pdks_hakedis('entitlements_finalize');
$pdo = db();
pdks_gunluk_sayfa_kapisi($pdo);
pdks_hakedis_sayfa_kapisi($pdo);
pdks_faz8b_sayfa_kapisi($pdo);

$depo = function_exists('active_depot') ? (active_depot() ?? '') : '';
$tarih = trim((string)($_GET['tarih'] ?? $_POST['tarih'] ?? ''));
if ($tarih === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tarih) || !strtotime($tarih)) $tarih = date('Y-m-d');
$cavusId = filter_var($_GET['cavus'] ?? $_POST['cavus'] ?? '', FILTER_VALIDATE_INT) ?: null;
$yalnizElle = (string)($_GET['kaynak'] ?? $_POST['kaynak'] ?? '') === 'elle';

$errors = [];
$onizleme = null;      // önizleme sonucu (POST action=onizle | oto_onizle)
$onizlemeOto = false;  // v508: önizleme otomatik (elle girilenler) modunda mı
$topluId = [];
$sinifKarari = trim((string)($_POST['sinif_karari'] ?? ''));
$fmKarar = trim((string)($_POST['fm_karar'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check($_POST['csrf'] ?? null);
    require_pdks_hakedis('entitlements_finalize');
    $action = trim((string)($_POST['action'] ?? ''));
    $idsRaw = $_POST['period_ids'] ?? [];
    $topluId = is_array($idsRaw) ? array_values(array_unique(array_map('intval', $idsRaw))) : [];
    if (!in_array($action, ['onizle', 'uygula', 'oto_onizle', 'oto_uygula'], true)) {
        $errors[] = 'Geçersiz işlem.';
    } else {
        $oto = in_array($action, ['oto_onizle', 'oto_uygula'], true);
        $uygula = in_array($action, ['uygula', 'oto_uygula'], true);
        $beklenen = null;
        if ($oto) {
            // v508: kapsam SUNUCUDA çözülür (gün + çavuş + aktif depo, yalnız source='manual'); istemciden id ALINMAZ.
            $topluId = array_map(static fn($s) => (int)$s['d']['id'], pdks_toplu_onay_bekleyenler($tarih, $depo, $cavusId, true, $pdo));
            $sinifKarari = ''; $fmKarar = '';
            // Uygulamada önizlemedeki onay adedi zorunlu; yoksa/uyuşmazsa HİÇBİR ŞEY yazılmaz.
            if ($uygula) $beklenen = isset($_POST['beklenen']) ? max(0, (int)$_POST['beklenen']) : -1;
        }
        $r = $oto && !$topluId
            ? ['ok' => false, 'hata' => 'Otomatik onaylanacak, elle girilmiş bekleyen dönem yok.', 'uygulandi' => false, 'satirlar' => [], 'sayac' => []]
            : pdks_toplu_onay_calistir($topluId, $sinifKarari, $fmKarar, (int)$auth_user['id'], $depo, $uygula, $pdo, $oto, $beklenen);
        $onizlemeOto = $oto;
        if ($uygula && $r['ok']) {
            $mesaj = $r['sayac']['onay'] . ' dönem onaylandı' . ($oto ? ' (otomatik: ' . $r['sayac']['tam'] . ' Tam' . ($r['sayac']['yarim'] > 0 ? ', ' . $r['sayac']['yarim'] . ' Yarım' : '') . ', ' . $r['sayac']['fm_saat'] . ' sa FM)' : '') . '.'
                . ($r['sayac']['fiyatsiz'] > 0 ? ' ' . $r['sayac']['fiyatsiz'] . ' kayıtta fiyat yok — fiyat girilene dek hakediş hesaplanamaz.' : '')
                . ($r['sayac']['uygunsuz'] > 0 ? ' ' . $r['sayac']['uygunsuz'] . ' uygunsuz satır onaylanmadı.' : '')
                . ($r['sayac']['karar'] > 0 ? ' ' . $r['sayac']['karar'] . ' satır karar beklediği için atlandı.' : '');
            set_flash('success', $mesaj);
            header('Location: mesai_toplu_onay.php?' . http_build_query(array_filter(['tarih' => $tarih, 'cavus' => $cavusId, 'kaynak' => $yalnizElle ? 'elle' : null], static fn($v) => $v !== null && $v !== '')));
            exit;
        }
        if (!$r['ok'] && $r['hata'] !== null) $errors[] = $r['hata'];
        $onizleme = $r;
    }
}

$cavuslar = [];
try { $cavuslar = $pdo->query("SELECT id, name, is_active FROM foremen ORDER BY is_active DESC, name ASC")->fetchAll(); }
catch (PDOException $e) { $cavuslar = []; }

$bekleyen = pdks_toplu_onay_bekleyenler($tarih, $depo, $cavusId, $yalnizElle, $pdo);
$topluGuvenli = array_flip($topluId);

define('TOPLU_ONAY_EKRAN', 1);
require __DIR__ . '/_toplu_onay_ekran.php';
