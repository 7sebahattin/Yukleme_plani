<?php
declare(strict_types=1);
/**
 * v550 — Muhasebe menüsü sayfalarının rol yetkileri (TEK kaynak). Migration YOK, tohum YOK.
 *
 * nav_muhasebe_gruplari() sayfaları eskiden `is_admin()` ile kapalıydı ve Roller ekranında yetkisiz
 * "yalnız yönetici" satırlarıydı. Artık Roller'den verilebilir; VARSAYILAN KAPALI:
 *  - Anahtarlar kurulum tohumuna ($all_p/$pdks_p) GİRMEZ, hiçbir eski izinden (stok.read, reports.*,
 *    records.*, users.admin …) TÜRETİLMEZ/genişletilmez; ROLE_CATALOG_VERSION artırılmaz. Yalnız Roller
 *    ekranında açıkça işaretlenirse role_permissions'a yazılır. Yönetici (is_admin) her zaman geçer.
 *  - Sayfa: `muhasebe.<sayfa_slug>.read` (slug = dosya adı, .php'siz).
 *  - İşlem (çalışan modüller cari/kasa/cek_senet/stok/fatura): `muhasebe.<grup>.create|edit|delete`.
 *  - palet_fisleri: kendi `muhasebe.palet_fisleri.create|edit|delete`.
 *  - Yer tutucu / yönlendirme sayfaları yalnız `read` alır.
 * KAPSAM DIŞI (hep is_admin): Şube/Depo Tanımları (v566: Admin Konsolu grubu kalktı), audit, backup, attendance_fis.
 */

/** İşlem anahtarı taşıyan çalışan modül grupları. */
function muhasebe_rol_islemli_gruplar(): array { return ['cari', 'kasa', 'cek_senet', 'stok', 'fatura', 'genel_muhasebe', 'raporlar_grup']; }

/**
 * v575 (sahip kararı): HER hesap kendi şifresini değiştirebilmeli. Bu sayfalar rol/yetki istemez — oturumu açık her kullanıcıya
 * açıktır; Roller ekranında satırı YOKTUR (verilecek/alınacak bir yetki yok). Menü, mobil "Diğer" ve ana sayfa kartı
 * aynı kapıdan (muhasebe_sayfa_can) beslendiği için her role görünür. Yalnız KENDİ hesabı üzerinde işlem yapan sayfalar buraya girer.
 */
function muhasebe_herkese_acik_sayfalar(): array { return ['sifre_degistirme']; }

/**
 * Geçiş dönemi 302 yönlendirme sayfaları → hedef sayfa eşlemesi (B-25).
 * Bu slug'lardan birine muhasebe.<slug>.read izni verilen rol, yönlendirildiği hedef
 * sayfada da (yalnız okuma) kabul edilir; menüde görünüp 403 alma çelişkisi oluşmaz.
 * @return array<string,string>
 */
function muhasebe_yonlendirme_hedefleri(): array {
    return [
        'arac_yukleme_fisleri' => 'records.php',
        'mal_kabul_fisleri'    => 'kantar.php',
        'raporlar_listeler'    => 'reports.php',
    ];
}

/** Rol kapsamındaki (yönetici sayfaları HARİÇ) grup anahtarları → [etiket, [slug => başlık]]. */
function muhasebe_rol_gruplari(): array {
    static $out = null;
    if ($out !== null) return $out;
    if (!function_exists('nav_muhasebe_gruplari')) return [];   // helpers.php yüklenmeden önce önbelleğe boş yazma
    $out = [];
    foreach (nav_muhasebe_gruplari() as $grup => [$etiket, , $sayfalar]) {
        $liste = [];
        foreach ($sayfalar as $dosya => [$baslik]) {
            $slug = pathinfo((string)$dosya, PATHINFO_FILENAME);
            if (in_array($slug, muhasebe_herkese_acik_sayfalar(), true)) continue;   // Roller'de satırı yok
            $liste[$slug] = (string)$baslik;
        }
        $out[(string)$grup] = ['etiket' => (string)$etiket, 'sayfalar' => $liste];
    }
    return $out;
}

/** Sayfa slug'ı → grup anahtarı (yalnız rol kapsamındaki sayfalar). */
function muhasebe_sayfa_grubu(string $slug): ?string {
    foreach (muhasebe_rol_gruplari() as $grup => $g) if (isset($g['sayfalar'][$slug])) return $grup;
    return null;
}

/** Sayfa girişi: yönetici ya da `muhasebe.<slug>.read`. Kapsam dışı / bilinmeyen slug yalnız yöneticiye açıktır (fail-closed). */
function muhasebe_sayfa_can(string $slug): bool {
    if (in_array($slug, muhasebe_herkese_acik_sayfalar(), true)) return function_exists('current_user') && current_user() !== null;   // v575: oturumu açık herkes
    if (function_exists('is_admin') && is_admin()) return true;
    if (!function_exists('can') || muhasebe_sayfa_grubu($slug) === null) return false;
    return can('muhasebe.' . $slug . '.read');
}

/** Grupta en az bir sayfa görülebiliyor mu (yönetici: her zaman). */
function muhasebe_grup_okuma_can(string $grup): bool {
    if (function_exists('is_admin') && is_admin()) return true;
    $g = muhasebe_rol_gruplari()[$grup] ?? null;
    if ($g === null || !function_exists('can')) return false;
    foreach ($g['sayfalar'] as $slug => $_) if (can('muhasebe.' . $slug . '.read')) return true;
    return false;
}

/** Grup işlemi: yönetici ya da (grupta en az bir sayfa Gör + `muhasebe.<grup>.<create|edit|delete>`). */
function muhasebe_grup_can(string $grup, string $eylem): bool {
    if (function_exists('is_admin') && is_admin()) return true;
    if (!in_array($grup, muhasebe_rol_islemli_gruplar(), true) || !in_array($eylem, ['create', 'edit', 'delete'], true)) return false;
    return muhasebe_grup_okuma_can($grup) && function_exists('can') && can('muhasebe.' . $grup . '.' . $eylem);
}

/**
 * Modül çekirdeklerinin (cari_can / stok_can / fatura_can / kasa_can / ceksenet_can) TEK ortak kapısı.
 * read: gruptaki herhangi bir sayfa Gör · create|edit|delete: grup işlemi ·
 * write: ikisi BİRDEN (create ve edit) — belirsiz işlemler için fail-closed · tanim: yalnız ARAYÜZ ipucu (Tanımlar
 * sayfasında yazma düğmeleri; create/edit/delete'ten biri) — uçlar kesin eylemi ister ·
 * admin: yalnız is_admin() (şema/yönetici işlemleri; bugün hiçbir uç istemez, migrate.php zaten yönetici-only).
 * Tanımlar sayfasındaki düz tanım düzenleme (tanim_kaydet/tanim_durum/tanim_sil) 'admin' DEĞİL,
 * create/edit/delete'e eşlenir (grup içi sade veri girişi; şema değiştirmez).
 */
function muhasebe_modul_can(string $grup, string $eylem): bool {
    if (!in_array($eylem, ['read', 'write', 'delete', 'admin', 'create', 'edit', 'tanim'], true)) return false;
    if (function_exists('is_admin') && is_admin()) return true;
    switch ($eylem) {
        case 'admin':  return false;
        case 'read':   return muhasebe_grup_okuma_can($grup);
        case 'write':  return muhasebe_grup_can($grup, 'create') && muhasebe_grup_can($grup, 'edit');
        case 'tanim':  return muhasebe_grup_can($grup, 'create') || muhasebe_grup_can($grup, 'edit') || muhasebe_grup_can($grup, 'delete');
        default:       return muhasebe_grup_can($grup, $eylem);
    }
}

/** palet_fisleri: read (sayfa) · create · edit · delete — kendi anahtarları. */
function muhasebe_palet_can(string $eylem): bool {
    if (!in_array($eylem, ['read', 'create', 'edit', 'delete'], true)) return false;
    if (function_exists('is_admin') && is_admin()) return true;
    if (!function_exists('can')) return false;
    return can('muhasebe.palet_fisleri.read') && ($eylem === 'read' || can('muhasebe.palet_fisleri.' . $eylem));
}

function muhasebe_rol_islem_etiketleri(): array { return ['create' => 'ekle', 'edit' => 'düzenle', 'delete' => 'sil']; }

/** Katalog etiketleri (permission_catalog → role_matrix_catalog_additions): her yeni anahtar Türkçe etiketlidir. */
function muhasebe_rol_katalog(): array {
    $out = [];
    $islem = muhasebe_rol_islem_etiketleri();
    foreach (muhasebe_rol_gruplari() as $grup => $g) {
        foreach ($g['sayfalar'] as $slug => $baslik) $out['muhasebe.' . $slug . '.read'] = $baslik . ' sayfasını görüntüle';
        if (in_array($grup, muhasebe_rol_islemli_gruplar(), true)) foreach ($islem as $e => $ad) $out['muhasebe.' . $grup . '.' . $e] = $g['etiket'] . ' · ' . $ad;
    }
    foreach ($islem as $e => $ad) $out['muhasebe.palet_fisleri.' . $e] = 'Palet Fişleri · ' . $ad;
    return $out;
}
