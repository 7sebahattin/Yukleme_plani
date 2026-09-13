# "Main'e merge = canlıya çıktı" nasıl kurulur

Başka bir ajana/projeye şu talimatı verebilirsin. Amaç: GitHub'daki `main`
branch'e her merge/push olduğunda, hosting'deki dosyalar OTOMATİK güncellensin
— SSH, CI/CD, GitHub Actions gerekmez. Sadece paylaşımlı/cPanel tipi hosting
+ PHP olan projeler için (VPS + Docker gibi ortamlarda daha iyi yollar var,
ama bu en az bağımlılıklı ve en hızlı kurulan yöntem).

## Nasıl çalışıyor (3 parça)

```
main'e push  →  GitHub "push" webhook  →  sunucudaki bir PHP dosyası (deploy.php)
                                                  ↓
                            main branch'in ZIP'i indirilir (GitHub'ın kendi
                            /archive/refs/heads/main.zip adresinden)
                                                  ↓
                            ZIP açılır, dosyalar site kökünün üzerine yazılır
                                                  ↓
                                              canlı güncellenmiş olur
```

Git, SSH, Actions YOK. Tek gereksinim: hosting'de PHP çalışıyor ve dış
dünyaya (GitHub'a) `curl`/`fopen` ile istek atabiliyor (çoğu paylaşımlı
hosting'de zaten açık).

## Kurulum — Adım 1: sunucuya deploy.php koy

Site kökünde (public_html/ veya eşdeğeri) `deploy.php` adında bir dosya
oluştur. Bu dosya git'e commit EDİLMEZ — sadece hosting dosya yöneticisinden
elle yüklenir, sürekli orada durur.

İçeriği (imza doğrulamalı, güvenli sürüm — aşağıdaki "Güvenlik" bölümüne bak):

```php
<?php
declare(strict_types=1);

// ── Bu üç sabiti doldur ──────────────────────────────────────────────
const DEPLOY_SECRET = 'BURAYA_UZUN_RASTGELE_BIR_DEGER';   // Adım 2'deki ile AYNI
const DEPLOY_REPO    = 'kullanici-adi/repo-adi';
const DEPLOY_BRANCH  = 'main';
// Deploy'un üzerine yazmayacağı dosyalar (bu dosyanın kendisi dahil)
const DEPLOY_PROTECTED = ['deploy.php', 'deploy.log', '.htaccess'];
// ──────────────────────────────────────────────────────────────────────

$work_dir = __DIR__;

function log_msg(string $msg): void {
    @file_put_contents(__DIR__ . '/deploy.log', '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}
function cikis(int $kod, string $mesaj): never {
    log_msg("[$kod] $mesaj");
    http_response_code($kod);
    header('Content-Type: text/plain; charset=utf-8');
    echo $mesaj . "\n";
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') cikis(405, 'Yalnızca POST.');
if (DEPLOY_SECRET === '') cikis(500, 'DEPLOY_SECRET boş.');

// İMZA DOĞRULAMA — bu olmadan adresi bilen herkes deploy tetikleyebilir.
$govde    = file_get_contents('php://input') ?: '';
$imza     = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$beklenen = 'sha256=' . hash_hmac('sha256', $govde, DEPLOY_SECRET);
if ($imza === '' || !hash_equals($beklenen, $imza)) cikis(401, 'İmza doğrulanamadı.');

$olay = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
if ($olay === 'ping') cikis(200, 'pong');
if ($olay !== 'push') cikis(200, "Olay '$olay' yoksayıldı.");

$payload = json_decode($govde, true);
$ref     = is_array($payload) ? (string)($payload['ref'] ?? '') : '';
if ($ref !== 'refs/heads/' . DEPLOY_BRANCH) cikis(200, "Branch '$ref' yoksayıldı.");

// Eşzamanlılık kilidi
$kilit = fopen(__DIR__ . '/.deploy.lock', 'c');
if (!flock($kilit, LOCK_EX | LOCK_NB)) cikis(409, 'Başka bir deploy sürüyor.');

// ZIP indir
$zip_url = 'https://github.com/' . DEPLOY_REPO . '/archive/refs/heads/' . DEPLOY_BRANCH . '.zip';
$ch = curl_init($zip_url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120]);
$zip_content = curl_exec($ch);
curl_close($ch);
if (!$zip_content) cikis(502, 'ZIP indirilemedi.');

$tmp = sys_get_temp_dir() . '/deploy_' . time() . '.zip';
file_put_contents($tmp, $zip_content);

$zip = new ZipArchive();
if ($zip->open($tmp) !== true) cikis(500, 'ZIP açılamadı.');

$prefix  = basename(DEPLOY_REPO) . '-' . DEPLOY_BRANCH . '/';
$updated = 0; $skipped = 0;

for ($i = 0; $i < $zip->numFiles; $i++) {
    $entry = $zip->getNameIndex($i);
    if (strpos($entry, $prefix) !== 0) continue;
    $rel = substr($entry, strlen($prefix));
    if ($rel === '' || strpos($rel, '..') !== false) continue;   // zip-slip koruması

    if (substr($rel, -1) === '/') { @mkdir($work_dir . '/' . rtrim($rel, '/'), 0755, true); continue; }
    if (in_array($rel, DEPLOY_PROTECTED, true)) { $skipped++; continue; }

    $dest = $work_dir . '/' . $rel;
    @mkdir(dirname($dest), 0755, true);
    if (file_put_contents($dest, $zip->getFromIndex($i)) !== false) $updated++;
}
$zip->close();
@unlink($tmp);
flock($kilit, LOCK_UN);

cikis(200, "Deploy tamamlandı: $updated güncellendi, $skipped atlandı");
```

**Not:** Eğer projede sunucuya özel bir config dosyası varsa (DB şifresi gibi
— bu dosya git'te placeholder değerle duruyor ama sunucuda gerçek değer var),
o dosyayı `DEPLOY_PROTECTED` listesine ekleme; onun yerine indirilen yeni
içerikle eskisini "birleştir" (yeni kod + eski gerçek şifre) — asıl projedeki
`smart_merge_db()` fonksiyonu buna örnek.

## Kurulum — Adım 2: GitHub webhook'u ekle

Repo → **Settings → Webhooks → Add webhook**:

| Alan | Değer |
|---|---|
| Payload URL | `https://senin-domainin.com/deploy.php` |
| Content type | `application/json` |
| **Secret** | Adım 1'deki `DEPLOY_SECRET` ile **aynı** uzun rastgele değer |
| Which events | **Just the push event** |

Kaydet. GitHub otomatik olarak bir `ping` isteği gönderir — **Recent
Deliveries**'te `200 pong` görürsen bağlantı çalışıyor demektir.

## Kurulum — Adım 3: dene

Herhangi bir dosyayı değiştirip `main`'e push et (veya bir PR'ı merge et).
Birkaç saniye içinde:
- GitHub → webhook → Recent Deliveries'te yeni bir teslimat, yanıtı
  `Deploy tamamlandı: N güncellendi`.
- Sunucudaki `deploy.log` dosyasında aynı satır.
- Site kökünde dosyaların değiştiği (timestamp'e bak).

## Güvenlik — neden Secret zorunlu

Secret olmadan bu uç nokta kimliksizdir: adresi öğrenen HERKES POST atıp
deploy tetikleyebilir, hatta (repo/branch payload'dan okunuyorsa) kendi
kodunu kurdurabilir. Yukarıdaki `deploy.php` bunu **X-Hub-Signature-256**
başlığıyla kapatıyor — GitHub her isteği bu Secret ile imzalıyor, sunucu
imzayı kendi Secret'ıyla yeniden hesaplayıp karşılaştırıyor.

**Secret'i ASLA git'e commit etme.** `deploy.php` sadece sunucuda durur,
repoya hiç girmez — repo'da olsaydı Secret'ın "sadece GitHub ile sunucunun
bildiği" özelliği bozulurdu. Secret'i üretmek için tarayıcı konsolunda:
```js
crypto.randomUUID()
```

## Bu projede (Yukleme_plani) durum

Bu tarif, bu depoda zaten **kurulu ve çalışıyor** (webhook + sunucudaki
`deploy.php`, doğrulandı 2026-09-13 — bkz. `docs/DEPLOY_WORKFLOW.md`).
`scripts/deploy_webhook.php` bu deponun kendi imza-doğrulamalı şablonu;
sunucudaki asıl `deploy.php`'nin yerini almak için hazırlandı ama henüz
sunucuya elle yüklenmedi (webhook'un Secret'ı hâlâ boş).
