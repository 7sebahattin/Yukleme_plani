<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require_once dirname(__DIR__) . '/config/role_permission_matrix.php';
$permissions=[]; $checks=0;
function current_user(): ?array { return ['id'=>1]; }
function require_login(): array { return current_user(); }
function can(string $code): bool { return in_array($code, $GLOBALS['permissions'], true); }
function is_admin(): bool { return false; }
function require_perm(string $code): void { if (!can($code)) throw new RuntimeException('denied'); }
function require_hesap(string $action): void { require_perm('hesap.'.$action); }
function require_maliyet(string $action): void { require_perm('maliyet.'.$action); }
function gate_case(string $file, string $stop, array $perms, array $get, bool $expected): void {
    $GLOBALS['permissions']=role_matrix_expand_permissions($perms);
    $_GET=$get; $_POST=[]; $_SERVER['REQUEST_METHOD']='GET';
    $source=str_replace("\r\n","\n",file_get_contents(dirname(__DIR__).'/'.$file));
    $position=strpos($source,$stop);
    if ($position===false) throw new RuntimeException('Missing safe test boundary '.$file);
    $source=substr($source,0,$position);
    $source=preg_replace('/^require_once .*?;\s*$/m','',$source);
    $source=preg_replace('/^<\?php\s*/','',$source);
    $source=str_replace('declare(strict_types=1);','',$source);
    $allowed=true;
    try { eval($source); } catch (RuntimeException $e) { if ($e->getMessage()!=='denied') throw $e; $allowed=false; }
    $GLOBALS['checks']++;
    if ($allowed!==$expected) throw new RuntimeException('Gate behavior '.$file.' '.json_encode($get));
}
$marker=['role.permissions.detailed'];
foreach ([
    ['record_create.php', '$record = [', 'records.loading.create', 'records.loading.edit'],
    ['cikma_create.php', '$record = [', 'records.pallet.create', 'records.loading.create'],
    ['kantar_create.php', "if (!function_exists('_parse_kp_rows'))", 'kantar.create', 'kantar.edit'],
] as [$file,$stop,$grant,$other]) {
    gate_case($file,$stop,[...$marker,$grant],[],true);
    gate_case($file,$stop,[...$marker,$other],[],false);
    gate_case($file,$stop,$marker,[],false);
}
foreach ([['hesap_kayit.php','hesap_migrate();','hesap'],['maliyet_form.php','cost_migrate();','maliyet']] as [$file,$stop,$module]) {
    gate_case($file,$stop,[...$marker,"$module.create"],[],true);
    gate_case($file,$stop,[...$marker,"$module.create"],['id'=>1],false);
    gate_case($file,$stop,[...$marker,"$module.edit"],[],false);
    gate_case($file,$stop,[...$marker,"$module.edit"],['id'=>1],true);
    gate_case($file,$stop,["$module.write"],[],true);
    gate_case($file,$stop,["$module.write"],['id'=>1],true);
}
foreach ([['record_edit.php','records.loading.create'],['kantar_edit.php','kantar.create'],['api_bulk_material.php','records.loading.create'],['api_etiket_foto.php','records.loading.create']] as [$file,$grant]) {
    $source=file_get_contents(dirname(__DIR__).'/'.$file);
    if (!preg_match("/require_perm\('([^']+)'\);/",$source,$match)) throw new RuntimeException('Missing route guard');
    $permissions=role_matrix_expand_permissions([...$marker,$grant]);
    $denied=false; try { require_perm($match[1]); } catch (RuntimeException $e) { $denied=true; }
    $checks++; if (!$denied) throw new RuntimeException('Create-only can enter edit route '.$file);
}
echo "Endpoint action gates: $checks checks passed.\n";
