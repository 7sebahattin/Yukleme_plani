#!/usr/bin/env bash
# PreToolUse (Bash): tehlikeli komutları ENGELLER (exit 2 + Türkçe gerekçe → Claude'a iletilir).
#  - main/master'a push: `git push origin main`, `HEAD:main`, `+x:refs/heads/main`, `-f origin main`,
#    `--all`/`--mirror`, refspec'siz push iken geçerli dal main/master ise.
#    "Canlıya al" = PR açıp main'e merge (CLAUDE.md › Critical Rules) — doğrudan push YOK.
#  - 410 tombstone dosyalarını silmek/taşımak: `git rm`, `git mv`, `rm`, `unlink`, `mv`
#    (deploy dosya SİLMEZ; tombstone canlıdaki eski aracı etkisizleştirir — CLAUDE.md › Tombstone politikası).
# Ayrıştırma tırnak farkındadır: commit mesajı / echo içindeki "git push origin main" metni ENGELLENMEZ;
# yalnız komut konumundaki git/rm çağrıları incelenir. `bash -c "..."` içi de incelenir. Heredoc gövdesi atlanır.
# Test için: HOOK_KOK (depo kökü). Araç (jq/php) yoksa sessizce geçer.
set -u
. "$(dirname "${BASH_SOURCE[0]}")/_ortak.sh"

json_arac_var || exit 0
girdi_oku
KOMUT="$(json_al tool_input.command)"
[ -n "$KOMUT" ] || exit 0
KOK="$(hook_kok)"
CWD="$(json_al cwd)"
[ -n "$CWD" ] || CWD="$KOK"

AYRAC=$'\037'
SATIR_ICI=$'\036'

# Bilinen tombstone'lar (v281; CLAUDE.md › Dosya Haritası) + kökte dinamik tespit
# (≤15 satır ve http_response_code(410) içeren *.php).
TOMBSTONE=(admin_db_backup_download.php hesap_sahipsiz.php depo_tasima.php firma_eslestirme.php
           fix_brand.php repair_xz_tables.php faz8b_migrate.php record_new.php)
while IFS= read -r f; do
    [ -f "$f" ] || continue
    if [ "$(wc -l < "$f")" -le 15 ]; then
        b="${f##*/}"
        case " ${TOMBSTONE[*]} " in *" $b "*) ;; *) TOMBSTONE+=("$b") ;; esac
    fi
done < <(grep -l -F 'http_response_code(410)' "$KOK"/*.php 2>/dev/null)

yol_normal() {
    local p="$1"
    if realpath -m / >/dev/null 2>&1; then realpath -m -- "$p"; else printf '%s' "$p"; fi
}
yol_coz() { # $1 = argüman, $2 = taban dizin
    local a="$1" b="$2"
    case "$a" in
        "~") a="$HOME" ;;
        "~/"*) a="$HOME/${a#\~/}" ;;
    esac
    case "$a" in /*) ;; *) a="$b/$a" ;; esac
    yol_normal "$a"
}

engelle() {
    printf '%s\n' "ENGELLENDİ (proje hook'u .claude/hooks/bash_koruma.sh): $1" >&2
    exit 2
}

# Heredoc gövdelerini at (gövdedeki satırlar komut değildir).
heredoc_at() {
    awk -v q="'" '
    BEGIN { bitis = ""; re = "<<-?[ \t]*[\"" q "]?[A-Za-z_][A-Za-z0-9_]*[\"" q "]?" }
    {
        if (bitis != "") { t = $0; if (tire) sub(/^\t+/, "", t); if (t == bitis) bitis = ""; next }
        print
        s = $0; gsub(/<<</, "   ", s)
        if (match(s, re)) {
            w = substr(s, RSTART, RLENGTH); tire = (w ~ /^<<-/)
            sub(/^<<-?[ \t]*/, "", w); gsub(/["\047]/, "", w); bitis = w
        }
    }'
}

# Tırnak farkında sözcüklere ayırma: her komut parçası bir satır, sözcükler \037 ile ayrılır.
# Ayırıcılar (tırnak dışında): satır sonu ; & | ( ) `   Tırnak içi satır sonu \036 olarak korunur.
sozcukle() {
    awk -v q="'" -v A="$AYRAC" -v N="$SATIR_ICI" '
    function tf() { if (has) { seg = (cnt ? seg A : "") tok; cnt++; tok = ""; has = 0 } }
    function sf() { tf(); if (cnt) print seg; seg = ""; cnt = 0 }
    BEGIN { RS = "\001" }
    {
        s = $0; n = length(s); tok = ""; has = 0; seg = ""; cnt = 0; sq = 0; dq = 0
        for (i = 1; i <= n; i++) {
            c = substr(s, i, 1)
            if (sq) { if (c == q) sq = 0; else tok = tok (c == "\n" ? N : c); continue }
            if (dq) {
                if (c == "\\" && i < n) {
                    d = substr(s, i + 1, 1)
                    if (d == "\"" || d == "\\" || d == "$" || d == "`") { tok = tok d; i++; continue }
                    tok = tok c; continue
                }
                if (c == "\"") dq = 0; else tok = tok (c == "\n" ? N : c)
                continue
            }
            if (c == q) { sq = 1; has = 1; continue }
            if (c == "\"") { dq = 1; has = 1; continue }
            if (c == "\\" && i < n) { d = substr(s, i + 1, 1); i++; if (d != "\n") { tok = tok d; has = 1 } continue }
            if (c == "#" && !has) { while (i <= n && substr(s, i, 1) != "\n") i++; sf(); continue }
            if (c == " " || c == "\t") { tf(); continue }
            if (c == "\n" || c == ";" || c == "&" || c == "|" || c == "(" || c == ")" || c == "`") { sf(); continue }
            tok = tok c; has = 1
        }
        sf()
    }'
}

tombstone_mu() { # $1 = argüman, $2 = taban → 0 ise tombstone'u etkiler
    local a="$1" b="$2" p dir pat t
    case "$a" in
        *[\*\?\[]*)
            dir="$(dirname -- "$a")"; pat="$(basename -- "$a")"
            [ "$(yol_coz "$dir" "$b")" = "$(yol_normal "$KOK")" ] || return 1
            for t in "${TOMBSTONE[@]}"; do
                # shellcheck disable=SC2053
                [[ "$t" == $pat ]] && { ESLESEN="$t"; return 0; }
            done
            return 1 ;;
    esac
    p="$(yol_coz "$a" "$b")"
    local k; k="$(yol_normal "$KOK")"
    for t in "${TOMBSTONE[@]}"; do
        [ "$p" = "$k/$t" ] && { ESLESEN="$t"; return 0; }
    done
    # Depo kökü ya da üst dizini (ör. `git rm -r .`, `rm -rf <kök>`) tombstone'ları da siler.
    [ "$p" = "/" ] && { ESLESEN="(kök dizin: $a)"; return 0; }
    case "$k/" in "$p"/*|"$p/") [ -n "$p" ] && { ESLESEN="(depo kökü ya da üstü: $a)"; return 0; } ;; esac
    return 1
}

dal_ana_mi() { # $1 = hedef ref adı
    local r="${1#+}"
    r="${r#refs/heads/}"
    [ "$r" = "main" ] || [ "$r" = "master" ]
}

gecerli_dal() { git -C "$1" symbolic-ref --short -q HEAD 2>/dev/null; }

incele_push() { # $1 = git dizini, kalan = push argümanları
    local gd="$1"; shift
    local konum=() a
    while [ $# -gt 0 ]; do
        a="$1"; shift
        case "$a" in
            --all|--mirror) engelle "git push $a tüm dalları (main dahil) gönderir. main'e doğrudan push YASAK — PR açıp merge edin." ;;
            --repo|-o|--push-option|--receive-pack|--exec) shift ;;
            --) konum+=("$@"); break ;;
            -*) ;;
            *) konum+=("$a") ;;
        esac
    done
    local dal
    if [ ${#konum[@]} -le 1 ]; then
        dal="$(gecerli_dal "$gd")"
        dal_ana_mi "$dal" && engelle "geçerli dal '$dal' iken refspec'siz 'git push' main'e gönderir. main'e doğrudan push YASAK — kendi dalınızı push edip PR açın."
        return 0
    fi
    local ref hedef
    for ref in "${konum[@]:1}"; do
        hedef="${ref##*:}"
        if [ "${hedef#+}" = "HEAD" ] || [ -z "$hedef" ]; then
            [ -z "$hedef" ] && continue   # ":dal" silme değil "x:" — hedef boş, atla
            hedef="$(gecerli_dal "$gd")"
        fi
        dal_ana_mi "$hedef" && engelle "'git push … $ref' main/master'a doğrudan gönderir (force dahil). Canlıya almak = PR açıp main'e merge (CLAUDE.md › Critical Rules, docs/DEPLOY_WORKFLOW.md)."
    done
    return 0
}

incele_silme() { # $1 = komut adı, $2 = taban, kalan = argümanlar (son=mv hedefi dahil)
    local ad="$1" b="$2"; shift 2
    local konum=() a secenek_bitti=0
    for a in "$@"; do
        if [ $secenek_bitti -eq 0 ]; then
            case "$a" in --) secenek_bitti=1; continue ;; -*) continue ;; esac
        fi
        konum+=("$a")
    done
    # mv / git mv: son argüman hedeftir, kaynak değil.
    case "$ad" in mv|"git mv") [ ${#konum[@]} -ge 2 ] && unset 'konum[${#konum[@]}-1]' ;; esac
    for a in "${konum[@]+"${konum[@]}"}"; do
        ESLESEN=""
        if tombstone_mu "$a" "$b"; then
            engelle "'$ad' 410 tombstone dosyasını siliyor/taşıyor: $ESLESEN. Tombstone'lar SİLİNMEZ ve git rm EDİLMEZ (deploy dosya silmez; canlıdaki eski aracı etkisizleştirir — CLAUDE.md › Tombstone politikası)."
        fi
    done
}

TABAN="$CWD"

incele_segment() { # $1 = derinlik, kalan = sözcükler
    local derin="$1"; shift
    local t=("$@") i=0 n=${#t[@]}
    # Komut konumuna ilerle: VAR=x, sudo/env/command/exec/time/nohup/nice, { , !
    while [ $i -lt $n ]; do
        case "${t[$i]}" in
            sudo|env|command|exec|time|nohup|nice|builtin|'{'|'}'|'!'|then|do|else) i=$((i+1)) ;;
            [A-Za-z_]*=*) i=$((i+1)) ;;
            *) break ;;
        esac
    done
    [ $i -lt $n ] || return 0
    local cmd="${t[$i]}"; i=$((i+1))
    case "${cmd##*/}" in
        bash|sh|zsh|dash)
            local j
            for ((j=i; j<n; j++)); do
                case "${t[$j]}" in
                    -c|-*c|-c*) [ $((j+1)) -lt $n ] && [ "$derin" -lt 3 ] && incele_metin "$((derin+1))" "${t[$((j+1))]//$SATIR_ICI/$'\n'}"; return 0 ;;
                    -*) ;;
                    *) return 0 ;;
                esac
            done
            return 0 ;;
        cd)
            if [ $i -lt $n ]; then TABAN="$(yol_coz "${t[$i]}" "$TABAN")"; else TABAN="$HOME"; fi
            return 0 ;;
        rm|unlink|mv)
            incele_silme "${cmd##*/}" "$TABAN" "${t[@]:$i}"
            return 0 ;;
        git) ;;
        *) return 0 ;;
    esac
    # git genel seçenekleri
    local gd="$TABAN"
    while [ $i -lt $n ]; do
        case "${t[$i]}" in
            -C) gd="$(yol_coz "${t[$((i+1))]:-.}" "$TABAN")"; i=$((i+2)) ;;
            -c|--git-dir|--work-tree|--namespace) i=$((i+2)) ;;
            -*) i=$((i+1)) ;;
            *) break ;;
        esac
    done
    [ $i -lt $n ] || return 0
    local alt="${t[$i]}"; i=$((i+1))
    case "$alt" in
        push) incele_push "$gd" "${t[@]:$i}" ;;
        rm)   incele_silme "git rm" "$gd" "${t[@]:$i}" ;;
        mv)   incele_silme "git mv" "$gd" "${t[@]:$i}" ;;
    esac
    return 0
}

incele_metin() { # $1 = derinlik, $2 = komut metni
    local derin="$1" satir
    local -a s
    while IFS= read -r satir; do
        IFS="$AYRAC" read -r -a s <<< "$satir"
        [ ${#s[@]} -gt 0 ] && incele_segment "$derin" "${s[@]}"
    done < <(printf '%s\n' "$2" | heredoc_at | sozcukle)
}

incele_metin 0 "$KOMUT"
exit 0
