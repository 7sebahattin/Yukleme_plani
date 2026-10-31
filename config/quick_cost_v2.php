<?php
// Hızlı Maliyet v2: ham mal, randıman ve ihracat maliyeti. Saf, yan etkisiz motor.
// Belge "model": 2 taşır; taşımayan belge v1 motoruyla (quick_cost_calculate) hesaplanır.
// assets/hizli-maliyet-v2.js bu dosyanın BİREBİR aynasıdır — birini değiştirirsen ötekini de değiştir
// (scripts/quick_cost_v2_parity_smoke.js iki motoru aynı senaryolarla karşılaştırır).
declare(strict_types=1);
require_once __DIR__ . '/quick_cost.php';

const QUICK_COST_V2_MODEL = 2;
const QUICK_COST_V2_CUSTOM_MAX = 40;
const QUICK_COST_V2_RATE_MAX = 1000;

function quick_cost_is_v2($document): bool
{
    return is_array($document) && ($document['model'] ?? null) === QUICK_COST_V2_MODEL;
}

/**
 * Varsayılan kalemler. basis: raw_kg | processed_kg | export_kg (bağlı miktar), manual, fixed.
 * quick: Hızlı Hesapta tutarı sıfır olsa da görünen satır. labor/material: fason dahil kapsamı.
 */
function quick_cost_v2_catalog(): array
{
    return [
        'raw_purchase' => ['label' => 'Ham mal alış bedeli', 'group' => 'raw', 'basis' => 'raw_kg', 'unit' => 'kg', 'quick' => true],
        'harvest' => ['label' => 'Hasat / toplama', 'group' => 'supply', 'basis' => 'raw_kg', 'unit' => 'kg', 'quick' => false],
        'field_transport' => ['label' => 'Tarladan fabrikaya nakliye', 'group' => 'supply', 'basis' => 'fixed', 'unit' => 'fixed', 'quick' => true],
        'sorting_labor' => ['label' => 'Ayıklama işçiliği', 'group' => 'sorting', 'basis' => 'processed_kg', 'unit' => 'kg', 'quick' => true, 'scope' => 'labor'],
        'grading' => ['label' => 'Boylama / sınıflandırma', 'group' => 'sorting', 'basis' => 'processed_kg', 'unit' => 'kg', 'quick' => false, 'scope' => 'labor'],
        'packing_labor' => ['label' => 'Paketleme işçiliği', 'group' => 'packing', 'basis' => 'export_kg', 'unit' => 'kg', 'quick' => true, 'scope' => 'labor'],
        'material' => ['label' => 'Malzeme', 'group' => 'material', 'basis' => 'export_kg', 'unit' => 'kg', 'quick' => true, 'scope' => 'material'],
        'fason_service' => ['label' => 'Fason işleme hizmeti', 'group' => 'fason', 'basis' => 'processed_kg', 'unit' => 'kg', 'quick' => true],
        'cold_storage' => ['label' => 'Ön soğutma / soğuk hava', 'group' => 'export', 'basis' => 'fixed', 'unit' => 'fixed', 'quick' => false],
        'loading' => ['label' => 'Yükleme gideri', 'group' => 'export', 'basis' => 'fixed', 'unit' => 'fixed', 'quick' => false],
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
    return ['raw' => 'Ham mal alışı', 'supply' => 'Hasat ve ön nakliye', 'sorting' => 'Ayıklama ve boylama',
        'packing' => 'Paketleme işçiliği', 'material' => 'Malzeme', 'fason' => 'Fason hizmet',
        'export' => 'Nakliye ve ihracat giderleri', 'other' => 'Genel ve diğer giderler'];
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
    return ['TL' => 'TL', 'USD' => 'USD', 'EUR' => 'EUR'];
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
        'fason_includes' => ['labor' => false, 'material' => false],
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

/**
 * Tek otorite. İstemcinin amount/total/derived/warnings alanları kullanılmaz.
 * Dönen belge yeniden hesaplanabilir (idempotent).
 */
function quick_cost_v2_calculate(array $input): array
{
    if (!quick_cost_is_v2($input)) throw new InvalidArgumentException('Hesap modeli geçersiz.');
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
    if (!in_array($yieldMode, ['actual', 'target'], true)) throw new InvalidArgumentException('Randıman yöntemi geçersiz.');

    // --- Miktarlar (tamsayı 1/10.000 kg) ---
    $q = $input['quantities'] ?? null;
    if (!is_array($q)) throw new InvalidArgumentException('Miktar bilgileri eksik.');
    $export = quick_cost_number($q['export_kg'] ?? null, 'İhracatlık net KG');
    $domestic = quick_cost_number($q['domestic_kg'] ?? null, 'İç piyasa KG');
    $industry = quick_cost_number($q['industry_kg'] ?? null, 'Sanayi / meyve suyu KG');
    $waste = quick_cost_number($q['waste_kg'] ?? null, 'Fire KG');
    $processedRaw = $q['processed_kg'] ?? null;
    $processedInput = ($processedRaw === null || $processedRaw === '') ? null : quick_cost_number($processedRaw, 'İşlenen KG');
    if ($processedInput !== null && $processedInput <= 0) $processedInput = null;
    $export4 = quick_cost_v2_i4($export);
    $targetYield = 0.0;
    if ($yieldMode === 'target') {
        $targetYield = quick_cost_v2_percent($input['target_yield'] ?? null, 'Tahmini randıman', true);
        if ($export4 > 0 && $targetYield <= 0) throw new InvalidArgumentException('Hedef hesap için tahmini randımanı (%) girin.');
        $ty100 = (int)round($targetYield * 100);
        $raw4 = $export4 > 0 ? intdiv(2 * $export4 * 10000 + $ty100, 2 * $ty100) : 0;
        if ($raw4 > QUICK_COST_NUMBER_MAX * 10000) {
            throw new InvalidArgumentException('Gerekli ham mal KG 1.000.000 sınırını aşıyor; randımanı veya hedefi kontrol edin.');
        }
        $raw = $raw4 / 10000;
    } else {
        $raw = quick_cost_number($q['raw_kg'] ?? null, 'Ham mal KG');
        $raw4 = quick_cost_v2_i4($raw);
        if ($input['target_yield'] ?? null) {
            // Diğer yöntemin taslağı korunur ama doğrulanır.
            $targetYield = quick_cost_v2_percent($input['target_yield'], 'Tahmini randıman', true);
        }
    }
    $processedAssumed = $processedInput === null;
    $processed4 = $processedAssumed ? $raw4 : quick_cost_v2_i4($processedInput);
    if ($export4 > $raw4) throw new InvalidArgumentException('İhracatlık net KG, ham mal KG\'den büyük olamaz.');
    if ($processed4 > $raw4) throw new InvalidArgumentException('İşlenen KG, satın alınan ham mal KG\'den büyük olamaz.');
    if ($export4 > $processed4) throw new InvalidArgumentException('İhracatlık net KG, işlenen KG\'den büyük olamaz.');
    $classified4 = $export4 + quick_cost_v2_i4($domestic) + quick_cost_v2_i4($industry) + quick_cost_v2_i4($waste);
    if ($classified4 > $raw4) {
        throw new InvalidArgumentException('Ürün dağılımı (ihracat + iç piyasa + sanayi + fire) ham mal KG\'yi aşıyor.');
    }
    $unclassified4 = $raw4 - $classified4;
    $yield100 = $raw4 > 0 ? intdiv(2 * $export4 * 10000 + $raw4, 2 * $raw4) : 0;
    $linked = ['raw_kg' => $raw4 / 10000, 'processed_kg' => $processed4 / 10000, 'export_kg' => $export];

    // --- Kalemler ---
    $catalog = quick_cost_v2_catalog();
    $units = quick_cost_v2_units();
    $rawItems = $input['items'] ?? null;
    if (!is_array($rawItems) || !array_is_list($rawItems) || count($rawItems) < count($catalog)
        || count($rawItems) > count($catalog) + QUICK_COST_V2_CUSTOM_MAX) {
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
    $fasonIncludes = is_array($input['fason_includes'] ?? null) ? $input['fason_includes'] : [];
    $includesLabor = quick_cost_v2_bool($fasonIncludes['labor'] ?? null, 'Fason işçilik kapsamı');
    $includesMaterial = quick_cost_v2_bool($fasonIncludes['material'] ?? null, 'Fason ambalaj kapsamı');
    $isFason = $operation === 'fason';
    $groupCents = array_fill_keys(array_keys(quick_cost_v2_groups()), 0);
    $items = [];
    $totalCents = 0; $excludedCents = 0; $excludedLabels = [];
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
        if ($cents > 999999999999) throw new InvalidArgumentException($label . ': tutar sınırı aşıldı.');
        $scope = $definition['scope'] ?? '';
        $excluded = $isFason && (($scope === 'labor' && $includesLabor) || ($scope === 'material' && $includesMaterial));
        if ($excluded) {
            $excludedCents += $cents;
            if ($cents > 0) $excludedLabels[] = $label;
        } else {
            $totalCents += $cents;
            $groupCents[$definition['group']] += $cents;
        }
        $row = ['code' => $code, 'label' => $label, 'group' => $definition['group'], 'basis' => $basis, 'unit' => $unit,
            'qty' => $qty, 'qty2' => $qty2, 'unit_price' => $price, 'amount' => $cents / 100, 'excluded' => $excluded];
        $items[] = $row;
    }
    if ($totalCents > (int)round(QUICK_COST_TOTAL_MAX * 100)) throw new InvalidArgumentException('Toplam maliyet sınırı aşıldı.');

    // --- Yan ürün geliri (yalnız açıkça seçilirse düşülür) ---
    $c = is_array($input['credit'] ?? null) ? $input['credit'] : [];
    $applyCredit = quick_cost_v2_bool($c['apply'] ?? null, 'Yan ürün kredisi');
    $domSold = quick_cost_number($c['domestic_sold_kg'] ?? 0, 'İç piyasada satılan KG');
    $domPrice = quick_cost_number($c['domestic_price'] ?? 0, 'İç piyasa net satış fiyatı');
    $indSold = quick_cost_number($c['industry_sold_kg'] ?? 0, 'Sanayiye satılan KG');
    $indPrice = quick_cost_number($c['industry_price'] ?? 0, 'Sanayi net satış fiyatı');
    if (quick_cost_v2_i4($domSold) > quick_cost_v2_i4($domestic)) throw new InvalidArgumentException('İç piyasada satılan KG, iç piyasaya ayrılan KG\'yi aşamaz.');
    if (quick_cost_v2_i4($indSold) > quick_cost_v2_i4($industry)) throw new InvalidArgumentException('Sanayiye satılan KG, sanayiye ayrılan KG\'yi aşamaz.');
    $creditCents = quick_cost_amount_cents($domSold, $domPrice) + quick_cost_amount_cents($indSold, $indPrice);
    $appliedCents = $applyCredit ? $creditCents : 0;
    if ($appliedCents > $totalCents) throw new InvalidArgumentException('Yan ürün geliri toplam operasyon maliyetini aşamaz.');
    $exportCostCents = $totalCents - $appliedCents;

    // --- Satış karşılaştırması (isteğe bağlı; fiyat > 0 ise hesaplanır) ---
    $s = is_array($input['sale'] ?? null) ? $input['sale'] : [];
    $currency = $s['currency'] ?? 'TL';
    if (!is_string($currency) || !isset(quick_cost_v2_currencies()[$currency])) throw new InvalidArgumentException('Para birimi TL, USD veya EUR olmalı.');
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
    if ($unclassified4 > 0 && $export4 > 0) $warnings[] = ['code' => 'unclassified', 'text' => quick_cost_v2_kg_text($unclassified4) . ' KG ham ürün henüz sınıflandırılmamış (iç piyasa / sanayi / fire ayrımı Detaylı Hesapta).'];
    if ($excludedLabels) $warnings[] = ['code' => 'fason_excluded', 'text' => 'Fason bedeline dahil seçildiği için toplama katılmadı: ' . implode(', ', $excludedLabels) . ' (' . number_format($excludedCents / 100, 2, ',', '.') . ' TL).'];
    $fasonCents = 0;
    foreach ($items as $row) if ($row['code'] === 'fason_service') $fasonCents = (int)round($row['amount'] * 100);
    if ($isFason && ($includesLabor || $includesMaterial) && $fasonCents === 0) $warnings[] = ['code' => 'fason_service_zero', 'text' => 'İşçilik/ambalaj fason bedeline dahil seçildi fakat fason hizmet bedeli girilmedi.'];
    if (!$isFason && $fasonCents > 0) $warnings[] = ['code' => 'uretim_fason_service', 'text' => 'Üretim seçili; girilen fason hizmet bedeli de toplama katıldı.'];
    if ($applyCredit && $creditCents > 0) $warnings[] = ['code' => 'credit_applied', 'text' => 'Yan ürün net geliri maliyetten düşüldü: yönetimsel tahmin, resmi muhasebe maliyeti değildir.'];
    if (!$applyCredit && $creditCents > 0) $warnings[] = ['code' => 'credit_not_applied', 'text' => 'Yan ürün geliri girildi fakat maliyetten düşülmedi (ihtiyatlı yöntem).'];

    $quantities = ['raw_kg' => $raw4 / 10000, 'processed_kg' => $processedInput, 'export_kg' => $export,
        'domestic_kg' => $domestic, 'industry_kg' => $industry, 'waste_kg' => $waste];
    return [
        'model' => QUICK_COST_V2_MODEL, 'product' => $product, 'sheet_date' => $date, 'operation_type' => $operation,
        'method' => $method, 'notes' => $notes, 'yield_mode' => $yieldMode, 'target_yield' => $targetYield,
        'quantities' => $quantities,
        'fason_includes' => ['labor' => $includesLabor, 'material' => $includesMaterial],
        'credit' => ['apply' => $applyCredit, 'domestic_sold_kg' => $domSold, 'domestic_price' => $domPrice,
            'industry_sold_kg' => $indSold, 'industry_price' => $indPrice],
        'sale' => ['price' => $salePrice, 'currency' => $currency, 'rate' => $rate, 'target_margin' => $margin],
        'items' => $items,
        'derived' => ['raw_kg' => $raw4 / 10000, 'processed_kg' => $processed4 / 10000, 'processed_assumed' => $processedAssumed,
            'unclassified_kg' => $unclassified4 / 10000, 'yield_pct' => $yield100 / 100],
        'groups' => array_map(static fn($cents) => $cents / 100, $groupCents),
        'total' => $totalCents / 100, 'excluded_total' => $excludedCents / 100,
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
    foreach (['quantities', 'credit', 'sale', 'fason_includes'] as $section) {
        if (!is_array($input[$section] ?? null)) continue;
        foreach ($safe[$section] as $key => $default) {
            $value = $input[$section][$key] ?? null;
            if (is_bool($default) || $section === 'fason_includes' || $key === 'apply') { if (is_bool($value)) $safe[$section][$key] = $value; }
            elseif ($value !== null && $scalar($value)) $safe[$section][$key] = $value;
        }
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
        if (!is_string($code) || !preg_match('/^custom_[a-z0-9_]{1,40}$/D', $code) || isset($seen[$code]) || $custom >= QUICK_COST_V2_CUSTOM_MAX) continue;
        $extra = ['code' => $code, 'label' => '', 'basis' => 'fixed', 'unit' => 'fixed', 'qty' => 0, 'qty2' => 0, 'unit_price' => 0];
        foreach (['label', 'basis', 'unit', 'qty', 'qty2', 'unit_price'] as $key) {
            if (isset($row[$key]) && $scalar($row[$key])) $extra[$key] = $row[$key];
        }
        $safe['items'][] = $extra;
        $seen[$code] = true;
        $custom++;
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
