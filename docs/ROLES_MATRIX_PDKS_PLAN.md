# PDKS yetki ayrıştırma planı

31 Ekim 2026. Mevcut attendance.* paketleri sayfa görüntüleme ve yazma işlemlerini
birlikte açıyor. Yeni ayrıntılı roller için gerçek sayfa/işlem kapıları eklenir;
eski roller aynı paketlerden rol başına genişletilir. Canlı veri/migration yok.

## Rota ve işlem haritası

| Sayfa grubu | GET/Gör rotaları | Gerçek yazma sınıfları |
| --- | --- | --- |
| Personel | personel.php, personel_form.php, personel_foto.php | save_employee: id yok Ekle / id var Düzenle; delete_employee: Sil; fotoğraf aynı kaydetme işlemi |
| Personel kartları | personel_kartlar.php; personel_form.php kart bölümü; pdks_nfc_test.php | kart_ata Ekle; kart_durum/kart_degistir Düzenle |
| Çavuşlar | cavuslar.php, cavus_form.php | save: id yok Ekle / id var Düzenle; sure_kaydet/aktiflik Düzenle; fiziksel silme yok |
| İşçi kart havuzu | isci_kartlari.php | tanim_toplu_kaydet Ekle; kart_duzenle/kart_durum/kart_tanim/kart_tanim_bitir/tanim_kaldir_toplu Düzenle; tanimsiz_sil Sil |
| İşçi tipleri | isci_tipleri.php | aktiflik Düzenle; yeni tip/silme yok |
| Personel tarama | giris_cikis.php | ajax=kaydet Ekle |
| Günlük tarama | gunluk_isci_giris_cikis.php | oturum/kaydet/tanimli_giris Ekle; ortak_cikis/ortak_mesailer/kapat Düzenle; kapanis_kontrol yan etkisiz okuma |
| Günlük rapor/puantaj | gunluk_isci_puantaj.php, gunluk_isci_puantaj_detay.php, iki yazdırma rotası | Geçmiş ekleme/düzeltme/iptal/toplu uçlar ve servis mutasyonları mevcut yalnız yönetici sınırını korur; standart rollere CRUD icat edilmez |
| Fiş Excel | gunluk_fis_yukle.php | Tüm rota yalnız yönetici kalır |
| Çavuş fiyatları | cavus_fiyatlari.php | cavus_ucret/servis_ucret/oran ekle Ekle; cavus_yontem/saat_duzelt/baslangic_geri Düzenle; silme yok |
| Hakediş | cavus_hakedis.php, cavus_hakedis_detay.php, iki yazdırma rotası | hesapla ve duzeltme_ekle Ekle; yeniden hesapla/finalize/yeniden_ac Düzenle; duzeltme_ters_kayit Sil (ters kayıt). Mevcut finansal kesişim ve admin özel sınırları korunur |
| Cari/ekstre | cavus_cari.php, cavus_ekstre.php, yazdırmaları | Salt okunur; CSV/XLSX ayrıca export kapısında |
| Ödemeler | cavus_odeme.php, cavus_odeme_yazdir.php | odeme_kaydet Ekle; iptal Sil; düzenleme yok |
| Yönetim raporları | raporlar.php, cavus_donem_raporu.php, cavus_toplu_dokum.php, cavus_toplu_dokum_detay.php, yazdırmaları | Salt okunur; mevcut finansal alt bölüm ve export kapıları korunur |
| Mesai değerlendirme | mesai_degerlendirme.php, yazdırması | tekli_kaydet/toplu_kaydet Düzenle; mevcut hakediş+fiyat kesişimi korunur |
| Manuel çıkış | manuel_cikis.php | POST Düzenle; mevcut hakediş+fiyat kesişimi korunur |
| Giriş tanımlı havuzu | ters_kart_havuz.php | ekle Ekle; islem cikar/sifirla Sil (üyelik bitirme); aktar/kart durumu/saat Düzenle |
| Sabah sayımı | ters_kart_sayim.php | baslat Ekle; okut/karar/onayla/manuel_onayla Düzenle; geri_al Sil; onizle/manuel_onizle salt okunur |
| Tanımlı çıkış | ters_kart_cikis.php | ajax=cikis günlük tarama Düzenle; GET ortak çıkışa yönlendirme |
| Tanımlı mutabakat | ters_kart.php, ters_kart_mutabakat.php | Salt okunur hub/rapor |

## Güvenlik uygulaması

Yeni saf config/role_pdks_matrix.php rotayı ve gerçek action/ajax/form değerlerini
sınıflandırır. Bilinmeyen POST mutasyonu reddedilir. Mevcut require_pdks,
require_pdks_gunluk, require_pdks_hakedis, require_pdks_cari, require_pdks_rapor ve
pdks_ters_require girişlerinde merkezi kontrol çalışır. Böylece JSON ve toplu
include uçlarına da yazmadan önce kapı uygulanır. Admin-only eski motor koşulları
kaldırılmaz. Yeni izinler attendance.<sayfa>.<read/create/edit/delete> biçimindedir.
Marker olmayan eski rol paketleri bu izinlere dönüştürülür; marker varsa Gör yazma
yetkisine genişlemez. Desteklenmeyen CRUD hücresi — gösterilir.

Anonim yeni test dosyası saf sınıflandırıcı, eski paket genişlemesi, read-only
mutasyon reddi, her var olan CRUD ve bilinmeyen action/route fail-closed davranışını
doğrular. Testleri ana ajan sıralı çalıştırır.
