---
name: kullanici-ayarlari
description: Kullanıcı Ayarları menü grubu (sifre_degistirme.php, ajanda.php, kullanici_tercihleri.php, config/kullanici_ayar.php, config/ajanda_core.php, assets/kullanici_ayar.css/js): kendi şifresini değiştirme, kişisel ajanda, profil/tema/tablo düzeni tercihleri. Bu dosyalara dokunurken kullan.
---

# Kullanıcı Ayarları (v574)

Grup `nav_muhasebe_gruplari()['kullanici_konsolu']`; Hesap (`hesap.php`) sidebar'da aynı gruba eklenir. Üç sayfa v574'te yer tutucudan çıktı. Kapı: `muhasebe_sayfa_can('<slug>')` (Roller'den `muhasebe.<slug>.read`; **varsayılan yalnız yönetici** — sahip kararı beklemede: şifre değiştirme tüm kullanıcılara açılacaksa bu kapı/nav değişir).

**Değişmezler**
- Kullanıcı id'si HER ZAMAN `$auth_user['id']` (oturum); POST/GET'ten `user_id` ALINMAZ. Sayfalar kendi POST'unu işler (PRG: `set_flash` + `header('Location')` + `exit`), her POST `csrf_check`.
- **Şifre Değiştirme:** `ka_sifre_degistir()` — biçim hatası deneme hakkı harcamaz; mevcut şifre `sifre_onay_dogrula(…,'sifre_degistir')` ile (5 hata → 60 sn); min 8, en çok 72 bayt (bcrypt), kullanıcı adı/mevcut şifreyle aynı olamaz; bcrypt cost 12; **bu oturum dışındaki `user_sessions` silinir** (çerez boşsa hiçbiri silinmez); audit `password_change` (şifre/hash YOK). Ekran kilidi PIN'i (`hks_kv`) bağımsız, dokunulmaz. Şifre alanları geri basılmaz.
- **Ajanda:** KİŞİSEL — her sorgu `user_id = ?`; yönetici dahil kimse başkasınınkini görmez; depo filtresi YOK. Şema `ajanda_kayitlar` YALNIZ migrate.php › "Ajanda" kartı (`ajanda_migrate`); tablo yoksa sayfa kurulum notu gösterir. audit'e yalnız olay + kayıt id'si (başlık/not YAZILMAZ). Açık kayıt tavanı 500/kullanıcı. Sayfada ham SQL yok; yazma `ajanda_*` fonksiyonlarıyla. Tarih `Y-m-d` kesin, saat `SS:DD`.
- **Kullanıcı Tercihleri:** Profil (`ka_profil_kaydet`: yalnız Ad Soyad + e-posta, e-posta benzersiz), Görünüm (tema = `asyaTemaSec`, `localStorage asya_tema`, cihaz bazlı), Çalışma deposu (`depo_sec.php?next=REQUEST_URI` — `next` `/` ile başlamalı), Tablo düzenleri (`kt_sil`, yalnız `ka_tablo_tercihleri()` beyaz listesi; sunucu kaydı + `data-ka-yerel` tarayıcı yedeği), Güvenlik (PIN düğmesi `#btnPinDegistir`'i tıklatır). **Yeni kaydedilebilir tablo düzeni eklenince** `kt_dogrula` + `ka_tablo_tercihleri()` ikisine de ekle. Şema değişikliği YOK.
- CSS/JS sınıf öneki `ka-`; sınıf adında `ad`/`ads`/`banner`/`sponsor` parçası KULLANMA (v556). Mobil `<768px` kırılımı + `input[type=time]` için `min-width:0` (yoksa dar ekranda taşar). Koyu tema yalnız belirteçlerle.

**Test:** `php scripts/kullanici_ayar_smoke.php` (çekirdek + izolasyon + statik değişmezler) · `php scripts/nav_muhasebe_static_smoke.php` · `php scripts/migrate_panel_smoke.php` (kart sayısı sabit: 39). Görsel: `php scripts/kullanici_ayar_render.php <ajanda|ajanda_tamam|sifre|tercih|ajanda_kurulmamis> > _test.html` (gerçek PHP, SQLite; `../assets` yollarını `assets/`e çevirip aç).
