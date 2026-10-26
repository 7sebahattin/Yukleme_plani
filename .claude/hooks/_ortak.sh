#!/usr/bin/env bash
# Claude Code hook'larının ortak yardımcıları (source edilir, doğrudan çalıştırılmaz).
# Kural: hook'lar geliştirici makinesinde araç (jq/php/git) eksikse oturumu ASLA bozmaz — sessizce exit 0.

# Depo kökü: testler için HOOK_KOK, yoksa CLAUDE_PROJECT_DIR, yoksa bu dosyanın iki üstü.
hook_kok() {
    if [ -n "${HOOK_KOK:-}" ]; then printf '%s' "$HOOK_KOK"; return; fi
    if [ -n "${CLAUDE_PROJECT_DIR:-}" ]; then printf '%s' "$CLAUDE_PROJECT_DIR"; return; fi
    (cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
}

# Araç var mı? Test için HOOK_ARAC_KAPALI="jq php" ile araç yokmuş gibi davranılır.
arac_var() {
    case " ${HOOK_ARAC_KAPALI:-} " in *" $1 "*) return 1 ;; esac
    command -v "$1" >/dev/null 2>&1
}

# stdin JSON'u bir kez okur (GIRDI değişkenine).
girdi_oku() { GIRDI="$(cat)"; }

# JSON ayrıştırıcı var mı? (jq tercih, yoksa php)
json_arac_var() { arac_var jq || arac_var php; }

# json_al "tool_input.file_path" → değer (yoksa boş). Boolean "true"/"false" döner.
json_al() {
    if arac_var jq; then
        printf '%s' "$GIRDI" | jq -r --arg p "$1" \
            'try (getpath($p|split(".")) | select(. != null) | if type=="string" then . else tojson end) catch empty' 2>/dev/null
    elif arac_var php; then
        printf '%s' "$GIRDI" | php -r '
            $d = json_decode(stream_get_contents(STDIN), true);
            $v = $d;
            foreach (explode(".", $argv[1]) as $k) {
                if (!is_array($v) || !array_key_exists($k, $v)) { exit(0); }
                $v = $v[$k];
            }
            if ($v === null) { exit(0); }
            echo is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        ' "$1" 2>/dev/null
    fi
}

# JSON dizgesi kaçışı (çıktı JSON'u kurmak için).
json_kacir() {
    if arac_var jq; then
        printf '%s' "$1" | jq -Rs .
    else
        printf '%s' "$1" | php -r 'echo json_encode(stream_get_contents(STDIN), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);'
    fi
}
