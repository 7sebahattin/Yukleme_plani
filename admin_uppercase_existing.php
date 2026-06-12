<?php
// =========================================================
// admin_uppercase_existing.php — Mevcut kayıtları büyük harfe çevir
// Sadece admin erişebilir.
// Mod: ?mode=dry (önizleme) | POST mode=apply confirm=BUYUK_HARF_UYGULA
// =========================================================
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
$auth_user = require_login();
if (!is_admin()) forbidden('Bu sayfaya yalnızca yönetici erişebilir.');

// Güncellenmeyecek kolonlar — kesinlikle dokunulmuyor
const UC_EXCLUDED = [
    'password', 'password_hash', 'token', 'remember_token', 'csrf',
    'email', 'phone', 'telefon', 'url', 'raw_text', 'unmatched_text',
    'analysis_note', 'revision_reason', 'note', 'nota', 'description',
    'aciklama', 'snapshot_json', 'created_at', 'updated_at', 'id',
];

// Tablo / kolon whitelist
$WHITELIST = [
    'loading_records' => [
        'firma', 'bolge', 'parti_no', 'gumruk', 'alici', 'urun',
        'brand', 'sofor_adi', 'nakliye_sirketi', 'on_plaka', 'arka_plaka',
        'fatura_no', 'casus_no',
    ],
    'loading_pallets' => [
        'size', 'urun_cinsi', 'depo',
    ],
    'material_definitions' => [
        'name',
    ],
    'customs_declarations' => [
        'declaration_title', 'company_name', 'company_address',
        'transport_type', 'line_type', 'party_no', 'product_name',
        'product_variety', 'crate_type', 'exit_depot', 'contact_person',
        'buyer_name', 'brand',
    ],
    'kantar_fisleri' => [
        'firma_adi', 'malin_cinsi', 'plaka', 'depo',
        'operator_adi', 'geldigi_yer', 'gittigi_yer', 'parti_no',
    ],
];

// Tablonun var olup olmadığını ve kolonun tabloda bulunup bulunmadığını kontrol et
function uc_table_exists(PDO $pdo, string $table): bool
{
    try {
        $r = $pdo->prepare("SHOW TABLES LIKE ?");
        $r->execute([$table]);
        return $r->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function uc_col_exists(PDO $pdo, string $table, string $col): bool
{
    try {
        $r = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
        $r->execute([$col]);
        return $r->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

// Bir tablonun PRIMARY KEY kolon adını döndür (genellikle 'id')
function uc_pk_col(PDO $pdo, string $table): string
{
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM `{$table}` WHERE `Key` = 'PRI'")->fetchAll();
        if (!empty($cols)) return $cols[0]['Field'];
    } catch (Throwable $e) {}
    return 'id';
}

// Dry-run: değişecek kayıtları analiz et, veri değiştirme
function uc_analyze(PDO $pdo, array $whitelist): array
{
    $results = [];
    foreach ($whitelist as $table => $cols) {
        if (!uc_table_exists($pdo, $table)) {
            $results[$table]['_missing'] = true;
            continue;
        }
        $pk = uc_pk_col($pdo, $table);
        foreach ($cols as $col) {
            if (!uc_col_exists($pdo, $table, $col)) {
                $results[$table][$col] = ['missing_col' => true];
                continue;
            }
            $st = $pdo->prepare(
                "SELECT `{$pk}`, `{$col}` FROM `{$table}` WHERE `{$col}` IS NOT NULL AND `{$col}` != ''"
            );
            $st->execute();
            $rows       = $st->fetchAll(PDO::FETCH_ASSOC);
            $change_cnt = 0;
            $samples    = [];
            foreach ($rows as $row) {
                $old = (string)$row[$col];
                $new = tr_upper($old);
                if ($new !== $old) {
                    $change_cnt++;
                    if (count($samples) < 3) {
                        $samples[] = ['old' => $old, 'new' => $new];
                    }
                }
            }
            $results[$table][$col] = [
                'change_cnt'  => $change_cnt,
                'total_rows'  => count($rows),
                'samples'     => $samples,
                'missing_col' => false,
            ];
        }
    }
    return $results;
}

// Apply: gerçek güncelleme — transaction içinde
function uc_apply(PDO $pdo, array $whitelist): array
{
    $summary = [];
    $pdo->beginTransaction();
    try {
        foreach ($whitelist as $table => $cols) {
            if (!uc_table_exists($pdo, $table)) continue;
            $pk = uc_pk_col($pdo, $table);
            foreach ($cols as $col) {
                if (!uc_col_exists($pdo, $table, $col)) continue;
                $st = $pdo->prepare(
                    "SELECT `{$pk}`, `{$col}` FROM `{$table}` WHERE `{$col}` IS NOT NULL AND `{$col}` != ''"
                );
                $st->execute();
                $rows    = $st->fetchAll(PDO::FETCH_ASSOC);
                $updated = 0;
                $upd_st  = $pdo->prepare("UPDATE `{$table}` SET `{$col}` = ? WHERE `{$pk}` = ?");
                foreach ($rows as $row) {
                    $old = (string)$row[$col];
                    $new = tr_upper($old);
                    if ($new !== $old) {
                        $upd_st->execute([$new, $row[$pk]]);
                        $updated++;
                    }
                }
                if ($updated > 0) {
                    $summary[$table][$col] = $updated;
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    return $summary;
}

// ── İstek işle ───────────────────────────────────────────

$mode = trim($_GET['mode'] ?? 'dry');
$pdo  = db();

$apply_done    = false;
$apply_summary = [];
$apply_error   = null;
$dry_results   = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'apply') {
    csrf_check($_POST['csrf'] ?? null);
    $confirm = trim($_POST['confirm'] ?? '');
    if ($confirm !== 'BUYUK_HARF_UYGULA') {
        $apply_error = 'Onay metni yanlış. Tam olarak <strong>BUYUK_HARF_UYGULA</strong> yazmalısınız.';
    } else {
        try {
            $apply_summary = uc_apply($pdo, $WHITELIST);
            $total_changed = array_sum(array_map('array_sum', $apply_summary));
            audit_log_event(
                'uppercase_existing_data',
                'admin',
                0,
                null,
                [
                    'user_id'       => (int)($auth_user['id'] ?? 0),
                    'total_changed' => $total_changed,
                    'detail'        => $apply_summary,
                    'mode'          => 'apply',
                ]
            );
            $apply_done = true;
            $mode = 'apply_result';
        } catch (Throwable $e) {
            $apply_error = 'Güncelleme hatası: ' . h($e->getMessage());
        }
    }
}

if ($mode === 'dry') {
    $dry_results = uc_analyze($pdo, $WHITELIST);
}

// ── Çıktı ─────────────────────────────────────────────────
render_header('Büyük Harf Dönüşümü (Admin)');
render_flash();
?>

<div class="page-head">
    <h1>🔠 Mevcut Kayıtları Büyük Harfe Çevir</h1>
    <a href="definitions.php" class="btn btn-ghost">← Tanımlar</a>
</div>

<div class="card" style="margin-bottom:16px">
    <div class="card-body">
        <p><strong>Bu araç nedir?</strong> Veritabanındaki mevcut metin alanlarını Türkçe büyük harf standardına çevirir.</p>
        <p class="muted" style="font-size:.87rem">
            i → İ &nbsp;·&nbsp; ı → I &nbsp;·&nbsp; ş → Ş &nbsp;·&nbsp;
            ğ → Ğ &nbsp;·&nbsp; ü → Ü &nbsp;·&nbsp; ö → Ö &nbsp;·&nbsp; ç → Ç
        </p>
        <p style="color:var(--danger);font-size:.87rem">
            ⚠ <strong>Uyarı:</strong> Apply işlemi öncesinde mutlaka veritabanı yedeği alın.
            Bu işlem geri alınamaz.
        </p>
    </div>
</div>

<?php if ($apply_error): ?>
<div class="flash flash-error"><?= $apply_error ?></div>
<?php endif; ?>

<?php if ($apply_done): ?>
<!-- ── Apply Sonucu ── -->
<div class="flash flash-success">Büyük harf dönüşümü tamamlandı.</div>
<div class="card">
    <div class="card-head"><h2>Güncelleme Özeti</h2></div>
    <div class="card-body">
        <?php
        $grand_total = 0;
        foreach ($apply_summary as $tbl => $cols):
            foreach ($cols as $col => $cnt): $grand_total += $cnt; ?>
        <div style="display:flex;gap:16px;padding:4px 0;border-bottom:1px solid var(--border)">
            <code style="flex:1"><?= h($tbl) ?>.<?= h($col) ?></code>
            <strong><?= $cnt ?> kayıt güncellendi</strong>
        </div>
        <?php endforeach; endforeach; ?>
        <?php if (empty($apply_summary)): ?>
        <p class="muted">Değiştirilecek kayıt bulunamadı — tüm değerler zaten büyük harfli.</p>
        <?php else: ?>
        <div style="margin-top:10px;font-size:1rem">
            <strong>Toplam: <?= $grand_total ?> kayıt güncellendi</strong>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- ── Sekme: Dry-run / Apply ── -->
<div style="display:flex;gap:8px;margin-bottom:16px">
    <a href="admin_uppercase_existing.php?mode=dry"
       class="btn <?= $mode === 'dry' ? 'btn-primary' : 'btn-ghost' ?>">
        🔍 Dry-Run (Önizleme)
    </a>
    <a href="#apply-form"
       class="btn <?= $mode === 'apply_form' ? 'btn-primary' : 'btn-ghost' ?>">
        ✅ Apply (Uygula)
    </a>
</div>

<?php if ($mode === 'dry' && !empty($dry_results)): ?>
<!-- ── Dry-run tablosu ── -->
<div class="card">
    <div class="card-head"><h2>🔍 Dry-Run Sonuçları <span class="muted" style="font-size:.85rem">(veri değiştirilmedi)</span></h2></div>
    <div class="card-body" style="padding:0">
        <div class="table-wrap">
        <table class="data-table" style="font-size:.87rem">
            <thead>
            <tr>
                <th>Tablo</th>
                <th>Kolon</th>
                <th class="num">Değişecek</th>
                <th class="num">Toplam</th>
                <th>Örnek Eski → Yeni</th>
            </tr>
            </thead>
            <tbody>
            <?php
            $total_will_change = 0;
            foreach ($dry_results as $tbl => $cols):
                if (!empty($cols['_missing'])):
            ?>
            <tr>
                <td><code><?= h($tbl) ?></code></td>
                <td colspan="4" style="color:var(--muted)">Tablo bulunamadı — atlandı</td>
            </tr>
            <?php continue; endif;
                foreach ($cols as $col => $info):
                    if (!is_array($info)) continue;
                    if (!empty($info['missing_col'])):
            ?>
            <tr>
                <td><code><?= h($tbl) ?></code></td>
                <td><code><?= h($col) ?></code></td>
                <td colspan="3" style="color:var(--muted)">Kolon bulunamadı — atlandı</td>
            </tr>
            <?php continue; endif;
                    $total_will_change += $info['change_cnt'];
                    $row_style = $info['change_cnt'] > 0 ? '' : 'color:var(--muted)';
            ?>
            <tr>
                <td><code><?= h($tbl) ?></code></td>
                <td><code><?= h($col) ?></code></td>
                <td class="num" style="<?= $row_style ?>">
                    <?= $info['change_cnt'] > 0 ? '<strong>' . $info['change_cnt'] . '</strong>' : '0' ?>
                </td>
                <td class="num" style="color:var(--muted)"><?= $info['total_rows'] ?></td>
                <td>
                    <?php foreach ($info['samples'] as $s): ?>
                    <div style="margin-bottom:2px">
                        <span style="color:var(--danger)"><?= h($s['old']) ?></span>
                        → <span style="color:var(--success)"><?= h($s['new']) ?></span>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($info['samples']) && $info['change_cnt'] === 0): ?>
                    <span class="muted">Tüm değerler zaten büyük harfli</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; endforeach; ?>
            </tbody>
            <tfoot>
            <tr>
                <td colspan="2"><strong>Toplam</strong></td>
                <td class="num"><strong><?= $total_will_change ?></strong></td>
                <td colspan="2"></td>
            </tr>
            </tfoot>
        </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Apply Formu ── -->
<div class="card" id="apply-form" style="margin-top:20px;border:2px solid var(--danger)">
    <div class="card-head" style="background:#fee2e2">
        <h2 style="color:#991b1b">✅ Apply — Büyük Harf Uygula</h2>
    </div>
    <div class="card-body">
        <p style="margin-bottom:12px">
            Bu işlem yukarıdaki <strong>tüm whitelist kolonlarını</strong> Türkçe büyük harfe çevirir.
            <br><strong style="color:var(--danger)">Önce veritabanı yedeği aldığınızdan emin olun.</strong>
        </p>
        <form method="post" action="admin_uppercase_existing.php">
            <input type="hidden" name="csrf"  value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="mode"  value="apply">
            <div style="margin-bottom:12px">
                <label style="display:block;margin-bottom:6px;font-weight:600">
                    Onay: Aşağıya tam olarak <code>BUYUK_HARF_UYGULA</code> yazın
                </label>
                <input type="text" name="confirm" class="form-control"
                       placeholder="BUYUK_HARF_UYGULA"
                       autocomplete="off"
                       style="max-width:320px;font-family:monospace;font-size:1rem;border:2px solid var(--danger)">
            </div>
            <label style="display:flex;align-items:center;gap:8px;margin-bottom:14px">
                <input type="checkbox" name="backup_confirm" required>
                Veritabanı yedeği aldım ve büyük harf dönüşümünü onaylıyorum.
            </label>
            <button type="submit" class="btn btn-danger btn-lg"
                    onclick="return confirm('Tüm whitelist kolonlarına büyük harf uygulanacak. Emin misiniz?')">
                Büyük Harf Uygula
            </button>
        </form>
    </div>
</div>

<?php endif; ?>

<?php render_footer(); ?>
