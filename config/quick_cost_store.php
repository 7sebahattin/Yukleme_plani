<?php
// Hızlı Maliyet deposu. DDL/otomatik migration yok; PDO uygulamadan enjekte edilir.
declare(strict_types=1);
require_once __DIR__ . '/quick_cost.php';

class QuickCostAccessException extends RuntimeException {}
class QuickCostConflictException extends RuntimeException {}

function quick_cost_storage_ready(PDO $pdo): bool
{
    try {
        $pdo->query('SELECT id, version FROM quick_cost_sheets WHERE 1=0');
        return true;
    } catch (PDOException $e) {
        if ($e->getCode() === '42S02'
            || ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && str_contains($e->getMessage(), 'no such table: quick_cost_sheets'))) {
            return false;
        }
        throw $e;
    }
}

/** İlk sürüm yalnız yöneticiye açık; yetki ve aktif depo ayrıca korunur. */
function quick_cost_access(bool $write = false): array
{
    $user = function_exists('current_user') ? current_user() : null;
    // Depo yardımcıları tanım tablolarını okuyabilir: yönetici kapısı önce.
    if (!$user || (int)($user['id'] ?? 0) <= 0 || !function_exists('is_admin') || !is_admin()) {
        throw new QuickCostAccessException('Hızlı Maliyet şu anda yalnız sistem yöneticisine açık.');
    }
    $depot = function_exists('active_depot') ? active_depot() : null;
    if (!function_exists('can_maliyet')
        || !can_maliyet('read') || ($write && !can_maliyet('write'))
        || !is_string($depot) || trim($depot) === '' || mb_strlen($depot) > 150
        || !function_exists('depot_visible_to_user') || !depot_visible_to_user($depot)) {
        throw new QuickCostAccessException('Hızlı Maliyet için yönetici, maliyet yetkisi ve aktif depo gerekli.');
    }
    return [(int)$user['id'], $depot];
}

/** Aktif depo zorunludur; uygulamanın ek depo kapsamı da korunur. */
function quick_cost_scope(string $depot): array
{
    if (!function_exists('depo_sql_in')) throw new QuickCostAccessException('Depo kapsamı kullanılamıyor.');
    [$extra, $params] = depo_sql_in('depo');
    return ['depo = ?' . ($extra !== '' ? ' AND (' . $extra . ')' : ''), array_merge([$depot], $params)];
}

function quick_cost_decode_row(array $row): array
{
    $document = json_decode((string)$row['document_json'], true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($document)) throw new RuntimeException('Maliyet kaydı okunamıyor.');
    // Kayıt açılışında da hesap sunucuda doğrulanır.
    $row['document'] = quick_cost_calculate($document);
    unset($row['document_json']);
    foreach (['id', 'version', 'created_by', 'updated_by'] as $key) $row[$key] = (int)$row[$key];
    return $row;
}

function quick_cost_find(PDO $pdo, int $id): ?array
{
    [, $depot] = quick_cost_access();
    [$scope, $params] = quick_cost_scope($depot);
    $statement = $pdo->prepare('SELECT * FROM quick_cost_sheets WHERE id = ? AND ' . $scope);
    $statement->execute(array_merge([$id], $params));
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    if (!depot_visible_to_user((string)$row['depo'])) throw new QuickCostAccessException('Kayıt aktif depoda görünmüyor.');
    return quick_cost_decode_row($row);
}

function quick_cost_list(PDO $pdo, int $page = 1, int $perPage = 20, string $query = ''): array
{
    [, $depot] = quick_cost_access();
    [$scope, $params] = quick_cost_scope($depot);
    $query = quick_cost_text($query, 'Arama', 150);
    $page = max(1, $page);
    $perPage = max(1, min(50, $perPage));
    if ($query !== '') {
        // ESCAPE '!' hem MySQL hem SQLite üzerinde aynı anlamdadır.
        $scope .= " AND product LIKE ? ESCAPE '!'";
        $params[] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query) . '%';
    }
    $statement = $pdo->prepare('SELECT COUNT(*) FROM quick_cost_sheets WHERE ' . $scope);
    $statement->execute($params);
    $total = (int)$statement->fetchColumn();
    $pages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $pages);
    $statement = $pdo->prepare('SELECT id, product, sheet_date, operation_type, method, net_kg, total, unit_cost,
        version, depo, created_by, updated_by, created_at, updated_at FROM quick_cost_sheets WHERE ' . $scope
        . ' ORDER BY sheet_date DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage));
    $statement->execute($params);
    return ['items' => $statement->fetchAll(PDO::FETCH_ASSOC), 'total' => $total, 'page' => $page, 'pages' => $pages];
}

function quick_cost_audit_summary(array $record): array
{
    return array_intersect_key($record, array_flip(['product', 'sheet_date', 'operation_type', 'method',
        'net_kg', 'total', 'unit_cost', 'depo', 'version']));
}

/** Kopyalama: find()->document gönderilip id=0 çağrılır; kaynak satır değişmez. */
function quick_cost_save(PDO $pdo, array $input, int $id = 0, int $expectedVersion = 0): array
{
    [$userId, $depot] = quick_cost_access(true);
    if ($id < 0 || ($id > 0 && $expectedVersion < 1)) throw new InvalidArgumentException('Kayıt sürümü geçersiz.');
    $document = quick_cost_calculate($input);
    if ($document['product'] === '') throw new InvalidArgumentException('Ürün adı gerekli.');
    if ($document['net_kg'] <= 0) throw new InvalidArgumentException('Satılacak net kilogram sıfırdan büyük olmalı.');
    if (!function_exists('audit_log_event')) throw new RuntimeException('Audit altyapısı kullanılamıyor.');
    if ($pdo->inTransaction()) throw new LogicException('Hızlı Maliyet ayrı bir işlem gerektirir.');
    $now = date('Y-m-d H:i:s');
    $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $values = [$document['product'], $document['sheet_date'], $document['operation_type'], $document['method'],
        $document['net_kg'], $json, $document['total'], $document['unit_cost'], $document['notes']];
    $pdo->beginTransaction();
    try {
        $old = null;
        if ($id > 0) {
            $old = quick_cost_find($pdo, $id);
            if (!$old) throw new QuickCostAccessException('Kayıt bulunamadı veya aktif depoda görünmüyor.');
            if ($old['version'] !== $expectedVersion) throw new QuickCostConflictException('Bu kayıt başka bir oturumda değişti. Sayfayı yeniden açın.');
            [$scope, $params] = quick_cost_scope($depot);
            $statement = $pdo->prepare('UPDATE quick_cost_sheets SET product=?, sheet_date=?, operation_type=?, method=?,
                net_kg=?, document_json=?, total=?, unit_cost=?, notes=?, updated_by=?, updated_at=?, version=version+1
                WHERE id=? AND version=? AND ' . $scope);
            $statement->execute(array_merge($values, [$userId, $now, $id, $expectedVersion], $params));
            if ($statement->rowCount() !== 1) throw new QuickCostConflictException('Bu kayıt başka bir oturumda değişti. Sayfayı yeniden açın.');
        } else {
            $statement = $pdo->prepare('INSERT INTO quick_cost_sheets
                (product, sheet_date, operation_type, method, net_kg, document_json, total, unit_cost, notes,
                 depo, created_by, updated_by, created_at, updated_at, version) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)');
            $statement->execute(array_merge($values, [$depot, $userId, $userId, $now, $now]));
            $id = (int)$pdo->lastInsertId();
        }
        $saved = quick_cost_find($pdo, $id);
        if (!$saved) throw new RuntimeException('Kaydedilen maliyet okunamadı.');
        // Mevcut helper kendi hata politikasını korur (audit hatasını loglayabilir).
        audit_log_event($old ? 'update' : 'create', 'quick_cost_sheets', $id,
            $old ? quick_cost_audit_summary($old) : null, quick_cost_audit_summary($saved));
        $pdo->commit();
        return $saved;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
