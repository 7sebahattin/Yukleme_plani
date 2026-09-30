<?php
// =========================================================
// config/hesap_calc.php — Hesap modülü çekirdeği
//   · Şema migrasyonu (idempotent)      → hesap_migrate()
//   · Tutar ayrıştırma                  → hesap_parse_amount()
//   · Durum makinesi                    → hesap_statuses() / hesap_transition()
//   · Bakiye hesabı                     → hesap_balance()
//   · Yetki kapısı                      → hesap_can() / require_hesap()
//
// Modül sayfaları bu dosyayı hesap_config.php üzerinden alır ve açılışta
// hesap_migrate() çağırır (maliyet modülündeki cost_migrate() emsali).
// =========================================================
declare(strict_types=1);

// ─────────────────────────────────────────────────────────
// 1) Şema migrasyonu — idempotent, tek çalıştırma
// ─────────────────────────────────────────────────────────

/**
 * account_transactions tablosuna personel kimliği, durum ve depo kolonlarını ekler.
 *
 * Geri dolum kuralları (yalnız kolon YENİ eklendiğinde çalışır, veri asla ezilmez):
 *   · status  = is_given_to_accountant ? 'approved' : 'submitted'
 *               → hiçbir eski kayıt taslağa düşmez, bakiyeler bugünkü değerinde kalır.
 *   · user_id = NULL bırakılır  → sahipsiz kayıt: yalnız yönetici görür, kimsenin
 *                                 bakiyesine girmez; sahibi yönetici tarafından
 *                                 hesap_kayit.php 'Kayıt sahibi' alanından atanır
 *                                 (otomatik geri dolum YOK).
 *   · depo    = ''   bırakılır  → yalnız bilgi amaçlı damga; Hesap depo filtresi kullanmaz.
 *
 * is_given_to_accountant SİLİNMEZ; hesap_transition() her durum değişiminde onu
 * senkron tutar, böylece eski sorgular (export, fiş PDF) bozulmadan çalışır.
 */
function hesap_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = db();

    try { $pdo->query("SELECT 1 FROM `account_transactions` LIMIT 0"); }
    catch (PDOException $e) { return; }   // tablo henüz yok — db.php oluşturacak

    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `account_transactions`")->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) { return; }

    $add = [
        'user_id'      => "ADD COLUMN `user_id`      INT NULL",
        'created_by'   => "ADD COLUMN `created_by`   INT NULL",
        'status'       => "ADD COLUMN `status`       VARCHAR(20) NOT NULL DEFAULT 'submitted'",
        'submitted_at' => "ADD COLUMN `submitted_at` DATETIME NULL",
        'reviewed_by'  => "ADD COLUMN `reviewed_by`  INT NULL",
        'reviewed_at'  => "ADD COLUMN `reviewed_at`  DATETIME NULL",
        'review_note'  => "ADD COLUMN `review_note`  VARCHAR(500) NOT NULL DEFAULT ''",
        'paid_at'      => "ADD COLUMN `paid_at`      DATETIME NULL",
        'depo'         => "ADD COLUMN `depo`         VARCHAR(150) NOT NULL DEFAULT ''",
    ];

    $parts = [];
    foreach ($add as $col => $sql) {
        if (!in_array($col, $cols, true)) $parts[] = $sql;
    }

    if (!empty($parts)) {
        try {
            $pdo->exec("ALTER TABLE `account_transactions` " . implode(', ', $parts));
        } catch (PDOException $e) {
            error_log('[hesap_migrate columns] ' . $e->getMessage());
            return;
        }
    }

    // Geri dolum — kendinden idempotent, marker gerektirmez.
    // status kolonu DEFAULT 'submitted' ile gelir; muhasebeye verilmiş eski kayıtlar
    // bu yüzden yanlışlıkla "gönderildi" görünür. Onları 'approved'a çekiyoruz.
    // Koşul yalnız geri doldurulmamış eski satırlarla eşleşir: hesap_transition()
    // status ile is_given_to_accountant'ı her zaman senkron tuttuğu için gerçek bir
    // 'submitted' kaydında is_given_to_accountant her zaman 0'dır.
    // (migrate.php kolonu önce eklemiş olsa bile bu düzeltme çalışır.)
    try {
        $n = $pdo->exec("UPDATE `account_transactions`
                            SET `status` = 'approved'
                          WHERE `status` = 'submitted' AND `is_given_to_accountant` = 1");
        $pdo->exec("UPDATE `account_transactions`
                       SET `submitted_at` = `created_at`
                     WHERE `submitted_at` IS NULL");
        if ($n > 0 && function_exists('audit_log_event')) {
            audit_log_event('migrate', 'hesap', null, null, [
                'operation'      => 'status_backfill',
                'rule'           => 'is_given_to_accountant=1 → approved',
                'affected_count' => (int)$n,
            ]);
        }
    } catch (PDOException $e) {
        error_log('[hesap_migrate backfill] ' . $e->getMessage());
    }

    // İndeksler
    foreach ([
        ['idx_at_user',   "ALTER TABLE `account_transactions` ADD INDEX `idx_at_user`   (`user_id`)"],
        ['idx_at_status', "ALTER TABLE `account_transactions` ADD INDEX `idx_at_status` (`status`)"],
        ['idx_at_depo',   "ALTER TABLE `account_transactions` ADD INDEX `idx_at_depo`   (`depo`(80))"],
    ] as [$key, $sql]) {
        try {
            $st = $pdo->prepare("SHOW INDEX FROM `account_transactions` WHERE Key_name = ?");
            $st->execute([$key]);
            if ($st->rowCount() === 0) $pdo->exec($sql);
        } catch (PDOException $e) { /* indeks yoksa sessiz geç */ }
    }
}

// ─────────────────────────────────────────────────────────
// 2) Tutar ayrıştırma  (B1 düzeltmesi)
// ─────────────────────────────────────────────────────────

/**
 * Kullanıcı girdisini güvenle float'a çevirir.
 * Eski kod `str_replace(['.',','],['','.'])` yapıyordu; "1234.56" → 123456 (100× hata).
 *
 * Kurallar (O1 — çoklu binlik ayırıcı düzeltmesi):
 *   · Para simgeleri (₺ $ € TL TRY USD EUR AED) ve boşluklar atılır. Kalan metinde
 *     rakam, '.' ve ',' dışında karakter varsa 0.0 döner ("1e5" → 0; eskiden 15).
 *   · İki ayırıcı türü de varsa sonuncusu ondalıktır, diğeri binliktir; binlik
 *     grupları 3 hane değilse 0.0.          "1.234.567,89" → 1234567.89
 *   · Tek ayırıcı türü BİRDEN ÇOK geçiyorsa hepsi binliktir, ilk grup hariç her
 *     grup 3 hane olmalıdır.                "1.234.567" → 1234567 · "1.234.56" → 0
 *   · Tek ayırıcı BİR KEZ geçiyor, arkasında tam 3 hane ve önünde 1-3 haneli
 *     (0 ile başlamayan) tam kısım varsa binliktir.   "12.500" → 12500
 *     Tam kısım '' ya da '0' ise ondalıktır.          "0,005"  → 0.005
 *   · Diğer her durumda ondalıktır.        "1234,56" → 1234.56 · "1234.567" → 1234.567
 *
 * 0.0 dönüşü çağıran tarafından "geçersiz tutar" olarak ele alınır
 * (hesap_kayit.php "Tutar 0'dan büyük olmalı").
 */
function hesap_parse_amount($raw): float
{
    $s = trim((string)$raw);
    if ($s === '') return 0.0;

    $s = str_ireplace(['₺', '$', '€', 'TRY', 'TL', 'USD', 'EUR', 'AED'], '', $s);
    $s = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $s) ?? '';

    $neg = false;
    if (str_starts_with($s, '-')) { $neg = true; $s = substr($s, 1); }
    if ($s === '' || !preg_match('/^[0-9.,]+$/', $s) || !preg_match('/[0-9]/', $s)) return 0.0;

    $n_dot   = substr_count($s, '.');
    $n_comma = substr_count($s, ',');

    // Binlik grupları: ilk grup 1-3 hane, sonrakiler tam 3 hane
    $gruplar_gecerli = static function (array $g): bool {
        if (!preg_match('/^[0-9]{1,3}$/', (string)array_shift($g))) return false;
        foreach ($g as $x) { if (!preg_match('/^[0-9]{3}$/', $x)) return false; }
        return true;
    };

    if ($n_dot === 0 && $n_comma === 0) {
        $val = (float)$s;
    } elseif ($n_dot > 0 && $n_comma > 0) {
        $dec_pos  = max((int)strrpos($s, '.'), (int)strrpos($s, ','));
        $dec_sep  = $s[$dec_pos];
        $bin_sep  = $dec_sep === '.' ? ',' : '.';
        $int_str  = substr($s, 0, $dec_pos);
        $tail     = substr($s, $dec_pos + 1);
        // Ondalık ayracı yalnız bir kez geçebilir ("1.234,5.6" geçersiz)
        if (str_contains($int_str, $dec_sep) || !ctype_digit($tail === '' ? '0' : $tail)) return 0.0;
        if (!$gruplar_gecerli(explode($bin_sep, $int_str))) return 0.0;
        $val = (float)(str_replace($bin_sep, '', $int_str) . '.' . ($tail === '' ? '0' : $tail));
    } else {
        $sep   = $n_dot > 0 ? '.' : ',';
        $parca = explode($sep, $s);
        if (count($parca) > 2) {
            if (!$gruplar_gecerli($parca)) return 0.0;
            $val = (float)implode('', $parca);
        } else {
            [$a, $b] = $parca;
            if (strlen($b) === 3 && preg_match('/^[1-9][0-9]{0,2}$/', $a)) {
                $val = (float)($a . $b);                       // binlik: "12.500"
            } else {
                $val = (float)(($a === '' ? '0' : $a) . '.' . ($b === '' ? '0' : $b));   // ondalık
            }
        }
    }
    return $neg ? -$val : $val;
}

/** 'Y-m-d' biçiminde ve takvimde var olan bir tarih mi? ("2026-02-30" → false) */
function hesap_tarih_gecerli(string $s): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) return false;
    return checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** 'HH:MM' ya da 'HH:MM:SS' saat mi? */
function hesap_saat_gecerli(string $s): bool
{
    if (!preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $s, $m)) return false;
    return (int)$m[1] < 24 && (int)$m[2] < 60 && (int)($m[3] ?? 0) < 60;
}

// ─────────────────────────────────────────────────────────
// 3) Durum makinesi
// ─────────────────────────────────────────────────────────

/** Durum kodu → etiket / rozet sınıfı / bakiyeye etkisi. */
function hesap_statuses(): array
{
    return [
        'draft'           => ['label' => 'Taslak',            'class' => 'draft',     'balance' => false, 'icon' => '✎'],
        'submitted'       => ['label' => 'Gönderildi',        'class' => 'submitted', 'balance' => false, 'icon' => '→'],
        'approved'        => ['label' => 'Muhasebe Onayladı', 'class' => 'approved',  'balance' => true,  'icon' => '✓'],
        'pending_payment' => ['label' => 'Ödeme Bekliyor',    'class' => 'pending',   'balance' => true,  'icon' => '⏳'],
        'paid'            => ['label' => 'Ödendi',            'class' => 'paid',      'balance' => true,  'icon' => '✓✓'],
        'rejected'        => ['label' => 'Reddedildi',        'class' => 'rejected',  'balance' => false, 'icon' => '✕'],
    ];
}

function hesap_status_valid(string $s): bool { return isset(hesap_statuses()[$s]); }

function hesap_status_label(string $s): string
{
    return hesap_statuses()[$s]['label'] ?? $s;
}

function hesap_status_class(string $s): string
{
    return hesap_statuses()[$s]['class'] ?? 'draft';
}

function hesap_status_icon(string $s): string
{
    return hesap_statuses()[$s]['icon'] ?? '';
}

/** Bakiyeye giren durum kodları. */
function hesap_balance_statuses(): array
{
    return array_keys(array_filter(hesap_statuses(), fn($m) => $m['balance']));
}

/** Onay bekleyen (bakiyeye girmeyen, henüz reddedilmemiş) durum kodları. */
function hesap_pending_statuses(): array
{
    return ['draft', 'submitted'];
}

/**
 * Geçiş haritası: kaynak durum → [hedef => ['perm'=>…, 'owner'=>bool, 'note'=>bool, 'label'=>…]]
 *   perm  : bu geçiş için gereken yetki (null → yalnız sahiplik yeter)
 *   owner : kaydın sahibi de (yetkisi olmasa bile hesap.write ile) yapabilir mi
 *   note  : review_note zorunlu mu
 */
function hesap_transitions(): array
{
    return [
        'draft' => [
            'submitted'       => ['perm' => null,            'owner' => true,  'note' => false, 'label' => 'Muhasebeye Gönder'],
        ],
        'submitted' => [
            'draft'           => ['perm' => null,            'owner' => true,  'note' => false, 'label' => 'Geri Çek'],
            'approved'        => ['perm' => 'hesap.approve', 'owner' => false, 'note' => false, 'label' => 'Onayla'],
            'rejected'        => ['perm' => 'hesap.approve', 'owner' => false, 'note' => true,  'label' => 'Reddet'],
        ],
        'approved' => [
            'pending_payment' => ['perm' => 'hesap.approve', 'owner' => false, 'note' => false, 'label' => 'Ödemeye Al'],
            'paid'            => ['perm' => 'hesap.pay',     'owner' => false, 'note' => false, 'label' => 'Ödendi İşaretle'],
            'rejected'        => ['perm' => 'hesap.approve', 'owner' => false, 'note' => true,  'label' => 'Reddet'],
        ],
        'pending_payment' => [
            'paid'            => ['perm' => 'hesap.pay',     'owner' => false, 'note' => false, 'label' => 'Ödendi İşaretle'],
            'approved'        => ['perm' => 'hesap.approve', 'owner' => false, 'note' => false, 'label' => 'Onaya Geri Al'],
            'rejected'        => ['perm' => 'hesap.approve', 'owner' => false, 'note' => true,  'label' => 'Reddet'],
        ],
        'paid' => [
            'approved'        => ['perm' => 'hesap.admin',   'owner' => false, 'note' => true,  'label' => 'Ödemeyi Geri Al'],
        ],
        'rejected' => [
            'draft'           => ['perm' => null,            'owner' => true,  'note' => false, 'label' => 'Düzeltmeye Al'],
        ],
    ];
}

/**
 * Kayıt sahibi mi? Sahipsiz (user_id NULL) kayıt KİMSENİN değildir (K2/O4):
 * eskiden NULL'da hesap.write yetiyordu ve her personel eski ortak kaydı
 * gönderip taslağa alabiliyordu.
 */
function hesap_is_owner(array $row): bool
{
    $u = current_user();
    if ($u === null) return false;
    $owner = $row['user_id'] ?? null;
    if ($owner === null || $owner === '') return false;
    return (int)$owner === (int)$u['id'];
}

/**
 * Bir geçiş bu kullanıcı için mümkün mü?
 * İlk kapı GÖRÜNÜRLÜKTÜR: hesap.approve / hesap.pay yalnız kullanıcının görebildiği
 * satırda çalışır (K-2). Muhasebe böylece yalnız KENDİ kaydını onaylar/öder;
 * başkasınınkini yönetici (is_admin / hesap.admin) onaylar.
 * Kendi kaydını onaylama BİLEREK serbest (kullanıcı kararı K-3) — yasak EKLEME.
 */
function hesap_can_transition(array $row, string $to): bool
{
    if (!hesap_row_visible($row)) return false;
    $from = (string)($row['status'] ?? 'submitted');
    $rule = hesap_transitions()[$from][$to] ?? null;
    if ($rule === null) return false;
    if (hesap_can('admin')) return true;
    if ($rule['perm'] !== null && can($rule['perm'])) return true;
    if ($rule['owner'] && hesap_is_owner($row) && hesap_can('write')) return true;
    return false;
}

/** Bu kullanıcının bu kayıt için yapabileceği geçişler → [hedef => kural]. */
function hesap_available_transitions(array $row): array
{
    $from = (string)($row['status'] ?? 'submitted');
    $out  = [];
    foreach (hesap_transitions()[$from] ?? [] as $to => $rule) {
        if (hesap_can_transition($row, $to)) $out[$to] = $rule;
    }
    return $out;
}

/** Kayıt içeriği düzenlenebilir mi? 'paid' kilitlidir (yükleme modülündeki 'yuklendi' gibi).
 *  Eski kapı — yeni kod hesap_icerik_kilitli() kullanır. */
function hesap_is_locked(array $row): bool
{
    return (string)($row['status'] ?? '') === 'paid' && !hesap_can('admin');
}

/**
 * İçerik kilidi — içerik düzenleme, fiş silme ve kayıt silme için TEK kapı (K4/Y4).
 * Bakiyeye giren durumlar (approved / pending_payment / paid) yönetici dışında kilitlidir;
 * yoksa onaylanmış bir tutar sonradan değiştirilip onaysız bakiyeye girerdi.
 * Düzeltme yolu durum makinesidir: onaycı "Reddet" → sahip "Düzeltmeye Al" → düzenle →
 * yeniden gönder. Yönetici düzeltmesi gerekçe ister (hesap_kayit.php / hesap_sil.php).
 */
function hesap_icerik_kilitli(array $row): bool
{
    if (!in_array((string)($row['status'] ?? ''), hesap_balance_statuses(), true)) return false;
    return !hesap_sees_all();
}

/** İçerik kilidi mesajı — sayfalar aynı metni gösterir. */
function hesap_kilit_mesaji(): string
{
    return 'Onaylanmış kayıt kilitlidir. Düzeltmek için muhasebeden kaydı reddetmesini isteyin; '
         . 'sonra "Düzeltmeye Al" ile düzenleyebilirsiniz.';
}

/**
 * Durum geçişini uygular: doğrular, yazar, audit'e düşer, legacy bayrağı senkronlar.
 * @return array{ok:bool,msg:string}
 */
function hesap_transition(int $id, string $to, string $note = ''): array
{
    $pdo = db();
    $st = $pdo->prepare("SELECT * FROM account_transactions WHERE id=?");
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) return ['ok' => false, 'msg' => 'Kayıt bulunamadı.'];

    // Görünmeyen kayıt "bulunamadı" der — "yetkiniz yok" başkasının kaydının
    // VAR olduğunu sızdırırdı. Hesap depo kapsamlı değildir (bkz. hesap_row_visible).
    if (!hesap_row_visible($row)) return ['ok' => false, 'msg' => 'Kayıt bulunamadı.'];

    $from = (string)($row['status'] ?? 'submitted');
    if (!hesap_status_valid($to)) return ['ok' => false, 'msg' => 'Geçersiz durum.'];
    if ($from === $to)            return ['ok' => false, 'msg' => 'Kayıt zaten bu durumda.'];

    $rule = hesap_transitions()[$from][$to] ?? null;
    if ($rule === null) {
        return ['ok' => false, 'msg' => hesap_status_label($from) . ' → ' . hesap_status_label($to) . ' geçişi tanımlı değil.'];
    }
    if (!hesap_can_transition($row, $to)) {
        return ['ok' => false, 'msg' => 'Bu işlem için yetkiniz yok.'];
    }
    $note = trim($note);
    if ($rule['note'] && $note === '') {
        return ['ok' => false, 'msg' => 'Gerekçe zorunlu.'];
    }

    $u   = current_user();
    $uid = $u ? (int)$u['id'] : null;

    // Bakiyeye giren durumlar legacy is_given_to_accountant=1 ile eşleşir
    $legacy = in_array($to, hesap_balance_statuses(), true) ? 1 : 0;

    $sql = "UPDATE account_transactions
               SET status=?, is_given_to_accountant=?,
                   review_note=?,
                   reviewed_by = CASE WHEN ? THEN ? ELSE reviewed_by END,
                   reviewed_at = CASE WHEN ? THEN NOW() ELSE reviewed_at END,
                   submitted_at = CASE WHEN ? THEN COALESCE(submitted_at, NOW()) ELSE submitted_at END,
                   paid_at      = CASE WHEN ? THEN NOW() ELSE paid_at END
             WHERE id=?";
    $is_review = in_array($to, ['approved', 'pending_payment', 'rejected', 'paid'], true) ? 1 : 0;
    $is_submit = $to === 'submitted' ? 1 : 0;
    $is_paid   = $to === 'paid' ? 1 : 0;

    $pdo->prepare($sql)->execute([
        $to, $legacy,
        $rule['note'] ? $note : (string)($row['review_note'] ?? ''),
        $is_review, $uid,
        $is_review,
        $is_submit,
        $is_paid,
        $id,
    ]);

    audit_log_event('status_change', 'hesap', $id,
        ['status' => $from],
        ['status' => $to, 'note' => $note, 'amount' => (float)$row['amount'], 'currency' => $row['currency'],
         'owner' => $row['user_id'] ?? null]
    );

    return ['ok' => true, 'msg' => hesap_status_label($to) . ' olarak işaretlendi.'];
}

// ─────────────────────────────────────────────────────────
// 4) Yetki kapısı
// ─────────────────────────────────────────────────────────

/**
 * hesap.<action> yetkisi. Geçiş dönemi için eski yetkiler de kabul edilir:
 * hesap.* henüz rollere işlenmemişse records.write / reports.read devreye girer.
 */
function hesap_can(string $action): bool
{
    if (!function_exists('can')) return true;
    if (is_admin()) return true;
    if (can('hesap.' . $action)) return true;

    // ⚠ Buradaki eski "geriye dönük eşleme" KALDIRILDI (Sprint Rol-02).
    // reports.read → hesap.read, records.write → hesap.write ve
    // records.delete → hesap.delete sessiz köprüleriydi: Roller ekranında
    // Hesap kutuları BOŞ bırakılan bir rol, yalnızca "Rapor görüntüle" ya da
    // "Yükleme düzenle" yetkisi yüzünden Hesap modülünü açıp masraf kaydı
    // yazabiliyordu — yetki ekranı gerçeği söylemiyordu. Köprü artık yok;
    // hesap.* yetkileri kurulum seed'inde (config/helpers.php) zaten
    // tanımlı olduğu için mevcut rollerin davranışı DEĞİŞMEZ.
    // 'approve'/'pay' için hesap.admin hâlâ üst yetki sayılır.
    return match ($action) {
        'approve', 'pay' => can('hesap.admin'),
        default          => false,
    };
}

function require_hesap(string $action): void
{
    if (current_user() === null) {
        $next = urlencode($_SERVER['REQUEST_URI'] ?? '');
        header('Location: ' . (function_exists('base_url') ? base_url() : '') . 'login.php' . ($next ? '?next=' . $next : ''));
        exit;
    }
    enforce_active_depot();
    if (!hesap_can($action)) {
        forbidden("Bu sayfaya erişim yetkiniz yok. (Gerekli yetki: hesap.{$action})");
    }
}

/**
 * Yönetici mi? — TÜM personelin hesabını ve sahipsiz kayıtları görür (K-2).
 * YALNIZ is_admin() ya da hesap.admin. hesap.approve / hesap.pay görünürlük VERMEZ:
 * muhasebe yalnız kendi hesabını görür; başkasının masrafını yönetici onaylar.
 */
function hesap_sees_all(): bool
{
    return is_admin() || (function_exists('can') && can('hesap.admin'));
}

/**
 * Ekran kapsamını çözer. Her Hesap ekranı varsayılan olarak oturumdaki kullanıcının
 * KENDİ hesabını gösterir; yalnız yönetici ?personel=<uid>|tum ile genişletir.
 * Yönetici değilse $param SESSİZCE yok sayılır (403 yok — zararsız parametre).
 *
 * @return array{tip:string,uid:?int,ad:string,kendi:bool}  tip: kendi | kisi | tum
 */
function hesap_kapsam_coz(?string $param, string $varsayilan = 'kendi'): array
{
    $u  = current_user();
    $me = $u !== null ? (int)$u['id'] : null;
    $kendi = [
        'tip'   => 'kendi',
        'uid'   => $me,
        'ad'    => $u !== null ? (string)(($u['display_name'] ?? '') ?: ($u['username'] ?? '')) : '',
        'kendi' => true,
    ];
    if ($u === null || !hesap_sees_all()) return $kendi;

    $p = trim((string)($param ?? ''));
    if ($p === '') $p = $varsayilan;
    if ($p === 'tum') return ['tip' => 'tum', 'uid' => null, 'ad' => 'Tüm personel', 'kendi' => false];
    if ($p !== '' && ctype_digit($p)) {
        $uid = (int)$p;
        if ($uid === $me) return $kendi;
        try {
            $st = db()->prepare("SELECT id, COALESCE(NULLIF(display_name,''), username) AS ad FROM users WHERE id = ?");
            $st->execute([$uid]);
            $r = $st->fetch();
        } catch (PDOException $e) { $r = false; }
        if ($r) return ['tip' => 'kisi', 'uid' => $uid, 'ad' => (string)($r['ad'] ?? ('#' . $uid)), 'kendi' => false];
    }
    return $kendi;
}

/**
 * Kapsamın WHERE parçası — POZİSYONEL (?). Depo filtresi YOK (bilinçli, bkz. CLAUDE.md).
 * Savunma: yönetici olmayan için kapsam ne gelirse gelsin "kendi"ye düşer.
 * @return array{0:string,1:array}
 */
function hesap_kapsam_sql(array $k, string $col = 'user_id'): array
{
    $tip = (string)($k['tip'] ?? 'kendi');
    if ($tip !== 'kendi' && !hesap_sees_all()) $tip = 'kendi';

    if ($tip === 'tum') return ["$col IS NOT NULL", []];
    if ($tip === 'kisi' && !empty($k['uid'])) return ["$col = ?", [(int)$k['uid']]];

    $u = current_user();
    if ($u === null) return ['0=1', []];
    return ["$col = ?", [(int)$u['id']]];
}

/** Sayfa içi linklerin taşıyacağı kapsam parametresi — yalnız yönetici + kendi dışı. */
function hesap_kapsam_query(array $k): array
{
    return match ($k['tip'] ?? 'kendi') {
        'tum'   => ['personel' => 'tum'],
        'kisi'  => ['personel' => (int)$k['uid']],
        default => [],
    };
}

/**
 * Geriye uyum sarmalayıcısı — herkes için "kendi kayıtlarım". Sahipsiz (NULL) YOK.
 * Yeni kod hesap_kapsam_coz() + hesap_kapsam_sql() kullanır.
 * @return array{0:string,1:array}
 */
function hesap_owner_sql(string $col = 'user_id'): array
{
    return hesap_kapsam_sql(hesap_kapsam_coz(null), $col);
}

/**
 * Tekil kayıt görünürlüğü — yalnız sahiplik. Depo KONTROL EDİLMEZ: hesap bir kişinin
 * şirketle carisidir, aktif depoya göre kaybolmamalı (Y1).
 * Yönetici her kaydı (sahipsizler dahil — atamadan önce incelenebilsin) görür.
 */
function hesap_row_visible(array $row): bool
{
    if (hesap_sees_all()) return true;
    $owner = $row['user_id'] ?? null;
    if ($owner === null || $owner === '') return false;   // sahipsiz → yalnız yönetici
    $u = current_user();
    return $u !== null && (int)$owner === (int)$u['id'];
}

// ─────────────────────────────────────────────────────────
// 5) Bakiye hesabı
// ─────────────────────────────────────────────────────────

/** Boş bakiye satırı. */
function hesap_balance_bos(): array
{
    return ['gelir' => 0.0, 'gider' => 0.0, 'net' => 0.0, 'bekleyen' => 0.0, 'adet' => 0];
}

/**
 * Bakiye sorgusunun ORTAK gövdesi — hesap_balance() ve hesap_balance_tum() kullanır.
 * @param string $where  pozisyonel (?) WHERE parçası (kapsam)
 * @return array<string,array{gelir:float,gider:float,net:float,bekleyen:float,adet:int}>
 */
function hesap_balance_sorgu(string $where, array $params, ?string $date_from, ?string $date_to): array
{
    $w = [$where];
    if ($date_from !== null) { $w[] = 'transaction_date >= ?'; $params[] = $date_from; }
    if ($date_to   !== null) { $w[] = 'transaction_date <  ?'; $params[] = $date_to; }

    $bal_ph  = implode(',', array_fill(0, count(hesap_balance_statuses()), '?'));
    $pend_ph = implode(',', array_fill(0, count(hesap_pending_statuses()), '?'));

    $sql = "SELECT currency,
                   COALESCE(SUM(CASE WHEN status IN ($bal_ph) AND type='gelir' THEN amount END),0) AS gelir,
                   COALESCE(SUM(CASE WHEN status IN ($bal_ph) AND type IN ('gider','nakit','havale') THEN amount END),0) AS gider,
                   COALESCE(SUM(CASE WHEN status IN ($pend_ph) AND type='gelir' THEN amount END),0) AS p_gelir,
                   COALESCE(SUM(CASE WHEN status IN ($pend_ph) AND type IN ('gider','nakit','havale') THEN amount END),0) AS p_gider,
                   COUNT(*) AS adet
            FROM account_transactions
            WHERE " . implode(' AND ', $w) . "
            GROUP BY currency";

    $args = array_merge(
        hesap_balance_statuses(), hesap_balance_statuses(),
        hesap_pending_statuses(), hesap_pending_statuses(),
        $params
    );

    $st = db()->prepare($sql);
    $st->execute($args);

    $out = [];
    foreach ($st->fetchAll() as $r) {
        $gelir = (float)$r['gelir'];
        $gider = (float)$r['gider'];
        $out[$r['currency']] = [
            'gelir'    => $gelir,
            'gider'    => $gider,
            'net'      => $gelir - $gider,
            'bekleyen' => (float)$r['p_gelir'] - (float)$r['p_gider'],
            'adet'     => (int)$r['adet'],
        ];
    }
    if (!isset($out['TRY'])) $out['TRY'] = hesap_balance_bos();
    return $out;
}

/**
 * KİŞİSEL bakiye — para birimi bazında. Para birimleri ASLA toplanmaz (B2 düzeltmesi).
 *
 *   net      = Σ gelir − Σ (gider + nakit + havale)   [yalnız bakiyeye giren durumlar]
 *   bekleyen = aynı toplam, draft + submitted durumları
 *
 * İşaret: net < 0 → personel cebinden harcamış, ŞİRKET personele borçlu.
 *         net > 0 → personel elinde şirket parası var, personel şirkete borçlu.
 *
 * Kapsam YALNIZ o kişinin kayıtlarıdır: sahipsiz (NULL) kayıt hiç kimsenin bakiyesine
 * girmez, depo filtresi yoktur. Global toplam için hesap_balance_tum().
 *
 * @param int|null    $user_id  null → oturumdaki kullanıcının KENDİ bakiyesi (yönetici için de).
 *                              Başkası istenir ve çağıran yönetici değilse sıfır döner (fail-closed).
 * @param string|null $date_from  'Y-m-d' dahil
 * @param string|null $date_to    'Y-m-d' hariç (üst sınır)
 * @return array<string,array{gelir:float,gider:float,net:float,bekleyen:float,adet:int}>
 */
function hesap_balance(?int $user_id = null, ?string $date_from = null, ?string $date_to = null): array
{
    $u  = current_user();
    $me = $u !== null ? (int)$u['id'] : null;
    $uid = $user_id ?? $me;
    if ($uid === null || ($uid !== $me && !hesap_sees_all())) {
        return ['TRY' => hesap_balance_bos()];
    }
    return hesap_balance_sorgu('user_id = ?', [$uid], $date_from, $date_to);
}

/**
 * Tüm SAHİPLİ kayıtların bakiyesi (yönetici "Tüm personel" kapsamı). Sahipsiz kayıt
 * burada da YOK — atanana dek kimsenin bakiyesi değildir. Yönetici değilse sıfır.
 */
function hesap_balance_tum(?string $date_from = null, ?string $date_to = null): array
{
    if (!hesap_sees_all()) return ['TRY' => hesap_balance_bos()];
    return hesap_balance_sorgu('user_id IS NOT NULL', [], $date_from, $date_to);
}

/**
 * Bakiye net değerini insan diline çevirir.
 * $ucuncu_sahis: yönetici BAŞKASININ hesabına bakıyorsa "size/-sunuz" yerine üçüncü şahıs.
 * @return array{yon:string,label:string,tutar:float}
 *         yon: 'alacak' (şirket borçlu) | 'borc' (personel borçlu) | 'denk'
 */
function hesap_balance_label(float $net, bool $ucuncu_sahis = false): array
{
    if (abs($net) < 0.005) return ['yon' => 'denk', 'label' => 'Bakiye denk', 'tutar' => 0.0];
    if ($net < 0)  return ['yon' => 'alacak', 'label' => $ucuncu_sahis ? 'Şirket personele borçlu' : 'Şirket size borçlu',   'tutar' => -$net];
    return                ['yon' => 'borc',   'label' => $ucuncu_sahis ? 'Personel şirkete borçlu' : 'Şirkete borçlusunuz', 'tutar' => $net];
}

/**
 * Personel kırılımı — YALNIZ yönetici (K1 kök düzeltmesi: bu fonksiyonda sahiplik
 * filtresi yoktu, PDF raporu herkesin bakiyesini sızdırıyordu). Yönetici değilse [].
 * Sahipsiz kayıt satırı ("Atanmamış") artık oluşmaz. Depo filtresi yok.
 *
 * @param string|null $currency 'TRY' (varsayılan — mevcut çağıranlar) · null → kişi × para birimi
 */
function hesap_balance_by_user(?string $date_from = null, ?string $date_to = null, ?string $currency = 'TRY'): array
{
    if (!hesap_sees_all()) return [];

    $where  = ['at.user_id IS NOT NULL'];
    $params = [];
    if ($currency !== null)  { $where[] = 'at.currency = ?';          $params[] = $currency; }
    if ($date_from !== null) { $where[] = 'at.transaction_date >= ?'; $params[] = $date_from; }
    if ($date_to   !== null) { $where[] = 'at.transaction_date <  ?'; $params[] = $date_to; }

    $bal_ph  = implode(',', array_fill(0, count(hesap_balance_statuses()), '?'));
    $pend_ph = implode(',', array_fill(0, count(hesap_pending_statuses()), '?'));

    $sql = "SELECT at.user_id, at.currency,
                   COALESCE(NULLIF(u.display_name,''), u.username, '') AS personel,
                   COALESCE(SUM(CASE WHEN at.status IN ($bal_ph) AND at.type='gelir' THEN at.amount END),0) AS gelir,
                   COALESCE(SUM(CASE WHEN at.status IN ($bal_ph) AND at.type IN ('gider','nakit','havale') THEN at.amount END),0) AS gider,
                   COALESCE(SUM(CASE WHEN at.status IN ($pend_ph) AND at.type='gelir' THEN at.amount END),0) AS p_gelir,
                   COALESCE(SUM(CASE WHEN at.status IN ($pend_ph) AND at.type IN ('gider','nakit','havale') THEN at.amount END),0) AS p_gider,
                   COUNT(*) AS adet
            FROM account_transactions at
            LEFT JOIN users u ON u.id = at.user_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY at.user_id, at.currency, personel
            ORDER BY personel, at.user_id, (at.currency = 'TRY') DESC, at.currency";

    $st = db()->prepare($sql);
    $st->execute(array_merge(
        hesap_balance_statuses(), hesap_balance_statuses(),
        hesap_pending_statuses(), hesap_pending_statuses(),
        $params
    ));

    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        if ((string)$r['personel'] === '') $r['personel'] = 'Kullanıcı #' . (int)$r['user_id'];
        $r['net']      = (float)$r['gelir'] - (float)$r['gider'];
        $r['bekleyen'] = (float)$r['p_gelir'] - (float)$r['p_gider'];
    }
    unset($r);
    return $rows;
}
