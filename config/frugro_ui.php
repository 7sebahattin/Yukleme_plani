<?php
// =========================================================
// config/frugro_ui.php — Frugro Siparişler EKRAN yardımcıları (include-only; frugro_core.php'yi tamamlar)
//
// Yetki: yeni yetki YOK. Okuma = require_siparis('read') (OGL ile aynı kapı); yazma/silme OGL sayfasının
// kapısıyla aynı kuralla (can_siparis + role_matrix_page_can('siparis','ogl',eylem)) — kapı TEK yerde: frgui_yetki().
// Para birimleri ASLA toplanmaz; tutar yalnız tek sipariş içinde (kutu × fiyat) hesaplanır.
// =========================================================
declare(strict_types=1);

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) { http_response_code(404); exit; }

/** Eylem kapısı. $eylem: read | create | edit | delete. */
function frgui_yetki(string $eylem): bool
{
    $perm = ['read' => 'read', 'create' => 'write', 'edit' => 'write', 'delete' => 'delete'][$eylem] ?? null;
    if ($perm === null || !can_siparis($perm)) return false;
    return role_matrix_page_can('siparis', 'ogl', $eylem);
}

/** Durum → [CSS son eki, ikon]. */
function frgui_durum_css(string $d): string
{
    return in_array($d, ['yeni', 'pkl_hazir', 'invoice_hazir', 'yola_cikti', 'kapali', 'iptal'], true) ? $d : 'yeni';
}

function frgui_durum_rozet(string $d): string
{
    $et = frg_durumlar()[$d] ?? $d;
    return '<span class="frg-rozet frg-rozet--' . h(frgui_durum_css($d)) . '">' . h($et) . '</span>';
}

/** Çıkış tarihi bugüne göre renk (OGL v376 eşiği): geçmiş → yeşil kalın, bugün → turuncu kalın, ileri → normal. */
function frgui_tarih_html(?string $t, ?string $bugun = null): string
{
    $t = (string)$t;
    if ($t === '' || !preg_match('/^\d{4}-\d{2}-\d{2}/', $t)) return '<span class="frg-soluk">—</span>';
    $t = substr($t, 0, 10);
    $bugun = $bugun ?? date('Y-m-d');
    $sinif = $t < $bugun ? 'gecmis' : ($t === $bugun ? 'bugun' : 'gelecek');
    return '<span class="frg-tar frg-tar--' . $sinif . '">' . h(date('d.m.Y', (int)strtotime($t))) . '</span>';
}

function frgui_tam($n): string
{
    return $n === null || $n === '' ? '—' : number_format((int)$n, 0, ',', '.');
}

/** Kuruş → "12.345,60". */
function frgui_para(int $kurus): string
{
    return number_format($kurus / 100, 2, ',', '.');
}

/**
 * Tutar hücresi. Fiyat yoksa "—"; para birimi atanmamışsa "GBP/EUR seçilecek" (tutar o zamana dek para birimsiz,
 * sayı gösterilmez — yanlış birimle yorumlanmasın). Para birimleri toplanmaz.
 */
function frgui_tutar_html(array $s): string
{
    $h = frg_hesap($s);
    if (!$h['fiyat_var']) return '<span class="frg-soluk">—</span>';
    if ($h['para_birimi'] === null) return '<span class="frg-pb-bekle">GBP/EUR seçilecek</span>';
    return h(frgui_para($h['tutar_kurus'])) . ' ' . h($h['para_birimi']);
}

/** Belge rozetleri (PKL / Invoice hazır). İptalde yok. */
function frgui_belge_rozetleri(array $s): string
{
    $d = (string)($s['durum'] ?? 'yeni');
    if ($d === 'iptal') return '';
    $sira = array_flip(array_keys(frg_durumlar()));
    $r = $sira[$d] ?? 0;
    $pkl = $r >= 1 && $d !== 'iptal';
    $inv = $r >= 2 || trim((string)($s['fatura_no'] ?? '')) !== '';
    $o = '<span class="frg-belge' . ($pkl ? ' frg-belge--hazir' : '') . '" title="Packing List">PKL' . ($pkl ? ' ✓' : '') . '</span>';
    $o .= '<span class="frg-belge' . ($inv ? ' frg-belge--hazir' : '') . '" title="Invoice">INV' . ($inv ? ' ✓' : '') . '</span>';
    return $o;
}

/** Düzenle penceresindeki alanlar: ad → [tür, etiket, azami uzunluk]. TEK beyaz liste (sunucu da bunu kullanır). */
function frgui_duzenle_alanlari(): array
{
    return [
        'cikis_tarihi' => ['tarih', 'Çıkış tarihi', 0], 'varis_tarihi' => ['tarih', 'Varış tarihi', 0], 'varis_saati' => ['metin', 'Varış saati', 30],
        'ihracatci' => ['metin', 'İhracatçı', 150], 'paketlemeci' => ['metin', 'Paketlemeci', 150], 'giris_limani' => ['metin', 'Giriş limanı', 150],
        'teslim_adresi' => ['uzun', 'Teslim adresi', 1000], 'son_musteri' => ['metin', 'Son müşteri', 150], 'son_musteri_po' => ['metin', 'Müşteri PO', 100],
        'urun' => ['metin', 'Ürün', 100], 'cesit' => ['metin', 'Çeşit', 100], 'mense' => ['metin', 'Menşe', 60], 'boy' => ['metin', 'Boy', 30],
        'fruit_agirlik_notu' => ['uzun', 'Meyve ağırlık notu', 1000], 'dis_paket' => ['metin', 'Dış paket', 255], 'ic_paket' => ['metin', 'İç paket', 255],
        'kutu' => ['tam', 'Kutu', 0], 'kutu_palet' => ['tam', 'Kutu / palet', 0], 'palet' => ['tam', 'Palet', 0], 'palet_tipi' => ['metin', 'Palet tipi', 60],
        'incoterm' => ['kod', 'Incoterm', 10], 'fiyat_kutu_birim' => ['fiyat', 'Fiyat (kutu başı)', 0], 'para_birimi' => ['pb', 'Para birimi', 3],
        'parti_no' => ['metin', 'Parti no', 30], 'plaka' => ['metin', 'Plaka', 80], 'sofor_adi' => ['metin', 'Şoför', 100], 'sofor_telefon' => ['metin', 'Şoför telefonu', 40],
        'nakliyeci' => ['metin', 'Nakliyeci', 150],
    ];
}

/**
 * Elle düzenleme girdisini doğrular (beyaz liste dışı alan SESSİZCE atılır).
 * @param array<string,mixed> $girdi
 * @return array{0:array<string,mixed>,1:list<string>} [DB'ye yazılacak değerler, hata mesajları]
 */
function frgui_duzenle_dogrula(array $girdi): array
{
    $temiz = []; $hata = [];
    foreach (frgui_duzenle_alanlari() as $alan => [$tur, $etiket, $max]) {
        if (!array_key_exists($alan, $girdi)) continue;
        $v = $girdi[$alan];
        if (is_array($v) || is_object($v)) { $hata[] = "$etiket: geçersiz değer."; continue; }
        $v = is_string($v) ? trim($v) : $v;
        $bos = $v === null || $v === '';
        switch ($tur) {
            case 'tarih':
                if ($bos) { $temiz[$alan] = null; break; }
                $t = frg_tarih((string)$v);
                if ($t === null) $hata[] = "$etiket: tarih geçersiz."; else $temiz[$alan] = $t;
                break;
            case 'tam':
                if ($bos) { $temiz[$alan] = null; break; }
                if (!preg_match('/^\d{1,9}$/', (string)$v)) { $hata[] = "$etiket: tam sayı olmalı."; break; }
                $temiz[$alan] = (int)$v;
                break;
            case 'fiyat':
                if ($bos) { $temiz[$alan] = null; break; }
                $f = frg_fiyat_birim((string)$v);
                if ($f === null || $f > 99999999999) $hata[] = "$etiket: sayı olmalı (örn. 4,60)."; else $temiz[$alan] = $f;
                break;
            case 'pb':
                $v = strtoupper((string)$v);
                if ($v === '') { $temiz[$alan] = null; break; }
                if (!in_array($v, ['GBP', 'EUR'], true)) $hata[] = "$etiket: GBP veya EUR olmalı."; else $temiz[$alan] = $v;
                break;
            case 'kod':
                $v = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', (string)$v));
                $temiz[$alan] = $v === '' ? null : substr($v, 0, $max);
                break;
            default: // metin | uzun
                $m = frg_metin((string)$v);
                if ($m !== null && mb_strlen($m) > $max) { $hata[] = "$etiket: en çok $max karakter."; break; }
                $temiz[$alan] = $m;
        }
    }
    return [$temiz, $hata];
}
