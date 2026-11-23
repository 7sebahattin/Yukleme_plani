<?php
// Frugro Siparişler — Roller ekranındaki KENDİ satırı (siparis.frugro.*) + geri uyumluluk.
// (a) eski düz rol → Frugro'ya OGL düzeyinde erişir · (b) Frugro satırından önce kaydedilmiş detaylı rol
// (siparis.frugro.* hiç yok) → OGL'den türetilir · (c) güncel sürümde Frugro kapatılıp kaydedilmiş rol → Frugro YOK,
// OGL var · (d) tersi. DB yok; can() rol başına genişletmeyi (role_matrix_expand_permissions) kullanır.
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__) . '/config/role_permission_matrix.php';
$grants = []; $administrator = false; $mailOku = true; $checks = 0; $fails = 0;
function can(string $p): bool { global $grants; return in_array($p, role_matrix_expand_permissions($grants), true); }
function is_admin(): bool { global $administrator; return $administrator; }
function current_user(): ?array { return ['id' => 1]; }
function enforce_active_depot(): void {}
function forbidden(string $message = ''): void { throw new RuntimeException('denied'); }
function siparis_mail_yetkisi(): bool { global $mailOku; return $mailOku && can_siparis('write'); }
function siparis_mail_ayar_yetkisi(): bool { global $mailOku; return $mailOku && can_siparis('delete'); }
function t(string $ad, bool $ok): void { global $checks, $fails; $checks++; if (!$ok) { $fails++; echo "FAIL $ad\n"; } }
function cikar(string $path, string $name): void { $s = file_get_contents($path); $a = strpos($s, 'function ' . $name . '('); $b = strpos($s, "\n}\n", $a) + 2; eval(substr($s, $a, $b - $a)); }
$root = dirname(__DIR__);
cikar($root . '/config/helpers.php', 'can_siparis');
cikar($root . '/config/siparis_core.php', 'require_siparis');
cikar($root . '/config/frugro_ui.php', 'frgui_yetki');
cikar($root . '/config/frugro_mail.php', 'frgm_yetkisi');
cikar($root . '/config/frugro_mail.php', 'frgm_ayar_yetkisi');
$gecer = static function (string $perm, string $page): bool { try { require_siparis($perm, $page); return true; } catch (RuntimeException $e) { return false; } };
$frg = static fn(): array => array_map(static fn($a) => frgui_yetki($a), ['read' => 'read', 'create' => 'create', 'edit' => 'edit', 'delete' => 'delete']);
$ogl = static fn(): array => array_map(static fn($a) => role_matrix_page_can('siparis', 'ogl', $a), ['read' => 'read', 'create' => 'create', 'edit' => 'edit', 'delete' => 'delete']);
$V = 'role.catalog.' . ROLE_CATALOG_VERSION;
$M = ['role.permissions.detailed', 'role.sections.detailed', $V];   // Roller ekranının bugün yazdığı işaretleyiciler

// ── Katalog / şema ──
t('sürüm: Frugro satırı güncel katalog sürümünde', ROLE_FRUGRO_SINCE === ROLE_CATALOG_VERSION && ROLE_CATALOG_VERSION >= 3);
t('sayfa listesi ogl, urun, frugro', role_siparis_pages() === ['ogl', 'urun', 'frugro']);
$kat = role_matrix_catalog_additions();
foreach (['read', 'create', 'edit', 'delete'] as $a) t("katalog: siparis.frugro.$a", isset($kat["siparis.frugro.$a"]));
t('katalog: güncel sürüm işaretleyicisi', isset($kat[$V]));
$satir = null; foreach (role_permission_matrix()['Operasyon'] as $r) if ($r['key'] === 'frugro') $satir = $r;
t('matris: Frugro Siparişler satırı + CRUD', $satir !== null && $satir['route'] === 'frugro_siparis.php' && array_keys($satir['actions']) === ['read', 'create', 'edit', 'delete'] && $satir['actions']['edit']['permission'] === 'siparis.frugro.edit');
t('özet şeması: frugro sayfası', in_array('frugro', array_column(role_ozet_sema(), 'key'), true));

// ── (a) eski düz rol (işaretleyicisiz) ──
$grants = ['siparis.read', 'siparis.write'];
t('(a) düz read+write: Frugro okur/ekler/düzenler, silemez', $frg() === ['read' => true, 'create' => true, 'edit' => true, 'delete' => false] && $gecer('read', 'frugro') && !$gecer('delete', 'frugro'));
t('(a) düz read+write: OGL aynı düzeyde', $ogl() === $frg());
t('(a) düz: Mailden al (frgm_yetkisi) açık', frgm_yetkisi());
$grants = ['siparis.read', 'siparis.write', 'siparis.delete'];
t('(a) düz read+write+delete: Frugro silme + ayar', $frg()['delete'] && $gecer('delete', 'frugro') && frgm_ayar_yetkisi());
$grants = ['siparis.read'];
t('(a) düz salt okuma: Frugro yalnız Gör', $frg() === ['read' => true, 'create' => false, 'edit' => false, 'delete' => false] && !frgm_yetkisi());
$grants = ['records.read'];
t('(a) sipariş izni yok: Frugro kapalı', !$gecer('read', 'frugro') && !in_array(true, $frg(), true));

// ── (b) Frugro satırından ÖNCE kaydedilmiş detaylı roller (siparis.frugro.* yok) ──
$grants = ['role.permissions.detailed', 'siparis.ogl.read', 'siparis.ogl.edit'];   // v465 matrisi
t('(b) v465 detaylı ogl.read+edit → Frugro read+edit', $frg() === ['read' => true, 'create' => false, 'edit' => true, 'delete' => false] && $gecer('read', 'frugro'));
$grants = ['role.permissions.detailed', 'role.sections.detailed', 'siparis.ogl.read', 'siparis.ogl_acik.read', 'siparis.ogl_acik.create', 'siparis.ogl_kapali.delete'];   // v505 kartlı
t('(b) v505 kartlı → Frugro OGL türevleriyle aynı', $frg() === ['read' => true, 'create' => true, 'edit' => false, 'delete' => true] && $gecer('delete', 'frugro'));
$grants = ['role.permissions.detailed', 'role.sections.detailed', 'role.catalog.2', 'siparis.ogl.read', 'siparis.ogl_acik.read', 'siparis.ogl_acik.edit', 'siparis.ogl_evrak.edit'];   // v507 içerikli
t('(b) v507 (role.catalog.2) → Frugro read+edit', $frg() === ['read' => true, 'create' => false, 'edit' => true, 'delete' => false]);
$grants = ['role.permissions.detailed', 'role.sections.detailed', 'role.catalog.2', 'siparis.urun.read', 'siparis.urun.edit'];
t('(b) eski detaylı yalnız Ürün Siparişleri → Frugro AÇILMAZ (OGL yoktu)', !$gecer('read', 'frugro') && !$frg()['edit']);
// Roller ekranı eski rolü genişletilmiş haliyle gösterir; olduğu gibi kaydedilirse erişim aynı kalır (idempotent).
$eski = ['role.permissions.detailed', 'role.sections.detailed', 'role.catalog.2', 'siparis.ogl.read', 'siparis.ogl_acik.read', 'siparis.ogl_acik.create'];
$gor = array_values(array_filter(role_matrix_expand_permissions($eski), static fn($p) => !str_starts_with($p, 'role.catalog.')));
t('(b) ekran eski role Frugro kutularını işaretli gösterir', in_array('siparis.frugro.read', $gor, true) && in_array('siparis.frugro.create', $gor, true) && !in_array('siparis.frugro.edit', $gor, true));
$grants = array_merge($gor, [$V]);
t('(b) değiştirmeden kaydedilen rol Frugro erişimini korur', $frg() === ['read' => true, 'create' => true, 'edit' => false, 'delete' => false]);

// ── (c) güncel sürümde Frugro KAPATILIP kaydedilmiş rol ──
$grants = array_merge($M, ['siparis.ogl.read', 'siparis.ogl_acik.read', 'siparis.ogl_acik.create', 'siparis.ogl_acik.edit', 'siparis.ogl_acik.delete']);
t('(c) Frugro kapalı: sayfa 403, hiçbir eylem yok', !$gecer('read', 'frugro') && !in_array(true, $frg(), true) && !frgm_yetkisi() && !frgm_ayar_yetkisi());
t('(c) Frugro kapalı: OGL açık', $gecer('read', 'ogl') && role_ogl_any('edit') && role_matrix_page_can('siparis', 'ogl', 'read'));
$grants = array_merge($M, ['siparis.ogl.read', 'siparis.ogl_acik.read', 'siparis.ogl_acik.edit', 'siparis.ogl_acik.delete', 'siparis.frugro.read']);
t('(c) OGL tam + Frugro yalnız Gör: modül siparis.write olsa da Frugro yazamaz', can_siparis('write') && can_siparis('delete') && $frg() === ['read' => true, 'create' => false, 'edit' => false, 'delete' => false] && !$gecer('delete', 'frugro') && !frgm_yetkisi() && !frgm_ayar_yetkisi());

// ── (d) tersi: yalnız Frugro ──
$grants = array_merge($M, ['siparis.frugro.read', 'siparis.frugro.create', 'siparis.frugro.edit']);
t('(d) yalnız Frugro: Frugro read/create/edit, silme yok', $frg() === ['read' => true, 'create' => true, 'edit' => true, 'delete' => false] && $gecer('write', 'frugro') && !$gecer('delete', 'frugro'));
t('(d) yalnız Frugro: OGL ve Ürün Siparişleri 403', !$gecer('read', 'ogl') && !$gecer('read', 'urun') && !role_ogl_any('read') && !role_ogl_any('edit'));
t('(d) yalnız Frugro: modül izinleri sayfadan türer (siparis.read/write)', can_siparis('read') && can_siparis('write') && !can_siparis('delete'));
$grants = array_merge($M, ['siparis.frugro.read', 'siparis.frugro.delete']);
t('(d) Frugro read+delete: silme + ayar (mail yönetimi ile)', $frg()['delete'] && $gecer('delete', 'frugro') && frgm_ayar_yetkisi());

// ── Admin her zaman ──
$administrator = true; $grants = [];
t('admin: Frugro tüm eylemler + OGL', $frg() === ['read' => true, 'create' => true, 'edit' => true, 'delete' => true] && $gecer('delete', 'frugro') && $gecer('read', 'ogl'));
$administrator = false;

echo "Frugro role matrix: " . ($checks - $fails) . " geçti, $fails kaldı\n";
exit($fails > 0 ? 1 : 0);
