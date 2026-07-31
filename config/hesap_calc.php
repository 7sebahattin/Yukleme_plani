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
 *   · user_id = NULL bırakılır  → "atanmamış veri herkese görünür" kuralı.
 *   · depo    = ''   bırakılır  → depo_sql_column() boş depoyu tüm depolarda gösterir.
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
 * Kural: son görülen ayırıcı ondalık ayracıdır, öncekiler binlik ayracıdır.
 *   "1.234,56" → 1234.56      "1,234.56" → 1234.56
 *   "1234.56"  → 1234.56      "1234,56"  → 1234.56
 *   "1.234"    → 1234         "1234"     → 1234
 *   "12.500"   → 12500  (binlik — 3 hane kuralı)
 */
function hesap_parse_amount($raw): float
{
    $s = trim((string)$raw);
    if ($s === '') return 0.0;

    $neg = str_starts_with($s, '-');
    $s = preg_replace('/[^0-9.,]/', '', $s) ?? '';
    if ($s === '') return 0.0;

    $last_dot   = strrpos($s, '.');
    $last_comma = strrpos($s, ',');

    if ($last_dot === false && $last_comma === false) {
        $val = (float)$s;
        return $neg ? -$val : $val;
    }

    // Son ayırıcının konumu → ondalık ayracı adayı
    $dec_pos = max(($last_dot === false ? -1 : $last_dot), ($last_comma === false ? -1 : $last_comma));
    $dec_sep = $s[$dec_pos];
    $tail    = substr($s, $dec_pos + 1);

    // Tek ayırıcı + arkasında tam 3 hane + başka ayırıcı yok → binlik ayracı ("12.500")
    $sep_count = substr_count($s, '.') + substr_count($s, ',');
    if ($sep_count === 1 && strlen($tail) === 3 && ctype_digit($tail)) {
        $val = (float)str_replace([',', '.'], '', $s);
        return $neg ? -$val : $val;
    }

    $int_part = str_replace([',', '.'], '', substr($s, 0, $dec_pos));
    $val = (float)($int_part . '.' . $tail);
    return $neg ? -$val : $val;
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

/** Kayıt sahibi mi? user_id NULL (atanmamış) kayıtlarda hesap.write yeterlidir. */
function hesap_is_owner(array $row): bool
{
    $u = current_user();
    if ($u === null) return false;
    $owner = $row['user_id'] ?? null;
    if ($owner === null || $owner === '') return hesap_can('write');
    return (int)$owner === (int)$u['id'];
}

/** Bir geçiş bu kullanıcı için mümkün mü? */
function hesap_can_transition(array $row, string $to): bool
{
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

/** Kayıt içeriği düzenlenebilir mi? 'paid' kilitlidir (yükleme modülündeki 'yuklendi' gibi). */
function hesap_is_locked(array $row): bool
{
    return (string)($row['status'] ?? '') === 'paid' && !hesap_can('admin');
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

    if (!depot_visible_to_user($row['depo'] ?? '')) {
        return ['ok' => false, 'msg' => 'Bu kayıt başka bir depoya ait.'];
    }

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
        ['status' => $to, 'note' => $note, 'amount' => (float)$row['amount'], 'currency' => $row['currency']]
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

    // Geriye dönük eşleme — hesap.* seed edilmeden önceki kurulumlar için
    return match ($action) {
        'read'            => can('reports.read'),
        'write'           => can('records.write'),
        'delete'          => can('records.delete'),
        'approve', 'pay'  => can('hesap.approve') || can('hesap.admin'),
        default           => false,
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

/** Tüm personelin kayıtlarını görebilir mi? (muhasebe + admin) */
function hesap_sees_all(): bool
{
    return is_admin() || hesap_can('admin') || hesap_can('approve');
}

/**
 * Kayıt sahipliği WHERE parçası — POZİSYONEL (?).
 * Tümünü görenler için boş döner; aksi halde "kendi kayıtlarım + atanmamış".
 * @return array{0:string,1:array}
 */
function hesap_owner_sql(string $col = 'user_id'): array
{
    if (hesap_sees_all()) return ['', []];
    $u = current_user();
    if ($u === null) return ['0=1', []];
    return ["($col = ? OR $col IS NULL)", [(int)$u['id']]];
}

/** Tekil kayıt görünürlük kontrolü (depo + sahiplik). */
function hesap_row_visible(array $row): bool
{
    if (!depot_visible_to_user($row['depo'] ?? '')) return false;
    if (hesap_sees_all()) return true;
    $owner = $row['user_id'] ?? null;
    if ($owner === null || $owner === '') return true;   // atanmamış → herkese görünür
    $u = current_user();
    return $u !== null && (int)$owner === (int)$u['id'];
}

// ─────────────────────────────────────────────────────────
// 5) Bakiye hesabı
// ─────────────────────────────────────────────────────────

/**
 * Para birimi bazında bakiye. Para birimleri ASLA toplanmaz (B2 düzeltmesi).
 *
 *   net      = Σ gelir − Σ (gider + nakit + havale)   [yalnız bakiyeye giren durumlar]
 *   bekleyen = aynı toplam, draft + submitted durumları
 *
 * İşaret: net < 0 → personel cebinden harcamış, ŞİRKET personele borçlu.
 *         net > 0 → personel elinde şirket parası var, personel şirkete borçlu.
 *
 * @param int|null    $user_id  null → görünürlük kuralına göre (kendi kayıtları / tümü)
 * @param string|null $date_from  'Y-m-d' dahil
 * @param string|null $date_to    'Y-m-d' hariç (üst sınır)
 * @return array<string,array{gelir:float,gider:float,net:float,bekleyen:float,adet:int}>
 */
function hesap_balance(?int $user_id = null, ?string $date_from = null, ?string $date_to = null): array
{
    $where  = ['1=1'];
    $params = [];

    if ($user_id !== null) {
        $where[]  = '(user_id = ? OR user_id IS NULL)';
        $params[] = $user_id;
    } else {
        [$osql, $oparams] = hesap_owner_sql();
        if ($osql !== '') { $where[] = $osql; $params = array_merge($params, $oparams); }
    }
    [$dsql, $dparams] = depo_sql_in('depo');
    if ($dsql !== '') { $where[] = $dsql; $params = array_merge($params, $dparams); }

    if ($date_from !== null) { $where[] = 'transaction_date >= ?'; $params[] = $date_from; }
    if ($date_to   !== null) { $where[] = 'transaction_date <  ?'; $params[] = $date_to; }

    $bal_ph  = implode(',', array_fill(0, count(hesap_balance_statuses()), '?'));
    $pend_ph = implode(',', array_fill(0, count(hesap_pending_statuses()), '?'));

    $sql = "SELECT currency,
                   COALESCE(SUM(CASE WHEN status IN ($bal_ph) AND type='gelir' THEN amount END),0) AS gelir,
                   COALESCE(SUM(CASE WHEN status IN ($bal_ph) AND type IN ('gider','nakit','havale') THEN amount END),0) AS gider,
                   COALESCE(SUM(CASE WHEN status IN ($pend_ph) AND type='gelir' THEN amount END),0) AS p_gelir,
                   COALESCE(SUM(CASE WHEN status IN ($pend_ph) AND type IN ('gider','nakit','havale') THEN amount END),0) AS p_gider,
                   COUNT(*) AS adet
            FROM account_transactions
            WHERE " . implode(' AND ', $where) . "
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
    if (!isset($out['TRY'])) {
        $out['TRY'] = ['gelir' => 0.0, 'gider' => 0.0, 'net' => 0.0, 'bekleyen' => 0.0, 'adet' => 0];
    }
    return $out;
}

/**
 * Bakiye net değerini insan diline çevirir.
 * @return array{yon:string,label:string,tutar:float}
 *         yon: 'alacak' (şirket borçlu) | 'borc' (personel borçlu) | 'denk'
 */
function hesap_balance_label(float $net): array
{
    if (abs($net) < 0.005) return ['yon' => 'denk', 'label' => 'Bakiye denk', 'tutar' => 0.0];
    if ($net < 0)  return ['yon' => 'alacak', 'label' => 'Şirket size borçlu',   'tutar' => -$net];
    return                ['yon' => 'borc',   'label' => 'Şirkete borçlusunuz', 'tutar' => $net];
}

/** Personel kırılımı — rapor ve muhasebe ekranı için. */
function hesap_balance_by_user(?string $date_from = null, ?string $date_to = null): array
{
    $where  = ["at.currency = 'TRY'"];
    $params = [];
    [$dsql, $dparams] = depo_sql_in('at.depo');
    if ($dsql !== '') { $where[] = $dsql; $params = array_merge($params, $dparams); }
    if ($date_from !== null) { $where[] = 'at.transaction_date >= ?'; $params[] = $date_from; }
    if ($date_to   !== null) { $where[] = 'at.transaction_date <  ?'; $params[] = $date_to; }

    $bal_ph = implode(',', array_fill(0, count(hesap_balance_statuses()), '?'));

    $sql = "SELECT at.user_id,
                   COALESCE(u.display_name, u.username, 'Atanmamış') AS personel,
                   COALESCE(SUM(CASE WHEN at.status IN ($bal_ph) AND at.type='gelir' THEN at.amount END),0) AS gelir,
                   COALESCE(SUM(CASE WHEN at.status IN ($bal_ph) AND at.type IN ('gider','nakit','havale') THEN at.amount END),0) AS gider,
                   COUNT(*) AS adet
            FROM account_transactions at
            LEFT JOIN users u ON u.id = at.user_id
            WHERE " . implode(' AND ', $where) . "
            GROUP BY at.user_id, personel
            ORDER BY personel";

    $st = db()->prepare($sql);
    $st->execute(array_merge(hesap_balance_statuses(), hesap_balance_statuses(), $params));

    $rows = $st->fetchAll();
    foreach ($rows as &$r) {
        $r['net'] = (float)$r['gelir'] - (float)$r['gider'];
    }
    return $rows;
}
