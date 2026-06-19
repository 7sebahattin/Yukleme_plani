<?php
// HKS AJAX endpoint — tüm AJAX işlemler burada
declare(strict_types=1);
ob_start(); // PHP uyarı/notice'lerini JSON'a sızdırmamak için
require_once __DIR__ . '/includes/hks_bootstrap.php';

// Auth zorunlu
set_exception_handler(function (Throwable $e): void {
    ob_clean();
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'message' => 'Sunucu hatası: ' . $e->getMessage()]);
    exit;
});

require_once __DIR__ . '/../config/auth.php';
$user = current_user();
if (!$user) {
    ob_clean();
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Oturum açmanız gerekiyor.']);
    exit;
}

// JSON body veya form body'den CSRF oku
$raw   = file_get_contents('php://input');
$input = [];
if ($raw) {
    $input = (array)(json_decode($raw, true) ?? []);
}
if (empty($input)) {
    parse_str($raw, $input);
}
$input = array_merge($input, $_POST);

csrf_check($input['csrf'] ?? null);

$action = $_GET['action'] ?? ($input['action'] ?? '');

// ── Firma Seçimi — redirect yaptığı için Content-Type header'dan önce işlenmeli ──
if ($action === 'select_company') {
    $cid = (int)($input['company_id'] ?? 0);
    if ($cid > 0) {
        $repo_co = new HksRepository(db());
        $co      = $repo_co->getSettings($cid);
        if (!$co) {
            set_flash('error', 'Firma bulunamadı.');
            header('Location: index.php'); exit;
        }
        $_SESSION['hks_company_id'] = $cid;
    } else {
        unset($_SESSION['hks_company_id']);
    }
    $redirect = $input['redirect'] ?? 'index.php';
    $safe = preg_replace('/[^a-z0-9_.]/i', '', basename($redirect));
    if ($safe === '') $safe = 'index.php';
    header('Location: ' . $safe); exit;
}

$repo   = new HksRepository(db());
$client = new HksClient($repo);
$http_status = 200;
$json_result = null;

switch ($action) {

    // ── Firma Seçimi — yukarıda işlendi ─────────────────────
    case 'select_company':
        $json_result = ['ok' => true];
        break;

    // ── Bağlantı Testi ──────────────────────────────────────
    case 'test_connection':
        if (!is_admin() && !(function_exists('can') && can('hks.settings'))) {
            $json_result = ['ok' => false, 'message' => 'Yetki yok.']; break;
        }
        audit_log_event('hks_connection_tested', 'hks_settings', null, null, ['user' => $user['username'] ?? '']);
        $json_result = $client->testConnection();
        break;

    // ── Sunucu Tanılama ─────────────────────────────────────
    case 'diagnose':
        if (!is_admin() && !(function_exists('can') && can('hks.settings'))) {
            $json_result = ['ok' => false, 'message' => 'Yetki yok.']; break;
        }
        $json_result = ['ok' => true, 'diag' => $client->diagnostics()];
        break;

    // ── WSDL Metod Keşfi ───────────────────────────────────
    case 'inspect_wsdl':
        if (!is_admin() && !(function_exists('can') && can('hks.settings'))) {
            $json_result = ['ok' => false, 'message' => 'Yetki yok.']; break;
        }
        $wsdl_service = $input['service'] ?? 'genel';
        $json_result = $client->inspectWsdl($wsdl_service);
        break;

    // ── Referans Senkronizasyonu ────────────────────────────
    case 'sync_reference':
        if (!is_admin() && !(function_exists('can') && can('hks.settings'))) {
            $json_result = ['ok' => false, 'message' => 'Yetki yok.']; break;
        }
        $ref_type = $input['ref_type'] ?? 'all';
        $json_result = hks_ajax_sync_reference($client, $repo, $ref_type, $user);
        break;

    // ── Stok Yeniden Hesaplama ──────────────────────────────
    case 'rebuild_stock':
        if (!is_admin()) {
            $json_result = ['ok' => false, 'message' => 'Admin yetkisi gerekiyor.']; break;
        }
        try {
            $repo->rebuildStock();
            $json_result = ['ok' => true, 'message' => 'Stok yeniden hesaplandı.'];
        } catch (Throwable $e) {
            $json_result = ['ok' => false, 'message' => 'Hata: ' . $e->getMessage()];
        }
        break;

    // ── Künye Sorgu ────────────────────────────────────────
    case 'query_kunye':
        if (!(function_exists('can') && (can('hks.read') || can('records.write')))) {
            $json_result = ['ok' => false, 'message' => 'Yetki yok.']; break;
        }
        $kunye_no = trim($input['kunye_no'] ?? '');
        if ($kunye_no === '') {
            $json_result = ['ok' => false, 'message' => 'Künye numarası girilmedi.']; break;
        }
        $uid = isset($user['id']) ? (int)$user['id'] : null;
        $json_result = $client->queryKunye($kunye_no);
        if ($json_result['ok']) {
            $repo->saveQuery('kunye', $kunye_no, 'ok', json_encode($json_result['data'] ?? null, JSON_UNESCAPED_UNICODE), $uid);
            audit_log_event('hks_kunye_query', 'hks_queries', null, null, ['kunye_no' => $kunye_no]);
        } else {
            $repo->saveQuery('kunye', $kunye_no, 'error', $json_result['message'] ?? null, $uid);
        }
        break;

    // ── Bildirim Sorgu ─────────────────────────────────────
    case 'query_bildirim':
        if (!(function_exists('can') && (can('hks.read') || can('records.write')))) {
            $json_result = ['ok' => false, 'message' => 'Yetki yok.']; break;
        }
        $bildirim_no = trim($input['bildirim_no'] ?? '');
        if ($bildirim_no === '') {
            $json_result = ['ok' => false, 'message' => 'Bildirim numarası girilmedi.']; break;
        }
        $uid2 = isset($user['id']) ? (int)$user['id'] : null;
        $json_result = $client->queryBildirim(['BildirimNo' => $bildirim_no]);
        if ($json_result['ok']) {
            $repo->saveQuery('bildirim', $bildirim_no, 'ok', json_encode($json_result['data'] ?? null, JSON_UNESCAPED_UNICODE), $uid2);
            audit_log_event('hks_bildirim_query', 'hks_queries', null, null, ['bildirim_no' => $bildirim_no]);
        } else {
            $repo->saveQuery('bildirim', $bildirim_no, 'error', $json_result['message'] ?? null, $uid2);
        }
        break;

    // ── Kayıtlı Kişi Sorgu ──────────────────────────────────────
    case 'query_kisi_sorgu':
        if (!(function_exists('can') && (can('hks.read') || can('records.write')))) {
            $json_result = ['ok' => false, 'message' => 'Yetki yok.']; break;
        }
        $tc_vkn = trim($input['tc_vkn'] ?? '');
        if ($tc_vkn === '') {
            $json_result = ['ok' => false, 'message' => 'TC/VKN girilmedi.']; break;
        }
        $uid_ks = isset($user['id']) ? (int)$user['id'] : null;
        $json_result = $client->kayitliKisiSorgu($tc_vkn);
        if ($json_result['ok'] && !empty($json_result['data'])) {
            $norm = hks_normalize_response($json_result['data']);
            if (!$norm['ok']) {
                $json_result = ['ok' => false, 'message' => $norm['message'], 'data' => $json_result['data']];
            }
        }
        $repo->saveQuery('kisi_sorgu', $tc_vkn,
            $json_result['ok'] ? 'ok' : 'error',
            json_encode($json_result['data'] ?? $json_result, JSON_UNESCAPED_UNICODE), $uid_ks);
        audit_log_event('hks_kisi_sorgu', 'hks_queries', null, null, ['tc_vkn' => substr($tc_vkn, 0, 4) . '***']);
        break;

    // ── Referans Künye Sorgu ────────────────────────────────
    case 'query_referans_kunye':
        if (!(function_exists('can') && (can('hks.read') || can('records.write')))) {
            $json_result = ['ok' => false, 'message' => 'Yetki yok.']; break;
        }
        $uid_rk = isset($user['id']) ? (int)$user['id'] : null;
        $rk_params = [];
        if (!empty($input['kunye_no'])) $rk_params['KunyeNo'] = trim($input['kunye_no']);
        if (!empty($input['urun_id']))  $rk_params['UrunId']  = trim($input['urun_id']);
        $json_result = $client->getReferansKunyeler($rk_params);
        if ($json_result['ok'] && !empty($json_result['data'])) {
            $norm = hks_normalize_response($json_result['data']);
            if (!$norm['ok']) {
                $json_result = ['ok' => false, 'message' => $norm['message'], 'data' => $json_result['data']];
            }
        }
        $repo->saveQuery('referans_kunye', $input['kunye_no'] ?? '*',
            $json_result['ok'] ? 'ok' : 'error',
            json_encode($json_result['data'] ?? null, JSON_UNESCAPED_UNICODE), $uid_rk);
        break;

    // ── Toplu Künye Sorgu ───────────────────────────────────
    case 'query_toplu_kunye':
        if (!(function_exists('can') && (can('hks.read') || can('records.write')))) {
            $json_result = ['ok' => false, 'message' => 'Yetki yok.']; break;
        }
        $uid_tk = isset($user['id']) ? (int)$user['id'] : null;
        $tk_params = [];
        if (!empty($input['kunye_no']))  $tk_params['KunyeNo'] = trim($input['kunye_no']);
        foreach (['baslangic' => 'BaslangicTarihi', 'bitis' => 'BitisTarihi'] as $inp => $hks_key) {
            if (!empty($input[$inp])) {
                $dt = hks_format_service_date($input[$inp]);
                if ($dt === null) { $json_result = ['ok' => false, 'message' => 'Başlangıç veya bitiş tarihi HKS formatına uygun değil.']; break 2; }
                $tk_params[$hks_key] = $dt;
            }
        }
        $json_result = $client->getTopluKunye($tk_params);
        if ($json_result['ok'] && !empty($json_result['data'])) {
            $norm = hks_normalize_response($json_result['data']);
            if (!$norm['ok']) {
                $json_result = ['ok' => false, 'message' => $norm['message'], 'data' => $json_result['data']];
            }
        }
        $repo->saveQuery('toplu_kunye', $input['kunye_no'] ?? '*',
            $json_result['ok'] ? 'ok' : 'error',
            json_encode($json_result['data'] ?? null, JSON_UNESCAPED_UNICODE), $uid_tk);
        break;

    // ── Bildirim Etiketi ────────────────────────────────────
    case 'query_bildirim_etiket':
        if (!(function_exists('can') && (can('hks.read') || can('records.write')))) {
            $json_result = ['ok' => false, 'message' => 'Yetki yok.']; break;
        }
        $uid_be = isset($user['id']) ? (int)$user['id'] : null;
        $be_params = [];
        if (!empty($input['bildirim_no'])) $be_params['BildirimNo'] = trim($input['bildirim_no']);
        if (!empty($input['kunye_no']))    $be_params['KunyeNo']    = trim($input['kunye_no']);
        $json_result = $client->getBildirimEtiket($be_params);
        if ($json_result['ok'] && !empty($json_result['data'])) {
            $norm = hks_normalize_response($json_result['data']);
            if (!$norm['ok']) {
                $json_result = ['ok' => false, 'message' => $norm['message'], 'data' => $json_result['data']];
            }
        }
        $repo->saveQuery('bildirim_etiket', $input['bildirim_no'] ?? $input['kunye_no'] ?? '*',
            $json_result['ok'] ? 'ok' : 'error',
            json_encode($json_result['data'] ?? $json_result, JSON_UNESCAPED_UNICODE), $uid_be);
        break;

    // ── Yaptığım Bildirimler ────────────────────────────────
    case 'query_yaptigim_bildirimler':
        if (!(function_exists('can') && (can('hks.read') || can('records.write')))) {
            $json_result = ['ok' => false, 'message' => 'Yetki yok.']; break;
        }
        $uid_yb = isset($user['id']) ? (int)$user['id'] : null;
        $yb_params = [];
        foreach (['baslangic' => 'BaslangicTarihi', 'bitis' => 'BitisTarihi'] as $inp => $hks_key) {
            if (!empty($input[$inp])) {
                $dt = hks_format_service_date($input[$inp]);
                if ($dt === null) { $json_result = ['ok' => false, 'message' => 'Başlangıç veya bitiş tarihi HKS formatına uygun değil.']; break 2; }
                $yb_params[$hks_key] = $dt;
            }
        }
        if (!empty($input['kunye_no']))  $yb_params['KunyeNo'] = trim($input['kunye_no']);
        $json_result = $client->getYaptigimBildirimler($yb_params);
        if ($json_result['ok'] && !empty($json_result['data'])) {
            $norm = hks_normalize_response($json_result['data']);
            if (!$norm['ok']) {
                $json_result = ['ok' => false, 'message' => $norm['message'], 'data' => $json_result['data']];
            }
        }
        $repo->saveQuery('bildirim_listesi', 'yaptigim',
            $json_result['ok'] ? 'ok' : 'error',
            json_encode($json_result['data'] ?? null, JSON_UNESCAPED_UNICODE), $uid_yb);
        break;

    // ── Bana Yapılan Bildirimler ────────────────────────────
    case 'query_bana_yapilan_bildirimler':
        if (!(function_exists('can') && (can('hks.read') || can('records.write')))) {
            $json_result = ['ok' => false, 'message' => 'Yetki yok.']; break;
        }
        $uid_byb = isset($user['id']) ? (int)$user['id'] : null;
        $byb_params = [];
        foreach (['baslangic' => 'BaslangicTarihi', 'bitis' => 'BitisTarihi'] as $inp => $hks_key) {
            if (!empty($input[$inp])) {
                $dt = hks_format_service_date($input[$inp]);
                if ($dt === null) { $json_result = ['ok' => false, 'message' => 'Başlangıç veya bitiş tarihi HKS formatına uygun değil.']; break 2; }
                $byb_params[$hks_key] = $dt;
            }
        }
        if (!empty($input['kunye_no']))  $byb_params['KunyeNo'] = trim($input['kunye_no']);
        $json_result = $client->getBanaYapilanBildirimler($byb_params);
        if ($json_result['ok'] && !empty($json_result['data'])) {
            $norm = hks_normalize_response($json_result['data']);
            if (!$norm['ok']) {
                $json_result = ['ok' => false, 'message' => $norm['message'], 'data' => $json_result['data']];
            }
        }
        $repo->saveQuery('bildirim_listesi', 'bana_yapilan',
            $json_result['ok'] ? 'ok' : 'error',
            json_encode($json_result['data'] ?? null, JSON_UNESCAPED_UNICODE), $uid_byb);
        break;

    // ── Veri Çekme: Ürünler ────────────────────────────────
    case 'fetch_urunler':
        if (!(function_exists('can') && (can('hks.read') || can('records.write') || is_admin()))) {
            $json_result = ['ok' => false, 'message' => 'Yetki yok.']; break;
        }
        $r = $client->fetchUrunlerWithDiag();
        $json_result = [
            'ok'      => $r['ok'],
            'message' => $r['ok']
                ? ('Ürünler çekildi: ' . ($r['count'] ?? 0) . ' kayıt.')
                : ($r['ui_message'] ?? $r['message'] ?? 'Servis hatası'),
            'count'   => $r['count'] ?? 0,
            'diag_hints' => $r['diag_hints'] ?? null,
            'partial' => false,
        ];
        break;

    // ── Veri Çekme: Ürün Cinsleri ─────────────────────────
    case 'fetch_urun_cinsleri':
        if (!(function_exists('can') && (can('hks.read') || can('records.write') || is_admin()))) {
            $json_result = ['ok' => false, 'message' => 'Yetki yok.']; break;
        }
        $r = $client->getUrunCinsleri();
        $diag = hks_diagnose_response($r);
        $json_result = [
            'ok'      => $diag['ok'],
            'message' => $diag['ok']
                ? ('Ürün cinsleri: ' . count($diag['data'] ?? []) . ' kayıt.')
                : $diag['ui_message'],
            'detail'  => $diag['ok'] ? null : ($diag['detail'] ?? null),
        ];
        break;

    // ── Veri Çekme: Ürün Birimleri ────────────────────────
    case 'fetch_urun_birimleri':
        if (!(function_exists('can') && (can('hks.read') || can('records.write') || is_admin()))) {
            $json_result = ['ok' => false, 'message' => 'Yetki yok.']; break;
        }
        $r = $client->getUrunBirimleri();
        $diag = hks_diagnose_response($r);
        $json_result = [
            'ok'      => $diag['ok'],
            'message' => $diag['ok']
                ? ('Ürün birimleri: ' . count($diag['data'] ?? []) . ' kayıt.')
                : $diag['ui_message'],
            'detail'  => $diag['ok'] ? null : ($diag['detail'] ?? null),
        ];
        break;

    // ── Veri Çekme: Ürün Miktar Birimleri ─────────────────
    case 'fetch_urun_miktar_birimleri':
        if (!(function_exists('can') && (can('hks.read') || can('records.write') || is_admin()))) {
            $json_result = ['ok' => false, 'message' => 'Yetki yok.']; break;
        }
        $r = $client->getUrunMiktarBirimleri();
        $diag = hks_diagnose_response($r);
        $json_result = [
            'ok'      => $diag['ok'],
            'message' => $diag['ok']
                ? ('Ürün miktar birimleri: ' . count($diag['data'] ?? []) . ' kayıt.')
                : $diag['ui_message'],
            'detail'  => $diag['ok'] ? null : ($diag['detail'] ?? null),
        ];
        break;

    // ── Veri Çekme: Sıfatlar ──────────────────────────────
    case 'fetch_sifatlar':
        if (!(function_exists('can') && (can('hks.read') || can('records.write') || is_admin()))) {
            $json_result = ['ok' => false, 'message' => 'Yetki yok.']; break;
        }
        $r = $client->getSifatlar();
        $diag = hks_diagnose_response($r);
        $json_result = [
            'ok'      => $diag['ok'],
            'message' => $diag['ok']
                ? ('Sıfatlar: ' . count($diag['data'] ?? []) . ' kayıt.')
                : $diag['ui_message'],
        ];
        break;

    // ── Veri Çekme: Belge Tipleri ─────────────────────────
    case 'fetch_belge_tipleri':
        if (!(function_exists('can') && (can('hks.read') || can('records.write') || is_admin()))) {
            $json_result = ['ok' => false, 'message' => 'Yetki yok.']; break;
        }
        $r = $client->getBelgeTipleri();
        $diag = hks_diagnose_response($r);
        $json_result = [
            'ok'      => $diag['ok'],
            'message' => $diag['ok']
                ? ('Belge tipleri: ' . count($diag['data'] ?? []) . ' kayıt.')
                : $diag['ui_message'],
        ];
        break;

    // ── Veri Çekme: Kişi Sorgu ────────────────────────────
    case 'fetch_kisi_sorgu':
        if (!(function_exists('can') && (can('hks.read') || can('records.write') || is_admin()))) {
            $json_result = ['ok' => false, 'message' => 'Yetki yok.']; break;
        }
        $tc_vkn_f = trim($input['tc_vkn'] ?? '');
        if ($tc_vkn_f === '') { $json_result = ['ok' => false, 'message' => 'TC/VKN girilmedi.']; break; }
        $r = $client->kayitliKisiSorgu($tc_vkn_f);
        if ($r['ok']) {
            $norm = hks_normalize_response($r['data']);
            if ($norm['ok']) {
                $d = $r['data'][0] ?? [];
                $sonuc = (array)($d['Sonuc'] ?? $d['sonuc'] ?? $d);
                $json_result = [
                    'ok'      => true,
                    'message' => 'Kişi sorgusu başarılı: ' . (empty($sonuc) ? 'Kayıt bulunamadı' : 'Kayıt bulundu'),
                    'detail'  => $sonuc,
                ];
            } else {
                $json_result = ['ok' => false, 'message' => $norm['message'] ?: 'Kişi HKS\'de kayıtlı bulunamadı veya bu kullanıcı ile sorgu yetkiniz yok.', 'detail' => $r['data']];
            }
        } else {
            $json_result = ['ok' => false, 'message' => 'HKS servisine ulaşılamadı: ' . $r['message']];
        }
        $uid_fk = isset($user['id']) ? (int)$user['id'] : null;
        $repo->saveQuery('kisi_sorgu', $tc_vkn_f, $json_result['ok'] ? 'ok' : 'error',
            json_encode($json_result['detail'] ?? null, JSON_UNESCAPED_UNICODE), $uid_fk);
        audit_log_event('hks_kisi_sorgu', 'hks_queries', null, null, ['tc_vkn' => substr($tc_vkn_f, 0, 4) . '***']);
        break;

    // ── Veri Çekme: Referans Künye ────────────────────────
    case 'fetch_referans_kunye':
    case 'update_stock_from_ref_kunye':
        if (!(function_exists('can') && (can('hks.read') || can('records.write') || is_admin()))) {
            $json_result = ['ok' => false, 'message' => 'Yetki yok.']; break;
        }
        $mal_vkn_f = trim($input['mal_sahibi_vkn'] ?? '');
        $kunye_no_f = trim($input['kunye_no'] ?? '0');
        $r = $client->getReferansKunyelerByVkn($mal_vkn_f, $kunye_no_f);
        $diag = hks_diagnose_response($r);
        $uid_rkf = isset($user['id']) ? (int)$user['id'] : null;
        if (!$diag['ok']) {
            $json_result = ['ok' => false, 'message' => $diag['ui_message'], 'detail' => $diag['detail'] ?? null];
            $repo->saveQuery('referans_kunye', $mal_vkn_f . '|' . $kunye_no_f, 'error', null, $uid_rkf);
            break;
        }
        $list_rk = $diag['data'];
        $stok_count = 0;
        if ($action === 'update_stock_from_ref_kunye') {
            foreach ($list_rk as $item) {
                $repo->upsertStockFromHks((array)$item);
                $stok_count++;
            }
        }
        $repo->saveQuery('referans_kunye', $mal_vkn_f . '|' . $kunye_no_f, 'ok',
            json_encode($list_rk, JSON_UNESCAPED_UNICODE), $uid_rkf);
        $json_result = [
            'ok'      => true,
            'message' => count($list_rk) . ' referans künye alındı.' .
                ($action === 'update_stock_from_ref_kunye' ? " {$stok_count} stok satırı güncellendi." : ''),
            'count'   => count($list_rk),
            'detail'  => count($list_rk) > 0 ? $list_rk : null,
        ];
        audit_log_event('hks_referans_kunye_cek', 'hks_queries', null, null, ['mal_vkn' => substr($mal_vkn_f,0,4).'***']);
        break;

    // ── Veri Çekme: Bildirim Listeleri ───────────────────
    case 'fetch_yaptigim_bildirimler':
    case 'fetch_bana_yapilan_bildirimler':
    case 'update_stock_from_bildirimler':
        if (!(function_exists('can') && (can('hks.read') || can('records.write') || is_admin()))) {
            $json_result = ['ok' => false, 'message' => 'Yetki yok.']; break;
        }
        $bl_params_f = [];
        foreach (['baslangic' => 'BaslangicTarihi', 'bitis' => 'BitisTarihi'] as $inp => $hks_key) {
            if (!empty($input[$inp])) {
                $dt = hks_format_service_date($input[$inp]);
                if ($dt === null) { $json_result = ['ok' => false, 'message' => 'Tarih formatı geçersiz.']; break 2; }
                $bl_params_f[$hks_key] = $dt;
            }
        }
        $bl_params_f['KunyeTuru'] = trim($input['kunye_turu'] ?? '1');
        $bl_params_f['KunyeNo']   = trim($input['kunye_no']   ?? '0');

        if ($action === 'fetch_bana_yapilan_bildirimler' || ($action === 'update_stock_from_bildirimler' && ($input['direction'] ?? '') === 'bana')) {
            $r_bl = $client->getBanaYapilanBildirimlerEx($bl_params_f);
            $lbl  = 'Bana yapılan bildirimler';
        } else {
            $r_bl = $client->getYaptigimBildirimlerEx($bl_params_f);
            $lbl  = 'Yaptığım bildirimler';
        }
        $diag_bl = hks_diagnose_response($r_bl);
        if (!$diag_bl['ok']) {
            $json_result = ['ok' => false, 'message' => $diag_bl['ui_message'], 'detail' => $diag_bl['detail'] ?? null]; break;
        }
        $list_bl   = $diag_bl['data'];
        $stok_bl   = 0;
        if ($action === 'update_stock_from_bildirimler') {
            foreach ($list_bl as $item) {
                $repo->upsertStockFromHks((array)$item);
                $stok_bl++;
            }
        }
        $uid_bl = isset($user['id']) ? (int)$user['id'] : null;
        $repo->saveQuery('bildirim_listesi', $lbl, 'ok',
            json_encode(array_slice($list_bl, 0, 50), JSON_UNESCAPED_UNICODE), $uid_bl);
        $json_result = [
            'ok'      => true,
            'message' => $lbl . ': ' . count($list_bl) . ' kayıt.' .
                ($action === 'update_stock_from_bildirimler' ? " {$stok_bl} stok satırı güncellendi." : ''),
            'count'   => count($list_bl),
            'detail'  => count($list_bl) > 0 ? array_slice($list_bl, 0, 10) : null,
        ];
        audit_log_event('hks_bildirim_liste_cek', 'hks_queries', null, null, ['yön' => $lbl]);
        break;

    // ── Bilinmeyen action ───────────────────────────────────
    default:
        $http_status = 400;
        $json_result = ['ok' => false, 'message' => 'Geçersiz işlem: ' . $action];
        break;
}

// SOAP/libxml işlemleri sırasında üretilen TÜM PHP uyarılarını temizle, JSON'ı kirletmesini önle
ob_end_clean();
if ($http_status !== 200) {
    http_response_code($http_status);
}
header('Content-Type: application/json; charset=utf-8');
echo json_encode($json_result ?? ['ok' => false, 'message' => 'İşlem tamamlanamadı.']);

// ── Yardımcı fonksiyonlar ────────────────────────────────

function hks_ajax_sync_reference(HksClient $client, HksRepository $repo, string $ref_type, array $user): array {
    $type_map = [
        'ulke'           => 'getUlkeler',
        'il'             => 'getIller',
        'depo'           => 'getDepolar',
        'sube'           => 'getSubeler',
        'belde'          => 'getBeldeler',
        'hal_ici_isyeri' => 'getHalIciIsyerleri',
        'isletme_turu'   => 'getIsletmeTurleri',
        'urun'           => 'getUrunler',
        'urun_birim'     => 'getUrunBirimleri',
        'urun_cins'      => 'getUrunCinsleri',
        'urun_miktar_birimi' => 'getUrunMiktarBirimleri',
        'bildirim_turu'  => 'getBildirimTurleri',
        'sifat'          => 'getSifatlar',
        'belge_tipi'         => 'getBelgeTipleri',
        'malin_niteligi' => 'getMalinNiteligi',
        'uretim_sekli'   => 'getUretimSekli',
    ];

    if ($ref_type === 'all') {
        $total = 0; $errors = [];
        foreach ($type_map as $rtype => $method) {
            $result = hks_ajax_sync_single($client, $repo, $rtype, $method);
            if ($result['ok']) {
                $total += $result['count'] ?? 0;
            } else {
                $errors[] = $rtype . ': ' . ($result['message'] ?? 'hata');
            }
        }
        audit_log_event('hks_reference_synced', 'hks_reference_cache', null, null, ['types' => 'all', 'total' => $total]);
        if ($errors) {
            // Bazı tipler HKS'den 0002 / parametre hatası aldı — başarıyla sync edilenleri bozmaz.
            // Partial success: ok:true + partial:true + warnings listesi.
            return [
                'ok'       => true,
                'partial'  => true,
                'message'  => "{$total} kayıt senkronize edildi. Yanıt alınamayan tipler: " . implode(', ', $errors),
                'warnings' => $errors,
                'count'    => $total,
            ];
        }
        return ['ok' => true, 'message' => "Tüm referanslar senkronize edildi. Toplam: {$total} kayıt.", 'count' => $total];
    }

    if (!isset($type_map[$ref_type])) {
        return ['ok' => false, 'message' => "Bilinmeyen referans tipi: {$ref_type}"];
    }

    $result = hks_ajax_sync_single($client, $repo, $ref_type, $type_map[$ref_type]);
    if ($result['ok']) {
        audit_log_event('hks_reference_synced', 'hks_reference_cache', null, null, ['type' => $ref_type, 'count' => $result['count'] ?? 0]);
    }
    return $result;
}

function hks_ajax_sync_single(HksClient $client, HksRepository $repo, string $ref_type, string $method): array {
    $result = $client->$method();
    if (!$result['ok']) {
        return ['ok' => false, 'message' => $result['message'] ?? 'Servis hatası'];
    }
    $data = $result['data'] ?? [];

    // HKS bazen IslemKodu envelope döner, bazen doğrudan liste döner.
    // İlk eleman IslemKodu taşıyorsa: normalize et, Sonuc listesini çıkar.
    if (!empty($data[0])) {
        $first = (array)$data[0];
        if (isset($first['IslemKodu']) || isset($first['islemKodu'])) {
            $norm = hks_normalize_response($data);
            if (!$norm['ok']) {
                return ['ok' => false, 'message' => $norm['message'] ?: ('HKS hata kodu: ' . ($norm['islem_kodu'] ?? ''))];
            }
            // Sonucu bul: Sonuc / Liste / sonuc alanında olabilir
            $list = $first['Sonuc'] ?? $first['sonuc'] ?? $first['Liste'] ?? $first['liste'] ?? null;
            $data = is_array($list) ? $list : ($list !== null ? [$list] : []);
        }
    }

    $repo->deactivateReferenceType($ref_type);
    $count = 0;
    foreach ($data as $item) {
        $item = (array)$item;
        $code   = (string)($item['Kod'] ?? $item['kod'] ?? $item['ID'] ?? $item['id'] ?? '');
        $name   = (string)($item['Ad'] ?? $item['ad'] ?? $item['Adi'] ?? $item['adi'] ?? $item['Unvan'] ?? $item['unvan'] ?? $item['name'] ?? $code);
        $parent = (string)($item['IlceKodu'] ?? $item['ilce_kodu'] ?? $item['IlKodu'] ?? $item['il_kodu'] ?? $item['ParentKod'] ?? '');
        if ($code === '') continue;
        $repo->upsertReference($ref_type, $code, $name, $parent ?: null, json_encode($item, JSON_UNESCAPED_UNICODE));
        $count++;
    }
    return ['ok' => true, 'message' => hks_ref_type_label($ref_type) . ": {$count} kayıt senkronize edildi.", 'count' => $count];
}
