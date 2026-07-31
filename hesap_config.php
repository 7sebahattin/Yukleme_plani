<?php
// hesap_config.php — Hesap modülü paylaşımlı yapılandırma
declare(strict_types=1);

require_once __DIR__ . '/config/hesap_calc.php';

// defined() koruması: testler yükleme dizinini kendi geçici klasörüne yönlendirebilsin
// (canlı uploads/hesap/ dizinine dokunmadan rapor/PDF testi koşturmak için).
defined('HESAP_UPLOAD_DIR')   || define('HESAP_UPLOAD_DIR', __DIR__ . '/uploads/hesap/');
defined('HESAP_MAX_FILE_SIZE')|| define('HESAP_MAX_FILE_SIZE', 10 * 1024 * 1024);
defined('HESAP_ALLOWED_EXT')  || define('HESAP_ALLOWED_EXT', ['jpg','jpeg','png','webp','pdf']);
defined('HESAP_ALLOWED_MIME') || define('HESAP_ALLOWED_MIME', ['image/jpeg','image/png','image/webp','application/pdf']);

function hesap_kategoriler(): array {
    return [
        'gelir'  => ['Hesabıma gelen para','Şirketten gelen ödeme','Müşteriden gelen ödeme','İade alınan para','Diğer gelir'],
        'gider'  => ['Yemek gideri','Şirket malzemesi','Yakıt','Otel / Konaklama','Kargo','Ofis gideri','Market / Temizlik','Personel gideri','Günlük harcama','Diğer gider'],
        'havale' => ['Gönderilen havale','Gelen havale','Kişiye verilen para','Kişiden alınan para'],
        'nakit'  => ['Elden ödeme','Elden alınan para','Nakit avans','Nakit harcama'],
    ];
}

function hesap_type_label(string $t): string {
    return match($t) { 'gelir'=>'Gelir','gider'=>'Gider','havale'=>'Havale','nakit'=>'Nakit',default=>$t };
}
function hesap_type_color(string $t): string {
    return match($t) { 'gelir'=>'#22c55e','gider'=>'#ef4444','havale'=>'#3b82f6','nakit'=>'#f97316',default=>'#64748b' };
}
function hesap_currency_sym(string $c): string {
    return match($c) { 'TRY'=>'₺','USD'=>'$','EUR'=>'€','AED'=>'AED',default=>$c };
}
function hesap_payment_label(string $p): string {
    return match($p) { 'nakit'=>'Nakit','banka'=>'Banka','kredi_karti'=>'Kredi Kartı','havale'=>'Havale','sirket_karti'=>'Şirket Kartı','sahsi'=>'Şahsi',default=>$p };
}
function fmt_para(float $v, string $cur='TRY'): string {
    return number_format($v,2,',','.') . ' ' . hesap_currency_sym($cur);
}
/** Modüle özel CSS — render_header()'dan sonra çağrılır (maliyet.css emsali). */
function hesap_assets(): void {
    $v = @filemtime(__DIR__ . '/assets/hesap.css') ?: time();
    echo '<link rel="stylesheet" href="assets/hesap.css?v=' . $v . '">' . "\n";
    // CSRF'yi JS'e taşı — hesap.js durum geçişi ve dosya silme için kullanır
    echo '<meta name="csrf-token" content="' . h(csrf_token()) . '">' . "\n";
}

/** Modüle özel JS — render_footer()'dan ÖNCE çağrılır. */
function hesap_scripts(): void {
    $v = @filemtime(__DIR__ . '/assets/hesap.js') ?: time();
    echo '<script src="assets/hesap.js?v=' . $v . '" defer></script>' . "\n";
}

/** Durum rozeti HTML'i. */
function hesap_status_badge(?string $status, bool $small = false): string {
    $s = (string)($status ?: 'submitted');
    return '<span class="hs-badge hs-badge--' . h(hesap_status_class($s)) . ($small ? ' hs-badge-sm' : '') . '">'
         . h(hesap_status_icon($s)) . ' ' . h(hesap_status_label($s)) . '</span>';
}

function hesap_get_files(int $tid): array {
    $st = db()->prepare("SELECT * FROM account_files WHERE transaction_id=? ORDER BY id");
    $st->execute([$tid]);
    return $st->fetchAll();
}
function hesap_is_image(string $fn): bool {
    return (bool)preg_match('/\.(jpg|jpeg|png|webp)$/i', $fn);
}

/**
 * Disk adından dosya + bağlı olduğu işlemi getirir.
 * hesap_dosya.php / hesap_dosya_sil.php erişim kontrolünü bunun üzerinden yapar:
 * dosyanın DB'de var olması yetmez, kaydın kullanıcıya görünür olması da gerekir (B5).
 * @return array{file:array,tx:array}|null
 */
function hesap_file_with_tx(string $file_name): ?array {
    $st = db()->prepare("SELECT * FROM account_files WHERE file_name=? LIMIT 1");
    $st->execute([$file_name]);
    $f = $st->fetch();
    if (!$f) return null;

    $ts = db()->prepare("SELECT * FROM account_transactions WHERE id=?");
    $ts->execute([(int)$f['transaction_id']]);
    $tx = $ts->fetch();
    if (!$tx) return null;

    return ['file' => $f, 'tx' => $tx];
}

/**
 * Fiş durumu mantığı — fotoğraf/dosya varsa "fiş var" kabul edilir.
 *   Foto/dosya var + fiş no var  → "Fiş var · No: 123"
 *   Foto/dosya var + fiş no yok  → "Fiş var · Fiş no yok"
 *   Foto/dosya yok               → "Fiş fotoğrafı yok"
 * has_invoice (manuel "fatura/fiş var" işareti) de fiş var sayılır.
 */
function hesap_fis_durumu(array $r): array {
    $has_foto = !empty($r['has_files']);
    $has_inv  = !empty($r['has_invoice']);
    $no       = trim((string)($r['document_no'] ?? ''));
    if ($has_foto || $has_inv) {
        $label = $no !== '' ? ('Fiş var · No: ' . $no) : 'Fiş var · Fiş no yok';
        return ['var' => true, 'label' => $label, 'kisa' => 'Fiş var', 'icon' => '✓', 'no' => $no];
    }
    return ['var' => false, 'label' => 'Fiş fotoğrafı yok', 'kisa' => 'Fiş fotoğrafı yok', 'icon' => '⚠', 'no' => ''];
}

/** Bir işlemin fotoğraf (resim) dosyalarını döndür — PDF dökümü için. */
function hesap_get_image_files(int $tid): array {
    $files = hesap_get_files($tid);
    return array_values(array_filter($files, fn($f) => hesap_is_image($f['file_name'])));
}
function hesap_upload_file(array $file, int $tid): array {
    if (!is_dir(HESAP_UPLOAD_DIR)) mkdir(HESAP_UPLOAD_DIR, 0755, true);
    $htaccess = HESAP_UPLOAD_DIR . '.htaccess';
    if (!file_exists($htaccess)) file_put_contents($htaccess, "Options -Indexes\n<FilesMatch \"\\.php$\">\n  Require all denied\n</FilesMatch>\n");

    if ($file['error'] !== UPLOAD_ERR_OK) return ['ok'=>false,'msg'=>'Yükleme hatası.'];
    if ($file['size'] > HESAP_MAX_FILE_SIZE) return ['ok'=>false,'msg'=>'Dosya 10 MB\'ı aşıyor.'];

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, HESAP_ALLOWED_EXT, true)) return ['ok'=>false,'msg'=>'Geçersiz dosya türü.'];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, HESAP_ALLOWED_MIME, true)) return ['ok'=>false,'msg'=>'Dosya içeriği geçersiz.'];

    $safe_name = bin2hex(random_bytes(16)) . '.' . $ext;
    $dest = HESAP_UPLOAD_DIR . $safe_name;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return ['ok'=>false,'msg'=>'Dosya kaydedilemedi.'];

    db()->prepare("INSERT INTO account_files (transaction_id,file_name,original_name,file_type,file_size) VALUES (?,?,?,?,?)")
        ->execute([$tid, $safe_name, basename($file['name']), $mime, $file['size']]);
    db()->prepare("UPDATE account_transactions SET has_files=1 WHERE id=?")->execute([$tid]);
    return ['ok'=>true,'file_name'=>$safe_name,'original_name'=>basename($file['name'])];
}
