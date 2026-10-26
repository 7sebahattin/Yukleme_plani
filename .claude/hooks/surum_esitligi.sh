#!/usr/bin/env bash
# Stop: sürüm eşitliği denetimi.
#  1) sw.js `const CACHE_NAME = 'yukleme-plani-vNNN';` ile config/helpers.php
#     `define('APP_SURUM', 'vNNN');` AYNI olmalı → farklıysa durmayı ENGELLER (decision: block).
#  2) Dal origin/main'e göre assets/style.css, assets/app.js ya da sw.js'i değiştirmiş ama
#     sürüm origin/main'deki sürümden büyük değilse ENGELLEMEYEN hatırlatma (additionalContext)
#     — oturum başına bir kez (işaret dosyası .git içinde).
# Döngü koruması: stop_hook_active=true ise hiçbir şey yapmaz.
# Test için: HOOK_KOK (depo kökü) ve HOOK_ANA_REF (varsayılan origin/main) değiştirilebilir.
set -u
. "$(dirname "${BASH_SOURCE[0]}")/_ortak.sh"

json_arac_var || exit 0
girdi_oku
[ "$(json_al stop_hook_active)" = "true" ] && exit 0

KOK="$(hook_kok)"
SW="$KOK/sw.js"
HP="$KOK/config/helpers.php"
[ -f "$SW" ] && [ -f "$HP" ] || exit 0

sw_surum()  { sed -n "s/^[[:space:]]*const CACHE_NAME = 'yukleme-plani-\(v[0-9][0-9]*\)';.*/\1/p" | head -n1; }
app_surum() { sed -n "s/.*define('APP_SURUM', '\(v[0-9][0-9]*\)');.*/\1/p" | head -n1; }

v_sw="$(sw_surum < "$SW")"
v_app="$(app_surum < "$HP")"

if [ -z "$v_sw" ] || [ -z "$v_app" ] || [ "$v_sw" != "$v_app" ]; then
    neden="Sürüm uyuşmazlığı: sw.js CACHE_NAME = '${v_sw:-bulunamadı}', config/helpers.php APP_SURUM = '${v_app:-bulunamadı}'. İkisini AYNI sayıya çekin (sw.js: const CACHE_NAME = 'yukleme-plani-vNNN'; · helpers.php: define('APP_SURUM', 'vNNN');). Sürümü artırmadan önce origin/main'deki güncel APP_SURUM'a bakın (CLAUDE.md › Geliştirme Kontrol Listesi)."
    printf '{"decision":"block","reason":%s}\n' "$(json_kacir "$neden")"
    exit 0
fi

# --- 2) Hatırlatma: değişen kritik dosya + artırılmamış sürüm ---
arac_var git || exit 0
git -C "$KOK" rev-parse --git-dir >/dev/null 2>&1 || exit 0
ANA="${HOOK_ANA_REF:-origin/main}"
git -C "$KOK" rev-parse --verify -q "$ANA^{commit}" >/dev/null 2>&1 || exit 0

degisen="$( {
    git -C "$KOK" diff --name-only "$ANA...HEAD" 2>/dev/null
    git -C "$KOK" diff --name-only HEAD 2>/dev/null
    git -C "$KOK" ls-files --others --exclude-standard -- assets/style.css assets/app.js sw.js 2>/dev/null
} | sort -u )"
printf '%s\n' "$degisen" | grep -qxE 'assets/style\.css|assets/app\.js|sw\.js' || exit 0

v_ana="$(git -C "$KOK" show "$ANA:config/helpers.php" 2>/dev/null | app_surum)"
[ -n "$v_ana" ] || exit 0
n_app="${v_app#v}"; n_ana="${v_ana#v}"
[ "$n_app" -gt "$n_ana" ] 2>/dev/null && exit 0

# Oturum başına bir kez hatırlat.
oturum="$(json_al session_id)"
isaret="$(git -C "$KOK" rev-parse --path-format=absolute --git-path claude-surum-hatirlatma 2>/dev/null)"
anahtar="${oturum:-oturumsuz}|$v_app|$v_ana"
if [ -n "$isaret" ] && [ -f "$isaret" ] && grep -qxF "$anahtar" "$isaret" 2>/dev/null; then
    exit 0
fi
[ -n "$isaret" ] && printf '%s\n' "$anahtar" >> "$isaret" 2>/dev/null

dosyalar="$(printf '%s\n' "$degisen" | grep -xE 'assets/style\.css|assets/app\.js|sw\.js' | tr '\n' ' ')"
mesaj="Hatırlatma (engel değil): bu dal ${ANA}'e göre ${dosyalar}değiştirdi ama sürüm ${v_app} (${ANA}: ${v_ana}) artırılmamış. Service Worker önbelleği tazelensin diye sw.js CACHE_NAME ve config/helpers.php APP_SURUM'u birlikte ${ANA}'deki sürümün bir üstüne çekin (önce git fetch origin main). Bilinçliyse yok sayın."
printf '{"hookSpecificOutput":{"hookEventName":"Stop","additionalContext":%s}}\n' "$(json_kacir "$mesaj")"
exit 0
