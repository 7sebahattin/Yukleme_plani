<?php
// =========================================================
// scripts/cost_link_smoke.php — Maliyet-Yükleme-01 / Adım 1 doğrulama
// SADECE CLI. Hiçbir DB yazımı yapmaz (cost_migrate() dışında — o da
// idempotent CREATE TABLE IF NOT EXISTS / ALTER'dır).
//
// Kullanım:
//   php scripts/cost_link_smoke.php search <metin>
//   php scripts/cost_link_smoke.php record <loading_record_id>
// =========================================================
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}

require_once __DIR__ . '/../config/cost_link.php';

cost_migrate(); // cost_sheets tablosu/kolonları yoksa oluşturur (idempotent)

function pr(string $s = ''): void { echo $s . "\n"; }
function dump($v): void { echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"; }

$cmd = $argv[1] ?? '';

if ($cmd === 'search') {
    $q = $argv[2] ?? '';
    pr("== cost_link_search(\"$q\") ==");
    $res = cost_link_search($q);
    pr('Sonuç sayısı: ' . count($res['items']) . ' | truncated: ' . ($res['truncated'] ? 'evet' : 'hayır'));
    dump($res['items']);
    exit(0);
}

if ($cmd === 'record') {
    $id = (int)($argv[2] ?? 0);
    if ($id <= 0) {
        pr('Kullanım: php scripts/cost_link_smoke.php record <loading_record_id>');
        exit(1);
    }

    pr("== cost_link_record_summary($id) ==");
    $summary = cost_link_record_summary($id);
    if ($summary === null) {
        pr('null döndü — kayıt yok, type != yukleme, veya sorgu hatası.');
        exit(1);
    }
    dump($summary);
    if (array_key_exists('sheet_no', $summary)) {
        pr('❌ HATA: özet çıktısında sheet_no anahtarı olmamalıydı!');
    } else {
        pr('✓ sheet_no anahtarı yok (beklenen davranış — Sheet No ile Parti No ayrı).');
    }

    pr('');
    pr("== cost_link_materials($id) ==");
    $materials = cost_link_materials($id);
    pr('Malzeme satırı sayısı: ' . count($materials));
    dump($materials);

    pr('');
    pr('== cost_link_apply() — varsayılan şablonla ==');
    $tpl_id = (int)(db()->query(
        "SELECT id FROM cost_templates WHERE is_active=1 ORDER BY is_default DESC, id ASC LIMIT 1"
    )->fetchColumn() ?: 0);

    if ($tpl_id <= 0) {
        pr('Aktif şablon bulunamadı — cost_migrate() default şablonu seed etmiş olmalı.');
        exit(1);
    }

    $st = db()->prepare("SELECT * FROM cost_template_sections WHERE template_id=? ORDER BY sort_order ASC, id ASC");
    $st->execute([$tpl_id]);
    $sections = [];
    $code_key = [];
    foreach ($st->fetchAll() as $i => $ts) {
        $key = 's' . ($i + 1);
        $code_key[$ts['code']] = $key;
        $ts['id'] = $key;
        $sections[] = $ts;
    }

    $st = db()->prepare("SELECT * FROM cost_template_items WHERE template_id=? ORDER BY sort_order ASC, id ASC");
    $st->execute([$tpl_id]);
    $items = [];
    foreach ($st->fetchAll() as $i => $ti) {
        $ti['id']          = 'n' . ($i + 1);
        $ti['section_key'] = $code_key[$ti['section_code']] ?? ($sections[0]['id'] ?? 's1');
        $items[] = $ti;
    }

    $applied = cost_link_apply($sections, $items, $materials);
    pr("Eşleşen: {$applied['matched']} | Eşleşmeyen (yeni satır): {$applied['unmatched']}");
    foreach ($applied['items'] as $it) {
        $tag = str_starts_with((string)$it['id'], 'ml') ? '[YENİ]' : '[ŞABLON]';
        printf("  %-9s %-30s qty=%-10s unit=%-6s price=%s\n",
            $tag, $it['label'], $it['qty'], $it['unit'] ?: '-', $it['unit_price']);
    }

    pr('');
    pr('== cost_link_stale_info() ==');
    pr('linked_at=NOW() ile:');
    dump(cost_link_stale_info($id, date('Y-m-d H:i:s')) ?? 'null (bayat değil — beklenen)');
    pr('linked_at=2000-01-01 ile:');
    dump(cost_link_stale_info($id, '2000-01-01 00:00:00') ?? 'null');
    pr('linked_at=NULL ile:');
    dump(cost_link_stale_info($id, null) ?? 'null (kontrol yapılmaz — beklenen)');

    exit(0);
}

pr('Kullanım:');
pr('  php scripts/cost_link_smoke.php search <metin>');
pr('  php scripts/cost_link_smoke.php record <loading_record_id>');
exit(1);
