# Deploy Workflow — "Canlıya Al" Talimatı

**Proje:** Yükleme Planı — `nuverna.derspros.com.tr`
**Kapsam:** Kullanıcı "canlıya al" / "yayına al" / "deploy et" dediğinde Claude'un izleyeceği adımlar.
**Not:** Bu dosya Claude'un kendi hafızası için yazıldı (CLAUDE.md'den referans verilir). Kullanıcı bu süreci değiştirirse burayı güncelle.

---

## Özet (tek cümle)

Claude'un GitHub dışında hiçbir erişimi yok — yapabildiği tek şey **feature branch'i `main`'e PR ile merge etmek**. Sunucuya asıl deploy (dosyaların canlıya yansıması) **ayrı ve manuel bir adım**, Claude bunu tetikleyemez.

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

## 2. Claude'un YAPAMADIĞI kısım — gerçek deploy

`main` branch'e merge olmak sunucudaki dosyaları **otomatik güncellemiyor**. Repo içinde `scripts/deploy.php` diye bir script var ama:

- **Yalnızca CLI'dan (SSH ile) elle çalıştırılabiliyor** — `PHP_SAPI !== 'cli'` kontrolü web erişimini 403'lüyor.
- Web üzerinden de `scripts/.htaccess` ile ekstra kapalı.
- Repo içinde hiçbir GitHub Action / webhook / cron bulunmuyor (kontrol edildi — `git grep -i webhook/hmac/cron` sonuç vermedi, `.github/workflows` yok).

Yani main'e her merge sonrası, sunucuya SSH erişimi olan biri şunu çalıştırmalı:

```bash
php scripts/deploy.php 7sebahattin/Yukleme_plani main
```

Bu script GitHub'daki `main` branch'inin ZIP'ini indirip sunucudaki dosyaların üzerine yazar (`config/db.php` içindeki DB_HOST/NAME/USER/PASS bilgilerini koruyarak — `smart_merge_db()`).

**Claude'un bu adımı tetiklemesi için hiçbir yolu yok** (SSH tool'u, hosting paneli erişimi, webhook endpoint'i — hiçbiri mevcut değil bu oturumda). Bu yüzden her "canlıya al" işleminden sonra kullanıcıya açıkça hatırlat:

> main'e merge edildi ✓ — ama sunucuya yansıması için SSH'dan `php scripts/deploy.php 7sebahattin/Yukleme_plani main` çalıştırılması lazım, bunu ben yapamıyorum.

---

## 3. Cache uyarısı

`sw.js` (service worker) `CACHE_NAME` versiyonunu her `assets/app.js` veya `assets/style.css` değişikliğinde artırmayı unutma (CLAUDE.md ana kuralı). Deploy sonrası kullanıcı hâlâ eski davranış görüyorsa önce SW cache'i sorgula, sonra deploy'un gerçekten çalışıp çalışmadığını sorgula.

---

## 4. Gelecekte otomatik deploy kurulursa

Eğer bir gün gerçek bir webhook/Action kurulursa (push → main → otomatik `deploy.php` tetikleme), bu dosyayı güncelle:
- "main'e merge = canlıya çıktı" hâline gelir, adım 2'deki uyarı kalkar.
- Webhook endpoint'i ve HMAC doğrulaması nasıl kurulduysa buraya not düş.

Şu an (bu dosyanın yazıldığı tarih itibarıyla) böyle bir mekanizma **yok**.
