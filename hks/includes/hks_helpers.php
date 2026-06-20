<?php
declare(strict_types=1);
// HKS modülü genel yardımcı fonksiyonları

function hks_h(mixed $v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hks_qty(mixed $v): float {
    return round((float)str_replace(',', '.', (string)($v ?? 0)), 3);
}

function hks_plate(string $plate): string {
    return strtoupper(preg_replace('/\s+/', '', trim($plate)));
}

function hks_check_soap(): bool {
    return extension_loaded('soap');
}

// ── Etiket fonksiyonları ──────────────────────────────────

function hks_status_label(string $status): string {
    return match($status) {
        'draft'        => 'Taslak',
        'ready'        => 'Gönderime Hazır',
        'checked'      => 'Kontrol Edildi',
        'send_pending' => 'Gönderim Kilidi',
        'sent'         => 'Gönderildi',
        'failed'       => 'Hata',
        'cancelled'    => 'İptal',
        default        => $status,
    };
}

function hks_status_badge(string $status): string {
    $label = hks_status_label($status);
    $cls   = match($status) {
        'draft'        => 'hks-badge-draft',
        'ready'        => 'hks-badge-ready',
        'checked'      => 'hks-badge-checked',
        'send_pending' => 'hks-badge-pending',
        'sent'         => 'hks-badge-sent',
        'failed'       => 'hks-badge-failed',
        'cancelled'    => 'hks-badge-cancelled',
        default        => '',
    };
    return '<span class="hks-badge ' . $cls . '">' . hks_h($label) . '</span>';
}

function hks_ref_type_label(string $type): string {
    return match($type) {
        'ulke'           => 'Ülke',
        'il'             => 'İl',
        'ilce'           => 'İlçe',
        'belde'          => 'Belde',
        'depo'           => 'Depo',
        'sube'           => 'Şube',
        'isletme_turu'   => 'İşletme Türü',
        'hal_ici_isyeri' => 'Hal İçi İşyeri',
        'urun'           => 'Ürün',
        'urun_birim'     => 'Ürün Birimi',
        'urun_cins'      => 'Ürün Cinsi',
        'malin_niteligi' => 'Malın Niteliği',
        'uretim_sekli'   => 'Üretim Şekli',
        'bildirim_turu'  => 'Bildirim Türü',
        'sifat'          => 'Sıfat',
        'referans_kunye' => 'Referans Künye',
        'urun_miktar_birimi' => 'Ürün Miktar Birimi',
        'belge_tipi'         => 'Belge Tipi',
        default          => $type,
    };
}

function hks_env_label(string $env): string {
    return $env === 'live' ? '🔴 Canlı' : '🟡 Test';
}

// ── Dropdown yardımcıları ────────────────────────────────

// Bildirimci sıfatı — resmi HKS bildirim ekranındaki sabit liste.
// (BildirimServisSifatListesi referansından farklıdır; o liste daha geniş kodlardan oluşur.)
// Senkronize "sifat" referansının ref_name değerleri sayısal/boş kalırsa bu liste kullanılır.
function hks_sifat_list(): array {
    return [
        ['code' => 'İhracat',                'name' => 'İhracat'],
        ['code' => 'Üretici',                'name' => 'Üretici'],
        ['code' => 'Depo/Tasnif ve Ambalaj', 'name' => 'Depo/Tasnif ve Ambalaj'],
        ['code' => 'Tüccar (Hal Dışı)',      'name' => 'Tüccar (Hal Dışı)'],
    ];
}

// Bir referans listesinin etiketleri (ref_name) anlamlı mı, yoksa hepsi sayısal/boş mu?
// Senkron sırasında etiket alanı eşlenemeyip koda düşülmüşse true döner.
function hks_refs_labels_numeric(array $refs): bool {
    if (empty($refs)) return true;
    foreach ($refs as $r) {
        $name = trim((string)($r['ref_name'] ?? ''));
        if ($name !== '' && !ctype_digit($name)) return false;
    }
    return true;
}

// Bildirim Türü — resmi HKS e-Bildirim ekranındaki sabit liste.
function hks_bildirim_turu_list(): array {
    return ['Satış', 'Satın Alım', 'Sevk Etme'];
}

// Bildirim Türüne göre yön (giriş/çıkış) — referans künye zorunluluğu vb. için.
function hks_bildirim_turu_direction(string $turu): string {
    return $turu === 'Satın Alım' ? 'giris' : 'cikis';
}

// Karşı tarafın (Kimden/Kime) sıfatı — Bildirim Türüne göre değişir (resmi HKS davranışı).
// Satış → alıcı işletme türleri; Satın Alım → Üretici; Sevk Etme → (boş, yalnızca Seçiniz).
function hks_karsi_taraf_sifat_map(): array {
    return [
        'Satış'      => ['E-Market', 'Hastane', 'Lokanta', 'Manav', 'Market', 'Otel', 'Pazarcı', 'Yemek Fabrikası', 'Yurt'],
        'Satın Alım' => ['Üretici'],
        'Sevk Etme'  => [],
    ];
}

// İşyeri Türü — resmi HKS Referans Künye ekranındaki sabit liste.
function hks_isyeri_turu_list(): array {
    return [
        'Şube', 'Tasnifleme ve Ambalajlama', 'Hal İçi Deposu', 'Hal Dışı Deposu',
        'Hal İçi İşyeri', 'Hal Dışı İşyeri', 'Sınai İşletme', 'Dağıtım Merkezi',
    ];
}

// Gidecek Yer İşletme Türüne göre işyeri Id'sinin alınacağı HKS referans kaynağı.
// KAYNAK: GTB kılavuzu §5 "Malın Gidecek Yer Bilgileri" — tahmin DEĞİL:
//   • "Hal İçi İşyeri"                                 → GenelServisHalIciIsyeri (hal_ici_isyeri)
//   • "Hal Dışı İşyeri" / "Sınai İşletme" / "Perakende Satış Yeri" → GenelServisSubeler (sube)
//   • "Hal İçi Deposu" / "Hal Dışı Deposu"             → GenelServisDepolar (depo)
//   • "Yurt Dışı"                                      → işyeri yok (GidecekUlkeId kullanılır)
// Kılavuzda eşleşmeyen türler (Tasnifleme, Dağıtım Merkezi vb.) → null (netleştirilmemiş).
function hks_isyeri_kaynak_for_turu(?string $turu): ?string {
    $t = mb_strtolower(trim((string)$turu), 'UTF-8');
    // Türkçe "İ" mb_strtolower'da "i" + birleşik nokta (U+0307) üretir → eşleşmeyi bozar; temizle.
    $t = str_replace("\u{0307}", '', $t);
    if ($t === '') return null;
    if (mb_strpos($t, 'yurt dış') !== false) return null;            // ihracat — işyeri yok
    if (mb_strpos($t, 'depo')     !== false) return 'depo';          // Hal İçi/Dışı Deposu
    if (mb_strpos($t, 'hal içi işyeri') !== false
        || mb_strpos($t, 'hal ici isyeri') !== false) return 'hal_ici_isyeri';
    if (mb_strpos($t, 'hal dışı işyeri') !== false
        || mb_strpos($t, 'hal disi isyeri') !== false
        || mb_strpos($t, 'sınai') !== false || mb_strpos($t, 'sinai') !== false
        || mb_strpos($t, 'perakende') !== false) return 'sube';
    if (mb_strpos($t, 'şube') !== false || mb_strpos($t, 'sube') !== false) return 'sube';
    return null;                                                     // kılavuzda netleştirilmemiş
}

// İşyeri kaynak tipi → HKS yardımcı servis adı (rapor/uyarı metinleri için).
function hks_isyeri_kaynak_servis(?string $kaynak): string {
    return match ($kaynak) {
        'depo'           => 'GenelServisDepolar',
        'sube'           => 'GenelServisSubeler',
        'hal_ici_isyeri' => 'GenelServisHalIciIsyeri',
        default          => '',
    };
}

// Sıralama Türü — Referans Künye sorgu sonuç sıralaması.
function hks_siralama_turu_list(): array {
    return ['Tarihe Göre Azalan', 'Tarihe Göre Artan'];
}

// Malın Niteliği — Yerli / İthal / Toplama Mal (resmi HKS radyo seçenekleri).
function hks_malin_niteligi_list(): array {
    return ['Yerli', 'İthal', 'Toplama Mal'];
}

// Malın Türü — üretim şekli.
function hks_malin_turu_list(): array {
    return ['Geleneksel(Konvansiyonel)', 'Organik', 'İyi Tarım Uygulamaları'];
}

// Belge Tipi — Gideceği yer belge tipi.
function hks_belge_tipi_list(): array {
    return ['İrsaliye', 'Fatura', 'Gümrük Beyannamesi'];
}

// Gideceği Yer — Yurt Dışı + işyeri türleri.
function hks_gidecek_yer_list(): array {
    return array_merge(['Yurt Dışı'], hks_isyeri_turu_list());
}

// Para Birimi — birim fiyatı para birimi.
function hks_para_birimi_list(): array {
    return ['TL', 'USD', 'EUR'];
}

// İhracat Yapılan Ülkeler — statik yedek liste (HKS 'ulke' referansı senkronlanmamışsa).
function hks_ulke_list(): array {
    return [
        'ABD','AFGANISTAN','ALMANYA','ANDORRA','ANGOLA','ARJANTİN','ARNAVUTLUK','ARUBA','AVUSTRALYA','AVUSTURYA',
        'AZERBAYCAN','BAE','BAHREYN','BANGLADEŞ','BELÇİKA','BELİZE','BENİN','BEYAZ RUSYA','BHUTAN','BİRLEŞİK KRALLIK',
        'BOLİVYA','BOSNA HERSEK','BREZİLYA','BULGARİSTAN','BURKİNA FASO','BURUNDİ','CEZAYİR','CİBUTİ','ÇAD','ÇEK CUMHURİYETİ',
        'ÇİN','DANİMARKA','DOMİNİK CUMHURİYETİ','EKVADOR','EKVATOR GİNESİ','EL SALVADOR','ENDONEZYA','ERİTRE','ERMENİSTAN','ESTONYA',
        'ETİYOPYA','FAS','FİLDİŞİ SAHİLİ','FİLİPİNLER','FİLİSTİN','FİNLANDİYA','FRANSA','GABON','GAMBİYA','GANA',
        'GİNE','GÜNEY AFRİKA','GÜNEY KORE','GÜRCİSTAN','HAİTİ','HİNDİSTAN','HIRVATİSTAN','HOLLANDA','HONDURAS','IRAK',
        'İRAN','İRLANDA','İSPANYA','İSRAİL','İSVEÇ','İSVİÇRE','İTALYA','İZLANDA','JAMAİKA','JAPONYA',
        'KAMBOÇYA','KAMERUN','KANADA','KARADAĞ','KATAR','KAZAKİSTAN','KENYA','KIBRIS','KIRGIZİSTAN','KOLOMBİYA',
        'KONGO','KOSOVA','KOSTA RİKA','KUVEYT','KUZEY KORE','KÜBA','LAOS','LESOTO','LETONYA','LİBERYA',
        'LİBYA','LİHTENŞTAYN','LİTVANYA','LÜBNAN','LÜKSEMBURG','MACARİSTAN','MADAGASKAR','MAKEDONYA','MALAVİ','MALDİVLER',
        'MALEZYA','MALİ','MALTA','MEKSİKA','MISIR','MOĞOLİSTAN','MOLDOVA','MONAKO','MORİTANYA','MOZAMBİK',
        'MYANMAR','NAMİBYA','NEPAL','NİJER','NİJERYA','NİKARAGUA','NORVEÇ','ORTA AFRİKA CUMHURİYETİ','ÖZBEKİSTAN','PAKİSTAN',
        'PANAMA','PARAGUAY','PERU','POLONYA','PORTEKİZ','ROMANYA','RUANDA','RUSYA','SENEGAL','SIRBİSTAN',
        'SİERRA LEONE','SİNGAPUR','SLOVAKYA','SLOVENYA','SOMALİ','SRİ LANKA','SUDAN','SURİYE','SUUDİ ARABİSTAN','ŞİLİ',
        'TACİKİSTAN','TANZANYA','TAYLAND','TAYVAN','TOGO','TUNUS','TÜRKMENİSTAN','UGANDA','UKRAYNA','UMMAN',
        'URUGUAY','ÜRDÜN','VENEZUELA','VİETNAM','YEMEN','YENİ ZELANDA','YUNANİSTAN','ZAMBİYA','ZİMBABVE',
    ];
}

function hks_ref_options(array $refs, string $selected = '', bool $include_empty = true): string {
    $out = $include_empty ? '<option value="">— Seçin —</option>' : '';
    foreach ($refs as $r) {
        $val = hks_h($r['ref_code']);
        $lbl = hks_h($r['ref_name']);
        $sel = $selected === (string)$r['ref_code'] ? ' selected' : '';
        $out .= "<option value=\"{$val}\"{$sel}>{$lbl}</option>";
    }
    return $out;
}

// ── Bildirim doğrulama ───────────────────────────────────

function hks_validate_notification(array $n): array {
    $errors = [];
    $val = static fn(string $f): string => trim((string)($n[$f] ?? ''));

    // ── Her zaman zorunlu alanlar (resmi e-Bildirim 3-adım formu) ──
    $required = [
        'notification_type' => 'Bildirim türü seçilmelidir.',
        'sifat'             => 'Bildirimci sıfatı seçilmelidir.',
        'firma'             => 'Bildirimci ünvanı boş olamaz.',
        'urun'              => 'Malın adı seçilmelidir.',
        'birim'             => 'Mal miktarı birimi seçilmelidir.',
        'depo'              => 'Depo / şube bilgisi gereklidir.',
        'sevk_tarihi'       => 'Sevk tarihi girilmelidir.',
        'arac_plaka'        => 'Araç plakası girilmelidir.',
        'gidecek_yer'       => 'Gideceği yer seçilmelidir.',
        'malin_niteligi'    => 'Malın niteliği seçilmelidir.',
        'malin_turu'        => 'Malın türü seçilmelidir.',
    ];
    foreach ($required as $field => $msg) {
        if ($val($field) === '') $errors[] = $msg;
    }

    // Miktar > 0
    if ($val('miktar') === '' || (float)$n['miktar'] <= 0) {
        $errors[] = 'Mal miktarı sıfırdan büyük olmalıdır.';
    }

    // ── Karşı taraf — "Sevk Etme" dışındaki türlerde zorunlu ──
    // (Satış / Satın Alım'da karşı taraf vardır; Sevk Etme'de yoktur.)
    $turu = $val('notification_type');
    $has_counterparty = $turu !== '' && mb_stripos($turu, 'Sevk') === false;
    if ($has_counterparty) {
        if ($val('alici_ad') === '')     $errors[] = 'Karşı taraf adı / ünvanı girilmelidir.';
        if ($val('alici_tc_vkn') === '') $errors[] = 'Karşı taraf TC / VKN bilgisi girilmelidir.';
        if ($val('karsi_sifat') === '')  $errors[] = 'Karşı tarafın sıfatı seçilmelidir.';
    }

    // ── Referans künye yoksa malın kaynağı daha sıkı sorulur ──
    $has_ref = $val('reference_kunye_no') !== '';
    if (!$has_ref) {
        if ($val('urun_cinsi') === '') $errors[] = 'Referans künye girilmediği için malın cinsi seçilmelidir.';
        if ($val('il') === '')         $errors[] = 'Referans künye girilmediği için üretildiği/girdiği il seçilmelidir.';
        if ($val('ilce') === '')       $errors[] = 'Referans künye girilmediği için ilçe girilmelidir.';
    }

    // ── Koşullu zorunluluklar ──
    // Yurt Dışı → ihracat ülkesi
    $is_yurt_disi_dest = mb_strtolower($val('gidecek_yer')) === mb_strtolower('Yurt Dışı');
    if ($is_yurt_disi_dest && $val('ihracat_ulke') === '') {
        $errors[] = 'Gideceği yer "Yurt Dışı" seçildiği için ihracat yapılan ülke seçilmelidir.';
    }
    // Belge no varsa belge tipi
    if ($val('belge_no') !== '' && $val('belge_tipi') === '') {
        $errors[] = 'Belge no girildiği için belge tipi seçilmelidir.';
    }

    // ── Sprint MissingFields-01 — kılavuz koşullu zorunlulukları ──
    // A) Malın niteliği "İthal" ise gelen ülke zorunlu (GelenUlkeId, kılavuz §5).
    if (mb_strtolower($val('malin_niteligi')) === mb_strtolower('İthal') && $val('gelen_ulke') === '') {
        $errors[] = 'İthal mal için gelen ülke seçilmelidir.';
    }
    // B) Gidecek yer yurt içi ve kayıtlı değilse il/ilçe zorunlu (GidecekYerIlId/IlceId, kılavuz §5).
    //    (Yurt dışı seçiliyse bu kural uygulanmaz; o durumda GidecekUlkeId/ihracat_ulke kullanılır.)
    $gidecek_kayitsiz = (int)($n['gidecek_kayitli_degil'] ?? 0) === 1;
    if ($gidecek_kayitsiz && !$is_yurt_disi_dest && $val('gidecek_yer') !== '') {
        if ($val('gidecek_yer_il') === '')   $errors[] = 'Kayıtlı olmayan gidecek yer için il seçilmelidir.';
        if ($val('gidecek_yer_ilce') === '') $errors[] = 'Kayıtlı olmayan gidecek yer için ilçe seçilmelidir.';
        // Belde: kılavuzda "0 olamaz" geçse de pratikte her yerde belde olmayabilir;
        // form akışında bloke etmiyoruz — mapping raporunda koşullu not olarak işaretli.
    }

    // ── Sprint GidecekIsyeri-Flow-01 — kayıtlı yurt içi gidecek yer → işyeri zorunlu (kılavuz §5) ──
    // İşletme türü (gidecek_yer) zaten yukarıda zorunlu. Burada işyeri Id'si ve kaynak netliği aranır.
    if (!$gidecek_kayitsiz && !$is_yurt_disi_dest && $val('gidecek_yer') !== '') {
        $kaynak = hks_isyeri_kaynak_for_turu($val('gidecek_yer'));
        if ($kaynak === null) {
            // Kılavuzda işyeri kaynağı tanımsız işletme türü — gönderime hazır sayma.
            $errors[] = 'Seçilen işletme türü için HKS işyeri kaynağı netleştirilmelidir.';
        } elseif ($val('gidecek_isyeri_id') === '') {
            $errors[] = 'Gidecek işyeri seçilmelidir.';
        }
    }

    // ── Format kontrolleri ──
    // Sevk tarihi format (Y-m-d)
    $sevk = $val('sevk_tarihi');
    if ($sevk !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $sevk);
        if (!$d || $d->format('Y-m-d') !== $sevk) {
            $errors[] = 'Sevk tarihi formatı geçersiz (YYYY-AA-GG olmalı).';
        }
    }

    // Plaka format
    $plaka = hks_plate((string)($n['arac_plaka'] ?? ''));
    if ($plaka !== '') {
        $first = explode('/', $plaka)[0];
        if (!preg_match('/^[0-9]{2}[A-ZÇĞİÖŞÜ]{1,3}[0-9]{2,4}$/u', $first)) {
            $errors[] = 'Araç plaka formatı geçersiz görünüyor (örn. 34ABC123).';
        }
    }

    // TC/VKN uzunluk — karşı taraf
    $alici_no = preg_replace('/\D/', '', (string)($n['alici_tc_vkn'] ?? ''));
    if ($alici_no !== '' && !in_array(strlen($alici_no), [10, 11], true)) {
        $errors[] = 'Karşı taraf TC (11) veya VKN (10) hane uzunluğunda olmalıdır.';
    }

    // TC/VKN uzunluk — gideceği yer sahibi (girildiyse)
    $gys_no = preg_replace('/\D/', '', (string)($n['gidecek_sahibi_tc'] ?? ''));
    if ($gys_no !== '' && !in_array(strlen($gys_no), [10, 11], true)) {
        $errors[] = 'Gideceği yer sahibi TC (11) veya VKN (10) hane uzunluğunda olmalıdır.';
    }

    // Üretici: ikisinden biri girildiyse her ikisi de zorunlu
    $up_ad  = $val('uretici_ad');
    $up_vkn = $val('uretici_tc_vkn');
    if (($up_ad !== '' || $up_vkn !== '') && ($up_ad === '' || $up_vkn === '')) {
        $errors[] = 'Üretici bilgisi girilecekse hem üretici adı hem TC/VKN zorunludur.';
    }

    // Çıkış yönünde referans künye zorunlu
    if (($n['direction'] ?? '') === 'cikis' && !$has_ref) {
        $errors[] = 'Çıkış bildiriminde referans künye no zorunludur.';
    }

    return $errors;
}

// Yeni e-Bildirim alanlarından biri doldurulmuş mu? (eski form koruması için)
// Yeni 3-adımlı operasyon formundan oluşan kayıtları tespit eder.
function hks_is_ebildirim_record(array $n): bool {
    foreach ([
        'malin_niteligi', 'malin_turu', 'gidecek_yer', 'ihracat_ulke',
        'belge_tipi', 'karsi_sifat', 'gidecek_sahibi_tc', 'gsm',
        'dogum_tarihi', 'eposta', 'bildirimci_tc_vkn',
        'gelen_ulke', 'gidecek_yer_il', 'gidecek_yer_ilce', 'gidecek_yer_belde',
        'gidecek_isyeri_id', 'gidecek_isyeri_adi',
    ] as $f) {
        if (trim((string)($n[$f] ?? '')) !== '') return true;
    }
    if ((float)($n['birim_fiyat'] ?? 0) > 0) return true;
    if ((int)($n['yurt_disi'] ?? 0) === 1) return true;
    if ((int)($n['gidecek_kayitli_degil'] ?? 0) === 1) return true;
    return false;
}

// Toplam tutar (miktar × birim fiyat) — hesaplanabiliyorsa formatlı döndürür, yoksa ''.
function hks_notification_total_amount(array $n): string {
    $miktar = (float)($n['miktar'] ?? 0);
    $fiyat  = (float)($n['birim_fiyat'] ?? 0);
    if ($miktar <= 0 || $fiyat <= 0) return '';
    $para = trim((string)($n['para_birimi'] ?? 'TL')) ?: 'TL';
    return number_format($miktar * $fiyat, 2, ',', '.') . ' ' . $para;
}

// HKS'ye gidecek operasyonel payload önizlemesi — şifre/secret İÇERMEZ.
// Kullanıcı kontrol/önizleme tablosu için okunabilir, bölümlere göre sıralı alanlar döndürür.
function hks_notification_payload_preview(array $n): array {
    $miktar = ($n['miktar'] ?? '') !== '' ? number_format((float)$n['miktar'], 3, ',', '.') . ' ' . ($n['birim'] ?? '') : '';
    $fiyat  = (float)($n['birim_fiyat'] ?? 0) > 0
        ? number_format((float)$n['birim_fiyat'], 2, ',', '.') . ' ' . (trim((string)($n['para_birimi'] ?? 'TL')) ?: 'TL')
        : '';
    $toplam = hks_notification_total_amount($n);
    $gidecek = trim((string)($n['gidecek_yer'] ?? ''));
    if (mb_strtolower($gidecek) === mb_strtolower('Yurt Dışı') && trim((string)($n['ihracat_ulke'] ?? '')) !== '') {
        $gidecek .= ' (' . $n['ihracat_ulke'] . ')';
    }
    $belge = trim((string)($n['belge_no'] ?? ''));
    if ($belge !== '' && trim((string)($n['belge_tipi'] ?? '')) !== '') {
        $belge .= ' / ' . $n['belge_tipi'];
    }

    return [
        // — Bildirimci —
        'Bildirim Türü'      => $n['notification_type'] ?? '',
        'Bildirimci Sıfat'   => $n['sifat'] ?? '',
        'Bildirimci'         => trim(($n['firma'] ?? '') . ' ' . (!empty($n['bildirimci_tc_vkn']) ? '(' . $n['bildirimci_tc_vkn'] . ')' : '')),
        // — Karşı Taraf —
        'Karşı Taraf'        => trim(($n['alici_ad'] ?? '') . ' ' . (!empty($n['alici_tc_vkn']) ? '(' . $n['alici_tc_vkn'] . ')' : '')),
        'Karşı Taraf Sıfatı' => $n['karsi_sifat'] ?? '',
        // — Referans Künye —
        'Referans Künye'     => $n['reference_kunye_no'] ?? '',
        // — Mal Bilgileri —
        'Malın Niteliği'     => $n['malin_niteligi'] ?? '',
        'Gelen Ülke'         => $n['gelen_ulke'] ?? '',
        'Malın Türü (Üretim Şekli)' => $n['malin_turu'] ?? '',
        'Ürün'               => $n['urun'] ?? '',
        'Ürün Cinsi'         => $n['urun_cinsi'] ?? '',
        'Miktar'             => $miktar,
        'Birim Fiyat'        => $fiyat,
        'Toplam Tutar'       => $toplam,
        'Üretici'            => trim(($n['uretici_ad'] ?? '') . ' ' . (!empty($n['uretici_tc_vkn']) ? '(' . $n['uretici_tc_vkn'] . ')' : '')),
        // — Gidecek Yer / Sevk —
        'Gideceği Yer'       => $gidecek,
        'Gidecek İşyeri'     => trim((string)($n['gidecek_isyeri_adi'] ?? '')
                                . (!empty($n['gidecek_isyeri_id']) ? ' (#' . $n['gidecek_isyeri_id'] . ')' : '')),
        'Gid. Yer Sahibi'    => $n['gidecek_sahibi_tc'] ?? '',
        'Gid. Yer İl / İlçe' => trim(((string)($n['gidecek_yer_il'] ?? '')) . ' / ' . ((string)($n['gidecek_yer_ilce'] ?? '')), ' /'),
        'Gid. Yer Belde'     => $n['gidecek_yer_belde'] ?? '',
        'Depo / Şube'        => $n['depo'] ?? '',
        'Üretim İl / İlçe'   => trim(($n['il'] ?? '') . ' / ' . ($n['ilce'] ?? ''), ' /'),
        'Üretim Belde'       => $n['belde'] ?? '',
        'Araç Plaka'         => $n['arac_plaka'] ?? '',
        'Belge No / Tipi'    => $belge,
        'Sevk Tarihi'        => $n['sevk_tarihi'] ?? '',
    ];
}

// Canlı gönderim için engelleyici koşulları döndürür — boş ise gönderilebilir
function hks_send_blockers(array $n, ?array $settings): array {
    $blockers = [];
    if (!(function_exists('can') && can('hks.send'))) {
        $blockers[] = 'Canlı gönderim yetkiniz yok (hks.send).';
    }
    if (!$settings || (int)($settings['live_send_enabled'] ?? 0) !== 1) {
        $blockers[] = 'Canlı gönderim ayarlardan etkinleştirilmemiş.';
    }
    // Mapping Approval Gate — alan eşleştirmesi onaylanmadan gönderim yapılamaz.
    if (function_exists('hks_payload_fields_confirmed') && !hks_payload_fields_confirmed()) {
        $blockers[] = 'BildirimKaydet alan eşleştirmesi onaylanmadı (Mapping Raporu → Mapping\'i Onayla).';
    }
    if (($n['status'] ?? '') !== 'checked') {
        $blockers[] = 'Kayıt "Kontrol Edildi" durumunda olmalı.';
    }
    if (!hks_can_save_passwords()) {
        $blockers[] = 'HKS_CRED_KEY tanımlı değil.';
    }
    if (trim((string)($settings['username'] ?? '')) === '') {
        $blockers[] = 'HKS kullanıcı adı kayıtlı değil.';
    }
    if (trim((string)($settings['password_enc'] ?? '')) === '') {
        $blockers[] = 'HKS kullanıcı şifresi kayıtlı değil.';
    }
    if (trim((string)($settings['service_password_enc'] ?? '')) === '') {
        $blockers[] = 'HKS servis şifresi kayıtlı değil.';
    }
    if ((int)($settings['last_test_ok'] ?? 0) !== 1) {
        $blockers[] = 'Son bağlantı testi başarılı değil.';
    }
    if (!empty($n['validation_errors_json']) && $n['validation_errors_json'] !== '[]') {
        $blockers[] = 'Doğrulama hataları mevcut.';
    }
    if (!empty($n['hks_bildirim_no']) || !empty($n['hks_kunye_no'])) {
        $blockers[] = 'Bu kayıt zaten HKS numarası almış (mükerrer engeli).';
    }
    return $blockers;
}

// ── Yükleme planından taslak oluştur ───────────────────

function hks_create_draft_from_loading_record(int $loading_record_id, HksRepository $repo, PDO $pdo): ?int {
    $st = $pdo->prepare("SELECT * FROM loading_records WHERE id = ?");
    $st->execute([$loading_record_id]);
    $rec = $st->fetch();
    if (!$rec) return null;

    $stNet = $pdo->prepare("SELECT COALESCE(SUM(net_kg),0) FROM loading_pallets WHERE loading_record_id = ?");
    $stNet->execute([$loading_record_id]);
    $miktar = (float)$stNet->fetchColumn();

    $on_plaka  = trim($rec['on_plaka']  ?? '');
    $arka_plaka = trim($rec['arka_plaka'] ?? '');
    $plaka = $on_plaka . ($arka_plaka && $arka_plaka !== $on_plaka ? ' / ' . $arka_plaka : '');

    return $repo->createNotification([
        'source_type'  => 'loading_record',
        'source_id'    => $loading_record_id,
        'firma'        => $rec['firma'] ?? '',
        'urun'         => $rec['urun'] ?? '',
        'miktar'       => $miktar,
        'birim'        => 'KG',
        'alici_ad'     => $rec['alici'] ?? '',
        'belge_no'     => $rec['parti_no'] ?? '',
        'arac_plaka'   => hks_plate($plaka),
        'sevk_tarihi'  => date('Y-m-d'),
        'status'       => 'draft',
        'created_by'   => $_SESSION['user_id'] ?? null,
    ]);
}

// Servis yanıtını normalize eder — IslemKodu / HataKodlari kontrol eder.
// $raw: callService'in döndürdüğü data dizisi veya tek bir yanıt nesnesi.
// Dizi ise ilk elemanı alır (BildirimService envelope yapısı).
function hks_normalize_response(mixed $raw): array {
    if ($raw === null) {
        return ['ok' => false, 'message' => 'Boş yanıt', 'islem_kodu' => ''];
    }
    // Liste (indexed array) ise ilk elemanı al
    $item = (is_array($raw) && isset($raw[0])) ? $raw[0] : $raw;
    $arr  = is_object($item) ? (array)$item : (is_array($item) ? $item : []);

    $islem_kodu = $arr['IslemKodu'] ?? $arr['islemKodu'] ?? $arr['islem_kodu'] ?? '';
    $hata_raw   = $arr['HataKodlari'] ?? $arr['hataKodlari'] ?? $arr['HataMesaji'] ?? null;

    $hata_msg = '';
    if (is_array($hata_raw)) {
        // Yapı: {'ErrorModel': [{'HataKodu': x, 'Mesaj': '...'}]}
        $errors = $hata_raw['ErrorModel'] ?? $hata_raw['errorModel'] ?? $hata_raw;
        if (is_array($errors)) {
            $msgs = [];
            foreach ($errors as $e) {
                if (is_array($e)) {
                    $msgs[] = $e['Mesaj'] ?? $e['HataAciklamasi'] ?? $e['HataMesaji'] ?? json_encode($e, JSON_UNESCAPED_UNICODE);
                } elseif (is_string($e) && $e !== '') {
                    $msgs[] = $e;
                }
            }
            $hata_msg = implode('; ', array_filter($msgs));
        }
    } elseif (is_string($hata_raw) && $hata_raw !== '') {
        $hata_msg = $hata_raw;
    }

    // IslemKodu varsa explicit kontrol; yoksa hata mesajı yoksa başarılı say
    if ($islem_kodu !== '') {
        $ok = ($islem_kodu === 'GTBWSRV0000001');
    } else {
        $ok = ($hata_msg === '');
    }
    return [
        'ok'         => $ok,
        'islem_kodu' => $islem_kodu,
        'message'    => $ok ? '' : ($hata_msg ?: ('HKS hata kodu: ' . ($islem_kodu ?: 'bilinmiyor'))),
    ];
}

// PHP 8.0 uyumlu liste (indexed array) kontrolü.
function hks_is_list(array $a): bool {
    if (function_exists('array_is_list')) return array_is_list($a);
    if ($a === []) return true;
    return array_keys($a) === range(0, count($a) - 1);
}

/**
 * HKS "Sonuc" wrapper'ından gerçek DTO kayıt listesini çıkarır.
 *
 * HKS yanıt yapısı iç içedir ve eski parser bunun yalnızca ilk katmanını alıyordu:
 *   Sonuc { HataKodu, Mesaj, <Koleksiyon> { <DTO>[] } }
 *     örn: Sonuc.Urunler.UrunDTO[]            → [UrunDTO, UrunDTO, ...]
 *          Sonuc.Bildirimler.BildirimDTO[]
 *          Sonuc.ReferansKunyeler.ReferansKunyeDTO[]
 *
 * Bu metod wrapper object'lerini gevşek şekilde aşağı iner; ilk DTO dizisine
 * ulaşana kadar tek-çocuklu sarmallayıcılara girer. Yalnızca skaler alan kalırsa
 * node'un kendisini tek kayıt sayar (düz tekil yanıtlar için).
 * Tek kayıt object, çok kayıt array gelebilir (SOAP_SINGLE_ELEMENT_ARRAYS değişkenliği).
 */
function hks_extract_records(mixed $node, int $depth = 0): array {
    if ($node === null) return [];

    // Doğrudan DTO listesi
    if (is_array($node)) {
        if (hks_is_list($node)) return $node;
        $node = (object)$node; // assoc → object gibi davran
    }
    if (!is_object($node)) return [];

    $meta = [
        'HataKodu', 'Mesaj', 'HataKodlari', 'HataMesaji', 'IslemKodu',
        'ToplamKayitSayisi', 'ToplamKayit', 'SayfaNo', 'SayfaBoyutu', 'SayfaSayisi',
    ];

    // Meta dışı, dolu alanları topla
    $payload = [];
    foreach ((array)$node as $key => $val) {
        if (in_array($key, $meta, true)) continue;
        if ($val === null || $val === '') continue;
        $payload[$key] = $val;
    }
    if (empty($payload)) return [];

    // 1) Doğrudan DTO listesi taşıyan alan (Urunler.UrunDTO[] gibi)
    foreach ($payload as $val) {
        if (is_array($val) && hks_is_list($val) && $val !== []) {
            return $val;
        }
    }
    // 2) İç içe wrapper — sonsuz döngüye girmeden makul derinliğe in
    if ($depth < 6) {
        foreach ($payload as $val) {
            if (is_object($val) || (is_array($val) && !hks_is_list($val))) {
                $deeper = hks_extract_records($val, $depth + 1);
                if (!empty($deeper)) return $deeper;
            }
        }
    }
    // 3) Yalnızca skaler alanlar → bu node tek bir kayıttır
    foreach ($payload as $val) {
        if (!is_array($val) && !is_object($val)) {
            return [(object)$node];
        }
    }
    return [];
}

/**
 * HKS response'u kategorize eder:
 * A = SOAP teknik hata (ok:false, SoapFault)
 * B = HKS işlem hatası (IslemKodu != 0001)
 * C = Başarılı ama boş liste
 * D = Response path hatası (IslemKodu tamam, liste alanı bulunamadı)
 * OK = Normal başarılı
 */
function hks_diagnose_response(array $service_result): array {
    // A — SOAP teknik hata
    if (!$service_result['ok']) {
        return [
            'category' => 'A',
            'ui_message' => 'HKS servisine ulaşılamadı.',
            'detail'   => $service_result['message'] ?? 'SOAP hatası',
            'ok'       => false,
        ];
    }

    $data = $service_result['data'] ?? [];

    // İlk elemanı kontrol et
    $first = !empty($data[0]) ? (array)$data[0] : null;
    $islem_kodu = $first ? ($first['IslemKodu'] ?? $first['islemKodu'] ?? '') : '';

    // B — HKS işlem hatası
    if ($islem_kodu !== '' && $islem_kodu !== 'GTBWSRV0000001') {
        $norm = hks_normalize_response($data);
        return [
            'category' => 'B',
            'ui_message' => 'HKS işlemi başarısız: ' . ($norm['message'] ?: 'Hata kodu: ' . $islem_kodu),
            'detail'   => $norm['message'],
            'islem_kodu' => $islem_kodu,
            'ok'       => false,
        ];
    }

    // IslemKodu başarılı — liste çıkar
    $list = $data;
    if ($islem_kodu === 'GTBWSRV0000001' && $first !== null) {
        $extracted = $first['Sonuc'] ?? $first['sonuc'] ?? $first['Liste'] ?? $first['liste'] ?? null;
        if ($extracted !== null) {
            // Sonuc.<Koleksiyon>.<DTO>[] iç içe yapısına in (örn. Sonuc.Urunler.UrunDTO[])
            $list = hks_extract_records($extracted);
        } elseif (count($data) === 1) {
            // D — IslemKodu tamam ama liste alanı bulunamadı
            $has_list_fields = false;
            foreach (['Kod','kod','ID','id','Ad','ad','Adi','adi'] as $f) {
                if (isset($first[$f])) { $has_list_fields = true; break; }
            }
            if (!$has_list_fields) {
                return [
                    'category' => 'D',
                    'ui_message' => 'HKS cevabı beklenenden farklı. Teknik logu kontrol edin.',
                    'detail'   => 'IslemKodu başarılı ama liste alanı (Sonuc/Liste) bulunamadı.',
                    'raw'      => $first,
                    'ok'       => false,
                ];
            }
            $list = $data;
        }
    }

    // C — Başarılı ama boş
    if (empty($list)) {
        return [
            'category' => 'C',
            'ui_message' => 'HKS sorgusu başarılı ama bu parametrelerle kayıt bulunamadı.',
            'detail'   => 'IslemKodu: ' . ($islem_kodu ?: 'yok') . ', liste boş.',
            'ok'       => true,
            'data'     => [],
        ];
    }

    return [
        'category' => 'OK',
        'ui_message' => '',
        'ok'       => true,
        'data'     => $list,
    ];
}

