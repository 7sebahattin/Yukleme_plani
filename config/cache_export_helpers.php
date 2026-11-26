<?php
// =========================================================
// config/cache_export_helpers.php — JSON kaçış, hafif önbellek ve ağır dışa aktarım yardımcıları
// config/helpers.php facade'ı üzerinden otomatik yüklenir (B-30, B-31, B-37, B-42).
// =========================================================
declare(strict_types=1);

if (!function_exists('json_html')) {
    /** HTML / <script> içi güvenli JSON (B-42). */
    function json_html($v): string {
        $j = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        return $j !== false ? $j : 'null';
    }
}

if (!function_exists('_app_cache_key')) {
    function _app_cache_key(string $key): string {
        $ns = (defined('DB_NAME') ? (string)DB_NAME : 'app') . ':' . (defined('APP_SURUM') ? (string)APP_SURUM : 'v0');
        return 'asya:' . $ns . ':' . $key;
    }
}

if (!function_exists('app_cache_get')) {
    /** Hafif önbellek katmanı: APCu varsa APCu, yoksa istek-içi bellek (B-30). */
    function app_cache_get(string $key, bool &$hit = false) {
        $k = _app_cache_key($key);
        if (isset($GLOBALS['_app_cache_mem']) && array_key_exists($k, $GLOBALS['_app_cache_mem'])) {
            $hit = true;
            return $GLOBALS['_app_cache_mem'][$k];
        }
        if (function_exists('apcu_fetch') && (PHP_SAPI !== 'cli' || ini_get('apc.enable_cli'))) {
            $ok = false;
            $val = @apcu_fetch($k, $ok);
            if ($ok) {
                $GLOBALS['_app_cache_mem'][$k] = $val;
                $hit = true;
                return $val;
            }
        }
        $hit = false;
        return null;
    }
}

if (!function_exists('app_cache_set')) {
    function app_cache_set(string $key, $val, int $ttl = 60): void {
        $k = _app_cache_key($key);
        $GLOBALS['_app_cache_mem'][$k] = $val;
        if (function_exists('apcu_store') && (PHP_SAPI !== 'cli' || ini_get('apc.enable_cli'))) {
            @apcu_store($k, $val, max(1, $ttl));
        }
    }
}

if (!function_exists('app_cache_del')) {
    function app_cache_del(string $key): void {
        $k = _app_cache_key($key);
        unset($GLOBALS['_app_cache_mem'][$k]);
        if (function_exists('apcu_delete') && (PHP_SAPI !== 'cli' || ini_get('apc.enable_cli'))) {
            @apcu_delete($k);
        }
    }
}

if (!function_exists('export_prepare_runtime')) {
    /** Ağır PDF / Excel dışa aktarım çalışma ortamı hazırlığı (B-31). */
    function export_prepare_runtime(int $memMb = 256, int $sec = 120): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        $lim = ini_get('memory_limit');
        if ($lim !== false && $lim !== '-1') {
            $v = trim((string)$lim);
            $n = (int)$v;
            $bytes = match (strtolower(substr($v, -1))) {
                'g' => $n * 1073741824,
                'm' => $n * 1048576,
                'k' => $n * 1024,
                default => $n,
            };
            if ($bytes < $memMb * 1048576) {
                @ini_set('memory_limit', $memMb . 'M');
            }
        }
        if (PHP_SAPI !== 'cli') {
            @set_time_limit($sec);
        }
    }
}
