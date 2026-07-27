<?php
// =========================================================
// api_maliyet_link.php — Maliyet ↔ Yükleme Planı AJAX uç noktası
//
// SADECE OKUMA. Hiçbir tabloya yazmaz. cost_migrate() ÇAĞIRMAZ —
// bu uç nokta cost_sheets'e hiç dokunmuyor (yalnız arama sırasında
// kullanıcı yazdıkça tekrar tekrar çağrılacağı için migration kontrolü
// gereksiz yük olurdu).
//
//   GET ?action=search&q=<en az 2 karakter>          → {items, truncated}
//   GET ?action=detail&record_id=<id>[&template_id=] → {header, rows, newRows, info}
//
// JavaScript bu uç noktayı SADECE veri getirmek için çağırır — hiçbir
// tutar burada hesaplanmaz; cost_compute() tek otorite olarak kalır.
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/cost_link.php';

// require_login()/enforce_active_depot() bu uygulamanın genel yönlendirme
// konvansiyonunu izler (redirect/HTML) — bu iki durum pratikte imkansızdır,
// çünkü bu uç noktaya yalnız zaten oturum açmış + depo seçmiş bir kullanıcının
// açtığı maliyet_form.php'den ulaşılır. Aşağıdaki YETKİ kontrolleri ise
// (maliyet.write, records.read) bilinçli olarak require_maliyet() ÇAĞIRMAZ —
// onun forbidden() çıkışı HTML döner; burada gerçekten ulaşılabilir bir hata
// durumu olduğu için (yetkisi sonradan alınmış oturum, doğrudan URL denemesi)
// JSON gövde şart.
$auth_user = require_login();
if (function_exists('enforce_active_depot')) enforce_active_depot();

header('Content-Type: application/json; charset=utf-8');

if (!can_maliyet('write')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'maliyet.write yetkisi gereklidir'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!can('records.read')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'records.read yetkisi gereklidir'], JSON_UNESCAPED_UNICODE);
    exit;
}

function ok(array $d = []): void { echo json_encode(['ok' => true] + $d, JSON_UNESCAPED_UNICODE); exit; }
function err(string $m, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $m], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = (string)($_GET['action'] ?? '');

switch ($action) {

    // ---- Parti No / firma / plaka / ürün canlı arama ----
    case 'search':
        $q = (string)($_GET['q'] ?? '');
        $res = cost_link_search($q);
        ok(['items' => $res['items'], 'truncated' => $res['truncated']]);
        break;

    // ---- Seçilen partinin ön-doldurma verisi ----
    case 'detail':
        $record_id = (int)($_GET['record_id'] ?? 0);
        if ($record_id <= 0) err('record_id gerekli');

        $summary = cost_link_record_summary($record_id);
        if ($summary === null) err('Yükleme kaydı bulunamadı', 404);

        if (!depot_visible_to_user($summary['depo'])) {
            err('Bu kayıt aktif deponuzda görünmüyor', 403);
        }

        // template_id parametresi HİÇ verilmemişse (isset değil) çağıran
        // hangi şablonu istediğini belirtmemiştir → sitenin varsayılan
        // şablonuna düşülür (basit manuel test/URL çağrıları için).
        //
        // AÇIKÇA 0 veya negatif verilmişse (maliyet_form.php'nin JS'i açık
        // sayfanın gerçek tpl'ini — "Boş sayfa"da -1 — her zaman gönderir)
        // bu bir "boş sayfa" SİNYALİDİR: hiçbir şablona eşleştirme yapılmaz,
        // DOM'da zaten var olan kalem yoktur, tüm malzemeler 'newRows' olarak
        // döner. Varsayılana DÜŞÜLMEZ — aksi halde sayfa boşken JS'in
        // bulamayacağı "eşleşme" sayıları üretilir (bkz. Adım 4 test notu).
        if (!isset($_GET['template_id'])) {
            $template_id = (int)(db()->query(
                "SELECT id FROM cost_templates WHERE is_active=1 ORDER BY is_default DESC, id ASC LIMIT 1"
            )->fetchColumn() ?: 0);
            if ($template_id <= 0) err('Aktif şablon bulunamadı', 404);
        } else {
            $template_id = (int)$_GET['template_id'];
            if ($template_id > 0) {
                $st = db()->prepare("SELECT 1 FROM cost_templates WHERE id=? AND is_active=1");
                $st->execute([$template_id]);
                if (!$st->fetchColumn()) $template_id = 0; // geçersiz/pasif → boş kabul edilir, HATA değil
            } else {
                $template_id = 0;
            }
        }

        $tpl       = cost_link_load_template($template_id);
        $materials = cost_link_materials($record_id);
        $applied   = cost_link_apply($tpl['sections'], $tpl['items'], $materials);

        // 'rows' YALNIZ gerçekten bir malzemeyle eşleşmiş kalemleri içerir —
        // "calc_type='qty_price' olan her kalem" değil. Aksi halde şablondaki
        // malzemeyle ilgisiz sabit kalemler (ör. "A-90 FRIGGA", "ALIM (TONAJ)")
        // de listeye girer; zararsızdır (kendi varsayılanını geri yazar) ama
        // Adım 4'teki "yükleme planından geldi" görsel işaretini yanlış tetikler.
        $matched_set = array_flip($applied['matched_codes']);
        $rows    = [];
        $newRows = [];
        foreach ($applied['items'] as $it) {
            if (str_starts_with((string)$it['id'], 'ml')) {
                $newRows[] = [
                    'label'      => (string)$it['label'],
                    'code'       => (string)$it['code'],
                    'qty'        => (float)$it['qty'],
                    'unit'       => (string)$it['unit'],
                    'unit_price' => (float)$it['unit_price'],
                ];
                continue;
            }
            $code = (string)($it['code'] ?? '');
            if ($code !== '' && isset($matched_set[$code])) {
                $rows[$code] = ['qty' => (float)$it['qty']];
            }
        }

        ok([
            'header' => [
                'product'    => $summary['product'],
                'firma'      => $summary['firma'],
                'alici'      => $summary['alici'],
                'brand'      => $summary['brand'],
                'gumruk'     => $summary['gumruk'],
                'plaka'      => $summary['plaka'],
                'sheet_date' => $summary['sheet_date'],
                'net_kg'     => $summary['net_kg'],
                'brut_kg'    => $summary['brut_kg'],
            ],
            // (object) zorlaması: PHP boş bir ilişkisel diziyi JSON'da [] olarak
            // kodlar, dolu haldeyse {} — JS tarafında tutarlı bir nesne sözleşmesi
            // için boşken de {} dönmesi gerekir.
            'rows'    => (object)$rows,
            'newRows' => $newRows,
            'info'    => [
                'parti_no'  => $summary['parti_no'],
                'matched'   => $applied['matched'],
                'unmatched' => $applied['unmatched'],
            ],
        ]);
        break;

    default:
        err('Geçersiz action');
}
