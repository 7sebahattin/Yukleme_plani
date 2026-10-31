<?php
// Hızlı Maliyet v2: ham mal, verimlilik ve ihracat maliyeti. Saf, yan etkisiz motor.
// Belge "model": 2 taşır; taşımayan belge v1 motoruyla (quick_cost_calculate) hesaplanır.
// assets/hizli-maliyet-v2.js bu dosyanın BİREBİR aynasıdır — birini değiştirirsen ötekini de değiştir
// (scripts/quick_cost_v2_parity_smoke.js iki motoru aynı senaryolarla karşılaştırır).
declare(strict_types=1);
require_once __DIR__ . '/quick_cost.php';

const QUICK_COST_V2_MODEL = 2;
const QUICK_COST_V2_CUSTOM_MAX = 40;
const QUICK_COST_V2_RATE_MAX = 1000;
const QUICK_COST_V2_MAT_CUSTOM_MAX = 20;
/** v472'den taşınan "(ESKİ)" kalemleri kullanıcının 40 ek kalem hakkından DÜŞMEZ (kayıt açılabilir kalsın). */
const QUICK_COST_V2_LEGACY_CODES = ['custom_eski_ayiklama', 'custom_eski_boylama', 'custom_eski_yukleme', 'custom_eski_soguk_hava', 'custom_eski_fason', 'custom_eski_fabrika'];
const QUICK_COST_V2_PER_PALLET_MAX_CENTS = 100000000; // 1.000.000 TL

function quick_cost_is_v2($document): bool
{
    return is_array($document) && ($document['model'] ?? null) === QUICK_COST_V2_MODEL;
}

/**
 * Varsayılan kalemler. basis: raw_kg | processed_kg | export_kg (bağlı miktar), manual, fixed.
 * quick: Hızlı Hesapta tutarı sıfır olsa da görünen satır. detail: kalemin kendi ayrıntı paneli var.
 * v473: ayıklama + boylama + paketleme aynı iş olduğundan yalnız "Paketleme işçiliği" kaldı;
 * yükleme / soğuk hava / fason hizmet kaldırıldı; v473'teki "Fabrika gideri" de kaldırıldı (İşletme gideri var).
 * Paketleme işçiliği varsayılanı ham mal KG tabanıdır (işçi ücreti ham ürün üzerinden verilir).
 */
function quick_cost_v2_catalog(): array
{
    return [
        'raw_purchase' => ['label' => 'Ham mal alış bedeli', 'group' => 'raw', 'basis' => 'raw_kg', 'unit' => 'kg', 'quick' => true],
        'harvest' => ['label' => 'Hasat / toplama', 'group' => 'supply', 'basis' => 'raw_kg', 'unit' => 'kg', 'quick' => false],
        'field_transport' => ['label' => 'Tarladan fabrikaya nakliye', 'group' => 'supply', 'basis' => 'fixed', 'unit' => 'fixed', 'quick' => true],
        'packing_labor' => ['label' => 'Paketleme işçiliği', 'group' => 'packing', 'basis' => 'raw_kg', 'unit' => 'kg', 'quick' => true],
        'material' => ['label' => 'Malzeme', 'group' => 'material', 'basis' => 'export_kg', 'unit' => 'kg', 'quick' => true, 'detail' => true],
        'export_freight' => ['label' => 'İhracat nakliyesi', 'group' => 'export', 'basis' => 'fixed', 'unit' => 'fixed', 'quick' => true],
        'customs' => ['label' => 'Gümrükleme', 'group' => 'export', 'basis' => 'fixed', 'unit' => 'fixed', 'quick' => true],
        'official_analysis' => ['label' => 'Resmi analiz', 'group' => 'export', 'basis' => 'fixed', 'unit' => 'fixed', 'quick' => true],
        'private_analysis' => ['label' => 'Özel analiz', 'group' => 'export', 'basis' => 'fixed', 'unit' => 'fixed', 'quick' => true],
        'dts_taxi' => ['label' => 'DTS taksi', 'group' => 'export', 'basis' => 'fixed', 'unit' => 'fixed', 'quick' => false],
        'overhead' => ['label' => 'İşletme gideri', 'group' => 'other', 'basis' => 'export_kg', 'unit' => 'kg', 'quick' => true],
        'casus' => ['label' => 'CASUS', 'group' => 'export', 'basis' => 'fixed', 'unit' => 'fixed', 'quick' => false],
    ];
}

function quick_cost_v2_groups(): array
{
    return ['raw' => 'Ham mal alışı', 'supply' => 'Hasat ve ön nakliye', 'packing' => 'Paketleme işçiliği',
        'material' => 'Malzeme',
        'export' => 'Nakliye ve ihracat giderleri', 'other' => 'Genel ve diğer giderler'];
}

/** Malzeme detay paneli: palet başına kullanılan malzeme satırları (sabit sıra). */
function quick_cost_v2_material_lines(): array
{
    return ['mat_pallet' => 'PALET', 'mat_hat' => 'ŞAPKA', 'mat_corner' => 'KÖŞEBENT', 'mat_crate' => 'KASA',
        'mat_divider' => 'VİYOL', 'mat_edge' => 'KENAR KARTONU', 'mat_base' => 'TABAN KAĞIDI',
        'mat_strap' => 'ŞERİT', 'mat_net' => 'FİLE'];
}

/** Birimler. second: ikinci çarpan (gün) adı; qty × qty2 dört ondalığa yarım yukarı yuvarlanır. */
function quick_cost_v2_units(): array
{
    return [
        'kg' => ['label' => 'Kg', 'price' => 'TL/kg'],
        'ton' => ['label' => 'Ton', 'price' => 'TL/ton'],
        'box' => ['label' => 'Koli / kasa', 'price' => 'TL/koli'],
        'pallet' => ['label' => 'Palet', 'price' => 'TL/palet'],
        'vehicle' => ['label' => 'Araç', 'price' => 'TL/araç'],
        'piece' => ['label' => 'Adet', 'price' => 'TL/adet'],
        'day' => ['label' => 'Çalışılan gün', 'price' => 'TL/yevmiye'],
        'worker_day' => ['label' => 'İşçi × gün', 'price' => 'TL/yevmiye', 'second' => 'Gün'],
        'hour' => ['label' => 'Çalışma saati', 'price' => 'TL/saat'],
        'pallet_day' => ['label' => 'Palet × gün', 'price' => 'TL/palet-gün', 'second' => 'Gün'],
        'ton_day' => ['label' => 'Ton × gün', 'price' => 'TL/ton-gün', 'second' => 'Gün'],
        'fixed' => ['label' => 'Sabit tutar', 'price' => 'TL'],
    ];
}

function quick_cost_v2_bases(): array
{
    return ['raw_kg' => 'Satın alınan ham KG', 'processed_kg' => 'İşlenen ham KG', 'export_kg' => 'İhracat net KG',
        'manual' => 'Manuel miktar', 'fixed' => 'Sabit toplam TL'];
}

function quick_cost_v2_currencies(): array
{
    return ['TL' => 'TL', 'USD' => 'USD', 'EUR' => 'EUR', 'RUB' => 'Ruble (RUB)', 'GBP' => 'Sterlin (GBP)'];
}

function quick_cost_v2_defaults(?string $date = null): array
{
    $items = [];
    foreach (quick_cost_v2_catalog() as $code => $definition) {
        $items[] = ['code' => $code, 'basis' => $definition['basis'], 'unit' => $definition['unit'],
            'qty' => 0, 'qty2' => 0, 'unit_price' => 0];
    }
    return ['model' => QUICK_COST_V2_MODEL, 'product' => '', 'sheet_date' => $date ?? date('Y-m-d'),
        'operation_type' => 'fason', 'method' => 'quick', 'notes' => '',
        'yield_mode' => 'actual', 'target_yield' => 0,
        'quantities' => ['raw_kg' => 0, 'processed_kg' => null, 'export_kg' => 0,
            'domestic_kg' => 0, 'industry_kg' => 0, 'waste_kg' => 0],
        'material_detail' => quick_cost_v2_material_defaults(),
        'credit' => ['apply' => false, 'domestic_sold_kg' => 0, 'domestic_price' => 0,
            'industry_sold_kg' => 0, 'industry_price' => 0],
        'sale' => ['price' => 0, 'currency' => 'TL', 'rate' => 1, 'target_margin' => null],
        'items' => $items];
}

/** PHP/JS aynı sonucu versin diye round() yerine: sıfırdan uzağa yarım yukarı. */
function quick_cost_v2_round(float $value, int $decimals): float
{
    $factor = 10 ** $decimals;
    $sign = $value < 0 ? -1 : 1;
    return $sign * floor(abs($value) * $factor + 0.5) / $factor;
}

function quick_cost_v2_i4(float $value): int
{
    return (int)round($value * 10000);
}

/** round(q × p / 1000) kuruş: bağlı KG miktarı TL/ton fiyatıyla (1 ton = 1.000 kg). */
function quick_cost_v2_ton_cents(float $kg, float $pricePerTon): int
{
    $q = quick_cost_v2_i4($kg); $p = quick_cost_v2_i4($pricePerTon);
    $qi = intdiv($q, 10000); $qf = $q % 10000; $pi = intdiv($p, 10000); $pf = $p % 10000;
    $a = $qi * $pi; $cross = $qi * $pf + $pi * $qf; $f = $qf * $pf;
    $a1 = intdiv($a, 10); $a0 = $a % 10; $c1 = intdiv($cross, 100000); $c0 = $cross % 100000;
    return $a1 + $c1 + intdiv($a0 * 100000000 + $c0 * 10000 + $f + 500000000, 1000000000);
}

/** İki çarpanlı miktar (işçi × gün, palet × gün, ton × gün) dört ondalığa yarım yukarı. */
function quick_cost_v2_product_qty(float $first, float $second, string $label): float
{
    if ($first * $second > QUICK_COST_NUMBER_MAX + 1) {
        throw new InvalidArgumentException($label . ': miktar çarpımı 1.000.000 sınırını aşıyor.');
    }
    $value = intdiv(quick_cost_v2_i4($first) * quick_cost_v2_i4($second) + 5000, 10000);
    if ($value > QUICK_COST_NUMBER_MAX * 10000) {
        throw new InvalidArgumentException($label . ': miktar çarpımı 1.000.000 sınırını aşıyor.');
    }
    return $value / 10000;
}

/** Sıfırdan büyük, en fazla iki ondalıklı ve 100'den küçük/eşit yüzde. */
function quick_cost_v2_percent($value, string $label, bool $allowHundred): float
{
    $number = quick_cost_number($value, $label);
    if (quick_cost_v2_i4($number) % 100 !== 0) throw new InvalidArgumentException($label . ': en fazla iki ondalık girin.');
    if ($number > 100 || (!$allowHundred && $number >= 100)) {
        throw new InvalidArgumentException($label . ($allowHundred ? ': %100\'den büyük olamaz.' : ': %100\'den küçük olmalı.'));
    }
    return $number;
}

function quick_cost_v2_bool($value, string $label): bool
{
    if ($value === null) return false;
    if (!is_bool($value)) throw new InvalidArgumentException($label . ': evet/hayır bekleniyor.');
    return $value;
}

function quick_cost_v2_kg_text(int $i4): string
{
    return rtrim(rtrim(number_format($i4 / 10000, 4, ',', '.'), '0'), ',');
}

function quick_cost_v2_material_defaults(): array
{
    $lines = [];
    // Yeni hesapta palet başına adetler hazır gelir (kullanıcı değiştirebilir); fiyatlar boş.
    $qty = ['mat_pallet' => 1, 'mat_hat' => 1, 'mat_corner' => 4, 'mat_crate' => 0, 'mat_divider' => 1,
        'mat_edge' => 2, 'mat_base' => 1, 'mat_strap' => 1, 'mat_net' => 1];
    foreach (quick_cost_v2_material_lines() as $code => $label) $lines[] = ['code' => $code, 'qty' => $qty[$code] ?? 0, 'price' => 0];
    return ['use' => false, 'pallets' => 0, 'lines' => $lines];
}

/**
 * Malzeme detayı: palet başına (adet × birim fiyat) satırları → palet başı maliyet → × satılan palet sayısı.
 * Satır tutarı kuruşa yuvarlanır; toplam = palet sayısı × (kuruşlanmış palet başı maliyet).
 * Eksik temel satırlar sıfırla tamamlanır (eski belge/eski sekme uyumu).
 */
function quick_cost_v2_material_detail($raw): array
{
    $base = quick_cost_v2_material_lines();
    $d = is_array($raw) ? $raw : [];
    $use = quick_cost_v2_bool($d['use'] ?? null, 'Malzeme detayı seçimi');
    $pallets = quick_cost_number($d['pallets'] ?? 0, 'Satılan palet sayısı');
    $rawLines = $d['lines'] ?? [];
    if (!is_array($rawLines) || !array_is_list($rawLines) || count($rawLines) > count($base) + QUICK_COST_V2_MAT_CUSTOM_MAX) {
        throw new InvalidArgumentException('Malzeme detayı satırları geçersiz ya da en fazla yirmi ek satır aşıldı.');
    }
    $by = [];
    foreach ($rawLines as $line) {
        $code = is_array($line) ? ($line['code'] ?? null) : null;
        if (!is_string($code) || (!isset($base[$code]) && !preg_match('/^mat_custom_[a-z0-9_]{1,30}$/D', $code)) || isset($by[$code])) {
            throw new InvalidArgumentException('Malzeme detayında bilinmeyen veya tekrarlanan satır var.');
        }
        $by[$code] = $line;
    }
    $order = array_keys($base);
    foreach ($by as $code => $line) if (!isset($base[$code])) $order[] = $code;
    $lines = []; $perPalletCents = 0;
    foreach ($order as $code) {
        $line = $by[$code] ?? [];
        $label = $base[$code] ?? quick_cost_upper_text($line['label'] ?? '', 'Malzeme satırı adı', 80);
        $qty = quick_cost_number($line['qty'] ?? 0, ($label ?: 'Ek malzeme') . ' palet başına adet');
        $price = quick_cost_number($line['price'] ?? 0, ($label ?: 'Ek malzeme') . ' birim fiyatı');
        if ($label === '') {
            // Yeni eklenmiş, henüz adı ve değeri girilmemiş satır hesabı bozmaz; değer girildiyse ad zorunlu.
            if ($qty == 0 && $price == 0) continue;
            throw new InvalidArgumentException('Malzeme satırının adı gerekli.');
        }
        $cents = quick_cost_amount_cents($qty, $price);
        $perPalletCents += $cents;
        $lines[] = ['code' => $code, 'label' => $label, 'qty' => $qty, 'price' => $price, 'per_pallet' => $cents / 100];
    }
    if ($perPalletCents > QUICK_COST_V2_PER_PALLET_MAX_CENTS) {
        throw new InvalidArgumentException('Palet başına malzeme maliyeti 1.000.000 TL sınırını aşıyor.');
    }
    $totalCents = quick_cost_amount_cents($pallets, $perPalletCents / 100);
    if ($totalCents > 999999999999) throw new InvalidArgumentException('Malzeme detayı toplamı tutar sınırını aşıyor.');
    return ['use' => $use, 'pallets' => $pallets, 'lines' => $lines, 'per_pallet' => $perPalletCents / 100,
        'total' => $totalCents / 100, 'per_pallet_cents' => $perPalletCents, 'total_cents' => $totalCents];
}

function quick_cost_v2_price_is_zero($value): bool
{
    try { return quick_cost_number($value, 'Fiyat') == 0; } catch (InvalidArgumentException $e) { return false; }
}

/**
 * v472 → v473 uyumu: kaldırılan kalemler (ayıklama, boylama, yükleme, soğuk hava, fason hizmet) tutarı varsa
 * "(ESKİ)" ek kalemine dönüşür, böylece kayıtlı toplam DEĞİŞMEZ; sıfırsa düşer. Eski "fason dahil" seçimiyle
 * toplamdan çıkarılmış satırlar yine çıkarılmış kalır (tutarsız bırakılmaz). `fason_includes` alanı artık yok.
 */
function quick_cost_v2_upgrade(array $input): array
{
    $legacy = ['sorting_labor' => ['custom_eski_ayiklama', 'AYIKLAMA İŞÇİLİĞİ (ESKİ)'],
        'grading' => ['custom_eski_boylama', 'BOYLAMA / SINIFLANDIRMA (ESKİ)'],
        'loading' => ['custom_eski_yukleme', 'YÜKLEME GİDERİ (ESKİ)'],
        'cold_storage' => ['custom_eski_soguk_hava', 'ÖN SOĞUTMA / SOĞUK HAVA (ESKİ)'],
        'fason_service' => ['custom_eski_fason', 'FASON İŞLEME HİZMETİ (ESKİ)'],
        'factory_cost' => ['custom_eski_fabrika', 'FABRİKA GİDERİ (ESKİ)']];
    $items = $input['items'] ?? null;
    if (!is_array($items) || !array_is_list($items)) return $input;
    $fi = is_array($input['fason_includes'] ?? null) ? $input['fason_includes'] : [];
    $fason = ($input['operation_type'] ?? '') === 'fason';
    $excluded = [];
    if ($fason && ($fi['labor'] ?? false) === true) array_push($excluded, 'sorting_labor', 'grading', 'packing_labor');
    if ($fason && ($fi['material'] ?? false) === true) $excluded[] = 'material';
    $out = []; $seen = [];
    foreach ($items as $row) {
        $code = is_array($row) ? ($row['code'] ?? null) : null;
        if (is_string($code)) $seen[$code] = true;
        if (is_string($code) && isset($legacy[$code])) {
            if (in_array($code, $excluded, true) || quick_cost_v2_price_is_zero($row['unit_price'] ?? 0)) continue;
            $row['code'] = $legacy[$code][0]; $row['label'] = $legacy[$code][1];
            $out[] = $row; continue;
        }
        if (is_string($code) && in_array($code, $excluded, true)) $row['unit_price'] = 0;
        $out[] = $row;
    }
    $input['items'] = $out;
    unset($input['fason_includes']);
    return $input;
}

/**
 * Tek otorite. İstemcinin amount/total/derived/warnings alanları kullanılmaz.
 * Dönen belge yeniden hesaplanabilir (idempotent).
 */
function quick_cost_v2_calculate(array $input): array
{
    if (!quick_cost_is_v2($input)) throw new InvalidArgumentException('Hesap modeli geçersiz.');
    $input = quick_cost_v2_upgrade($input);
    $product = quick_cost_upper_text($input['product'] ?? '', 'Ürün', 150);
    $notes = quick_cost_upper_text($input['notes'] ?? '', 'Açıklama', 2000);
    $date = $input['sheet_date'] ?? '';
    if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts)
        || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
        throw new InvalidArgumentException('Geçerli bir işlem tarihi girin.');
    }
    $operation = $input['operation_type'] ?? '';
    if (!in_array($operation, ['fason', 'uretim'], true)) throw new InvalidArgumentException('İşlem türü Fason veya Üretim olmalı.');
    $method = $input['method'] ?? '';
    if (!in_array($method, ['quick', 'detailed'], true)) throw new InvalidArgumentException('Görünüm Hızlı veya Detaylı olmalı.');
    $yieldMode = $input['yield_mode'] ?? '';
    if (!in_array($yieldMode, ['actual', 'target'], true)) throw new InvalidArgumentException('Verimlilik yöntemi geçersiz.');

    // --- Miktarlar (tamsayı 1/10.000 kg) ---
    $q = $input['quantities'] ?? null;
    if (!is_array($q)) throw new InvalidArgumentException('Miktar bilgileri eksik.');
    $export = quick_cost_number($q['export_kg'] ?? null, 'İhracatlık net KG');
    $domestic = quick_cost_number($q['domestic_kg'] ?? null, 'Çıkma KG');
    $industry = quick_cost_number($q['industry_kg'] ?? null, 'Suluk KG');
    $waste = quick_cost_number($q['waste_kg'] ?? null, 'Çöp KG');
    $processedRaw = $q['processed_kg'] ?? null;
    $processedInput = ($processedRaw === null || $processedRaw === '') ? null : quick_cost_number($processedRaw, 'İşlenen KG');
    if ($processedInput !== null && $processedInput <= 0) $processedInput = null;
    $export4 = quick_cost_v2_i4($export);
    $targetYield = 0.0;
    if ($yieldMode === 'target') {
        $targetYield = quick_cost_v2_percent($input['target_yield'] ?? null, 'Tahmini verimlilik', true);
        if ($export4 > 0 && $targetYield <= 0) throw new InvalidArgumentException('Hedef hesap için tahmini verimliliği (%) girin.');
        $ty100 = (int)round($targetYield * 100);
        $raw4 = $export4 > 0 ? intdiv(2 * $export4 * 10000 + $ty100, 2 * $ty100) : 0;
        if ($raw4 > QUICK_COST_NUMBER_MAX * 10000) {
            throw new InvalidArgumentException('Gerekli ham mal KG 1.000.000 sınırını aşıyor; verimliliği veya hedefi kontrol edin.');
        }
        $raw = $raw4 / 10000;
    } else {
        $raw = quick_cost_number($q['raw_kg'] ?? null, 'Ham mal KG');
        $raw4 = quick_cost_v2_i4($raw);
        if ($input['target_yield'] ?? null) {
            // Diğer yöntemin taslağı korunur ama doğrulanır.
            $targetYield = quick_cost_v2_percent($input['target_yield'], 'Tahmini verimlilik', true);
        }
    }
    $processedAssumed = $processedInput === null;
    $processed4 = $processedAssumed ? $raw4 : quick_cost_v2_i4($processedInput);
    if ($export4 > $raw4) throw new InvalidArgumentException('İhracatlık net KG, ham mal KG\'den büyük olamaz.');
    if ($processed4 > $raw4) throw new InvalidArgumentException('İşlenen KG, satın alınan ham mal KG\'den büyük olamaz.');
    if ($export4 > $processed4) throw new InvalidArgumentException('İhracatlık net KG, işlenen KG\'den büyük olamaz.');
    $classified4 = $export4 + quick_cost_v2_i4($domestic) + quick_cost_v2_i4($industry) + quick_cost_v2_i4($waste);
    if ($classified4 > $raw4) {
        throw new InvalidArgumentException('Ürün dağılımı (ihracat + çıkma + suluk + çöp) ham mal KG\'yi aşıyor.');
    }
    $unclassified4 = $raw4 - $classified4;
    $yield100 = $raw4 > 0 ? intdiv(2 * $export4 * 10000 + $raw4, 2 * $raw4) : 0;
    $linked = ['raw_kg' => $raw4 / 10000, 'processed_kg' => $processed4 / 10000, 'export_kg' => $export];

    // --- Kalemler ---
    $catalog = quick_cost_v2_catalog();
    $units = quick_cost_v2_units();
    $rawItems = $input['items'] ?? null;
    $legacyExtra = 0;
    if (is_array($rawItems)) foreach ($rawItems as $legacyRow) {
        if (is_array($legacyRow) && in_array($legacyRow['code'] ?? null, QUICK_COST_V2_LEGACY_CODES, true)) $legacyExtra++;
    }
    if (!is_array($rawItems) || !array_is_list($rawItems) || count($rawItems) < count($catalog)
        || count($rawItems) > count($catalog) + QUICK_COST_V2_CUSTOM_MAX + $legacyExtra) {
        throw new InvalidArgumentException('Temel maliyet kalemleri bir kez, en fazla kırk ek kalemle gönderilmeli.');
    }
    $byCode = [];
    foreach ($rawItems as $item) {
        $code = is_array($item) ? ($item['code'] ?? null) : null;
        if (!is_string($code) || (!isset($catalog[$code]) && !preg_match('/^custom_[a-z0-9_]{1,40}$/D', $code)) || isset($byCode[$code])) {
            throw new InvalidArgumentException('Eksik, tekrarlanan veya bilinmeyen maliyet kalemi.');
        }
        $byCode[$code] = $item;
    }
    foreach ($catalog as $code => $definition) {
        if (!isset($byCode[$code])) throw new InvalidArgumentException('Temel maliyet kalemlerinin tamamı gerekli.');
    }
    foreach ($byCode as $code => $item) {
        if (isset($catalog[$code])) continue;
        $label = quick_cost_upper_text($item['label'] ?? null, 'Ek kalem adı', 150);
        if ($label === '') throw new InvalidArgumentException('Ek maliyet kaleminin adı gerekli.');
        $catalog[$code] = ['label' => $label, 'group' => 'other', 'quick' => false];
    }
    $mat = quick_cost_v2_material_detail($input['material_detail'] ?? null);
    $groupCents = array_fill_keys(array_keys(quick_cost_v2_groups()), 0);
    $items = [];
    $totalCents = 0;
    foreach ($catalog as $code => $definition) {
        $item = $byCode[$code];
        $label = $definition['label'];
        $basis = $item['basis'] ?? '';
        $unit = $item['unit'] ?? '';
        if (!is_string($basis) || !isset(quick_cost_v2_bases()[$basis]) || !is_string($unit) || !isset($units[$unit])
            || (isset($linked[$basis]) && !in_array($unit, ['kg', 'ton'], true))
            || (($basis === 'fixed') !== ($unit === 'fixed'))) {
            throw new InvalidArgumentException($label . ': hesaplama tabanı ve birim uyumsuz.');
        }
        $postedQty = quick_cost_number($item['qty'] ?? 0, $label . ' miktarı');
        $postedQty2 = quick_cost_number($item['qty2'] ?? 0, $label . ' gün sayısı');
        $price = quick_cost_number($item['unit_price'] ?? 0, $label . ' fiyatı');
        $qty2 = 0.0;
        if (isset($linked[$basis])) {
            $qty = $linked[$basis];
            $cents = $unit === 'ton' ? quick_cost_v2_ton_cents($qty, $price) : quick_cost_amount_cents($qty, $price);
        } elseif ($basis === 'fixed') {
            $qty = 1.0;
            $cents = quick_cost_amount_cents(1.0, $price);
        } else {
            $qty = $postedQty;
            $effective = $qty;
            if (isset($units[$unit]['second'])) {
                $qty2 = $postedQty2;
                $effective = quick_cost_v2_product_qty($qty, $qty2, $label);
            }
            $cents = quick_cost_amount_cents($effective, $price);
        }
        $fromDetail = $code === 'material' && $mat['use'];
        if ($fromDetail) $cents = $mat['total_cents']; // satır girdileri korunur ama tutarı palet detayı belirler
        if ($cents > 999999999999) throw new InvalidArgumentException($label . ': tutar sınırı aşıldı.');
        $totalCents += $cents;
        $groupCents[$definition['group']] += $cents;
        $items[] = ['code' => $code, 'label' => $label, 'group' => $definition['group'], 'basis' => $basis, 'unit' => $unit,
            'qty' => $qty, 'qty2' => $qty2, 'unit_price' => $price, 'amount' => $cents / 100, 'from_detail' => $fromDetail];
    }
    if ($totalCents > (int)round(QUICK_COST_TOTAL_MAX * 100)) throw new InvalidArgumentException('Toplam maliyet sınırı aşıldı.');

    // --- Yan ürün geliri (yalnız açıkça seçilirse düşülür) ---
    $c = is_array($input['credit'] ?? null) ? $input['credit'] : [];
    $applyCredit = quick_cost_v2_bool($c['apply'] ?? null, 'Yan ürün kredisi');
    $domSold = quick_cost_number($c['domestic_sold_kg'] ?? 0, 'Çıkma satılan KG');
    $domPrice = quick_cost_number($c['domestic_price'] ?? 0, 'Çıkma net satış fiyatı');
    $indSold = quick_cost_number($c['industry_sold_kg'] ?? 0, 'Suluk satılan KG');
    $indPrice = quick_cost_number($c['industry_price'] ?? 0, 'Suluk net satış fiyatı');
    if (quick_cost_v2_i4($domSold) > quick_cost_v2_i4($domestic)) throw new InvalidArgumentException('Çıkma satılan KG, Çıkma KG\'yi aşamaz.');
    if (quick_cost_v2_i4($indSold) > quick_cost_v2_i4($industry)) throw new InvalidArgumentException('Suluk satılan KG, Suluk KG\'yi aşamaz.');
    $creditCents = quick_cost_amount_cents($domSold, $domPrice) + quick_cost_amount_cents($indSold, $indPrice);
    $appliedCents = $applyCredit ? $creditCents : 0;
    if ($appliedCents > $totalCents) throw new InvalidArgumentException('Yan ürün geliri toplam operasyon maliyetini aşamaz.');
    $exportCostCents = $totalCents - $appliedCents;

    // --- Satış karşılaştırması (isteğe bağlı; fiyat > 0 ise hesaplanır) ---
    $s = is_array($input['sale'] ?? null) ? $input['sale'] : [];
    $currency = $s['currency'] ?? 'TL';
    if (!is_string($currency) || !isset(quick_cost_v2_currencies()[$currency])) throw new InvalidArgumentException('Para birimi TL, USD, EUR, RUB veya GBP olmalı.');
    $salePrice = quick_cost_number($s['price'] ?? 0, 'Satış fiyatı');
    $rate = $currency === 'TL' ? 1.0 : quick_cost_number($s['rate'] ?? 0, 'Döviz kuru');
    $marginRaw = $s['target_margin'] ?? null;
    $margin = ($marginRaw === null || $marginRaw === '') ? null : quick_cost_v2_percent($marginRaw, 'Hedef kâr marjı', false);
    $saleResult = null;
    if ($salePrice > 0) {
        if ($rate <= 0 || $rate > QUICK_COST_V2_RATE_MAX) throw new InvalidArgumentException('Döviz kuru 0\'dan büyük ve en fazla 1.000 olmalı.');
        if ($export4 > 0) {
            $fxCents = quick_cost_amount_cents($export, $salePrice);
            if ($fxCents > (int)round(QUICK_COST_TOTAL_MAX * 100)) throw new InvalidArgumentException('Satış tutarı sınırı aşıldı.');
            $tlCents = $currency === 'TL' ? $fxCents : quick_cost_amount_cents($fxCents / 100, $rate);
            if ($tlCents > (int)round(QUICK_COST_TOTAL_MAX * 100)) throw new InvalidArgumentException('Satış tutarı sınırı aşıldı.');
            $profitCents = $tlCents - $exportCostCents;
            $breakEven = quick_cost_unit_cost($exportCostCents, $export);
            $targetPrice = $margin === null ? null : quick_cost_v2_round($breakEven / (1 - $margin / 100), 4);
            $saleResult = [
                'revenue_fx' => $fxCents / 100, 'revenue_tl' => $tlCents / 100, 'profit' => $profitCents / 100,
                'profit_per_kg' => ($profitCents < 0 ? -1 : 1) * quick_cost_unit_cost(abs($profitCents), $export),
                'margin_pct' => $tlCents > 0 ? quick_cost_v2_round($profitCents / $tlCents * 100, 2) : null,
                'break_even_tl' => $breakEven, 'break_even_fx' => quick_cost_v2_round($breakEven / $rate, 4),
                'target_price_tl' => $targetPrice,
                'target_price_fx' => $targetPrice === null ? null : quick_cost_v2_round($targetPrice / $rate, 4),
            ];
        }
    }

    // --- Uyarılar (engel değil) ---
    $warnings = [];
    if ($processedAssumed && $raw4 > 0) $warnings[] = ['code' => 'processed_assumed', 'text' => 'İşlenen KG girilmedi; ham mal KG ile aynı kabul edildi (varsayım).'];
    if ($unclassified4 > 0 && $export4 > 0) $warnings[] = ['code' => 'unclassified', 'text' => quick_cost_v2_kg_text($unclassified4) . ' KG ham ürün henüz sınıflandırılmamış (Çıkma / Suluk / Çöp ayrımı Detaylı Hesapta).'];
    if ($mat['use'] && $mat['total_cents'] === 0) $warnings[] = ['code' => 'material_detail_empty', 'text' => 'Malzeme detayından hesaplama açık ama tutar 0: satılan palet sayısını ve malzeme satırlarını girin.'];
    if ($applyCredit && $creditCents > 0) $warnings[] = ['code' => 'credit_applied', 'text' => 'Yan ürün net geliri maliyetten düşüldü: yönetimsel tahmin, resmi muhasebe maliyeti değildir.'];
    if (!$applyCredit && $creditCents > 0) $warnings[] = ['code' => 'credit_not_applied', 'text' => 'Yan ürün geliri girildi fakat maliyetten düşülmedi (ihtiyatlı yöntem).'];

    $quantities = ['raw_kg' => $raw4 / 10000, 'processed_kg' => $processedInput, 'export_kg' => $export,
        'domestic_kg' => $domestic, 'industry_kg' => $industry, 'waste_kg' => $waste];
    return [
        'model' => QUICK_COST_V2_MODEL, 'product' => $product, 'sheet_date' => $date, 'operation_type' => $operation,
        'method' => $method, 'notes' => $notes, 'yield_mode' => $yieldMode, 'target_yield' => $targetYield,
        'quantities' => $quantities,
        'material_detail' => ['use' => $mat['use'], 'pallets' => $mat['pallets'], 'lines' => $mat['lines'],
            'per_pallet' => $mat['per_pallet'], 'total' => $mat['total']],
        'credit' => ['apply' => $applyCredit, 'domestic_sold_kg' => $domSold, 'domestic_price' => $domPrice,
            'industry_sold_kg' => $indSold, 'industry_price' => $indPrice],
        'sale' => ['price' => $salePrice, 'currency' => $currency, 'rate' => $rate, 'target_margin' => $margin],
        'items' => $items,
        'derived' => ['raw_kg' => $raw4 / 10000, 'processed_kg' => $processed4 / 10000, 'processed_assumed' => $processedAssumed,
            'unclassified_kg' => $unclassified4 / 10000, 'yield_pct' => $yield100 / 100],
        'groups' => array_map(static fn($cents) => $cents / 100, $groupCents),
        'total' => $totalCents / 100,
        'credit_total' => $creditCents / 100, 'credit_applied' => $appliedCents / 100,
        'export_cost' => $exportCostCents / 100,
        'unit_cost' => quick_cost_unit_cost($exportCostCents, $export),
        'unit_cost_prudent' => quick_cost_unit_cost($totalCents, $export),
        'net_kg' => $export,
        'sale_result' => $saleResult,
        'warnings' => $warnings,
    ];
}

/** 422/409 sonrası taslak kurtarma: yalnız skaler değerler forma geri yazılır, diziler metin olmaz. */
function quick_cost_v2_form_input(array $input): array
{
    $safe = quick_cost_v2_defaults();
    $scalar = static fn($v) => is_string($v) || is_int($v) || is_float($v);
    foreach (['product', 'sheet_date', 'operation_type', 'method', 'notes', 'yield_mode', 'target_yield'] as $key) {
        if (isset($input[$key]) && $scalar($input[$key])) $safe[$key] = $input[$key];
    }
    foreach (['quantities', 'credit', 'sale'] as $section) {
        if (!is_array($input[$section] ?? null)) continue;
        foreach ($safe[$section] as $key => $default) {
            $value = $input[$section][$key] ?? null;
            if (is_bool($default) || $key === 'apply') { if (is_bool($value)) $safe[$section][$key] = $value; }
            elseif ($value !== null && $scalar($value)) $safe[$section][$key] = $value;
        }
    }
    $md = is_array($input['material_detail'] ?? null) ? $input['material_detail'] : [];
    if (is_bool($md['use'] ?? null)) $safe['material_detail']['use'] = $md['use'];
    if (isset($md['pallets']) && $scalar($md['pallets'])) $safe['material_detail']['pallets'] = $md['pallets'];
    $mrows = is_array($md['lines'] ?? null) ? $md['lines'] : [];
    foreach ($safe['material_detail']['lines'] as &$mline) {
        foreach ($mrows as $mrow) {
            if (!is_array($mrow) || ($mrow['code'] ?? null) !== $mline['code']) continue;
            foreach (['qty', 'price'] as $key) if (isset($mrow[$key]) && $scalar($mrow[$key])) $mline[$key] = $mrow[$key];
            break;
        }
    }
    unset($mline);
    $mseen = array_fill_keys(array_column($safe['material_detail']['lines'], 'code'), true);
    $mcustom = 0;
    foreach ($mrows as $mrow) {
        $mcode = is_array($mrow) ? ($mrow['code'] ?? null) : null;
        if (!is_string($mcode) || !preg_match('/^mat_custom_[a-z0-9_]{1,30}$/D', $mcode) || isset($mseen[$mcode]) || $mcustom >= QUICK_COST_V2_MAT_CUSTOM_MAX) continue;
        $extra = ['code' => $mcode, 'label' => '', 'qty' => 0, 'price' => 0];
        foreach (['label', 'qty', 'price'] as $key) if (isset($mrow[$key]) && $scalar($mrow[$key])) $extra[$key] = $mrow[$key];
        $safe['material_detail']['lines'][] = $extra;
        $mseen[$mcode] = true; $mcustom++;
    }
    $rows = is_array($input['items'] ?? null) ? $input['items'] : [];
    foreach ($safe['items'] as &$item) {
        foreach ($rows as $row) {
            if (!is_array($row) || ($row['code'] ?? null) !== $item['code']) continue;
            foreach (['basis', 'unit', 'qty', 'qty2', 'unit_price'] as $key) {
                if (isset($row[$key]) && $scalar($row[$key])) $item[$key] = $row[$key];
            }
            break;
        }
    }
    unset($item);
    $seen = array_fill_keys(array_column($safe['items'], 'code'), true);
    $custom = 0;
    foreach ($rows as $row) {
        $code = is_array($row) ? ($row['code'] ?? null) : null;
        if (!is_string($code) || !preg_match('/^custom_[a-z0-9_]{1,40}$/D', $code) || isset($seen[$code]) || ($custom >= QUICK_COST_V2_CUSTOM_MAX && !in_array($code, QUICK_COST_V2_LEGACY_CODES, true))) continue;
        $extra = ['code' => $code, 'label' => '', 'basis' => 'fixed', 'unit' => 'fixed', 'qty' => 0, 'qty2' => 0, 'unit_price' => 0];
        foreach (['label', 'basis', 'unit', 'qty', 'qty2', 'unit_price'] as $key) {
            if (isset($row[$key]) && $scalar($row[$key])) $extra[$key] = $row[$key];
        }
        $safe['items'][] = $extra;
        $seen[$code] = true;
        if (!in_array($code, QUICK_COST_V2_LEGACY_CODES, true)) $custom++;
    }
    return $safe;
}

/** Ayar seçicisindeki "taban|birim" seçenekleri; JS aynı listeyi config'ten alır. */
function quick_cost_v2_basis_options(): array
{
    $options = [
        'raw_kg|kg' => 'Satın alınan ham KG × TL/kg', 'raw_kg|ton' => 'Satın alınan ham KG ÷ 1.000 × TL/ton',
        'processed_kg|kg' => 'İşlenen ham KG × TL/kg', 'processed_kg|ton' => 'İşlenen ham KG ÷ 1.000 × TL/ton',
        'export_kg|kg' => 'İhracat net KG × TL/kg', 'export_kg|ton' => 'İhracat net KG ÷ 1.000 × TL/ton',
    ];
    foreach (quick_cost_v2_units() as $unit => $meta) {
        if ($unit === 'fixed') continue;
        $options['manual|' . $unit] = 'Manuel: ' . $meta['label'] . ' × ' . $meta['price'];
    }
    $options['fixed|fixed'] = 'Sabit toplam tutar (TL)';
    return $options;
}
