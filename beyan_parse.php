<?php
// =========================================================
// beyan_parse.php — WhatsApp beyan metni ayrıştırıcı (Sprint Beyan-02)
// AJAX endpoint: POST {csrf, text} → JSON {ok, parsed, unmatched, matched_count}
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
if (!can_beyan('write')) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Yetersiz yetki.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Yalnızca POST desteklenir.']);
    exit;
}

csrf_check($_POST['csrf'] ?? null);

$raw = trim((string)($_POST['text'] ?? ''));
if ($raw === '') {
    echo json_encode(['ok' => false, 'error' => 'Metin boş.']);
    exit;
}

// ── Turkish number parser ── mirrors num() but returns null for empty/invalid ──
function parse_beyan_number(string $s): ?float
{
    $s = trim($s);
    if ($s === '') return null;
    // Strip trailing unit suffixes (KG, ADET, AD, PCS, TN)
    $s = preg_replace('/\s*(KG|ADET|AD\.?|PCS|TN)\.?\s*$/ui', '', $s);
    $s = trim(str_replace([' ', "\xc2\xa0"], '', $s));
    if ($s === '') return null;
    if (str_contains($s, ',')) {
        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);
    } else {
        $s = str_replace('.', '', $s); // dot is always thousands sep in Turkish
    }
    return is_numeric($s) ? (float)$s : null;
}

// ── Main parser ──
function parse_beyan_text(string $raw): array
{
    $out = [
        'declaration_title' => null,
        'party_no'          => null,
        'transport_type'    => null,
        'line_type'         => null,
        'company_name'      => null,
        'company_address'   => null,
        'product_name'      => null,
        'product_variety'   => null,
        'pallet_count'      => null,
        'gross_kg'          => null,
        'net_kg'            => null,
        'crate_count'       => null,
        'crate_type'        => null,
        'exit_depot'        => null,
        'buyer_name'        => null,
        'contact_person'    => null,
        'brand'             => null,
    ];

    $lines   = preg_split('/\r?\n/', trim($raw));
    $matched = array_fill(0, count($lines), false);

    // Helper: set field only once; returns true when actually set
    $setOnce = static function (string $field, $val) use (&$out): bool {
        if ($out[$field] !== null || $val === null || $val === '') return false;
        $out[$field] = $val;
        return true;
    };

    // ── Pass 1: labeled patterns (KEY: VALUE or KEY - VALUE) ──
    $labeled = [
        'declaration_title' => '/^(?:BEYAN\s*T[İI]P[İI]|BA[ŞS]LIK|TITLE)\s*[:\-]\s*(.+)/ui',
        'party_no'          => '/^PART[İI]\s*NO\s*[:\-]\s*(.+)/ui',
        'transport_type'    => '/^(?:NAKL[İI]YE\s*T[ÜU]R[ÜU]|TRANSPORT\s*TYPE)\s*[:\-]\s*(.+)/ui',
        'line_type'         => '/^(?:HAT|LINE|G[ÜU]ZERGAH|GÜZERGAH)\s*[:\-]\s*(.+)/ui',
        'product_name'      => '/^(?:[ÜU]R[ÜU]N(?:\s*ADI)?|MAHSUL)\s*[:\-]\s*(.+)/ui',
        'product_variety'   => '/^(?:[CÇ]E[ŞS][İI]T(?:\s*ADI)?|VARYETE|VAR\.)\s*[:\-]\s*(.+)/ui',
        'pallet_count'      => '/^(?:PALET(?:\s*ADET[İI])?|PALLET)\s*[:\-]\s*(.+)/ui',
        'gross_kg'          => '/^(?:BR[ÜU]T(?:\s*KG)?|GROSS(?:\s*KG)?|TOPLAM\s*KG)\s*[:\-]\s*(.+)/ui',
        'net_kg'            => '/^NET(?:\s*KG(?:\s*A[ĞG]IRLI[ĞG]I)?|(?:\s*A[ĞG]IRLI[ĞG]I)?)?\s*[:\-]\s*(.+)/ui',
        'crate_count'       => '/^(?:KASA(?:\s*ADET[İI])?|SANDIK|KUTU)\s*[:\-]\s*(.+)/ui',
        'crate_type'        => '/^(?:KASA\s*C[İI]NS[İI]|KASA\s*T[İI]P[İI]|AMBALAJ)\s*[:\-]\s*(.+)/ui',
        'exit_depot'        => '/^(?:[CÇ][IÇ]KI[ŞS]\s*DEPO|DEPO(?:\s*ADI)?)\s*[:\-]\s*(.+)/ui',
        'buyer_name'        => '/^(?:ALICI|BUYER|M[ÜU][ŞS]TER[İI])\s*[:\-]\s*(.+)/ui',
        'contact_person'    => '/^(?:[İI]LG[İI]L[İI](?:\s*K[İI][ŞŞ][İI])?|CONTACT|YETKL[İI])\s*[:\-]\s*(.+)/ui',
        'brand'             => '/^(?:MARKA|BRAND)\s*[:\-]\s*(.+)/ui',
    ];

    $numFields = ['pallet_count', 'gross_kg', 'net_kg', 'crate_count'];

    foreach ($lines as $i => $line) {
        $t = trim($line);
        if ($t === '') {
            $matched[$i] = true;
            continue;
        }
        foreach ($labeled as $field => $pattern) {
            if (!preg_match($pattern, $t, $m)) continue;
            $val = trim($m[1]);
            if (in_array($field, $numFields, true)) {
                $n = parse_beyan_number($val);
                if ($n !== null) {
                    $v = in_array($field, ['pallet_count', 'crate_count'], true) ? (int)$n : $n;
                    if ($setOnce($field, $v)) $matched[$i] = true;
                }
            } else {
                if ($setOnce($field, $val)) $matched[$i] = true;
            }
            break;
        }
    }

    // ── Pass 2: unlabeled / positional patterns ──
    foreach ($lines as $i => $line) {
        if ($matched[$i]) continue;
        $t = trim($line);
        if ($t === '') {
            $matched[$i] = true;
            continue;
        }
        $tu = mb_strtoupper($t, 'UTF-8');

        // "YENİ BEYAN" / "BEYAN" title line (≤60 chars)
        if (preg_match('/\bBEYAN\b/ui', $t) && mb_strlen($t) <= 60) {
            if ($setOnce('declaration_title', $t)) { $matched[$i] = true; continue; }
        }

        // Parti no: standalone "46/22" or "PRTİ:46/22"
        if (preg_match('/^(\d{1,4}\/\d{1,4})$/u', $t, $m)) {
            if ($setOnce('party_no', $m[1])) { $matched[$i] = true; continue; }
        }

        // Transport type standalone keyword
        if (preg_match('/^(DEN[İI]ZYOLU|KARAYOLU|HAVAYOLU|T[İI]R|U[ÇC]AK)$/ui', $t, $m)) {
            if ($setOnce('transport_type', $tu)) { $matched[$i] = true; continue; }
        }

        // Line type: "YEŞİL HAT", "KIRMIZI HAT", etc.
        if (preg_match('/^(YE[ŞS][İI]L|KIRMIZI|TURUNCU|LAC[İI]VERT|MAV[İI]|SARI)\s+HAT$/ui', $t, $m)) {
            if ($setOnce('line_type', $tu)) { $matched[$i] = true; continue; }
        }

        // "26 PALET" or "26 PALET ADEDİ"
        if (preg_match('/^(\S+)\s+PALET(?:\s*ADET[İI])?$/ui', $t, $m)) {
            $n = parse_beyan_number($m[1]);
            if ($n !== null && $setOnce('pallet_count', (int)$n)) { $matched[$i] = true; continue; }
        }

        // "24.200 BRÜT" or "24.200 KG BRÜT" or "BRÜT 24.200" or "BRÜT: 24.200 KG"
        if (preg_match('/^(\S+)\s+(?:KG\s+)?BR[ÜU]T(?:\s+KG)?$/ui', $t, $m)
            || preg_match('/^BR[ÜU]T\s+(?:KG\s+)?(\S+(?:\s+KG)?)$/ui', $t, $m)) {
            $n = parse_beyan_number($m[1]);
            if ($n !== null && $setOnce('gross_kg', $n)) { $matched[$i] = true; continue; }
        }

        // "22.400 NET" or "NET 22.400"
        if (preg_match('/^(\S+)\s+(?:KG\s+)?NET(?:\s+KG)?$/ui', $t, $m)
            || preg_match('/^NET\s+(?:KG\s+)?(\S+(?:\s+KG)?)$/ui', $t, $m)) {
            $n = parse_beyan_number($m[1]);
            if ($n !== null && $setOnce('net_kg', $n)) { $matched[$i] = true; continue; }
        }

        // "2.662 KASA" or "KASA 2.662"
        if (preg_match('/^(\S+)\s+KASA(?:\s*ADET[İI])?$/ui', $t, $m)
            || preg_match('/^KASA\s+(\S+)$/ui', $t, $m)) {
            $n = parse_beyan_number($m[1]);
            if ($n !== null && $setOnce('crate_count', (int)$n)) { $matched[$i] = true; continue; }
        }

        // Crate type: PLASTİK KASA (typo-tolerant: PLASİTK, PLASTIK, PLASITK)
        if (preg_match('/^(?:PLAS(?:T[İI]|[İI]T)K|PLAS(?:TIK|[İI]K)?)\s+(?:KASA|SANDIK|KUTU)$/ui', $t)
            || preg_match('/^(?:AH[ŞS]AP|KARTON|OLUKLU|KAĞIT|TAHTA|WOOD|METAL)\s+(?:KASA|SANDIK|KUTU)$/ui', $t)) {
            if ($setOnce('crate_type', $t)) { $matched[$i] = true; continue; }
        }

        // Depot: line containing "DEPO" (≤80 chars)
        if (preg_match('/\bDEPO\b/ui', $t) && mb_strlen($t) <= 80) {
            if ($setOnce('exit_depot', $t)) { $matched[$i] = true; continue; }
        }

        // Brand: "URAS MARKA" or "MARKA URAS"
        if (preg_match('/^(.+?)\s+MARKA$/ui', $t, $m)) {
            if ($setOnce('brand', trim($m[1]))) { $matched[$i] = true; continue; }
        }
        if (preg_match('/^MARKA\s+(.+)$/ui', $t, $m)) {
            if ($setOnce('brand', trim($m[1]))) { $matched[$i] = true; continue; }
        }

        // Company block: LLC, LTD, LIMITED, A.Ş., GMBH, CORP
        if (preg_match('/\b(?:LIMITED|COMPANY|LLC|LTD\.?|A\.?[ŞS]\.?|GMBH|CORP(?:ORATION)?|[İI][ŞS]LET|L[İI]M[İI]TED\s*[ŞS][İI]RKET)\b/ui', $t)) {
            if ($out['company_name'] === null) {
                $out['company_name'] = $t;
                $matched[$i]         = true;
                // Following non-empty lines as address (up to 4 lines)
                $addrParts = [];
                for ($j = $i + 1; $j < count($lines) && $j <= $i + 4; $j++) {
                    $nxt = trim($lines[$j]);
                    if ($nxt === '') break;
                    if ($matched[$j]) break;
                    // Address heuristic: contains digits or looks like city/country
                    if (preg_match('/\d/', $nxt) || preg_match('/\b(?:KRA[YI]|OBL|STR|AVE|KAD|MAH|SK\.?|CAD\.?)\b/ui', $nxt)) {
                        $addrParts[] = $nxt;
                        $matched[$j] = true;
                    } else {
                        break;
                    }
                }
                if ($addrParts) $out['company_address'] = implode("\n", $addrParts);
                continue;
            }
        }
    }

    // ── Pass 3: collect unmatched non-empty lines ──
    $unmatched = [];
    foreach ($lines as $i => $line) {
        if (!$matched[$i] && trim($line) !== '') {
            $unmatched[] = trim($line);
        }
    }

    $matchedCount = 0;
    foreach ($out as $v) {
        if ($v !== null) $matchedCount++;
    }

    return [
        'parsed'        => $out,
        'unmatched'     => implode("\n", $unmatched),
        'matched_count' => $matchedCount,
    ];
}

$result = parse_beyan_text($raw);

echo json_encode([
    'ok'            => true,
    'parsed'        => $result['parsed'],
    'unmatched'     => $result['unmatched'],
    'matched_count' => $result['matched_count'],
]);
