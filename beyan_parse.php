<?php
// =========================================================
// beyan_parse.php — WhatsApp beyan metni ayrıştırıcı (Sprint Beyan-02 bugfix)
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

// ── Normalize a single line: NBSP/ZWS → space, collapse whitespace ──
function normalize_line(string $s): string
{
    $s = str_replace(["\xc2\xa0", "\xe2\x80\x8b", "\xef\xbb\xbf"], ' ', $s);
    $s = (string)preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

// ── Turkish number parser ── mirrors num() but returns null for empty/invalid ──
function parse_beyan_number(string $s): ?float
{
    $s = trim($s);
    if ($s === '') return null;
    // Strip trailing unit suffixes (KG, ADET, AD, PCS, TN, TON)
    $s = (string)preg_replace('/\s*(KG|ADET|AD\.?|PCS|TN|TON)\.?\s*$/ui', '', $s);
    $s = trim(str_replace([' ', "\xc2\xa0"], '', $s));
    if ($s === '') return null;
    if (str_contains($s, ',')) {
        $s = str_replace('.', '', $s);  // remove thousands dots
        $s = str_replace(',', '.', $s); // decimal comma → dot
    } else {
        $s = str_replace('.', '', $s);  // dot = always thousands sep in Turkish
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

    $rawLines = preg_split('/\r?\n/', trim($raw));
    // Normalize each line (collapse spaces, NBSP); keep raw for nothing — normalized is the value too
    $lines   = array_map('normalize_line', $rawLines);
    $matched = array_fill(0, count($lines), false);

    // Set field only once; returns true when value was actually stored
    $setOnce = static function (string $field, $val) use (&$out): bool {
        if ($out[$field] !== null || $val === null || $val === '') return false;
        $out[$field] = $val;
        return true;
    };

    // ── Pass 1: labeled patterns (KEY: VALUE or KEY - VALUE) ──
    $labeled = [
        'declaration_title' => '/^(?:BEYAN\s*T[İI]P[İI]|BA[ŞS]LIK|TITLE)\s*[:\-]\s*(.+)/ui',
        // "PARTİ: 46/22", "PARTİ NO: 46/22", "PARTİ: 46-17" (slash veya tire ayraç)
        'party_no'          => '/^PART[İI](?:\s*NO)?\s*[:\-]\s*(\d[\d\s\/\-]*\d)/ui',
        'transport_type'    => '/^(?:NAKL[İI]YE\s*T[ÜU]R[ÜU]|TRANSPORT\s*(?:TYPE)?)\s*[:\-]\s*(.+)/ui',
        'line_type'         => '/^(?:HAT|LINE|G[ÜU]ZERGAH)\s*[:\-]\s*(.+)/ui',
        'product_name'      => '/^(?:[ÜU]R[ÜU]N(?:\s*ADI)?|MAHSUL)\s*[:\-]\s*(.+)/ui',
        'product_variety'   => '/^(?:[CÇ]E[ŞS][İI]T(?:\s*ADI)?|VARYETE|VAR\.)\s*[:\-]\s*(.+)/ui',
        'pallet_count'      => '/^(?:PALET(?:\s*ADET[İI])?|PALLET)\s*[:\-]\s*(.+)/ui',
        'gross_kg'          => '/^(?:BR[ÜU]T(?:\s*KG)?|GROSS(?:\s*KG)?|TOPLAM\s*KG)\s*[:\-]\s*(.+)/ui',
        'net_kg'            => '/^NET(?:\s*(?:KG|A[ĞG]IRLI[ĞG]I|KG\s*A[ĞG]IRLI[ĞG]I))?\s*[:\-]\s*(.+)/ui',
        'crate_count'       => '/^(?:KASA(?:\s*ADET[İI])?|SANDIK|KUTU)\s*[:\-]\s*(.+)/ui',
        'crate_type'        => '/^(?:KASA\s*C[İI]NS[İI]|KASA\s*T[İI]P[İI])\s*[:\-]\s*(.+)/ui',
        // "ÇIKIŞ: KARAMAN DEPO", "ÇIKIŞ DEPO: ...", "DEPO: ..." — [Iı] dotsuz ı'yı da kapsar
        'exit_depot'        => '/^(?:[CÇ][Iı]K[Iı][ŞS](?:\s*DEPO)?|DEPO(?:\s*ADI)?)\s*[:\-]\s*(.+)/ui',
        'buyer_name'        => '/^(?:AL[Iı]C[Iı]|BUYER|M[ÜU][ŞS]TER[İI])\s*[:\-]\s*(.+)/ui',
        'contact_person'    => '/^(?:[İI]LG[İI]L[İI](?:\s*K[İI][ŞS][İI])?|CONTACT|YETKL[İI])\s*[:\-]\s*(.+)/ui',
        // MARKA / BRAND / AMBALAJ → marka (bu beyanlarda AMBALAJ paketleme markasını taşır: ASYA/URAL…)
        'brand'             => '/^(?:MARKA|BRAND|AMBALAJ)\s*[:\-]\s*(.+)/ui',
    ];

    $numFields = ['pallet_count', 'gross_kg', 'net_kg', 'crate_count'];

    foreach ($lines as $i => $t) {
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
            } elseif ($field === 'party_no') {
                // Collapse spaces: "46 / 22" → "46/22"
                $val = (string)preg_replace('/\s+/u', '', $val);
                if ($setOnce($field, $val)) $matched[$i] = true;
            } else {
                if ($setOnce($field, $val)) $matched[$i] = true;
            }
            break;
        }
    }

    // ── Pass 2: PLASTİK KASA special — typo-tolerant, optional embedded count ──
    // Must run before generic unlabeled pass so crate_type is always normalized.
    // Handles: PLASİTK, PLASTIK, PLASTİK, PLASITK, PLAS\w+ variants with optional ": 2.662"
    foreach ($lines as $i => $t) {
        if ($matched[$i] || $t === '') continue;

        // PLAS* KASA (any typo) with optional separator and count
        // \S* (\w yerine) — Türkçe İ harfini de geçer: PLASTİK, PLASİTK, PLASTIK…
        if (preg_match('/^PLAS\S*\s+KASA\s*[:\-]?\s*([\d.,]*)$/ui', $t, $m)) {
            $setOnce('crate_type', 'PLASTİK KASA');
            $matched[$i] = true;
            if ($m[1] !== '') {
                $n = parse_beyan_number($m[1]);
                if ($n !== null) $setOnce('crate_count', (int)$n);
            }
            continue;
        }
        // Other material types: AHŞAP, KARTON, OLUKLU, METAL with optional count
        if (preg_match('/^(AH[ŞS]AP|KARTON|OLUKLU|KA[ĞG]IT|TAHTA|WOOD|METAL)\s+(?:KASA|SANDIK|KUTU)\s*[:\-]?\s*([\d.,]*)$/ui', $t, $m)) {
            $typeWord = mb_strtoupper(trim($m[1]), 'UTF-8');
            $setOnce('crate_type', $typeWord . ' KASA');
            $matched[$i] = true;
            if (!empty($m[2])) {
                $n = parse_beyan_number($m[2]);
                if ($n !== null) $setOnce('crate_count', (int)$n);
            }
            continue;
        }
    }

    // ── Pass 3: unlabeled / positional patterns ──
    foreach ($lines as $i => $t) {
        if ($matched[$i] || $t === '') {
            $matched[$i] = true;
            continue;
        }

        // "YENİ BEYAN" title — always mark as matched even if title already set
        if (preg_match('/\bBEYAN\b/ui', $t) && mb_strlen($t) <= 60) {
            $setOnce('declaration_title', $t);
            $matched[$i] = true;
            continue;
        }

        // Transport type, optionally combined with line type on same line:
        //   "DENİZYOLU ( YEŞİL HAT)"  "KARAYOLU"  "DENIZYOLU YESIL HAT"
        if (preg_match('/\b(DEN[İI]ZYOLU|KARAYOLU|HAVAYOLU|T[İI]R|U[ÇC]AK)\b(.*)/ui', $t, $m)) {
            $setOnce('transport_type', mb_strtoupper(trim($m[1]), 'UTF-8'));
            $matched[$i] = true;
            // Extract HAT component from the rest, stripping parentheses
            $rest = trim($m[2]);
            $rest = trim((string)preg_replace('/^\s*\(\s*|\s*\)\s*$/', '', $rest));
            if ($rest !== '' && preg_match('/\bHAT\b/ui', $rest)) {
                $hatNorm = (string)preg_replace('/\s+/u', ' ', mb_strtoupper($rest, 'UTF-8'));
                $setOnce('line_type', $hatNorm);
            }
            continue;
        }

        // Line type standalone: "YEŞİL HAT", "KIRMIZI HAT", etc.
        if (preg_match('/^(YE[ŞS][İI]L|KIRMIZI|TURUNCU|LAC[İI]VERT|MAV[İI]|SARI)\s+HAT$/ui', $t)) {
            if ($setOnce('line_type', mb_strtoupper($t, 'UTF-8'))) {
                $matched[$i] = true;
                continue;
            }
        }

        // Parti no: "46/22" / "46-17" alone, "46-17 parti", "46 / 22 PARTİ"
        if (preg_match('/^(\d{1,4}\s*[\/\-]\s*\d{1,4})\s*(?:PART[İI])?$/ui', $t, $m)) {
            $pno = (string)preg_replace('/\s+/', '', $m[1]);
            if ($setOnce('party_no', $pno)) {
                $matched[$i] = true;
                continue;
            }
        }
        // "PARTİ 46/22" / "PARTİ 46-17" (prefix without colon — colon form caught in Pass 1)
        if (preg_match('/^PART[İI]\s+(\d{1,4}\s*[\/\-]\s*\d{1,4})$/ui', $t, $m)) {
            $pno = (string)preg_replace('/\s+/', '', $m[1]);
            if ($setOnce('party_no', $pno)) {
                $matched[$i] = true;
                continue;
            }
        }

        // "26 PALET KAYISI MİKADO" — pallet count, then optional product name + variety
        if (preg_match('/^(\d[\d.,]*)\s+PALET\s*(.*)$/ui', $t, $m)) {
            $n = parse_beyan_number($m[1]);
            if ($n !== null) {
                $setOnce('pallet_count', (int)$n);
                $matched[$i] = true;
                $rest = trim($m[2]);
                // Only parse product info if rest is not an ADET/NO suffix
                if ($rest !== '' && !preg_match('/^ADET[İI]?\b/ui', $rest)) {
                    $parts = (array)preg_split('/\s+/u', $rest, 2);
                    $setOnce('product_name', $parts[0]);
                    if (!empty($parts[1])) $setOnce('product_variety', $parts[1]);
                }
                continue;
            }
        }

        // "24.200 BRÜT" / "BRÜT 24.200" / "24.200 KG BRÜT"
        if (preg_match('/^(\S+)\s+(?:KG\s+)?BR[ÜU]T(?:\s+KG)?$/ui', $t, $m)
            || preg_match('/^BR[ÜU]T\s+(?:KG\s+)?(\S+)$/ui', $t, $m)) {
            $n = parse_beyan_number($m[1]);
            if ($n !== null && $setOnce('gross_kg', $n)) {
                $matched[$i] = true;
                continue;
            }
        }

        // "22.400 NET" / "NET 22.400"
        if (preg_match('/^(\S+)\s+(?:KG\s+)?NET(?:\s+KG)?$/ui', $t, $m)
            || preg_match('/^NET\s+(?:KG\s+)?(\S+)$/ui', $t, $m)) {
            $n = parse_beyan_number($m[1]);
            if ($n !== null && $setOnce('net_kg', $n)) {
                $matched[$i] = true;
                continue;
            }
        }

        // "2.662 KASA" / "KASA 2.662"
        if (preg_match('/^(\S+)\s+KASA(?:\s*ADET[İI])?$/ui', $t, $m)
            || preg_match('/^KASA\s+(\S+)$/ui', $t, $m)) {
            $n = parse_beyan_number($m[1]);
            if ($n !== null && $setOnce('crate_count', (int)$n)) {
                $matched[$i] = true;
                continue;
            }
        }

        // Depot: any line containing "DEPO" (≤80 chars)
        if (preg_match('/\bDEPO\b/ui', $t) && mb_strlen($t) <= 80) {
            if ($setOnce('exit_depot', $t)) {
                $matched[$i] = true;
                continue;
            }
        }

        // Brand: "URAS MARKA" or "MARKA URAS"
        if (preg_match('/^(.+?)\s+MARKA$/ui', $t, $m)) {
            if ($setOnce('brand', trim($m[1]))) {
                $matched[$i] = true;
                continue;
            }
        }
        if (preg_match('/^MARKA\s+(.+)$/ui', $t, $m)) {
            if ($setOnce('brand', trim($m[1]))) {
                $matched[$i] = true;
                continue;
            }
        }

        // Company block: LIMITED, LLC, LTD, A.Ş., GMBH, CORP
        if (preg_match('/\b(?:LIMITED|COMPANY|LLC|LTD\.?|A\.?[ŞS]\.?|GMBH|CORP(?:ORATION)?|L[İI]M[İI]TED\s*[ŞS][İI]RKET)\b/ui', $t)) {
            if ($out['company_name'] === null) {
                $out['company_name'] = $t;
                $matched[$i]         = true;
                $addrParts = [];
                for ($j = $i + 1; $j < count($lines) && $j <= $i + 4; $j++) {
                    $nxt = $lines[$j];
                    if ($nxt === '' || $matched[$j]) break;
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

    // ── Pass 4: collect unmatched non-empty lines ──
    $unmatched = [];
    foreach ($lines as $i => $t) {
        if (!$matched[$i] && $t !== '') {
            $unmatched[] = $t;
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

// ── WhatsApp satır önekini soyar: "[10:41, 17.06.2026] Ad Soyad: METİN" → "METİN" ──
function strip_whatsapp_prefix(string $line): string
{
    return (string)preg_replace(
        '/^\s*\[\d{1,2}:\d{2},\s*\d{1,2}\.\d{1,2}\.\d{2,4}\]\s*[^:\n]*:\s*/u',
        '',
        $line
    );
}

// ── Ham metni N beyan bloğuna böler. Tek beyan → 1 blok. ──
// Öncelik: WhatsApp zaman damgaları (her mesaj = 1 beyan). Yoksa: "YENİ BEYAN" başlık işareti.
function split_beyan_blocks(string $raw): array
{
    $lines = preg_split('/\r?\n/', $raw) ?: [];

    $tsPattern     = '/^\s*\[\d{1,2}:\d{2},\s*\d{1,2}\.\d{1,2}\.\d{2,4}\]/u';
    $markerPattern = '/\bYEN[İI]\s+BEYAN\b/ui';

    $tsCount = 0;
    foreach ($lines as $ln) {
        if (preg_match($tsPattern, $ln)) $tsCount++;
    }
    $useTs = $tsCount >= 1;

    $blocks  = [];
    $current = [];
    $started = false;

    foreach ($lines as $ln) {
        $isBoundary = $useTs
            ? (bool)preg_match($tsPattern, $ln)
            : (bool)preg_match($markerPattern, $ln);

        if ($isBoundary) {
            if ($started && trim(implode("\n", $current)) !== '') {
                $blocks[] = implode("\n", $current);
            }
            $current = [];
            $started = true;
            if ($useTs) $ln = strip_whatsapp_prefix($ln);
        }
        $current[] = $ln;
    }
    if (trim(implode("\n", $current)) !== '') {
        $blocks[] = implode("\n", $current);
    }

    if (empty($blocks)) {
        $blocks = [trim($raw)];
    }

    return array_values(array_filter(array_map('trim', $blocks), fn($b) => $b !== ''));
}

// ── Çoklu beyan ayrıştırma ──
$blocks       = split_beyan_blocks($raw);
$declarations = [];
foreach ($blocks as $blk) {
    $r = parse_beyan_text($blk);
    if ($r['matched_count'] === 0) continue;   // tanınan alan yok → atla
    $declarations[] = [
        'parsed'        => $r['parsed'],
        'unmatched'     => $r['unmatched'],
        'matched_count' => $r['matched_count'],
        'raw_text'      => $blk,
    ];
}

// Hiç anlamlı blok çıkmadıysa: tüm metni tek beyan gibi dön (kullanıcı elle düzeltsin)
if (empty($declarations)) {
    $r = parse_beyan_text($raw);
    $declarations[] = [
        'parsed'        => $r['parsed'],
        'unmatched'     => $r['unmatched'],
        'matched_count' => $r['matched_count'],
        'raw_text'      => trim($raw),
    ];
}

$count = count($declarations);
$resp  = [
    'ok'           => true,
    'count'        => $count,
    'declarations' => $declarations,
];
// Geriye dönük uyumluluk — tekli doldurma yolu (beyan_edit ve tek beyan)
if ($count === 1) {
    $resp['parsed']        = $declarations[0]['parsed'];
    $resp['unmatched']     = $declarations[0]['unmatched'];
    $resp['matched_count'] = $declarations[0]['matched_count'];
}

echo json_encode($resp);
