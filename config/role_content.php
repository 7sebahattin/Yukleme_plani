<?php
declare(strict_types=1);
// =============================================================================
// config/role_content.php — İÇERİK YETKİLERİ (v507): sayfa İÇİNDEKİ kart ve düğmeler.
// =============================================================================
// Sayfa/kart Gör-Ekle-Düzenle-Sil anahtarları role_permission_matrix.php'dedir; burası
// onların ALTINDA tek tek kart ("kart") ve düğmeleri ("dugme") açıp kapatır.
//
// KURALLAR
//  • Anahtar biçimi `ui.<sayfa>.<oge>`; sayfa = matristeki satır anahtarı.
//  • Kapı: icerik_can('siparis.mail_al'). İçerik anahtarı mevcut kapının YERİNE GEÇMEZ,
//    ona EKLENİR (örn. Mailden al = İşlem Bekleyen › Ekle + ui.siparis.mail_al).
//  • `gerek`: bu içeriğin anlamlı olması için gereken izin(ler) — HERHANGİ biri yeter.
//    Roller ekranı ön koşul kapalıyken öğeyi soluk gösterir.
//  • `salt`: "Yalnız görüntüleme" hazır ayarında açık kalır (veri değiştirmeyen içerik).
//  • `since`: öğenin kataloğa girdiği sürüm. ROLE_CATALOG_VERSION'dan ESKİ sürümde
//    kaydedilmiş rol, sonradan eklenen öğeyi `gerek` izni varsa KENDİLİĞİNDEN alır
//    (role_content_expand). Böylece yeni içerik anahtarı eklemek hiçbir rolü bozmaz ve
//    Roller ekranında yeniden kayıt gerektirmez. Yeni öğe eklerken: ROLE_CATALOG_VERSION'ı
//    bir artır ve öğeye o sürümü `since` olarak yaz.
//  • Bilinmeyen anahtar FAIL-CLOSED (false). role_content_smoke kullanılan her anahtarın
//    katalogda olduğunu denetler.
// =============================================================================

const ROLE_CATALOG_VERSION = 2;   // 0 = eski rol · 1 = v505 kart işaretleyicisi · 2 = v507 içerik anahtarları

/** Katalog: satır anahtarı => [ grup başlığı => [ 'ui.x.y' => [ad, tur, gerek, salt, since, not] ] ]. */
function role_content_catalog(): array {
    static $c = null;
    if ($c !== null) return $c;
    $oglKart = static fn(string $a): array => ["siparis.ogl_acik.$a", "siparis.ogl_evrak.$a", "siparis.ogl_kapali.$a"];
    $oglYaz  = array_merge($oglKart('create'), $oglKart('edit'));
    // i(ad, tur, gerek, salt, not)
    $i = static fn(string $ad, string $tur, $gerek, bool $salt = false, string $not = '', int $since = 2): array
        => ['ad' => $ad, 'tur' => $tur, 'gerek' => (array)$gerek, 'salt' => $salt, 'not' => $not, 'since' => $since];
    $c = [
        'dashboard' => [
            'Ana Sayfa' => [
                'ui.dashboard.rozetler' => $i('Kartlardaki sayaç rozetleri ve özet satırları', 'kart', 'dashboard.read', true, 'Kapalıyken kartlar görünür ama sayılar/tutarlar gösterilmez.'),
            ],
        ],
        'siparis' => [
            'Liste sayfası' => [
                'ui.siparis.kpi'         => $i('Özet çipleri (Açık · Araç bekleyen · Yolda · PKL · Mail · Evrak)', 'kart', 'siparis.ogl.read', true),
                'ui.siparis.yetim'       => $i('Eşleşmemiş / hatalı belgeler kartı', 'kart', 'siparis.ogl.read', true),
                'ui.siparis.yazdir'      => $i('Yazdır · PDF Gönder · WP Resim', 'dugme', 'siparis.ogl.read', true),
                'ui.siparis.wp_paylas'   => $i('WP Paylaş (sevkiyat kartı görseli)', 'dugme', 'siparis.ogl.read', true),
                'ui.siparis.zip'         => $i('Evrak takımı ZIP indir', 'dugme', 'siparis.ogl.read', true),
                'ui.siparis.belge_indir' => $i('Belge önizleme ve indirme', 'dugme', 'siparis.ogl.read', true, 'PO, Loading List, Price Confirmation, PKL ve Invoice dosyaları.'),
                'ui.siparis.mail_al'     => $i('Mailden al (Sipariş · Lojistik · Ödeme sorgula)', 'dugme', 'siparis.ogl_acik.create', false, 'Mail Merkezi okuma yetkisi de gerekir.'),
                'ui.siparis.belge_yukle' => $i('Dosyadan belge yükle', 'dugme', $oglYaz),
                'ui.siparis.elle_ac'     => $i('Elle sevkiyat aç', 'dugme', 'siparis.ogl_acik.create'),
                'ui.siparis.cari'        => $i('Cari Döküm ve Fiyat Dengeleme', 'dugme', 'reports.export', true, 'Fiyat ve tutar içerir; Raporlar › Dışa aktar yetkisi de gerekir.'),
                'ui.siparis.odeme'       => $i('Ödemeler sayfası', 'dugme', 'reports.export', true, 'Ödeme girmek/iptal etmek için ayrıca Hesap › Ödendi işaretle gerekir.'),
                'ui.siparis.ayarlar'     => $i('Sipariş ayarları (sayaçlar, profiller, mail)', 'dugme', array_merge($oglYaz, $oglKart('delete'))),
            ],
            'Sevkiyat sayfası — kartlar' => [
                'ui.siparis.sev_bilgi'    => $i('Sevkiyat bilgileri', 'kart', 'siparis.ogl.read', true),
                'ui.siparis.sev_kalemler' => $i('Kalemler (kalibre bazında)', 'kart', 'siparis.ogl.read', true),
                'ui.siparis.sev_belgeler' => $i('Belgeler', 'kart', 'siparis.ogl.read', true),
                'ui.siparis.sev_pkl'      => $i('Packing List (PKL)', 'kart', 'siparis.ogl.read', true),
                'ui.siparis.sev_invoice'  => $i('Invoice (OGL)', 'kart', 'siparis.ogl.read', true),
                'ui.siparis.sev_debit'    => $i('OGL debit note', 'kart', 'reports.export', true),
                'ui.siparis.sev_catisma'  => $i('Çatışmalar', 'kart', 'siparis.ogl.read', true),
                'ui.siparis.sev_beyan'    => $i('Gümrük beyanı', 'kart', 'siparis.ogl.read', true),
                'ui.siparis.sev_gecmis'   => $i('Geçmiş', 'kart', 'siparis.ogl.read', true),
            ],
            'Sevkiyat sayfası — işlemler' => [
                'ui.siparis.alan_duzenle'    => $i('Alan düzelt · not · elle kilidi kaldır', 'dugme', $oglKart('edit')),
                'ui.siparis.kalem_duzenle'   => $i('Kalem ekle / düzenle', 'dugme', $oglKart('edit')),
                'ui.siparis.catisma_coz'     => $i('Çatışma çöz', 'dugme', $oglKart('edit')),
                'ui.siparis.durum_degistir'  => $i('Durumu ilerlet / değiştir', 'dugme', $oglKart('edit'), false, 'Hazırlık, Yola çıktı ve “Durumu değiştir”.'),
                'ui.siparis.durum_mail'      => $i('“Mail gönderildi” işaretle', 'dugme', $oglKart('edit')),
                'ui.siparis.durum_evrak'     => $i('“Evrak takımı gönderildi” işaretle', 'dugme', $oglKart('edit')),
                'ui.siparis.durum_iptal'     => $i('Sevkiyatı iptal et', 'dugme', $oglKart('edit')),
                'ui.siparis.pkl_olustur'     => $i('PKL oluştur / düzenle', 'dugme', $oglKart('edit')),
                'ui.siparis.invoice_olustur' => $i('Invoice oluştur / düzenle', 'dugme', $oglKart('edit')),
                'ui.siparis.belge_isle'      => $i('Belgeyi yeniden işle / sevkiyata bağla', 'dugme', $oglKart('edit')),
                'ui.siparis.belge_sil'       => $i('Belge sil', 'dugme', $oglKart('delete')),
                'ui.siparis.sev_sil'         => $i('Sevkiyatı sil', 'dugme', $oglKart('delete')),
                'ui.siparis.mail_taslak'     => $i('Mail taslağı (Outlook / mail programı)', 'dugme', 'siparis.ogl.read', true),
            ],
        ],
        'beyan' => [
            'Liste sayfası' => [
                'ui.beyan.yazdir'         => $i('Bekleyenler: Yazdır · PDF Gönder', 'dugme', 'beyan.bek.read', true),
                'ui.beyan.taslak_olustur' => $i('Taslak Oluştur (beyanı kopyala)', 'dugme', 'beyan.create'),
                'ui.beyan.toplu_bildirim' => $i('Toplu HKS taslağı oluştur', 'dugme', 'beyan.notify'),
            ],
            'Beyan sayfası — kartlar' => [
                'ui.beyan.kart_urun'     => $i('Ürün Bilgileri', 'kart', 'beyan.read', true),
                'ui.beyan.kart_numune'   => $i('Numune Bilgileri', 'kart', 'beyan.read', true),
                'ui.beyan.kart_not'      => $i('Not', 'kart', 'beyan.read', true),
                'ui.beyan.kart_baglanti' => $i('Yükleme Planı Bağlantısı', 'kart', 'beyan.read', true),
            ],
            'Beyan sayfası — işlemler' => [
                'ui.beyan.wa_kopyala'   => $i('WhatsApp metnini kopyala', 'dugme', 'beyan.read', true),
                'ui.beyan.plaka_hizli'  => $i('Hızlı plaka girişi', 'dugme', 'beyan.hks.write'),
                'ui.beyan.hks_eslestir' => $i('HKS bilgilerini eşleştir', 'dugme', 'beyan.notify'),
                'ui.beyan.hks_taslak'   => $i('HKS taslağı oluştur', 'dugme', 'beyan.notify'),
                'ui.beyan.tekrar_aktif' => $i('Taslağı silinen beyanı tekrar aktif et', 'dugme', 'beyan.notify'),
            ],
            'Beyan formu' => [
                'ui.beyan.metin_ayristir' => $i('Metni ayrıştır (yapıştırılan metinden doldur)', 'dugme', 'beyan.source.write'),
                'ui.beyan.oneri_yonet'    => $i('Öneri listesine “+ ekle” ve öneriden sil', 'dugme', ['beyan.create', 'beyan.bek.edit', 'beyan.kap.edit']),
            ],
        ],
        'hks' => [
            'Ekranlar' => [
                'ui.hks.ekr_bildirim'      => $i('Bildirim Yap', 'kart', 'hks.create'),
                'ui.hks.ekr_taslaklar'     => $i('Taslaklar', 'kart', 'hks.read', true),
                'ui.hks.ekr_gonderilenler' => $i('Gönderilenler', 'kart', 'hks.read', true),
                'ui.hks.ekr_stok'          => $i('Stok sorgulama', 'kart', 'hks.read', true),
                'ui.hks.ekr_sorgu'         => $i('Bildirim sorgulama', 'kart', 'hks.read', true),
                'ui.hks.ekr_gelen'         => $i('Bana yapılan bildirimler', 'kart', 'hks.read', true),
                'ui.hks.ekr_etiket'        => $i('Bildirim etiket basım', 'kart', 'hks.read', true),
                'ui.hks.ekr_kunye'         => $i('Çoklu künye basım', 'kart', 'hks.read', true),
                'ui.hks.gonderim_raporu'   => $i('Gönderim Raporu', 'kart', 'hks.read', true),
            ],
            'İşlemler' => [
                'ui.hks.gonder'         => $i('HKS’ye bildirim gönder', 'dugme', 'hks.create', false, 'Geri alınamaz ve rüsum doğurur. Kapalıyken kullanıcı taslak hazırlar ama gönderemez.'),
                'ui.hks.taslak_kopyala' => $i('Taslağı kopyala / başka firmaya kopyala', 'dugme', 'hks.create'),
                'ui.hks.tekrar_gonder'  => $i('Gönderileni tekrar gönder (formu doldur)', 'dugme', 'hks.create'),
                'ui.hks.kisi_havuzu'    => $i('Kişi Havuzu’nu yönet', 'dugme', ['hks.create', 'hks.edit', 'hks.delete']),
                'ui.hks.liste_guncelle' => $i('HKS listelerini güncelle', 'dugme', 'hks.read', true),
            ],
        ],
    ];
    return $c;
}

/** Düz görünüm: 'ui.x.y' => öğe + ['sayfa','grup']. */
function role_content_flat(): array {
    static $f = null;
    if ($f !== null) return $f;
    $f = [];
    foreach (role_content_catalog() as $sayfa => $gruplar) {
        foreach ($gruplar as $grup => $ogeler) {
            foreach ($ogeler as $key => $oge) $f[$key] = $oge + ['sayfa' => $sayfa, 'grup' => $grup];
        }
    }
    return $f;
}

/** Rolün kaydedildiği katalog sürümü (işaretleyicilerden). */
function role_catalog_version_of(array $permissions): int {
    $v = in_array('role.sections.detailed', $permissions, true) ? 1 : 0;
    foreach ($permissions as $p) {
        if (is_string($p) && preg_match('/^role\.catalog\.(\d+)$/D', $p, $m)) $v = max($v, (int)$m[1]);
    }
    return $v;
}

/**
 * Rol başına genişletme (role_matrix_expand_permissions'ın SON adımı): rolün sürümünden SONRA
 * kataloğa giren içerik öğeleri, ön koşul izni varsa verilir. Güncel sürümde kaydedilmiş rolde
 * yalnız açıkça seçilenler kalır. İdempotent.
 */
function role_content_expand(array $permissions): array {
    $v = role_catalog_version_of($permissions);
    if ($v >= ROLE_CATALOG_VERSION) return $permissions;
    $var = array_flip(array_filter($permissions, 'is_string'));
    foreach (role_content_flat() as $key => $oge) {
        if ((int)$oge['since'] <= $v || isset($var[$key])) continue;
        foreach ($oge['gerek'] as $g) {
            if (isset($var[$g])) { $permissions[] = $key; break; }
        }
    }
    return $permissions;
}

/** Katalog etiketleri (permission_catalog'a eklenir; POST doğrulaması bu listeyi kullanır). */
function role_content_catalog_labels(): array {
    $out = ['role.catalog.' . ROLE_CATALOG_VERSION => 'İçerik (kart / düğme) bazlı yetkilendirme sürümü'];
    foreach (role_content_flat() as $key => $oge) $out[$key] = $oge['ad'];
    return $out;
}

/**
 * İçerik kapısı. $kisa = 'siparis.mail_al' (ui. öneki olmadan). Admin her zaman geçer.
 * Mevcut sayfa/işlem kapısının YERİNE kullanılmaz; onunla birlikte (&&) kullanılır.
 */
function icerik_can(string $kisa): bool {
    $key = 'ui.' . $kisa;
    if (!isset(role_content_flat()[$key])) return false;                       // bilinmeyen anahtar: fail-closed
    if (function_exists('is_admin') && is_admin()) return true;
    return function_exists('can') && can($key);
}

/** Uç kapısı: içerik kapalıysa 403 (forbidden() AJAX-aware). */
function icerik_kapi(string $kisa, string $mesaj = ''): void {
    if (icerik_can($kisa)) return;
    $ad = role_content_flat()['ui.' . $kisa]['ad'] ?? $kisa;
    forbidden($mesaj !== '' ? $mesaj : 'Bu içerik için yetkiniz yok: ' . $ad);
}
