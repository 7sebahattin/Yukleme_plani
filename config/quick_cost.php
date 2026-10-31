<?php
// Hızlı Maliyet: bağımsız, yan etkisiz doğrulama ve hesaplama.
declare(strict_types=1);

const QUICK_COST_NUMBER_MAX = 1000000;
const QUICK_COST_TOTAL_MAX = 9999999999.99;

function quick_cost_catalog(): array
{
    return [
        'product' => ['label' => 'Ürün maliyeti', 'group' => 'A', 'unit' => 'kg', 'basis' => 'net_kg'],
        'material' => ['label' => 'Malzeme', 'group' => 'B', 'unit' => 'kg', 'basis' => 'net_kg'],
        'transport' => ['label' => 'Nakliye', 'group' => 'D', 'unit' => 'kg', 'basis' => 'net_kg'],
        'customs' => ['label' => 'Gümrükleme', 'group' => 'D', 'unit' => 'fixed', 'basis' => 'fixed'],
        'labor' => ['label' => 'İşçilik', 'group' => 'D', 'unit' => 'kg', 'basis' => 'net_kg'],
        'official_analysis' => ['label' => 'Resmi analiz', 'group' => 'D', 'unit' => 'fixed', 'basis' => 'fixed'],
        'private_analysis' => ['label' => 'Özel analiz', 'group' => 'D', 'unit' => 'fixed', 'basis' => 'fixed'],
        'dts_taxi' => ['label' => 'DTS taksi', 'group' => 'D', 'unit' => 'fixed', 'basis' => 'fixed'],
        'overhead' => ['label' => 'İşletme gideri', 'group' => 'D', 'unit' => 'kg', 'basis' => 'net_kg'],
        'casus' => ['label' => 'CASUS', 'group' => 'D', 'unit' => 'fixed', 'basis' => 'fixed'],
    ];
}

function quick_cost_units(): array
{
    return ['kg' => 'Kg', 'ton' => 'Ton', 'piece' => 'Adet', 'box' => 'Koli / kasa',
        'pallet' => 'Palet', 'vehicle' => 'Araç', 'fixed' => 'Sabit tutar'];
}

/** Türlere göre ileride fiyat/açıklama özelleştirmesi için tek kaynak. */
function quick_cost_operation_profiles(): array
{
    return [
        'fason' => ['label' => 'Fason', 'description' => 'Ürün KG üzerinden fason maliyeti.', 'prices' => []],
        'uretim' => ['label' => 'Üretim', 'description' => 'Ürün KG üzerinden üretim maliyeti.', 'prices' => []],
    ];
}

function quick_cost_defaults(?string $date = null): array
{
    $items = [];
    foreach (quick_cost_catalog() as $code => $definition) {
        $items[] = ['code' => $code, 'unit' => $definition['unit'],
            'qty' => $definition['basis'] === 'fixed' ? 1 : 0,
            'unit_price' => quick_cost_operation_profiles()['fason']['prices'][$code] ?? 0,
            'basis' => $definition['basis']];
    }
    return ['product' => '', 'sheet_date' => $date ?? date('Y-m-d'), 'operation_type' => 'fason',
        'method' => 'quick', 'net_kg' => 0, 'notes' => '', 'items' => $items];
}

/**
 * Form metni Türkçe biçimdedir: 20.000 / 1,60 / 1.234,5678.
 * Kanonik JSON sayıları int/float gönderilir; noktalı ondalık metin belirsizdir
 * ve reddedilir. Boş giriş başlangıç ekranında sıfırdır. Hatalı metin asla 0 olmaz.
 */
function quick_cost_number($value, string $label = 'Değer'): float
{
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') return 0.0;
        if (!preg_match('/^(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d{1,4})?$/D', $value)) {
            throw new InvalidArgumentException($label . ': geçerli, negatif olmayan Türkçe sayı girin.');
        }
        $value = (float)str_replace(',', '.', str_replace('.', '', $value));
    } elseif (!is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException($label . ': sayı bekleniyor.');
    }
    $number = (float)$value;
    if (!is_finite($number) || $number < 0 || $number > QUICK_COST_NUMBER_MAX
        || abs($number * 10000 - round($number * 10000)) > 0.00001) {
        throw new InvalidArgumentException($label . ': 0–1.000.000 arasında, en fazla dört ondalıklı sayı girin.');
    }
    return round($number, 4);
}

/**
 * Sabit dört ondalık üzerinden tam kuruş hesabı. Q×P doğrudan çarpılmaz:
 * o çarpım int64 sınırını aşabilir. Parçalanmış tüm ara değerler <= 10^14
 * olduğundan PHP int64 ve JavaScript safe integer aralığında aynıdır.
 */
function quick_cost_amount_cents(float $qty, float $price): int
{
    $q = (int)round($qty * 10000);
    $p = (int)round($price * 10000);
    $qi = intdiv($q, 10000); $qf = $q % 10000;
    $pi = intdiv($p, 10000); $pf = $p % 10000;
    $cross = $qi * $pf + $pi * $qf;
    return $qi * $pi * 100 + intdiv($cross, 100)
        + intdiv(($cross % 100) * 10000 + $qf * $pf + 500000, 1000000);
}

function quick_cost_unit_cost(int $totalCents, float $net): float
{
    if ($net <= 0) return 0.0;
    $q = (int)round($net * 10000);
    $numerator = $totalCents * 100;
    $whole = intdiv($numerator, $q);
    $remainder = $numerator % $q;
    // 3+3 basamak: JS aynasında da bütün ara değerler safe integer kalır.
    $first = $remainder * 1000;
    $hi = intdiv($first, $q);
    $rest = $first % $q;
    $lo = intdiv($rest * 1000 + intdiv($q, 2), $q);
    return $whole + ($hi * 1000 + $lo) / 1000000;
}

function quick_cost_text($value, string $label, int $limit): string
{
    if (!is_string($value) || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $limit
        || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
        throw new InvalidArgumentException($label . ': geçersiz veya çok uzun metin.');
    }
    return trim($value);
}

/** Türkçe kullanıcı metni; dönüşüm uzunluğu artırırsa üst sınır yine uygulanır. */
function quick_cost_upper_text($value, string $label, int $limit): string
{
    $text = quick_cost_text($value, $label, $limit);
    $upper = mb_strtoupper(strtr($text, ['i' => 'İ', 'ı' => 'I']), 'UTF-8');
    return quick_cost_text($upper, $label, $limit);
}

/**
 * Tek hesap modeli. Eski quick/detailed yöntemi yalnız uyumluluk metadatasıdır.
 * net_kg bağlı satırlar net kilogramı izler; manual satırlar kendi miktarını korur.
 * İstemcinin amount/total/group/label alanları kullanılmaz.
 */
function quick_cost_calculate(array $input): array
{
    // v2 (ham mal/verimlilik) belgesi kendi motoruna gider; model taşımayan eski belge aşağıdaki v1 kuralıyla.
    if (quick_cost_is_v2($input)) return quick_cost_v2_calculate($input);
    $product = quick_cost_upper_text($input['product'] ?? '', 'Ürün', 150);
    $notes = quick_cost_upper_text($input['notes'] ?? '', 'Açıklama', 2000);
    $date = $input['sheet_date'] ?? '';
    if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $parts)
        || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
        throw new InvalidArgumentException('Geçerli bir işlem tarihi girin.');
    }
    $operation = $input['operation_type'] ?? '';
    $method = $input['method'] ?? '';
    if (!is_string($operation) || !isset(quick_cost_operation_profiles()[$operation])) {
        throw new InvalidArgumentException('İşlem türü Fason veya Üretim olmalı.');
    }
    if (!in_array($method, ['quick', 'detailed'], true)) {
        throw new InvalidArgumentException('Hesaplama yöntemi geçersiz.');
    }
    $net = quick_cost_number($input['net_kg'] ?? null, 'Ürün KG');
    $catalog = quick_cost_catalog();
    $rawItems = $input['items'] ?? null;
    if (!is_array($rawItems) || count($rawItems) < count($catalog) || count($rawItems) > 50 || !array_is_list($rawItems)) {
        throw new InvalidArgumentException('On temel kalem bir kez, en fazla kırk ek kalemle gönderilmeli.');
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
        if (!isset($byCode[$code])) throw new InvalidArgumentException('On temel maliyet kaleminin tamamı gerekli.');
    }
    foreach ($byCode as $code => $item) {
        if (isset($catalog[$code])) continue;
        $label = quick_cost_upper_text($item['label'] ?? null, 'Ek kalem adı', 150);
        if ($label === '') throw new InvalidArgumentException('Ek maliyet kaleminin adı gerekli.');
        $catalog[$code] = ['label' => $label, 'group' => 'D'];
    }
    $items = [];
    $groupCents = ['A' => 0, 'B' => 0, 'D' => 0];
    foreach ($catalog as $code => $definition) {
        $item = $byCode[$code];
        $unit = $item['unit'] ?? '';
        $basis = $item['basis'] ?? '';
        if (!is_string($unit) || !isset(quick_cost_units()[$unit])
            || !in_array($basis, ['net_kg', 'manual', 'fixed'], true)
            || ($basis === 'net_kg' && $unit !== 'kg')
            || (($basis === 'fixed') !== ($unit === 'fixed'))) {
            throw new InvalidArgumentException($definition['label'] . ': birim ve miktar türü uyumsuz.');
        }
        // Bağlı/sabit miktar dahi gönderilmişse bozuk/negatif değeri kabul etme.
        $postedQty = quick_cost_number($item['qty'] ?? null, $definition['label'] . ' miktarı');
        $qty = $basis === 'net_kg' ? $net : ($basis === 'fixed' ? 1.0 : $postedQty);
        $price = quick_cost_number($item['unit_price'] ?? null, $definition['label'] . ' fiyatı');
        $cents = quick_cost_amount_cents($qty, $price);
        $amount = $cents / 100;
        if ($cents > 999999999999) {
            throw new InvalidArgumentException($definition['label'] . ': tutar sınırı aşıldı.');
        }
        $groupCents[$definition['group']] += $cents;
        $items[] = ['code' => $code, 'label' => $definition['label'], 'group' => $definition['group'],
            'unit' => $unit, 'qty' => $qty, 'unit_price' => $price, 'basis' => $basis, 'amount' => $amount];
    }
    $total = array_sum($groupCents) / 100;
    if ($total > QUICK_COST_TOTAL_MAX) throw new InvalidArgumentException('Toplam maliyet sınırı aşıldı.');
    return ['product' => $product, 'sheet_date' => $date, 'operation_type' => $operation,
        'method' => $method, 'net_kg' => $net, 'notes' => $notes, 'items' => $items,
        'groups' => array_map(static fn($cents) => $cents / 100, $groupCents),
        'total' => $total, 'unit_cost' => quick_cost_unit_cost(array_sum($groupCents), $net)];
}

require_once __DIR__ . '/quick_cost_v2.php';
