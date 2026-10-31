<?php
declare(strict_types=1);

// Stored alongside role permissions; no schema change or automatic migration.
function role_data_scope_code(string $depot): string {
    return 'scope.depot.' . substr(hash('sha256', $depot), 0, 32);
}

function role_data_scope_decode(array $permissions): array {
    $selected = in_array('scope.depots.selected', $permissions, true);
    $depots = []; $names = [];
    foreach ($permissions as $permission) {
        if (is_string($permission) && preg_match('/^scope\.name\.([a-f0-9]{16})\.([0-9]{2})\.([A-Za-z0-9_-]{1,28})$/D', $permission, $m)) $names[$m[1]][(int)$m[2]] = $m[3];
    }
    foreach ($names as $hash => $chunks) {
        ksort($chunks);
        if (array_keys($chunks) !== range(0, count($chunks) - 1)) continue;
        $encoded = implode('', $chunks);
        $value = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($value !== false && $value !== '' && strlen($value) <= 600 && preg_match('//u', $value)
            && substr(hash('sha256', $value), 0, 16) === (string)$hash && in_array(role_data_scope_code($value), $permissions, true)) $depots[] = $value;
    }
    return ['mode' => $selected ? 'selected' : 'all', 'depots' => array_values(array_unique($depots))];
}

function role_data_scope_options(PDO $pdo): array {
    $depots = [];
    foreach (["SELECT name FROM material_definitions WHERE type = 'depo'", "SELECT DISTINCT depo FROM user_depolar"] as $sql) {
        try {
            foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) as $value) {
                if (is_string($value) && trim($value) !== '' && mb_strlen($value) <= 150) $depots[$value] = true;
            }
        } catch (PDOException $e) { /* Missing optional source: offer only verified choices. */ }
    }
    // Keep a saved, subsequently removed depot visible so editing a role does
    // not silently discard its explicit restriction.
    try {
        $roles = [];
        foreach ($pdo->query("SELECT role_id, permission FROM role_permissions WHERE permission LIKE 'scope.%'")->fetchAll(PDO::FETCH_ASSOC) as $row) $roles[(int)$row['role_id']][] = $row['permission'];
        foreach ($roles as $permissions) foreach (role_data_scope_decode($permissions)['depots'] as $value) $depots[$value] = true;
    } catch (PDOException $e) { /* Retain choices from the remaining sources. */ }
    $out = array_keys($depots);
    sort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

// Accept either submitted scope fields or a raw permission list. Unknown depot
// codes cannot be injected; removed choices may be preserved only for that role.
function role_data_scope_permissions(array $raw, array $options): array {
    $mode = $raw['scope_mode'] ?? null;
    if ($mode === null) {
        $decoded = role_data_scope_decode($raw);
        $mode = $decoded['mode'];
        $values = $decoded['depots'];
    } else {
        $values = is_array($raw['scope_depots'] ?? null) ? $raw['scope_depots'] : [];
    }
    if ($mode === 'all') return [];
    if ($mode !== 'selected') return ['scope.depots.selected'];
    $out = ['scope.depots.selected'];
    foreach ($values as $depot) {
        if (is_string($depot) && in_array($depot, $options, true)) {
            $out[] = role_data_scope_code($depot);
            $encoded = rtrim(strtr(base64_encode($depot), '+/', '-_'), '=');
            foreach (str_split($encoded, 28) as $index => $chunk) $out[] = 'scope.name.' . substr(hash('sha256', $depot), 0, 16) . '.' . str_pad((string)$index, 2, '0', STR_PAD_LEFT) . '.' . $chunk;
        }
    }
    return array_values(array_unique($out));
}

// null = this role imposes no additional depot restriction; [] = deny all.
// Permissions are additive; explicit data boundaries are restrictive and
// intersect across roles. An unrelated or legacy role cannot remove a boundary.
function role_data_scope_union(array $roles): ?array {
    $out = null;
    foreach ($roles as $permissions) {
        $scope = role_data_scope_decode($permissions);
        if ($scope['mode'] === 'all') continue;
        $out = role_depot_scope_intersect($out, $scope['depots']);
    }
    return $out === null ? null : array_values(array_unique($out));
}

function role_user_depot_scope(int $userId): ?array {
    static $cache = [];
    if (array_key_exists($userId, $cache)) return $cache[$userId];
    try {
        $stmt = db()->prepare('SELECT rp.role_id, rp.permission FROM user_roles ur JOIN role_permissions rp ON rp.role_id = ur.role_id WHERE ur.user_id = ?');
        $stmt->execute([$userId]);
        $roles = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $roles[(int)$row['role_id']][] = $row['permission'];
        return $cache[$userId] = role_data_scope_union($roles);
    } catch (PDOException $e) {
        return $cache[$userId] = []; // Never widen a scope on a read failure.
    }
}

function role_depot_scope_intersect(?array $assigned, ?array $scope): ?array {
    if ($scope === null) return $assigned;
    if ($assigned === null) return $scope;
    return array_values(array_filter($assigned, static function ($depot) use ($scope) {
        foreach ($scope as $allowed) {
            if (function_exists('depo_fold') ? depo_fold($depot) === depo_fold($allowed) : $depot === $allowed) return true;
        }
        return false;
    }));
}

function role_current_depot_scope_restricted(): bool {
    $user = current_user();
    return $user !== null && !is_admin() && role_user_depot_scope((int)$user['id']) !== null;
}

function role_require_record_access(int $recordId, string $action = 'read'): void {
    $stmt = db()->prepare('SELECT type FROM loading_records WHERE id = ?');
    $stmt->execute([$recordId]);
    $type = $stmt->fetchColumn();
    $module = match ($type) { 'yukleme' => 'loading', 'cikma' => 'pallet', default => null };
    if ($module === null || !can('records.' . $module . '.' . $action)) forbidden('Bu kayıt için işlem yetkiniz yok.');
    if (!role_current_depot_scope_restricted()) return;
    $stmt = db()->prepare('SELECT depo FROM loading_pallets WHERE loading_record_id = ?');
    $stmt->execute([$recordId]);
    $depots = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!$depots) forbidden('Bu kayıt seçili depo kapsamına ait değil.');
    foreach ($depots as $depot) {
        if (!depot_visible_to_user($depot === null ? null : (string)$depot)) forbidden('Bu kayıt seçili depo kapsamına ait değil.');
    }
}

function role_require_depot_value(?string $depot): void {
    if (role_current_depot_scope_restricted() && !depot_visible_to_user($depot)) forbidden('Bu işlem seçili depo kapsamının dışında.');
}

function role_require_pallet_depots(array $pallets): void {
    if (!role_current_depot_scope_restricted()) return;
    if (!$pallets) forbidden('En az bir izinli depolu palet gereklidir.');
    foreach ($pallets as $pallet) role_require_depot_value((string)($pallet['depo'] ?? ''));
}

function role_require_kantar_scope(int $id): void {
    if (!role_current_depot_scope_restricted()) return;
    $stmt = db()->prepare('SELECT depo FROM kantar_fisleri WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) forbidden('Fiş bulunamadı.');
    role_require_depot_value($row['depo'] === null ? null : (string)$row['depo']);
}
