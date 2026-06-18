<?php
// HKS AJAX endpoint — tüm AJAX işlemler burada
declare(strict_types=1);
require_once __DIR__ . '/includes/hks_bootstrap.php';

// Auth zorunlu
require_once __DIR__ . '/../config/auth.php';
$user = current_user();
if (!$user) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Oturum açmanız gerekiyor.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

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
$repo   = new HksRepository(db());
$client = new HksClient($repo);

switch ($action) {

    // ── Bağlantı Testi ──────────────────────────────────────
    case 'test_connection':
        if (!is_admin() && !(function_exists('can') && can('hks.settings'))) {
            echo json_encode(['ok' => false, 'message' => 'Yetki yok.']); exit;
        }
        audit_log_event('hks_connection_tested', 'hks_settings', null, null, ['user' => $user['username'] ?? '']);
        echo json_encode($client->testConnection());
        break;

    // ── WSDL Metod Keşfi ───────────────────────────────────
    case 'inspect_wsdl':
        if (!is_admin() && !(function_exists('can') && can('hks.settings'))) {
            echo json_encode(['ok' => false, 'message' => 'Yetki yok.']); exit;
        }
        $wsdl_service = $input['service'] ?? 'genel';
        echo json_encode($client->inspectWsdl($wsdl_service));
        break;

    // ── Referans Senkronizasyonu ────────────────────────────
    case 'sync_reference':
        if (!is_admin() && !(function_exists('can') && can('hks.settings'))) {
            echo json_encode(['ok' => false, 'message' => 'Yetki yok.']); exit;
        }
        $ref_type = $input['ref_type'] ?? 'all';
        echo json_encode(hks_ajax_sync_reference($client, $repo, $ref_type, $user));
        break;

    // ── Stok Yeniden Hesaplama ──────────────────────────────
    case 'rebuild_stock':
        if (!is_admin()) {
            echo json_encode(['ok' => false, 'message' => 'Admin yetkisi gerekiyor.']); exit;
        }
        try {
            $repo->rebuildStock();
            echo json_encode(['ok' => true, 'message' => 'Stok yeniden hesaplandı.']);
        } catch (Throwable $e) {
            echo json_encode(['ok' => false, 'message' => 'Hata: ' . $e->getMessage()]);
        }
        break;

    // ── Künye Sorgu ────────────────────────────────────────
    case 'query_kunye':
        if (!(function_exists('can') && (can('hks.read') || can('records.write')))) {
            echo json_encode(['ok' => false, 'message' => 'Yetki yok.']); exit;
        }
        $kunye_no = trim($input['kunye_no'] ?? '');
        if ($kunye_no === '') {
            echo json_encode(['ok' => false, 'message' => 'Künye numarası girilmedi.']); exit;
        }
        $uid = isset($user['id']) ? (int)$user['id'] : null;
        $result = $client->queryKunye($kunye_no);
        if ($result['ok']) {
            $repo->saveQuery('kunye', $kunye_no, 'ok', json_encode($result['data'] ?? null, JSON_UNESCAPED_UNICODE), $uid);
            audit_log_event('hks_kunye_query', 'hks_queries', null, null, ['kunye_no' => $kunye_no]);
        } else {
            $repo->saveQuery('kunye', $kunye_no, 'error', $result['message'] ?? null, $uid);
        }
        echo json_encode($result);
        break;

    // ── Bildirim Sorgu ─────────────────────────────────────
    case 'query_bildirim':
        if (!(function_exists('can') && (can('hks.read') || can('records.write')))) {
            echo json_encode(['ok' => false, 'message' => 'Yetki yok.']); exit;
        }
        $bildirim_no = trim($input['bildirim_no'] ?? '');
        if ($bildirim_no === '') {
            echo json_encode(['ok' => false, 'message' => 'Bildirim numarası girilmedi.']); exit;
        }
        $uid2 = isset($user['id']) ? (int)$user['id'] : null;
        $result = $client->queryBildirim(['BildirimNo' => $bildirim_no]);
        if ($result['ok']) {
            $repo->saveQuery('bildirim', $bildirim_no, 'ok', json_encode($result['data'] ?? null, JSON_UNESCAPED_UNICODE), $uid2);
            audit_log_event('hks_bildirim_query', 'hks_queries', null, null, ['bildirim_no' => $bildirim_no]);
        } else {
            $repo->saveQuery('bildirim', $bildirim_no, 'error', $result['message'] ?? null, $uid2);
        }
        echo json_encode($result);
        break;

    // ── Bilinmeyen action ───────────────────────────────────
    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Geçersiz işlem: ' . $action]);
        break;
}

// ── Yardımcı fonksiyonlar ────────────────────────────────

function hks_ajax_sync_reference(HksClient $client, HksRepository $repo, string $ref_type, array $user): array {
    $type_map = [
        'ulke'          => 'getUlkeler',
        'il'            => 'getIller',
        'depo'          => 'getDepolar',
        'sube'          => 'getSubeler',
        'urun'          => 'getUrunler',
        'urun_birim'    => 'getUrunBirimleri',
        'urun_cins'     => 'getUrunCinsleri',
        'bildirim_turu' => 'getBildirimTurleri',
        'sifat'         => 'getSifatlar',
        'malin_niteligi'=> 'getMalinNiteligi',
        'uretim_sekli'  => 'getUretimSekli',
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
            return ['ok' => false, 'message' => 'Bazı tipler başarısız: ' . implode(', ', $errors)];
        }
        return ['ok' => true, 'message' => "Tüm referanslar senkronize edildi. Toplam: {$total} kayıt."];
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
    $repo->deactivateReferenceType($ref_type);
    $count = 0;
    foreach ($data as $item) {
        $item = (array)$item;
        $code   = (string)($item['Kod'] ?? $item['kod'] ?? $item['ID'] ?? $item['id'] ?? '');
        $name   = (string)($item['Ad'] ?? $item['ad'] ?? $item['Adi'] ?? $item['adi'] ?? $item['name'] ?? $code);
        $parent = (string)($item['IlKodu'] ?? $item['il_kodu'] ?? $item['ParentKod'] ?? '');
        if ($code === '') continue;
        $repo->upsertReference($ref_type, $code, $name, $parent ?: null, json_encode($item, JSON_UNESCAPED_UNICODE));
        $count++;
    }
    return ['ok' => true, 'message' => hks_ref_type_label($ref_type) . ": {$count} kayıt senkronize edildi.", 'count' => $count];
}
