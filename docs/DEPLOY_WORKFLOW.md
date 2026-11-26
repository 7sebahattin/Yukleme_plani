# Deploy Workflow — "Canlıya Al" Talimatı

**Proje:** Yükleme Planı — canlı `asya.scai.tr` · test `test.scai.tr`
**Kapsam:** Kullanıcı "canlıya al" / "yayına al" / "deploy et" dediğinde Claude'un izleyeceği adımlar.
**Not:** Bu dosya Claude'un kendi hafızası için yazıldı (CLAUDE.md'den referans verilir). Kullanıcı bu süreci değiştirirse burayı güncelle.

---

## Özet (tek cümle)

**`main`'e merge = canlıya çıktı.** Repoda bir GitHub **webhook**'u var; `main`'e her push'ta
sunucudaki `https://asya.scai.tr/deploy.php` (canlı) ve `https://test.scai.tr/deploy.php`
(test) tetikleniyor ve dosyalar dakikalar
içinde canlıya iniyor. Claude'un yapması gereken tek şey PR açıp merge etmek; **kullanıcıdan
elle bir şey çalıştırmasını İSTEME.**

---

## 0. İki site

| | Canlı | Test |
|---|---|---|
| Adres | `https://asya.scai.tr` | `https://test.scai.tr` |
| cPanel klasörü | `/asya.scai.tr` | `/test.scai.tr` |
| Veritabanı | `derspros_asya` | `derspros_yukleme_plani` |

- Aynı cPanel hesabı (`talom.wlsrv.com`, kullanıcı `derspros`), aynı PHP 8.3.
- Canlı site, test klasörünün **kopyalanmasıyla** kuruldu; git'te olmayan `config/db.php`
  (sunucuya özgü DB bilgileri), `config/local.php` (`HKS_CRED_KEY`), kökteki `deploy.php`,
  `.htaccess` ve `uploads/` bu yüzden iki sitede de var. **Boş klasöre GitHub'dan kurulum
  YAPMA:** deploy `config/db.php`'yi repodaki `root`/boş şifre hâliyle yazar, site açılmaz.
- `scai.tr` DNS'i hosting.com.tr'de (`ns1/ns2.hosting.com.tr`); cPanel'e domain eklemek
  zone'u kendiliğinden açmadı, destek talebiyle açıldı. SSL: Let's Encrypt (cPanel SSL/TLS Wizard).
- Eski `nuverna.derspros.com.tr` artık kullanılmıyor; test ortamı `test.scai.tr` altındadır.
- Şu an iki site de `main`'i izler: merge edilen her şey test ve canlıya birlikte iner.

---

## 1. Claude'un yaptığı kısım — "main'e al"

Kullanıcı "canlıya al" dediğinde:

1. Üzerinde çalışılan feature branch'te commit + push yapılmış olmalı (`git commit`, `git push -u origin <branch>`).
2. GitHub MCP ile PR aç:
   ```
   mcp__github__create_pull_request(
     owner="7sebahattin", repo="Yukleme_plani",
     head="<feature-branch>", base="main",
     title="...", body="..."
   )
   ```
3. Aynı PR'ı hemen merge et:
   ```
   mcp__github__merge_pull_request(
     owner="7sebahattin", repo="Yukleme_plani",
     pullNumber=<PR no>, merge_method="merge"
   )
   ```
4. Kullanıcıya PR numarasını ve merge'in başarılı olduğunu bildir.

Bu adımlar kod kalitesi kontrolleri (php -l, ilgiliyse node --check, mantıksal doğrulama) YAPILDIKTAN SONRA uygulanır — "canlıya al" onayı kod incelemesinin yerine geçmez.

**Not:** Kullanıcı ayrıca "PR açma" demeden PR açmaya normalde izin yok (genel kural), ama bu proje için "canlıya al" talebi = açık PR + merge izni olarak sayılır (kullanıcı bunu 2026-08-23'te açıkça istedi: *"create pull request üzerinden yapıyorduk bu işi"*).

---

## 2. Otomatik kısım — webhook deploy

Merge'den sonra **kimsenin bir şey yapmasına gerek yok.** (Doğrulandı: 2026-09-13,
repo Settings → Webhooks ekranından.)

```
main'e merge  →  GitHub push webhook'ları  →  https://asya.scai.tr/deploy.php (canlı)
                                          →  https://test.scai.tr/deploy.php (test)
              →  main.zip indirilir    →  dosyaların üzerine yazılır  →  canlı
```

**Webhook ayarları (GitHub → Settings → Webhooks):**

| Alan | Değer |
|---|---|
| Payload URL | `https://asya.scai.tr/deploy.php` (canlı) · `https://test.scai.tr/deploy.php` (test) — iki ayrı webhook |
| Content type | `application/json` |
| Olay | Just the **push** event |
| SSL verification | Açık |
| Aktif | ✔ |
| **Secret** | `asya.scai.tr` için tanımlı (HMAC-SHA256) — bkz. aşağıdaki güvenlik notu |

**Ölçülen gecikme:** v216 13:20'de merge edildi, 13:24'te canlıda görüldü → ~4 dakika.

### Sunucudaki `deploy.php` repoda YOK

Webhook'un çağırdığı dosya sitenin kökünde duruyor ama **git'te değil**. Sebebi
`scripts/deploy.php` içindeki koruma listesi:

```php
$protected = ['deploy.php', 'deploy.log', '.htaccess', 'scripts/.htaccess'];
```

Bu yollar depo köküne göre; yani her deploy kökteki `deploy.php`'yi **bilerek atlıyor**
(kendini ezmesin diye). Dolayısıyla o dosyayı Claude ne görebilir ne de
güncelleyebilir — depoya kök `deploy.php` eklemek de işe yaramaz, deploy onu atlar.
Değiştirilmesi gerekirse **hosting dosya yöneticisinden elle** yüklenmeli.

`scripts/deploy.php` (repodaki, CLI-only olan) bundan AYRI bir dosyadır: yedek/elle
çalıştırma yolu. Webhook onu kullanmıyor.

### Doğrulama

- **GitHub tarafı:** Settings → Webhooks → hook → **Recent Deliveries**. Her merge'de bir
  teslimat olmalı; Response gövdesinde `Deploy tamamlandı: N güncellendi, M atlandı`.
- **Sunucu tarafı:** site kökündeki **`deploy.log`** — her çalışma tarih/saatle yazılıyor.
- **Kullanıcı tarafı:** hard refresh (Ctrl+Shift+R) → sidebar altındaki `APP_SURUM`.

### ⚠️ Güvenlik notu — Secret boş

Webhook'ta **Secret tanımlı değil**, yani istek GitHub'dan mı geliyor doğrulanmıyor.
Adresi bilen herkes `deploy.php`'ye POST atıp deploy tetikleyebilir. En hafif sonucu
kaynak tüketimi ve yarım yazılmış dosyalarla siteyi tutarsız bırakmak; **kökteki
`deploy.php` repo/branch bilgisini webhook payload'ından okuyup doğrulamıyorsa
uzaktan kod çalıştırmaya kadar gider** (o dosya görülemediği için hangisi olduğu
bilinmiyor — kullanıcıdan iste).

**Hazır çözüm: `scripts/deploy_webhook.php`** — imza doğrulamalı, kuruluma hazır
şablon. Orada durduğu yerde çalışmaz (scripts/ web'e kapalı); site köküne
`deploy.php` olarak **elle** kopyalanmalı, çünkü deploy kökteki `deploy.php`'yi atlar.

Kurulum sırası (deploy'u hiç kesmez):
1. GitHub → webhook → **Secret** alanına uzun rastgele bir değer yaz. *(Eski
   `deploy.php` imzayı kontrol etmediği için bu adım tek başına hiçbir şeyi bozmaz.)*
2. Sunucudaki mevcut `deploy.php`'yi `deploy.php.yedek` olarak yeniden adlandır.
3. `scripts/deploy_webhook.php` içeriğini köke `deploy.php` olarak kaydet.
4. İçindeki `DEPLOY_SECRET` sabitine 1. adımdaki değerin aynısını yaz.
5. GitHub → Recent Deliveries → son teslimat → **Redeliver**. Yanıt `200` +
   `Deploy tamamlandı: N güncellendi` olmalı. Olmazsa 2. adımdaki yedeği geri al.

Şablonun getirdikleri (yerel PHP sunucusunda gerçek isteklerle doğrulandı):

| Durum | Yanıt |
|---|---|
| GET | `405` |
| İmzasız / yanlış imzalı POST | `401` |
| `DEPLOY_SECRET` boş | `500` — **fail-closed**, kimliksiz deploy yapmaz |
| Doğru imza + `ping` | `200 pong` |
| Doğru imza + push, başka branch | `200` yoksayıldı |
| Doğru imza + push `main` | `200 Deploy tamamlandı: …` |

Ayrıca: repo/branch **sabit** (payload'dan okunmaz), eşzamanlı iki teslimat için
`flock` kilidi (`storage/.deploy.lock`, ikincisi `409`, dosyalar iç içe yazılmaz),
ZIP yolları için zip-slip koruması (`..` içeren girdi atlanır), `smart_merge_db()`
için `preg_replace_callback` (şifredeki `$1`/`\1` karakterleri bozulmaz), loglar
web'e kapalı `storage/deploy.log`'a yazılır ve deploy sonunda `opcache_reset()`
çağrılır. (`scripts/deploy.php` CLI betiğindeki `config/db.php` ezme hatası ve
zip-slip eksikliği de v582'de giderilmiştir.)

---

## 3. Cache uyarısı

`sw.js` (service worker) `CACHE_NAME` versiyonunu her `assets/app.js` veya `assets/style.css` değişikliğinde artırmayı unutma (CLAUDE.md ana kuralı). Deploy sonrası kullanıcı hâlâ eski davranış görüyorsa önce SW cache'i sorgula, sonra deploy'un gerçekten çalışıp çalışmadığını sorgula.

---

## 4. Deploy yansımadıysa sırayla bak

1. **SW cache** — hard refresh yapıldı mı? Sidebar'daki `APP_SURUM` hâlâ eski mi?
2. **Recent Deliveries** — teslimat kırmızı ✖ mi? Response ne diyor?
3. **`deploy.log`** — son satırın saati merge saatine yakın mı, hata satırı var mı?
4. Hiçbiri değilse yedek yol: SSH'dan `php scripts/deploy.php 7sebahattin/Yukleme_plani main`.

**Tarihçe:** Bu dosya bir dönem "otomatik deploy YOK, kullanıcı SSH'dan elle çalıştırmalı"
diyordu ve Claude her merge'den sonra kullanıcıya bunu boş yere hatırlatıyordu. Webhook
o notun yazılmasından sonra kurulmuş, doküman güncellenmemişti. 2026-09-13'te düzeltildi.

**Silinen dosyalar (v281):** Deploy repodan silinen dosyayı sunucudan SİLMEZ. Web'den erişilen bir PHP sayfasını
kaldırırken dosyayı silme; içeriğini 410 tombstone ile değiştir (DB/oturum yok, ≤15 satır, kalıcı ekrana link).
Taşınan belgelerin eski kopyaları sunucuda kalır — `docs/.htaccess` (v281) tüm `docs/` klasörünü web'e kapatır.
Kökteki `SYSTEM_AUDIT_REPORT.md` bu yüzden 3 satırlık yer tutucudur.
