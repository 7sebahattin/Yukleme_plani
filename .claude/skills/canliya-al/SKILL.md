---
name: canliya-al
description: >-
  "Canlıya al" akışı — ön kontroller (sürüm, testler, migration kuralı), PR açma ve main'e merge. main'e merge iki siteye (canlı + test) otomatik deploy eder. Yalnız kullanıcı /canliya-al yazınca ya da açıkça "canlıya al" dediğinde.
argument-hint: "[PR başlığı (isteğe bağlı)]"
disable-model-invocation: true
---

# Canlıya al

Kaynak: `docs/DEPLOY_WORKFLOW.md` (önce onu oku; çelişki varsa o kazanır).

**`main`'e merge = canlıya çıktı.** GitHub push webhook'ları
`https://asya.scai.tr/deploy.php` (canlı) ve `https://nuverna.derspros.com.tr/deploy.php`
(test) adreslerini tetikler; merge İKİ siteye birden iner (~4 dk). Claude'un işi
yalnız PR açıp merge etmektir. **Kullanıcıdan SSH'dan / sunucuda bir şey
çalıştırmasını İSTEME.** Kökteki sunucu `deploy.php` repoda yoktur ve deploy onu
bilerek atlar — depoya kök `deploy.php` ekleme.

Bu proje için kullanıcının "canlıya al" demesi = PR açma + merge izni. Bu onay kod
incelemesinin yerine GEÇMEZ; aşağıdaki ön kontroller geçmeden merge etme.

## 1. Ön kontroller (hepsi geçmeli)

1. **Çalışma ağacı:** `git status` — commit'lenmemiş, istenmeyen dosya yok mu
   (`_test_*.html` gibi test çıktıları commit'lenmez). Dal `main` olmamalı.
2. **Sürüm:** `git fetch origin main`, sonra
   - `sw.js` `CACHE_NAME` sayısı = `config/helpers.php` `APP_SURUM` sayısı = CLAUDE.md
     `**SW Cache:**` satırı.
   - `git diff --name-only origin/main...HEAD` içinde `assets/`, `sw.js` ya da
     `manifest.json` değiştiyse sürüm origin/main'dekinden BÜYÜK olmalı (aksi hâlde SW
     eski önbelleği sunar). Yalnız PHP değiştiyse de artırmak önerilir — kullanıcı
     deploy'u sidebar'daki `APP_SURUM` ile doğrular.
   - Değilse `/surum-artir` becerisini uygula, değişikliği commit'le.
3. **Testler:** `bash scripts/tum_testler.sh` (tüm PHP testleri) GEÇMELİ; ayrıca
   değişikliğe ilgili `.js` (Playwright) testleri `/ilgili-testler` becerisiyle çalıştır.
   Değişen her PHP dosyasına `php -l`, her JS dosyasına `node --check`.
   Kalan test varsa DUR ve kullanıcıya raporla — "canlıya al" kalan testi geçersiz kılmaz.
   (`scripts/tum_testler.sh` yoksa ilgili testleri `php scripts/x.php` ile tek tek çalıştır.)
4. **Migration kuralı:** değişiklik DB şemasına dokunuyorsa (yeni tablo/kolon,
   `ALTER`, migrate.php kartı) kullanıcı bu iş için açıkça **"GO veriyorum"** demiş
   olmalı. Yoksa DUR ve sor. Merge şemayı kendiliğinden kurmaz: migrate.php kartı
   gerekiyorsa kullanıcıya canlıda VE test sitesinde ayrı ayrı basması gerektiğini söyle
   (bu bir tarayıcı işlemidir, SSH değil).
5. **Tombstone:** kaldırılan web sayfası `git rm` EDİLMEZ, 410 tombstone olur
   (deploy dosya silmez).

## 2. Commit + push

Feature dalında commit (mesaj oturumun gerektirdiği atıf satırlarıyla biter) ve
`git push -u origin <dal>`.

## 3. PR aç ve merge et

PR şablonu varsa (`.github/pull_request_template.md` ya da
`.github/PULL_REQUEST_TEMPLATE/`) gövdeyi ona göre kur.

```
mcp__github__create_pull_request(
  owner="7sebahattin", repo="Yukleme_plani",
  head="<feature-dalı>", base="main",
  title="<başlık>",
  body="<ne değişti · neden · çalıştırılan testler ve sonuçları · migration var/yok>"
)
```

Başlık: kullanıcının verdiği argüman → "$ARGUMENTS" (boşsa kısa Türkçe başlık + sürüm
yaz, ör. `v442: …`). Gövde oturumun gerektirdiği atıf satırlarıyla biter.

Merge'den HEMEN önce: `git fetch origin main` — origin/main bu PR açıkken ilerlediyse
ve oradaki `APP_SURUM` bu daldakine eşit/büyükse `/surum-artir` ile bir üste al,
commit + push et, sonra merge et. Çakışma (conflict) varsa kendin çözüp testleri
yeniden çalıştır; çözemiyorsan kullanıcıya raporla.

```
mcp__github__merge_pull_request(
  owner="7sebahattin", repo="Yukleme_plani",
  pullNumber=<PR no>, merge_method="merge"
)
```

Merge YALNIZ kullanıcı açıkça "canlıya al" istediyse yapılır; aksi hâlde PR açık
bırakılır ve bağlantısı verilir.

## 4. Sonra

Kullanıcıya bildir: PR numarası/bağlantısı, merge başarılı, yeni sürüm. Ekle:
"Birkaç dakika sonra hard refresh (Ctrl+Shift+R) yapın; sidebar altındaki
`APP_SURUM` yeni sürümü göstermeli." (Mobilde `index.php` sayfa sonunda yazar.)

Yansımadıysa sırayla: SW önbelleği (hard refresh, sürüm hâlâ eski mi) → GitHub
Settings → Webhooks → Recent Deliveries (yanıt `Deploy tamamlandı: …` mı) → site
kökündeki `deploy.log`. Bunları kullanıcıyla birlikte incele; SSH komutu isteme.
