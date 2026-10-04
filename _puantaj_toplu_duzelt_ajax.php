<?php
// =========================================================
// _puantaj_toplu_duzelt_ajax.php — Kart Hareketleri toplu düzenle / iptal JSON uçları (v302, YALNIZ yönetici)
//
// Sayfa (gunluk_isci_puantaj_detay.php), çıktı basmadan ÖNCE include eder. Partial fonksiyon TANIMLAMAZ.
//   ?ajax=toplu_duzelt → pdks_faz8j_toplu_duzelt()  (hep-ya-hiç)
//   ?ajax=toplu_iptal  → pdks_faz8j_toplu_iptal()   (hep-ya-hiç)
// Mesai id'si SUNUCU değeridir ($id) — istemciden session_id ALINMAZ.
// Beklenen: $pdo, $auth_user, $id (mesai id),
//           $tdAjaxKapi (bool: yönetici + faz8j şeması hazır + mesai deposu = aktif depo)
// =========================================================
if (!isset($pdo, $auth_user, $id, $tdAjaxKapi)) { http_response_code(404); exit; }
$__tdAjax = (string)($_GET['ajax'] ?? '');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($__tdAjax, ['toplu_duzelt', 'toplu_iptal'], true)) {
    header('Content-Type: application/json; charset=utf-8');
    $__tdIn = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($__tdIn)) $__tdIn = [];
    csrf_check(isset($__tdIn['csrf']) && is_string($__tdIn['csrf']) ? $__tdIn['csrf'] : null);
    $__tdCikti = static function (array $d, int $kod = 200) { http_response_code($kod); echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; };
    if ($__tdHata = (function_exists('pdks_faz8j_yetki') ? pdks_faz8j_yetki() : 'Yetkisiz.')) $__tdCikti(['ok' => false, 'error' => $__tdHata, 'hata' => $__tdHata, 'hatalar' => []], 403);
    if (empty($tdAjaxKapi)) $__tdCikti(['ok' => false, 'error' => 'Bu mesai için toplu düzenleme yapılamaz.', 'hata' => 'Bu mesai için toplu düzenleme yapılamaz.', 'hatalar' => []], 403);
    // Yalnız beklenen alanlar geçer. Skaler olmayan değer (dizi/nesne) '' / 0 sayılır —
    // JSON çıktısına PHP uyarısı karışmaz.
    $__tdS = static fn($x): string => is_scalar($x) ? (string)$x : '';
    $__tdI = static fn($x): int => is_scalar($x) ? (int)$x : 0;
    $__tdSid = (int)$id;
    $__tdUser = (int)$auth_user['id'];
    if ($__tdAjax === 'toplu_duzelt') {
        $__tdSatirlar = [];
        foreach ((is_array($__tdIn['satirlar'] ?? null) ? $__tdIn['satirlar'] : []) as $__s) {
            if (!is_array($__s)) $__s = [];
            $__tdSatirlar[] = [
                'period_id' => $__tdI($__s['period_id'] ?? 0), 'worker_type_id' => $__tdI($__s['worker_type_id'] ?? 0),
                'entry_clock' => $__tdS($__s['entry_clock'] ?? ''),
                'exit_date' => $__tdS($__s['exit_date'] ?? ''), 'exit_clock' => $__tdS($__s['exit_clock'] ?? ''),
            ];
        }
        $__tdR = pdks_faz8j_toplu_duzelt($__tdSid, $__tdSatirlar, $__tdS($__tdIn['reason'] ?? ''), $__tdS($__tdIn['note'] ?? ''),
            $__tdS($__tdIn['istek_id'] ?? ''), $__tdUser, $pdo);
        if (!empty($__tdR['ok'])) {
            set_flash('success', (int)$__tdR['guncellenen'] . ' kayıt düzeltildi' . ((int)$__tdR['atlanan'] > 0 ? ', ' . (int)$__tdR['atlanan'] . ' kayıt değişmediği için atlandı' : '') . ' (toplu düzenleme ' . $__tdR['toplu_id'] . ').');
        }
    } else {
        $__tdIds = array_values(array_map($__tdI, is_array($__tdIn['period_ids'] ?? null) ? $__tdIn['period_ids'] : []));
        $__tdR = pdks_faz8j_toplu_iptal($__tdSid, $__tdIds, $__tdS($__tdIn['reason'] ?? ''), $__tdS($__tdIn['istek_id'] ?? ''), $__tdUser, $pdo);
        if (!empty($__tdR['ok'])) {
            set_flash('success', (int)$__tdR['iptal_edilen'] . ' kayıt iptal edildi' . ((int)$__tdR['atlanan'] > 0 ? ', ' . (int)$__tdR['atlanan'] . ' kayıt zaten iptal edilmişti' : '') . '.');
        }
    }
    if (!empty($__tdR['ok'])) $__tdR['redirect'] = 'gunluk_isci_puantaj_detay.php?id=' . $__tdSid;
    $__tdCikti($__tdR);
}
