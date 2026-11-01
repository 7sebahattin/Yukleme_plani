<?php
// Hızlı Hesapla (model 3): tek ekran ihracat maliyeti + satış fiyatı. Saf motor; DB/oturum yok.
// Kayıt mevcut quick_cost_sheets tablosunda tutulur (şema DEĞİŞMEZ): method='hizli', document_json.model=3.
// Eski Hızlı Maliyet v1/v2 hesapları bu dosyadan etkilenmez. JS aynası: assets/hizli-hesapla.js
// ('hh_compute' ile 'compute' AYNI işlem sırasını kullanır — ikisini birlikte değiştir).
declare(strict_types=1);

const HH_MODEL = 3;        // v478: fire modeli (eski kayıtlar kendi matematiğiyle açılır)
const HH_MODEL_V4 = 4;    // v479: ihracat randımanı modeli (YENİ kayıtlar)
const HH_METHOD = 'hizli';
const HH_CURRENCIES = ['USD', 'EUR', 'RUB', 'GBP'];   // v481: Ruble + Sterlin (kur elle)

/** 3 (fire, eski) ya da 4 (randıman, yeni) — iki model de Hızlı Hesapla ekranına aittir. */
function hh_is_model($document): bool
{
    return is_array($document) && in_array($document['model'] ?? null, [HH_MODEL, HH_MODEL_V4], true);
}

function hh_model_of($document): int
{
    return is_array($document) && ($document['model'] ?? null) === HH_MODEL ? HH_MODEL : HH_MODEL_V4;
}

/** Yeni hesap (model 4). Fire (ekranda; saklanan yield_pct = 100 − fire) BİLEREK boş: ürüne göre kullanıcı girer (sabit varsayım yok). */
function hh_defaults(): array
{
    // v481: yeni hesap BOŞ açılır (örnek veri yok); patron kendi değerlerini girer.
    return ['model' => HH_MODEL_V4, 'product' => '', 'sheet_date' => date('Y-m-d'), 'sale_kg' => null, 'sale_price' => null,
        'currency' => 'USD', 'rate' => null, 'price_in_fx' => true, 'raw_price' => null, 'freight' => null, 'freight_in_fx' => false,
        'material_kg' => null, 'docs' => null, 'other_kg' => null, 'other_kg_basis' => 'net', 'other_fixed' => null,
        'yield_pct' => null, 'byproduct_income' => null, 'commission_pct' => null, 'target_margin' => null];
}

/** Eski fire modelinin (model 3) örnek değerleri — yalnız eski testler/fixture'lar için. */
function hh_defaults_v3(): array
{
    return ['model' => HH_MODEL, 'product' => '', 'sheet_date' => date('Y-m-d'), 'sale_kg' => 20000.0, 'sale_price' => 1.1,
        'currency' => 'USD', 'rate' => 50.0, 'price_in_fx' => true, 'raw_price' => 20.0, 'freight' => 15000.0,
        'material_kg' => 4.0, 'docs' => 5000.0, 'other_kg' => 0.5, 'other_fixed' => 0.0, 'waste_pct' => 8.0, 'target_margin' => 20.0];
}

/** Boş metin → 0; geçersiz biçim → hata (sessizce 0 yapılmaz). */
function hh_num($value, string $label): float
{
    if ($value === null) return 0.0;
    return quick_cost_number($value, $label);
}

/**
 * Saf formül zinciri (erken yuvarlama YOK). $n: sayılar; $rate > 0, $sale_kg > 0, 0 <= waste < 100.
 * Dönüş tüm ara/son sonuçları ham float olarak verir.
 */
function hh_compute(array $n): array
{
    $s = $n['sale_kg'];
    $rawKg = $s / (1 - $n['waste_pct'] / 100);
    $rawCost = $rawKg * $n['raw_price'];
    $material = $s * $n['material_kg'];
    $otherKg = $s * $n['other_kg'];
    $fixed = $n['freight'] + $n['docs'] + $n['other_fixed'];
    $total = $rawCost + $material + $otherKg + $fixed;
    $unit = $total / $s;
    $unitFx = $unit / $n['rate'];
    $priceTl = $n['price_in_fx'] ? $n['sale_price'] * $n['rate'] : $n['sale_price'];
    $priceFx = $n['price_in_fx'] ? $n['sale_price'] : $n['sale_price'] / $n['rate'];
    $saleTl = $n['price_in_fx'] ? $s * $n['sale_price'] * $n['rate'] : $s * $n['sale_price'];
    $profit = $saleTl - $total;
    $margin = $saleTl > 0 ? $profit / $saleTl * 100 : null;
    $tm = $n['target_margin'];
    $sugTl = $tm === null ? null : $unit / (1 - $tm / 100);
    return ['raw_kg' => $rawKg, 'raw_cost' => $rawCost, 'material_cost' => $material, 'other_kg_cost' => $otherKg,
        'fixed_cost' => $fixed, 'total' => $total, 'unit_cost' => $unit, 'unit_cost_fx' => $unitFx,
        'price_tl' => $priceTl, 'price_fx' => $priceFx, 'sale_tl' => $saleTl, 'sale_fx' => $saleTl / $n['rate'],
        'profit' => $profit, 'profit_fx' => $profit / $n['rate'], 'margin_pct' => $margin,
        'breakeven_fx' => $unitFx, 'suggested_tl' => $sugTl, 'suggested_fx' => $sugTl === null ? null : $sugTl / $n['rate']];
}

/** Doğrulayıp kanonik belge + sonuç üretir (sunucu her kayıtta ve açılışta yeniden hesaplar). */
function hh_calculate_v3(array $input): array
{
    $name = quick_cost_upper_text($input['product'] ?? '', 'Hesap adı', 150);
    $date = $input['sheet_date'] ?? date('Y-m-d');
    if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $p) || !checkdate((int)$p[2], (int)$p[3], (int)$p[1])) {
        throw new InvalidArgumentException('Geçerli bir işlem tarihi girin.');
    }
    $currency = $input['currency'] ?? '';
    if (!is_string($currency) || !in_array($currency, HH_CURRENCIES, true)) throw new InvalidArgumentException('Döviz USD, EUR, RUB veya GBP olmalı.');
    $fx = $input['price_in_fx'] ?? null;
    if (!in_array($fx, [true, false, 1, 0, '1', '0'], true)) throw new InvalidArgumentException('Fiyat döviz seçimi geçersiz.');
    $n = [
        'sale_kg' => hh_num($input['sale_kg'] ?? null, 'Satış net KG'),
        'sale_price' => hh_num($input['sale_price'] ?? null, 'Satış fiyatı'),
        'rate' => hh_num($input['rate'] ?? null, 'Kur'),
        'price_in_fx' => ($fx === true || $fx === 1 || $fx === '1'),
        'raw_price' => hh_num($input['raw_price'] ?? null, 'Ham mal alış fiyatı'),
        'freight' => hh_num($input['freight'] ?? null, 'Nakliye bedeli'),
        'material_kg' => hh_num($input['material_kg'] ?? null, 'Malzeme gideri'),
        'docs' => hh_num($input['docs'] ?? null, 'Evrak masrafları'),
        'other_kg' => hh_num($input['other_kg'] ?? null, 'Diğer masraf'),
        'other_fixed' => hh_num($input['other_fixed'] ?? null, 'Diğer sabit masraf'),
        'waste_pct' => hh_num($input['waste_pct'] ?? null, 'Fire oranı'),
    ];
    if ($n['sale_kg'] <= 0) throw new InvalidArgumentException('Satış net KG sıfırdan büyük olmalı.');
    if ($n['rate'] <= 0) throw new InvalidArgumentException($currency . ' kuru sıfırdan büyük olmalı.');
    if ($n['waste_pct'] >= 100) throw new InvalidArgumentException('Fire oranı %100\'ün altında olmalı.');
    $tmRaw = $input['target_margin'] ?? null;
    $n['target_margin'] = ($tmRaw === null || $tmRaw === '') ? null : hh_num($tmRaw, 'Hedef kâr marjı');
    if ($n['target_margin'] !== null && $n['target_margin'] >= 100) throw new InvalidArgumentException('Hedef kâr marjı %100\'ün altında olmalı.');
    $r = hh_compute($n);
    foreach ($r as $v) if ($v !== null && !is_finite($v)) throw new InvalidArgumentException('Hesap sınırı aşıldı.');
    if ($r['total'] > 99999999999.0 || $r['sale_tl'] > 99999999999.0) throw new InvalidArgumentException('Toplam tutar sınırı aşıldı.');
    return ['model' => HH_MODEL, 'product' => $name, 'sheet_date' => $date, 'operation_type' => 'fason', 'method' => HH_METHOD,
        'notes' => '', 'net_kg' => $n['sale_kg'], 'sale_kg' => $n['sale_kg'], 'sale_price' => $n['sale_price'],
        'currency' => $currency, 'rate' => $n['rate'], 'price_in_fx' => $n['price_in_fx'], 'raw_price' => $n['raw_price'],
        'freight' => $n['freight'], 'material_kg' => $n['material_kg'], 'docs' => $n['docs'], 'other_kg' => $n['other_kg'],
        'other_fixed' => $n['other_fixed'], 'waste_pct' => $n['waste_pct'], 'target_margin' => $n['target_margin'],
        'results' => $r, 'total' => round($r['total'], 2), 'unit_cost' => round($r['unit_cost'], 6)];
}

/**
 * Ondalık sınırı ayarlanabilir sayı (satış fiyatı döviz dönüşümünde 8 ondalık gerekir). Boş → 0; geçersiz → hata.
 */
function hh_num_dec($value, string $label, int $dec): float
{
    if ($value === null) return 0.0;
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') return 0.0;
        if (!preg_match('/^(?:\d+|\d{1,3}(?:\.\d{3})+)(?:,\d{1,' . $dec . '})?$/D', $value)) {
            throw new InvalidArgumentException($label . ': geçerli, negatif olmayan Türkçe sayı girin.');
        }
        $value = (float)str_replace(',', '.', str_replace('.', '', $value));
    } elseif (!is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException($label . ': sayı bekleniyor.');
    }
    $n = (float)$value;
    $k = pow(10, $dec);
    if (!is_finite($n) || $n < 0 || $n > QUICK_COST_NUMBER_MAX || abs($n * $k - round($n * $k)) > 0.001) {
        throw new InvalidArgumentException($label . ': sayı çok büyük, negatif veya çok fazla ondalıklı.');
    }
    return $n;
}

/**
 * Model 4 formül zinciri. İşlem sırası assets/hizli-hesapla.js compute4 ile BİREBİR aynı (ikisini birlikte değiştir).
 * v481: nakliye döviz olabilir (× kur), çıkma/suluk satış geliri maliyetten düşülür, komisyon/finansman satışın %'si.
 * Başabaş ve önerilen fiyat komisyonu da karşılar: fiyat = (komisyonsuz maliyet/kg) / (1 − komisyon% − hedef marj%).
 */
function hh_compute_v4(array $n): array
{
    $s = $n['sale_kg'];
    $rawKg = $s / ($n['yield_pct'] / 100);
    $rawCost = $rawKg * $n['raw_price'];
    $material = $s * $n['material_kg'];
    $otherBase = $n['other_kg_basis'] === 'raw' ? $rawKg : $s;   // 'raw' yalnız v479 eski kayıtları için
    $otherKg = $otherBase * $n['other_kg'];
    $freightTl = $n['freight_in_fx'] ? $n['freight'] * $n['rate'] : $n['freight'];
    $fixed = $freightTl + $n['docs'] + $n['other_fixed'];
    $base = $rawCost + $material + $otherKg + $fixed - $n['byproduct_income'];
    $priceTl = $n['price_in_fx'] ? $n['sale_price'] * $n['rate'] : $n['sale_price'];
    $priceFx = $n['price_in_fx'] ? $n['sale_price'] : $n['sale_price'] / $n['rate'];
    $saleTl = $n['price_in_fx'] ? $s * $n['sale_price'] * $n['rate'] : $s * $n['sale_price'];
    $commission = $saleTl * $n['commission_pct'] / 100;
    $total = $base + $commission;
    $unit = $total / $s;
    $unitFx = $unit / $n['rate'];
    $profit = $saleTl - $total;
    $margin = $saleTl > 0 ? $profit / $saleTl * 100 : null;
    $baseUnit = $base / $s;
    $beTl = $baseUnit / (1 - $n['commission_pct'] / 100);
    $tm = $n['target_margin'];
    $sugTl = $tm === null ? null : $baseUnit / (1 - $n['commission_pct'] / 100 - $tm / 100);
    return ['raw_kg' => $rawKg, 'raw_cost' => $rawCost, 'material_cost' => $material, 'other_kg_cost' => $otherKg,
        'freight_tl' => $freightTl, 'fixed_cost' => $fixed, 'byproduct_income' => $n['byproduct_income'], 'base_total' => $base,
        'commission' => $commission, 'total' => $total, 'unit_cost' => $unit, 'unit_cost_fx' => $unitFx,
        'price_tl' => $priceTl, 'price_fx' => $priceFx, 'sale_tl' => $saleTl, 'sale_fx' => $saleTl / $n['rate'],
        'profit' => $profit, 'profit_fx' => $profit / $n['rate'], 'margin_pct' => $margin,
        'breakeven_tl' => $beTl, 'breakeven_fx' => $beTl / $n['rate'],
        'suggested_tl' => $sugTl, 'suggested_fx' => $sugTl === null ? null : $sugTl / $n['rate']];
}

/** Model 4: doğrular, kanonik belge + sonuç üretir (sunucu her kayıtta/açılışta yeniden hesaplar). */
function hh_calculate_v4(array $input): array
{
    $name = quick_cost_upper_text($input['product'] ?? '', 'Hesap adı', 150);
    $date = $input['sheet_date'] ?? date('Y-m-d');
    if (!is_string($date) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $p) || !checkdate((int)$p[2], (int)$p[3], (int)$p[1])) {
        throw new InvalidArgumentException('Geçerli bir işlem tarihi girin.');
    }
    $currency = $input['currency'] ?? '';
    if (!is_string($currency) || !in_array($currency, HH_CURRENCIES, true)) throw new InvalidArgumentException('Döviz USD, EUR, RUB veya GBP olmalı.');
    $fx = $input['price_in_fx'] ?? null;
    if (!in_array($fx, [true, false, 1, 0, '1', '0'], true)) throw new InvalidArgumentException('Fiyat döviz seçimi geçersiz.');
    $basis = $input['other_kg_basis'] ?? 'net';
    if (!in_array($basis, ['net', 'raw'], true)) throw new InvalidArgumentException('Diğer masraf tabanı Net KG veya Ham KG olmalı.');
    $ffx = $input['freight_in_fx'] ?? false;
    if (!in_array($ffx, [true, false, 1, 0, '1', '0'], true)) throw new InvalidArgumentException('Nakliye para birimi seçimi geçersiz.');
    $n = [
        'sale_kg' => hh_num($input['sale_kg'] ?? null, 'Satış net KG'),
        'sale_price' => hh_num_dec($input['sale_price'] ?? null, 'Satış fiyatı', 8),
        'rate' => hh_num($input['rate'] ?? null, 'Kur'),
        'price_in_fx' => ($fx === true || $fx === 1 || $fx === '1'),
        'raw_price' => hh_num($input['raw_price'] ?? null, 'Ham mal alış fiyatı'),
        'freight' => hh_num($input['freight'] ?? null, 'Nakliye bedeli'),
        'material_kg' => hh_num($input['material_kg'] ?? null, 'Malzeme gideri'),
        'docs' => hh_num($input['docs'] ?? null, 'Evrak masrafları'),
        'other_kg' => hh_num($input['other_kg'] ?? null, 'Diğer masraf'),
        'other_kg_basis' => $basis,
        'other_fixed' => hh_num($input['other_fixed'] ?? null, 'Diğer sabit masraf'),
        'yield_pct' => hh_num($input['yield_pct'] ?? null, 'Fire oranı (ihracata kalan)'),
        'freight_in_fx' => ($ffx === true || $ffx === 1 || $ffx === '1'),
        'byproduct_income' => hh_num($input['byproduct_income'] ?? null, 'Çıkma / suluk satış geliri'),
        'commission_pct' => hh_num($input['commission_pct'] ?? null, 'Komisyon / finansman'),
    ];
    if ($n['commission_pct'] >= 100) throw new InvalidArgumentException('Komisyon / finansman %100\'ün altında olmalı.');
    if ($n['sale_kg'] <= 0) throw new InvalidArgumentException('Satış net KG sıfırdan büyük olmalı.');
    if ($n['rate'] <= 0) throw new InvalidArgumentException($currency . ' kuru sıfırdan büyük olmalı.');
    if (!($n['yield_pct'] > 0 && $n['yield_pct'] <= 100)) throw new InvalidArgumentException('Fire oranı %0 ile %100 arasında olmalı (%100 hariç).');
    $tmRaw = $input['target_margin'] ?? null;
    $n['target_margin'] = ($tmRaw === null || $tmRaw === '') ? null : hh_num($tmRaw, 'Hedef kâr marjı');
    if ($n['target_margin'] !== null && $n['target_margin'] >= 100) throw new InvalidArgumentException('Hedef kâr marjı %100\'ün altında olmalı.');
    if ($n['target_margin'] !== null && $n['target_margin'] + $n['commission_pct'] >= 100) throw new InvalidArgumentException('Hedef kâr marjı + komisyon %100\'ün altında olmalı.');
    $r = hh_compute_v4($n);
    if ($r['base_total'] < 0) throw new InvalidArgumentException('Çıkma / suluk satış geliri toplam giderlerden büyük olamaz.');
    foreach ($r as $v) if ($v !== null && !is_finite($v)) throw new InvalidArgumentException('Hesap sınırı aşıldı.');
    if ($r['total'] > 99999999999.0 || $r['sale_tl'] > 99999999999.0) throw new InvalidArgumentException('Toplam tutar sınırı aşıldı.');
    return ['model' => HH_MODEL_V4, 'calc_version' => 4, 'product' => $name, 'sheet_date' => $date, 'operation_type' => 'fason', 'method' => HH_METHOD,
        'notes' => '', 'net_kg' => $n['sale_kg'], 'sale_kg' => $n['sale_kg'], 'sale_price' => $n['sale_price'],
        'currency' => $currency, 'rate' => $n['rate'], 'price_in_fx' => $n['price_in_fx'], 'raw_price' => $n['raw_price'],
        'freight' => $n['freight'], 'material_kg' => $n['material_kg'], 'docs' => $n['docs'], 'other_kg' => $n['other_kg'],
        'other_kg_basis' => $basis, 'other_fixed' => $n['other_fixed'], 'yield_pct' => $n['yield_pct'], 'target_margin' => $n['target_margin'],
        'freight_in_fx' => $n['freight_in_fx'], 'byproduct_income' => $n['byproduct_income'], 'commission_pct' => $n['commission_pct'],
        'results' => $r, 'total' => round($r['total'], 2), 'unit_cost' => round($r['unit_cost'], 6)];
}

/** Model dağıtıcı: 3 → eski fire matematiği, 4 → ihracat randımanı. */
function hh_calculate(array $input): array
{
    return ($input['model'] ?? null) === HH_MODEL ? hh_calculate_v3($input) : hh_calculate_v4($input);
}

/**
 * Eski (model 3) hesaptan YENİ model için form girdisi. İkisinde de fire = ihracata çıkmayan pay: ihracata kalan (yield_pct) = 100 − fire,
 * ham KG aynıdır. Yalnız kullanıcı "Yeni modelle kopyala" dediğinde kullanılır; kaynak kayıt değişmez.
 */
function hh_donustur_v3_v4(array $doc): array
{
    $out = ['model' => HH_MODEL_V4, 'product' => (string)($doc['product'] ?? ''), 'sheet_date' => date('Y-m-d'), 'other_kg_basis' => 'net', 'yield_pct' => null];
    $w = $doc['waste_pct'] ?? null;
    if (is_numeric($w) && $w >= 0 && $w < 100) $out['yield_pct'] = round(100 - (float)$w, 4);
    foreach (['sale_kg', 'sale_price', 'currency', 'rate', 'price_in_fx', 'raw_price', 'freight', 'material_kg', 'docs', 'other_kg', 'other_fixed', 'target_margin'] as $k) {
        if (array_key_exists($k, $doc)) $out[$k] = $doc[$k];
    }
    return $out;
}

/** Hata sonrası formu korumak için yalnız skaler, bilinen anahtarlar. */
function hh_form_input(array $input): array
{
    $safe = [];
    foreach (['product', 'sheet_date', 'sale_kg', 'sale_price', 'currency', 'rate', 'price_in_fx', 'raw_price', 'freight',
        'material_kg', 'docs', 'other_kg', 'other_kg_basis', 'other_fixed', 'waste_pct', 'yield_pct', 'target_margin',
        'freight_in_fx', 'byproduct_income', 'commission_pct'] as $k) {
        if (isset($input[$k]) && (is_string($input[$k]) || is_int($input[$k]) || is_float($input[$k]) || is_bool($input[$k]))) $safe[$k] = $input[$k];
    }
    return ['model' => (($input['model'] ?? null) === HH_MODEL ? HH_MODEL : HH_MODEL_V4)] + $safe;
}
