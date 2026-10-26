---
name: surum-artir
description: Uygulama sürümünü (APP_SURUM + sw.js CACHE_NAME + CLAUDE.md "SW Cache" satırı) origin/main'in de üstünde bir sonraki numaraya çeker. assets/style.css, assets/app.js, sw.js ya da önbelleğe giren kritik bir dosya değiştiğinde, PR açmadan önce ve "sürümü artır" denince kullan.
argument-hint: "[hedef-numara (isteğe bağlı, ör. 445)]"
allowed-tools: Bash(git fetch *) Bash(git show *) Bash(grep *)
---

# Sürüm artırma (APP_SURUM = CACHE_NAME)

Sürüm ÜÇ yerde aynı sayıyı taşır; biri bile ayrışırsa Service Worker eski
önbelleği sunar ya da sidebar yanlış sürüm gösterir:

| Dosya | Satır |
|---|---|
| `sw.js` | `const CACHE_NAME = 'yukleme-plani-vNNN';` |
| `config/helpers.php` | `    define('APP_SURUM', 'vNNN');` |
| `CLAUDE.md` | `**SW Cache:** \`yukleme-plani-vNNN\`` |

**Neden origin/main'e bakılır:** aynı anda iki oturum/PR açıksa ikisi de yerel
sayıdan +1 alır ve AYNI numarayla çıkar (v291 iki ayrı özellikle iki kez çıktı,
v292'ye çekildi). Aynı numara = ikinci deploy'da SW önbelleği tazelenmez.
Kural: **yeni = max(origin/main, çalışma ağacı) + 1**.

## Adımlar

Kabuk değişkenleri Bash çağrıları arasında KORUNMAZ — 1–4. adımları tek bir
Bash çağrısında çalıştır (ya da her çağrıda değerleri yeniden oku).

1. Güncel main'i al ve üç değeri oku:

```bash
git fetch origin main
ORIGIN=$(git show origin/main:config/helpers.php | grep -oP "define\('APP_SURUM', 'v\K[0-9]+")
YEREL=$(grep -oP "define\('APP_SURUM', 'v\K[0-9]+" config/helpers.php)
SW=$(grep -oP "const CACHE_NAME = 'yukleme-plani-v\K[0-9]+" sw.js)
echo "origin/main=$ORIGIN yerel=$YEREL sw=$SW"
```

2. Yeni numarayı hesapla. Kullanıcının verdiği hedef: "$ARGUMENTS" — boş değilse ve
   hesaplanandan küçük değilse onu kullan; küçükse kullanıcıya söyle, hesaplananı kullan:

```bash
ESKI=$(( ORIGIN > YEREL ? ORIGIN : YEREL )); ESKI=$(( ESKI > SW ? ESKI : SW ))
YENI=$(( ESKI + 1 )); echo "yeni sürüm: v$YENI"
```

   Not: şu anki dalda sürüm ZATEN origin/main'in üstündeyse (bu dalda önceden
   artırılmışsa) yeniden artırmak gerekmez — kullanıcıya söyle, ancak istenirse artır.

3. Üç dosyayı güncelle (yalnız bu üç satır değişir):

```bash
sed -i -E "s/(const CACHE_NAME = 'yukleme-plani-v)[0-9]+(';)/\1$YENI\2/" sw.js
sed -i -E "s/(define\('APP_SURUM', 'v)[0-9]+('\);)/\1$YENI\2/" config/helpers.php
sed -i -E "s/(\*\*SW Cache:\*\* \`yukleme-plani-v)[0-9]+(\`)/\1$YENI\2/" CLAUDE.md
```

4. Doğrula — üç satır da aynı `v$YENI` olmalı, başka sürüm satırı kalmamalı:

```bash
grep -n "CACHE_NAME = 'yukleme-plani-v" sw.js
grep -n "define('APP_SURUM'" config/helpers.php
grep -n '\*\*SW Cache:\*\*' CLAUDE.md
php -l config/helpers.php
```

5. Kullanıcıya eski → yeni numarayı bildir. Commit/push yalnız istenirse.

## Uyarılar

- **Merge'den hemen önce yeniden bak:** PR açıkken başka bir PR main'e girmiş
  olabilir. `git fetch origin main` + adım 1'i tekrarla; origin/main'deki sayı
  bu daldakine eşit ya da büyükse bu beceriyi yeniden çalıştır (bir üste al).
- `v` önekini ve tırnakları koru; `APP_SURUM` başka yerde (ör. testlerde
  `v441` geçen açıklama metinleri) DEĞİŞTİRİLMEZ — yalnız yukarıdaki üç satır.
- CLAUDE.md'de başka bir sürüm geçmişi metnine (vNNN notları) dokunma.
