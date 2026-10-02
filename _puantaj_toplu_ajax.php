<?php
// =========================================================
// _puantaj_toplu_ajax.php — Toplu İşlem JSON uçları (v294, YALNIZ yönetici)
//
// Sayfa, çıktı basmadan ÖNCE include eder. Partial fonksiyon TANIMLAMAZ.
//   ?ajax=toplu_onizle → pdks_faz8j_toplu_onizle()  (yan etkisiz)
//   ?ajax=toplu_ekle   → pdks_faz8j_toplu_ekle()    (hep-ya-hiç)
// Çavuş/gün/depo SUNUCU değerleridir: $topluAjaxSabit istemcininkini ezer
// (foreman_id yalnız liste sayfasında istemciden gelir; işlev doğrular).
// Beklenen: $pdo, $auth_user, $topluAjaxSabit ['foreman_id'=>?int,'work_date'=>,'depo'=>],
//           $topluAjaxKapi (bool: yönetici + aktif depo + şema hazır + gün ≤ bugün)
// =========================================================
if (!isset($pdo, $auth_user, $topluAjaxSabit)) { http_response_code(404); exit; }
$__tpAjax = (string)($_GET['ajax'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($__tpAjax, ['toplu_onizle', 'toplu_ekle'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    $__tpIn = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($__tpIn)) $__tpIn = [];
    csrf_check(isset($__tpIn['csrf']) && is_string($__tpIn['csrf']) ? $__tpIn['csrf'] : null);
    $__tpCikti = static function (array $d, int $kod = 200) { http_response_code($kod); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
    if ($__tpHata = (function_exists('pdks_faz8j_yetki') ? pdks_faz8j_yetki() : 'Yetkisiz.')) $__tpCikti(['ok' => false, 'error' => $__tpHata, 'hatalar' => [$__tpHata]], 403);
    if (empty($topluAjaxKapi)) $__tpCikti(['ok' => false, 'error' => 'Bu mesai için toplu işlem yapılamaz.', 'hatalar' => ['Bu mesai için toplu işlem yapılamaz.']], 403);
    // Yalnız beklenen alanlar geçer (kart_ids tam sayıya indirgenir).
    $__tpGruplar = [];
    foreach ((is_array($__tpIn['gruplar'] ?? null) ? $__tpIn['gruplar'] : []) as $__g) {
        if (!is_array($__g)) continue;
        $__tpGruplar[] = [
            'worker_type_id' => (int)($__g['worker_type_id'] ?? 0),
            'entry_clock' => (string)($__g['entry_clock'] ?? ''), 'exit_date' => (string)($__g['exit_date'] ?? ''), 'exit_clock' => (string)($__g['exit_clock'] ?? ''),
            'kart_ids' => array_values(array_map('intval', is_array($__g['kart_ids'] ?? null) ? $__g['kart_ids'] : [])),
            'kartsiz_adet' => (int)($__g['kartsiz_adet'] ?? 0),
        ];
    }
    $__tpV = [
        'foreman_id' => $topluAjaxSabit['foreman_id'] ?? (int)($__tpIn['foreman_id'] ?? 0),
        'work_date' => (string)$topluAjaxSabit['work_date'], 'depo' => (string)$topluAjaxSabit['depo'],
        'reason' => (string)($__tpIn['reason'] ?? ''), 'note' => (string)($__tpIn['note'] ?? ''), 'gruplar' => $__tpGruplar,
    ];
    if ($__tpAjax === 'toplu_onizle') {
        $__tpS = pdks_faz8j_toplu_onizle($__tpV, (int)$auth_user['id'], $pdo);
    } else {
        $__tpS = pdks_faz8j_toplu_ekle($__tpV, (int)$auth_user['id'], $pdo);
        if (!empty($__tpS['ok'])) {
            set_flash('success', (int)$__tpS['eklenen'] . ' kayıt eklendi (toplu işlem ' . $__tpS['toplu_id'] . ').');
            $__tpS['redirect'] = 'gunluk_isci_puantaj_detay.php?id=' . (int)$__tpS['session_id'];
        }
    }
    $__tpCikti($__tpS);
}
