#!/usr/bin/env bash
# .claude/hooks/ altındaki Claude Code hook'larının testi (ağsız, depoya yazmaz; geçici klasör kullanır).
# Çalıştır: bash .claude/hooks/test_hooks.sh   → tümü geçerse "TAMAM", çıkış 0.
# Her senaryo jq ile VE jq yokmuş gibi (php yedeği) ayrı ayrı çalışır.
set -u
H="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
KOK="$(cd "$H/../.." && pwd)"
GEC="$(mktemp -d)"
trap 'rm -rf "$GEC"' EXIT
GECTI=0; KALDI=0

ok()   { GECTI=$((GECTI+1)); }
hata() { KALDI=$((KALDI+1)); echo "  ✗ $*"; }

# json_yap <alan=değer>... → basit JSON (değerler dizge; true/false çıplak)
json_bash() { # $1 komut, $2 cwd
    php -r 'echo json_encode(["hook_event_name"=>"PreToolUse","tool_name"=>"Bash","tool_input"=>["command"=>$argv[1]],"cwd"=>$argv[2],"session_id"=>"t"], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);' "$1" "$2"
}
json_dosya() { # $1 araç, $2 dosya
    php -r 'echo json_encode(["hook_event_name"=>"PostToolUse","tool_name"=>$argv[1],"tool_input"=>["file_path"=>$argv[2]],"tool_response"=>["filePath"=>$argv[2]]], JSON_UNESCAPED_SLASHES);' "$1" "$2"
}
json_stop() { # $1 stop_hook_active (true/false), $2 oturum
    printf '{"hook_event_name":"Stop","stop_hook_active":%s,"session_id":"%s"}' "$1" "$2"
}

# calis <beklenen kod> <açıklama> <hook> [ortam...] — stdin GIRDI değişkeninden
calis() {
    local bek="$1" ad="$2" hook="$3"; shift 3
    local kod
    CIKTI="$(printf '%s' "$GIRDI" | env "$@" "$H/$hook" 2>"$GEC/stderr")"; kod=$?
    HATA="$(cat "$GEC/stderr")"
    if [ "$kod" = "$bek" ]; then ok; else hata "$ad: beklenen çıkış $bek, gelen $kod (stderr: $HATA)"; fi
}

for MOD in jq php; do
    if [ "$MOD" = php ]; then KAPALI="jq"; else KAPALI=""; command -v jq >/dev/null 2>&1 || continue; fi
    echo "== mod: $MOD"
    E=("HOOK_ARAC_KAPALI=$KAPALI" "HOOK_KOK=$KOK")

    # ---------- bash_koruma.sh ----------
    izinli=(
        'git push -u origin claude/beautiful-tesla-tygq4f'
        'git push origin claude/x:claude/x'
        'git push origin main:claude/yedek'
        'git -C /tmp push origin feature'
        'git rm some_other.php'
        'git rm --cached scripts/x.php'
        'rm -f /tmp/fix_brand.php'
        'rm -rf vendor/foo'
        'git commit -m "fix: git push origin main; rm fix_brand.php"'
        'echo "git push origin main" && git status'
        "git commit -F - <<'EOF'
git push origin main
git rm fix_brand.php
EOF"
        'git log --oneline main | head'
        'git checkout main && git pull origin main'
        'git diff origin/main...HEAD'
        'grep -n "git push origin main" docs/x.md # git push origin main'
        'mv yeni.php eski.php'
        'cp fix_brand.php /tmp/'
    )
    for k in "${izinli[@]}"; do
        GIRDI="$(json_bash "$k" "$KOK")"; calis 0 "izinli: $k" bash_koruma.sh "${E[@]}"
    done
    engelli=(
        'git push origin main'
        'git push origin HEAD:main'
        'git push -f origin main'
        'git push --force origin main'
        'git push origin +main'
        'git push origin feature:refs/heads/main'
        'git push origin :main'
        'git push origin master'
        'git push --all origin'
        'git push --mirror origin'
        'cd /tmp && git push origin main'
        'git -C . push origin "main"'
        "bash -c 'git push origin main'"
        'sudo git push origin main'
        'git status; git push origin main'
        'git rm fix_brand.php'
        'git rm -f ./hesap_sahipsiz.php'
        "git rm $KOK/record_new.php"
        'git rm -r .'
        'git rm *.php'
        'git rm -- depo_tasima.php'
        'rm fix_brand.php'
        'rm -f repair_xz_tables.php other.php'
        'unlink faz8b_migrate.php'
        'git mv firma_eslestirme.php arsiv/firma.php'
        'mv admin_db_backup_download.php /tmp/'
        'cd scripts && git rm ../fix_brand.php'
    )
    for k in "${engelli[@]}"; do
        GIRDI="$(json_bash "$k" "$KOK")"; calis 2 "engelli: $k" bash_koruma.sh "${E[@]}"
        case "$HATA" in *ENGELLENDİ*) ok ;; *) hata "engelli mesajı yok: $k" ;; esac
    done
    # Refspec'siz push: geçerli dal main ise engel, değilse serbest (geçici repo).
    R="$GEC/repo_$MOD"; mkdir -p "$R"
    git -C "$R" init -q -b main 2>/dev/null || { git -C "$R" init -q; git -C "$R" checkout -q -b main; }
    GIRDI="$(json_bash 'git push' "$R")"; calis 2 "refspec'siz push (dal main)" bash_koruma.sh "${E[@]}"
    GIRDI="$(json_bash 'git push origin' "$R")"; calis 2 "push origin (dal main)" bash_koruma.sh "${E[@]}"
    GIRDI="$(json_bash 'git push origin HEAD' "$R")"; calis 2 "push origin HEAD (dal main)" bash_koruma.sh "${E[@]}"
    git -C "$R" checkout -q -b claude/deneme
    GIRDI="$(json_bash 'git push' "$R")"; calis 0 "refspec'siz push (dal claude/*)" bash_koruma.sh "${E[@]}"
    GIRDI="$(json_bash 'git push -u origin HEAD' "$R")"; calis 0 "push HEAD (dal claude/*)" bash_koruma.sh "${E[@]}"
    # Araç yoksa sessizce geç.
    GIRDI="$(json_bash 'git push origin main' "$KOK")"; calis 0 "jq+php yok → geç" bash_koruma.sh HOOK_ARAC_KAPALI="jq php" HOOK_KOK="$KOK"

    # ---------- php_lint.sh ----------
    printf '<?php\nfunction ( {\n' > "$GEC/bozuk.php"
    printf '<?php\necho "tamam";\n' > "$GEC/iyi.php"
    printf 'function ( {\n' > "$GEC/notlar.txt"
    for arac in Edit Write MultiEdit; do
        GIRDI="$(json_dosya $arac "$GEC/bozuk.php")"; calis 2 "lint bozuk ($arac)" php_lint.sh "${E[@]}"
        case "$HATA" in *"PHP sözdizimi hatası"*bozuk.php*) ok ;; *) hata "lint mesajı eksik: $HATA" ;; esac
        GIRDI="$(json_dosya $arac "$GEC/iyi.php")"; calis 0 "lint iyi ($arac)" php_lint.sh "${E[@]}"
    done
    GIRDI="$(json_dosya Write "$GEC/notlar.txt")"; calis 0 "lint php değil" php_lint.sh "${E[@]}"
    GIRDI="$(json_dosya Write "$GEC/yok.php")"; calis 0 "lint dosya yok" php_lint.sh "${E[@]}"
    GIRDI="$(json_dosya Write "$GEC/bozuk.php")"; calis 0 "lint php yok → geç" php_lint.sh HOOK_ARAC_KAPALI="jq php"

    # ---------- surum_esitligi.sh ----------
    S="$GEC/surum_$MOD"; mkdir -p "$S/config" "$S/assets"
    yaz() { # $1 sw sürümü, $2 app sürümü
        printf "// sw\nconst CACHE_NAME = 'yukleme-plani-%s';\n" "$1" > "$S/sw.js"
        printf "<?php\nif (!defined('APP_SURUM')) {\n    define('APP_SURUM', '%s');\n}\n" "$2" > "$S/config/helpers.php"
    }
    git -C "$S" init -q 2>/dev/null
    git -C "$S" config user.email t@ornek.test; git -C "$S" config user.name t
    yaz v100 v100; echo a > "$S/assets/style.css"; echo a > "$S/assets/app.js"
    git -C "$S" add -A; git -C "$S" commit -qm ilk
    git -C "$S" update-ref refs/remotes/origin/main HEAD
    ES=("HOOK_ARAC_KAPALI=$KAPALI" "HOOK_KOK=$S")

    GIRDI="$(json_stop false o1)"; calis 0 "sürüm eşit, değişiklik yok" surum_esitligi.sh "${ES[@]}"
    [ -z "$CIKTI" ] && ok || hata "sessiz olmalıydı: $CIKTI"

    yaz v101 v100
    GIRDI="$(json_stop false o1)"; calis 0 "sürüm farklı" surum_esitligi.sh "${ES[@]}"
    case "$CIKTI" in *'"decision":"block"'*v101*v100*) ok ;; *) hata "block bekleniyordu: $CIKTI" ;; esac
    GIRDI="$(json_stop true o1)"; calis 0 "sürüm farklı ama stop_hook_active" surum_esitligi.sh "${ES[@]}"
    [ -z "$CIKTI" ] && ok || hata "stop_hook_active iken sessiz olmalıydı: $CIKTI"

    # style.css değişti, sürüm artırılmadı → hatırlatma (oturum başına bir kez)
    yaz v100 v100; echo b > "$S/assets/style.css"
    GIRDI="$(json_stop false oA)"; calis 0 "style değişti, sürüm aynı" surum_esitligi.sh "${ES[@]}"
    case "$CIKTI" in *additionalContext*Hatırlatma*assets/style.css*) ok ;; *) hata "hatırlatma bekleniyordu: $CIKTI" ;; esac
    GIRDI="$(json_stop false oA)"; calis 0 "aynı oturumda ikinci kez" surum_esitligi.sh "${ES[@]}"
    [ -z "$CIKTI" ] && ok || hata "ikinci kez hatırlatmamalıydı: $CIKTI"
    GIRDI="$(json_stop false oB)"; calis 0 "yeni oturum" surum_esitligi.sh "${ES[@]}"
    case "$CIKTI" in *additionalContext*) ok ;; *) hata "yeni oturumda hatırlatma bekleniyordu: $CIKTI" ;; esac
    # Commit edilmiş değişiklik (origin/main...HEAD) de sayılır
    git -C "$S" commit -qam style
    GIRDI="$(json_stop false oC)"; calis 0 "commitli style değişikliği" surum_esitligi.sh "${ES[@]}"
    case "$CIKTI" in *additionalContext*) ok ;; *) hata "commitli değişiklikte hatırlatma bekleniyordu: $CIKTI" ;; esac
    # Sürüm artırıldı → sessiz
    yaz v101 v101
    GIRDI="$(json_stop false oD)"; calis 0 "sürüm artırıldı" surum_esitligi.sh "${ES[@]}"
    [ -z "$CIKTI" ] && ok || hata "sürüm artınca sessiz olmalıydı: $CIKTI"
    # origin/main yoksa ikinci denetim atlanır
    git -C "$S" update-ref -d refs/remotes/origin/main; yaz v100 v100
    GIRDI="$(json_stop false oE)"; calis 0 "origin/main yok" surum_esitligi.sh "${ES[@]}"
    [ -z "$CIKTI" ] && ok || hata "origin/main yokken sessiz olmalıydı: $CIKTI"
    # Araç yoksa geç
    yaz v101 v100
    GIRDI="$(json_stop false oF)"; calis 0 "jq+php yok" surum_esitligi.sh HOOK_ARAC_KAPALI="jq php" HOOK_KOK="$S"
    [ -z "$CIKTI" ] && ok || hata "araç yokken sessiz olmalıydı: $CIKTI"

    # Gerçek depo durumu Stop'u ENGELLEMEMELİ
    GIRDI="$(json_stop false "gercek-$$-$MOD")"; calis 0 "gerçek depo" surum_esitligi.sh "HOOK_ARAC_KAPALI=$KAPALI" "HOOK_KOK=$KOK"
    case "$CIKTI" in *'"decision":"block"'*) hata "gerçek depoda sürüm uyuşmazlığı: $CIKTI" ;; *) ok ;; esac
done

# settings.json geçerli JSON ve hook dosyaları çalıştırılabilir
php -r 'exit(is_array(json_decode(file_get_contents($argv[1]), true)) ? 0 : 1);' "$KOK/.claude/settings.json" && ok || hata "settings.json geçersiz JSON"
for f in bash_koruma.sh php_lint.sh surum_esitligi.sh; do [ -x "$H/$f" ] && ok || hata "$f çalıştırılabilir değil"; done

echo "geçti: $GECTI · kaldı: $KALDI"
[ "$KALDI" -eq 0 ] && { echo TAMAM; exit 0; }
exit 1
