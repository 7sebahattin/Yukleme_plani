<?php
declare(strict_types=1);
// =========================================================
// config/kullanici_ayar.php — "Kullanıcı Ayarları" menü grubunun ortak mantığı (v574)
//
//   • ka_sifre_degistir()  — Şifre Değiştirme sayfası (oturumdaki kullanıcının KENDİ şifresi)
//   • ka_profil_kaydet()   — Kullanıcı Tercihleri › Profil (Ad Soyad + e-posta)
//   • ka_tablo_tercihleri() — Kullanıcı Tercihleri › Tablo düzenleri (kullanici_tercihleri anahtarları)
//
// DEĞİŞMEZLER:
//  - Kullanıcı kimliği HER ZAMAN çağıranın (current_user()) verdiği id'dir; istemciden id/kullanıcı adı ALINMAZ.
//  - Şifre hiçbir yere yazılmaz: audit, error_log, exception mesajı, dönüş değeri.
//  - Mevcut şifre config/sifre_onay.php ile doğrulanır → 5 hatalı denemede 60 sn bekleme (bağlam 'sifre_degistir').
//  - Şifre değişince BU oturum dışındaki tüm oturumlar kapatılır (çalınmış/unutulmuş cihaz düşer).
//  - Ekran kilidi PIN'i (hks_kv ekran_pin_*) bağımsızdır; buraya dokunulmaz.
//  - Fonksiyonlar çıktı basmaz, exit etmez (test edilebilir). Şema değişikliği YOK (users tablosu mevcut kolonlar).
// =========================================================
require_once __DIR__ . '/sifre_onay.php';

const KA_SIFRE_MIN = 8;
const KA_SIFRE_MAX_BAYT = 72;   // bcrypt 72 bayttan sonrasını sessizce keser; kullanıcıyı yanıltmamak için reddedilir
const KA_AD_MAX = 100;
const KA_EPOSTA_MAX = 180;

/**
 * Biçim denetimi (veritabanına dokunmaz). Hata varsa Türkçe mesaj, yoksa null.
 */
function ka_sifre_bicim_hata(string $yeni, string $yeni2, string $kullaniciAdi = ''): ?string
{
    if ($yeni === '') return 'Yeni şifre boş olamaz.';
    if (strlen($yeni) < KA_SIFRE_MIN) return 'Yeni şifre en az ' . KA_SIFRE_MIN . ' karakter olmalı.';
    if (strlen($yeni) > KA_SIFRE_MAX_BAYT) return 'Yeni şifre en fazla ' . KA_SIFRE_MAX_BAYT . ' bayt (yaklaşık 70 karakter) olabilir.';
    if ($yeni !== $yeni2) return 'Yeni şifre ile tekrarı eşleşmiyor.';
    if ($kullaniciAdi !== '' && mb_strtolower($yeni, 'UTF-8') === mb_strtolower($kullaniciAdi, 'UTF-8')) return 'Şifre kullanıcı adınızla aynı olamaz.';
    return null;
}

/**
 * Kendi şifresini değiştirir.
 * @param string $mevcutToken oturum çerezi (asya_session) — bu oturum açık KALIR, diğerleri kapanır
 * @return array{ok:bool, mesaj:string, kapatilan?:int}
 */
function ka_sifre_degistir(PDO $pdo, int $uid, string $mevcut, string $yeni, string $yeni2, string $mevcutToken = ''): array
{
    if ($uid < 1) return ['ok' => false, 'mesaj' => 'Oturum bulunamadı.'];
    if ($mevcut === '') return ['ok' => false, 'mesaj' => 'Mevcut şifrenizi girin.'];

    try {
        $st = $pdo->prepare('SELECT username FROM users WHERE id = ?');
        $st->execute([$uid]);
        $kullaniciAdi = (string)($st->fetchColumn() ?: '');
    } catch (Throwable $e) {
        error_log('[ka_sifre] kullanıcı okunamadı: ' . get_class($e));
        return ['ok' => false, 'mesaj' => 'Şifre değiştirilemedi (teknik hata). Tekrar deneyin.'];
    }

    // Biçim hataları deneme hakkı HARCAMAZ; yalnız yanlış mevcut şifre sayılır.
    $bicim = ka_sifre_bicim_hata($yeni, $yeni2, $kullaniciAdi);
    if ($bicim !== null) return ['ok' => false, 'mesaj' => $bicim];

    $onay = sifre_onay_dogrula($pdo, $uid, $mevcut, 'sifre_degistir');
    if (!$onay['ok']) return ['ok' => false, 'mesaj' => $onay['mesaj']];

    if (hash_equals($mevcut, $yeni)) return ['ok' => false, 'mesaj' => 'Yeni şifre mevcut şifrenizle aynı olamaz.'];

    try {
        $hash = password_hash($yeni, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $uid]);
        // Bu oturum hariç hepsi kapanır. Token boşsa (çerez okunamadı) HİÇBİR oturum silinmez — yanlışlıkla kendini atma yok.
        $kapatilan = 0;
        if ($mevcutToken !== '') {
            $d = $pdo->prepare('DELETE FROM user_sessions WHERE user_id = ? AND token <> ?');
            $d->execute([$uid, $mevcutToken]);
            $kapatilan = $d->rowCount();
        }
    } catch (Throwable $e) {
        error_log('[ka_sifre] güncelleme hatası: ' . get_class($e));   // yalnız sınıf adı: ne şifre ne SQL
        return ['ok' => false, 'mesaj' => 'Şifre değiştirilemedi (teknik hata). Tekrar deneyin.'];
    }

    try {
        audit_log_event('password_change', 'users', $uid, null, ['diger_oturum_kapatildi' => $kapatilan]);   // şifre/hash YOK
    } catch (Throwable $e) {
        error_log('[ka_sifre] audit yazılamadı: ' . get_class($e));
    }
    return ['ok' => true, 'kapatilan' => $kapatilan,
            'mesaj' => 'Şifreniz değiştirildi.' . ($kapatilan > 0 ? " Diğer cihazlardaki $kapatilan oturum kapatıldı." : '')];
}

/** Ad Soyad / e-posta temizliği: kontrol karakterleri ve baştaki/sondaki boşluk atılır. */
function ka_metin_temizle(string $s): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
    return trim(preg_replace('/\s{2,}/u', ' ', $s) ?? '');
}

/**
 * Kendi profilini (Ad Soyad + e-posta) günceller. Kullanıcı adı, rol, aktiflik, depo BURADAN DEĞİŞMEZ.
 * @return array{ok:bool, mesaj:string}
 */
function ka_profil_kaydet(PDO $pdo, int $uid, string $ad, string $eposta): array
{
    if ($uid < 1) return ['ok' => false, 'mesaj' => 'Oturum bulunamadı.'];
    $ad = ka_metin_temizle($ad);
    $eposta = ka_metin_temizle($eposta);
    if (mb_strlen($ad, 'UTF-8') > KA_AD_MAX) return ['ok' => false, 'mesaj' => 'Ad Soyad en fazla ' . KA_AD_MAX . ' karakter olabilir.'];
    if ($eposta !== '') {
        if (strlen($eposta) > KA_EPOSTA_MAX || !filter_var($eposta, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'mesaj' => 'Geçerli bir e-posta adresi girin (ya da boş bırakın).'];
    }
    try {
        $st = $pdo->prepare('SELECT display_name, email FROM users WHERE id = ?');
        $st->execute([$uid]);
        $eski = $st->fetch();
        if (!$eski) return ['ok' => false, 'mesaj' => 'Kullanıcı bulunamadı.'];
        if ($eposta !== '') {
            $c = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND email <> '' AND id <> ?");
            $c->execute([$eposta, $uid]);
            if ((int)$c->fetchColumn() > 0) return ['ok' => false, 'mesaj' => 'Bu e-posta adresi başka bir kullanıcıda kayıtlı.'];
        }
        if ((string)($eski['display_name'] ?? '') === $ad && (string)($eski['email'] ?? '') === $eposta) return ['ok' => true, 'mesaj' => 'Değişiklik yok.'];
        $pdo->prepare('UPDATE users SET display_name = ?, email = ? WHERE id = ?')->execute([$ad, $eposta, $uid]);
    } catch (Throwable $e) {
        error_log('[ka_profil] ' . get_class($e));
        return ['ok' => false, 'mesaj' => 'Profil kaydedilemedi (teknik hata). Tekrar deneyin.'];
    }
    try {
        audit_log_event('update', 'users', $uid, ['display_name' => (string)($eski['display_name'] ?? ''), 'email' => (string)($eski['email'] ?? '')],
            ['display_name' => $ad, 'email' => $eposta, 'kaynak' => 'kullanici_tercihleri']);
    } catch (Throwable $e) {
        error_log('[ka_profil] audit yazılamadı: ' . get_class($e));
    }
    return ['ok' => true, 'mesaj' => 'Profil bilgileriniz kaydedildi.'];
}

/**
 * Kullanıcı Tercihleri sayfasında listelenen tablo düzenleri:
 * kullanici_tercihleri anahtarı => [görünen ad, tarayıcı yedek (localStorage) anahtarı].
 * Yeni kaydedilebilir tablo düzeni eklenince (kt_dogrula beyaz listesi) BURAYA da eklenir.
 */
function ka_tablo_tercihleri(): array
{
    return [
        'palet_fisleri.tablo' => ['Palet Fişleri', 'asya.palet-fisleri.tablo.v2'],
        'kantar.tablo'        => ['Mal Kabul (Kantar)', 'kantar.tablo.v1'],
        'records.tablo'       => ['Yükleme Planları', 'asya.records.tablo.v1'],
    ];
}
