# Güvenlik Notları

## Auth

- Cookie-based session (`asya_session`)
- `require_login()` — session yoksa login'e yönlendir
- `can('perm')` — rol tabanlı yetki kontrolü
- `is_admin()` — admin özel işlemler
- `forbidden()` — AJAX-aware (Content-Type/Accept'e göre JSON veya HTML 403 döner)

## Permissions

Her write/delete/import/export endpoint'i şunları kontrol eder:
1. `require_login()` — oturum
2. `require_perm('records.write')` veya `can('...')` — yetki
3. Kilitli kayıt için ek `can('records.unlock')` kontrolü

Viewer yazamaz, Operator silemez, Muhasebe rapor/stok dışına erişemez.

## CSRF

- Token: `$_SESSION['csrf']`, sayfaya `<meta name="csrf-token">` ile eklenir
- Form POST: `csrf_check($_POST['csrf'] ?? null)`
- JSON API: `csrf_check($input['csrf'] ?? null)` — `is_json_request()` + `headers_list()` ile JSON tespiti
- **`csrf_check()` JSON-aware:** başarısız olunca `{"ok":false,"error":"...","code":403}` döner ve `exit`
- **Eski pattern** `try { csrf_check() } catch` — YOK, csrf_check exception atmaz, `exit` kullanır

## Audit

- `audit_log_event(action, module, record_id, old, new)` — her zaman try/catch içinde, asla exception atmaz
- `_audit_sanitize()` filtreler: `password`, `token`, `csrf`, `cookie`, `foto_data`, `session`
- String >1000 char truncate edilir
- Loglanması gerekenler: create, update, delete, lock, revision_open, status_change, upload, hks_send/failed

## Kilitli Kayıtlar

- `durum='yuklendi'` → `locked_at` + `locked_by` set → kayıt kilitli
- Kilit açma: `records.unlock` yetkisi + `revision_reason` zorunlu
- Audit: `revision_open` action

## Hassas Veri

- DB credentials: `config/db.php` içinde plaintext (`.env`'e taşınmadı — teknik borç)
- Audit'e asla: password_hash, token, csrf, cookie, API key
- Git'e commit edilmemiş hassas dosya: `.gitignore`'da `backups/`, `scripts/*.sql`

## Web Root Korumaları

- `hks/.htaccess` — `HksClient/HksRepository/HksConfig/helpers.php`'e HTTP erişim kapalı
- `.sql/.bak/.log` dosyalarına erişim kapalı
- `setup_admin.php`, `database.sql`, `hks/migrate.sql` — silindi (Sprint 29A)
- `scripts/` dizini: `deploy.php`, `diagnostik.php`, `migrate.php` — CLI-only guard var

## CLI-Only Scripts

```php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Bu script yalnızca CLI üzerinden çalıştırılabilir.');
}
```

## Bilinen Kalan Riskler

| Risk | Durum |
|---|---|
| DB credentials plaintext | Açık — `.env`'e taşıma planlandı |
| Git history eski SQL dump | Kontrol edilmedi — `git log --all --name-only` ile taranabilir |
| `migrate_normalize_v2.php` dual-mode | CLI-only'ye geçmedi, web erişimi var |
| Audit log retention yok | 180 gün arşivleme planlandı, kod yok |
| `record_view.php` N+1 sorgu | İncelenmedi |
