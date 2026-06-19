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

    // Her zaman zorunlu alanlar
    $required = [
        'notification_type' => 'Bildirim türü',
        'sifat'             => 'Sıfat',
        'firma'             => 'Firma',
        'urun'              => 'Ürün',
        'urun_cinsi'        => 'Ürün cinsi',
        'miktar'            => 'Miktar',
        'birim'             => 'Birim',
        'depo'              => 'Depo / şube',
        'il'                => 'İl',
        'ilce'              => 'İlçe',
        'sevk_tarihi'       => 'Sevk tarihi',
        'arac_plaka'        => 'Araç plaka',
        'alici_ad'          => 'Alıcı adı',
        'alici_tc_vkn'      => 'Alıcı TC / VKN / vergi no',
    ];
    foreach ($required as $field => $label) {
        if (trim((string)($n[$field] ?? '')) === '') {
            $errors[] = $label . ' zorunludur.';
        }
    }

    // Miktar > 0
    if (trim((string)($n['miktar'] ?? '')) !== '' && (float)$n['miktar'] <= 0) {
        $errors[] = 'Miktar sıfırdan büyük olmalıdır.';
    }

    // Sevk tarihi format kontrolü (Y-m-d)
    $sevk = trim((string)($n['sevk_tarihi'] ?? ''));
    if ($sevk !== '') {
        $d = DateTime::createFromFormat('Y-m-d', $sevk);
        if (!$d || $d->format('Y-m-d') !== $sevk) {
            $errors[] = 'Sevk tarihi formatı geçersiz (YYYY-AA-GG olmalı).';
        }
    }

    // Plaka format kontrolü — boş/geçersiz değil
    $plaka = hks_plate((string)($n['arac_plaka'] ?? ''));
    if ($plaka !== '') {
        $first = explode('/', $plaka)[0];
        if (!preg_match('/^[0-9]{2}[A-ZÇĞİÖŞÜ]{1,3}[0-9]{2,4}$/u', $first)) {
            $errors[] = 'Araç plaka formatı geçersiz görünüyor (örn. 34ABC123).';
        }
    }

    // TC/VKN uzunluk kontrolü (alıcı)
    $alici_no = preg_replace('/\D/', '', (string)($n['alici_tc_vkn'] ?? ''));
    if ($alici_no !== '' && !in_array(strlen($alici_no), [10, 11], true)) {
        $errors[] = 'Alıcı TC (11) veya VKN (10) hane uzunluğunda olmalı.';
    }

    // Üretici: ikisinden biri girildiyse her ikisi de zorunlu
    $up_ad  = trim((string)($n['uretici_ad'] ?? ''));
    $up_vkn = trim((string)($n['uretici_tc_vkn'] ?? ''));
    if (($up_ad !== '' || $up_vkn !== '') && ($up_ad === '' || $up_vkn === '')) {
        $errors[] = 'Üretici bilgisi girilecekse hem üretici adı hem TC/VKN zorunludur.';
    }

    // Çıkış yönünde referans künye zorunlu
    if (($n['direction'] ?? '') === 'cikis' && trim((string)($n['reference_kunye_no'] ?? '')) === '') {
        $errors[] = 'Çıkış bildiriminde referans künye no zorunludur.';
    }

    return $errors;
}

// HKS'ye gidecek operasyonel payload önizlemesi — şifre/secret İÇERMEZ
function hks_notification_payload_preview(array $n): array {
    return [
        'Bildirim Türü'  => $n['notification_type'] ?? '',
        'Sıfat'          => $n['sifat'] ?? '',
        'Yön'            => $n['direction'] ?? '',
        'Firma'          => $n['firma'] ?? '',
        'Ürün'           => $n['urun'] ?? '',
        'Ürün Cinsi'     => $n['urun_cinsi'] ?? '',
        'Miktar'         => ($n['miktar'] ?? '') !== '' ? number_format((float)$n['miktar'], 3, ',', '.') . ' ' . ($n['birim'] ?? '') : '',
        'Depo / Şube'    => $n['depo'] ?? '',
        'İl / İlçe'      => trim(($n['il'] ?? '') . ' / ' . ($n['ilce'] ?? ''), ' /'),
        'Belde'          => $n['belde'] ?? '',
        'Üretici'        => trim(($n['uretici_ad'] ?? '') . ' ' . ($n['uretici_tc_vkn'] ? '(' . $n['uretici_tc_vkn'] . ')' : '')),
        'Alıcı'          => trim(($n['alici_ad'] ?? '') . ' ' . ($n['alici_tc_vkn'] ? '(' . $n['alici_tc_vkn'] . ')' : '')),
        'Araç Plaka'     => $n['arac_plaka'] ?? '',
        'Sevk Tarihi'    => $n['sevk_tarihi'] ?? '',
        'Belge No'       => $n['belge_no'] ?? '',
        'Referans Künye' => $n['reference_kunye_no'] ?? '',
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

// ISO tarihi (YYYY-MM-DD) Türkiye formatına (DD.MM.YYYY) çevirir.
// HTML date input'u ISO döndürür; HKS API DD.MM.YYYY bekler.
function hks_date_to_tr(string $iso): string {
    $d = DateTime::createFromFormat('Y-m-d', trim($iso));
    return $d ? $d->format('d.m.Y') : trim($iso);
}
