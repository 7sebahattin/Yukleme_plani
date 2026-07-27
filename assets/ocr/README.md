# assets/ocr — Tarayıcı içi OCR dosyaları

`tarama.php` sunucuda `tesseract` çalıştıramadığında (paylaşımlı hostingde
`shell_exec` kapalı ve binary yok) OCR'ı kullanıcının tarayıcısında yapar.
Buradaki dosyalar bunun içindir; **CDN kullanılmaz**, hepsi kendi sitemizden servis edilir.

## İçerik ve kaynak

| Dosya | Boyut | npm paketi |
|---|---|---|
| `tesseract.min.js` | 0,1 MB | `tesseract.js@7.0.0` (dist) |
| `worker.min.js` | 0,1 MB | `tesseract.js@7.0.0` (dist) |
| `core/tesseract-core-simd-lstm.wasm.js` | 3,7 MB | `tesseract.js-core@7.0.0` |
| `core/tesseract-core-lstm.wasm.js` | 3,7 MB | `tesseract.js-core@7.0.0` |
| `lang/tur.traineddata.gz` | 2,0 MB | `@tesseract.js-data/tur` (4.0.0_best_int) |
| `lang/eng.traineddata.gz` | 2,8 MB | `@tesseract.js-data/eng` (4.0.0_best_int) |

Kullanıcı bunların hepsini indirmez: tarayıcı tek bir core dosyası (SIMD desteği
varsa `simd-lstm`, yoksa `lstm`) ve yalnız seçilen dilin verisini indirir —
Türkçe için ~5 MB. Sonraki taramalarda tarayıcı/PWA önbelleğinden gelir.

## Neden `.wasm.js` (gömülü) varyant?

Ayrı `tesseract-core-*.js` + `.wasm` ikilisi denendi ve **çalışmıyor**: worker bir
blob URL'den koştuğu için emscripten kardeş `.wasm` dosyasını çözemiyor
(`Aborted(SyntaxError: Failed to execute 'open' on 'XMLHttpRequest': Invalid URL)`).
Gömülü `.wasm.js` varyantı kendi kendine yeter — 1 MB daha büyük ama sorunsuz.
Bu yüzden `corePath` **dizin değil, tam dosya yolu** verilir; dizin verilirse
kütüphane `relaxedsimd` varyantını da isteyebilir (o dosya burada yok).

## Güncelleme

```bash
npm install tesseract.js tesseract.js-core @tesseract.js-data/tur @tesseract.js-data/eng
cp node_modules/tesseract.js/dist/{tesseract.min.js,worker.min.js}   assets/ocr/
cp node_modules/tesseract.js-core/tesseract-core-{simd-,}lstm.wasm.js assets/ocr/core/
cp node_modules/@tesseract.js-data/tur/4.0.0_best_int/tur.traineddata.gz assets/ocr/lang/
cp node_modules/@tesseract.js-data/eng/4.0.0_best_int/eng.traineddata.gz assets/ocr/lang/
```

Sürüm değişirse `sw.js` önbellek versiyonunu artır (tarayıcılar eski motoru sunmasın).

`.htaccess` dosyası `.gz` dil dosyalarının ham ikili sunulmasını ve uzun süreli
önbelleklenmesini sağlar. Not: tesseract.js gzip'i sihirli baytlardan (1F 8B)
tanıyıp gerekiyorsa kendisi açtığı için, Apache `Content-Encoding: gzip` eklese
bile tarama çalışır — bu senaryo test edildi. Kural yine de aktarımı
öngörülebilir tutmak ve önbellek başlıklarını vermek için duruyor.
