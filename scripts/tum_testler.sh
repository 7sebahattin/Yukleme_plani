#!/usr/bin/env bash
# =========================================================
# scripts/tum_testler.sh — deponun test takımını TEK komutla çalıştırır.
#
#   bash scripts/tum_testler.sh                 → tüm PHP testleri (scripts/*_smoke.php + scripts/*_test.php)
#   bash scripts/tum_testler.sh --js            → PHP testleri + Playwright tarayıcı testleri (scripts/*.js)
#   bash scripts/tum_testler.sh --yalniz-js     → yalnız tarayıcı testleri
#   bash scripts/tum_testler.sh scripts/a_smoke.php scripts/b_smoke.js
#                                               → yalnız verilenler (.js için hazırlık adımları uygulanır)
#   bash scripts/tum_testler.sh --liste [--js]  → ne çalışacağını yazar, çalıştırmaz
#   bash scripts/tum_testler.sh -v              → başarısız testin çıktısının son 40 satırını gösterir
#
# Ortam değişkenleri:
#   TEST_ZAMAN_ASIMI=120        test başına süre sınırı (sn); tarayıcı testleri 2 katı
#   TEST_ATLAMA_HATA=1          tarayıcı testi "ATLANDI" derse (Playwright/Chromium yok) BAŞARISIZ say (CI)
#
# Her test depo kökünden çalışır (canlı DB'ye dokunmaz — testler bellek içi SQLite kullanır).
# Tarayıcı testlerinin önce çalışması gereken render adımları aşağıdaki js_hazirla() tablosundadır;
# kökte üretilen _test_*.html dosyaları .gitignore'dadır. Diğer geçici çıktılar ${TMPDIR:-/tmp} altında.
# Liste ayarları (aşağıda, gerekçeleriyle): JS_ATLA (atlanan tarayıcı testleri), JS_KARARSIZ (düşerse bir kez
# yeniden denenen tarayıcı testleri), PHP_HARIC (adı *_test.php olsa da test olmayan canlı/ağ betikleri).
# Çıkış kodu: herhangi bir test başarısızsa 1, aksi 0.
# =========================================================
set -u

KOK="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$KOK" || exit 2

ZAMAN="${TEST_ZAMAN_ASIMI:-120}"
JS_ZAMAN=$((ZAMAN * 2))
ATLAMA_HATA="${TEST_ATLAMA_HATA:-0}"

PHP_AC=1; JS_AC=0; LISTE=0; AYRINTI=0
SECILI=()
for a in "$@"; do
    case "$a" in
        --js) JS_AC=1 ;;
        --yalniz-js) JS_AC=1; PHP_AC=0 ;;
        --liste) LISTE=1 ;;
        -v|--ayrinti) AYRINTI=1 ;;
        -h|--help|--yardim) awk 'NR>1 && /^#/ {sub(/^# ?/, ""); print; next} NR>1 {exit}' "$0"; exit 0 ;;
        -*) echo "Bilinmeyen seçenek: $a" >&2; exit 2 ;;
        *) SECILI+=("$a") ;;
    esac
done

# ---------------------------------------------------------
# Tarayıcı testleri: bilinçli olarak ATLANANLAR (gerekçesiyle).
# Kurulumdan bağımsız olarak bozuk/kararsız olan test buraya gerekçesiyle yazılır —
# sessizce gizlenmez, özet satırında "atlandı" diye sayılır.
# Biçim: [dosya adı]="gerekçe"
# ---------------------------------------------------------
declare -A JS_ATLA=(
)

# ---------------------------------------------------------
# Tarayıcı testleri: bilinen KARARSIZ (flaky) olanlar — başarısız olursa BİR KEZ yeniden denenir;
# ikinci denemede geçerse "KARARSIZ" satırıyla bildirilir (gizlenmez), yine kalırsa FAIL.
# Biçim: [dosya adı]="gerekçe"
# ---------------------------------------------------------
declare -A JS_KARARSIZ=(
    # 4 çalıştırmanın 2'sinde [820] "v402 liste: sevkiyat sayfası açılınca çerçeve görülen zamana göre
    # kalkar" adımı du='' ile düştü: detay → liste geçişinde localStorage kaydı listede okunamıyor
    # (file:// gezinmesinde depolama yarışı olabilir). Diğer 618 adım her seferinde geçti.
    [siparis_ui_smoke.js]="v402 görülme kaydı adımı ara sıra düşüyor (localStorage yarışı)"
)

# ---------------------------------------------------------
# Varsayılan PHP çalıştırmasından HARİÇ tutulanlar (adı *_test.php / *_smoke.php olsa da
# test DEĞİL: elle çalıştırılan canlı servis / ağ denetimleri). CI canlı servise gitmesin.
# Dosya açıkça argüman olarak verilirse yine çalışır.
# ---------------------------------------------------------
declare -A PHP_HARIC=(
    # Canlı HKS (ws.gtb.gov.tr) uç noktalarını firma kimliğiyle karşılaştıran elle betik;
    # yerelde DB bağlantı hatası basıp 0 ile çıkar → sahte "geçti".
    [hks_endpoint_test.php]="elle çalıştırılan canlı HKS ağ denetimi (test değil)"
    [cost_link_smoke.php]="yapılandırılmış DB ve migration kullanan elle tanılama; izole regresyon role_data_scope_smoke.php içinde"
)

# ---------------------------------------------------------
# Tarayıcı testi → hazırlık adımları tablosu.
# Her dosyanın başlık yorumundaki "önce şunu çalıştır" satırlarının aynası.
# Kendi fixture'ını üreten / kendi sunucusunu açan testler "-" (hazırlık yok).
#
#   beyan_hks_urun_e2e.js ............ -  (php -S + dosya SQLite, kendisi kurar)
#   beyan_js_smoke.js ................ -
#   beyan_status_menu_smoke.js ....... -  (beyan_ui_smoke.php --render-status kendisi)
#   beyan_workspace_permissions_smoke.js - (beyan_workspace_render.php kendisi)
#   beyan_workspace_smoke.js ......... -  (satır içi fixture)
#   beyan_yazdir_smoke.js ............ -  (beyan_ui_smoke.php --render-liste kendisi)
#   bottomnav_smoke.js ............... BOTTOMNAV_OUT=<tmp> php scripts/bottomnav_render.php  (test de BOTTOMNAV_OUT ile)
#   buton_gorunum_smoke.js ........... PUANTAJ_SAYFA=liste PUANTAJ_TARIH=bugun pdks_puantaj_dialog_render.php > _test_puantaj_liste.html
#   export_menu_smoke.js ............. export_menu_render.php > _test_export_menu.html
#   hks_*_smoke.js (15 dosya) ........ -  (app.html yerel sunucu + sahte api.php)
#   mail_ui_smoke.js ................. MAIL_UI_OUT=<tmp> php scripts/mail_ui_render.php  (test de MAIL_UI_OUT ile)
#   pdks_cavus_fiyat_smoke.js ........ CAVUS_FIYAT_SENARYO=dolu|bos pdks_cavus_fiyat_render.php > _test_cavus_fiyat[_bos].html
#   pdks_cavus_sure_e2e.js ........... -  (kendi router'ı + dosya SQLite)
#   pdks_fis_ui_smoke.js ............. -  (kendi router'ı + dosya SQLite: pdks_fis_e2e_setup/router/say.php)
#   pdks_gecmis_ekle_smoke.js ........ -  (render'ı kendisi)
#   pdks_geri_ptak_smoke.js .......... PUANTAJ_SAYFA=liste PUANTAJ_TARIH=bugun ... > _test_puantaj_liste.html
#   pdks_karisik_smoke.js ............ pdks_ortak_cikis_render.php > _test_ortak_cikis.html + pdks_puantaj_dialog_render.php > _test_puantaj_dialog.html
#   pdks_kart_havuzu_smoke.js ........ -  (her istekte render'ı kendisi çağırır)
#   pdks_kart_tanim_modal_smoke.js ... pdks_kart_tanim_render.php > _test_kart_tanim.html
#   pdks_kart_toplu_smoke.js ......... pdks_kart_toplu_render.php > _test_kart_toplu.html
#   pdks_kiosk_renk_smoke.js ......... pdks_ortak_cikis_render.php > _test_ortak_cikis.html
#   pdks_kiosk_v293_smoke.js ......... pdks_ortak_cikis_render.php > _test_ortak_cikis.html
#   pdks_ortak_cikis_smoke.js ........ pdks_ortak_cikis_render.php > _test_ortak_cikis.html
#   pdks_oto_filtre_smoke.js ......... liste/bugun > _test_puantaj_liste.html + PUANTAJ_SAYFA=toplu > _test_toplu_dokum.html
#   pdks_puantaj_dialog_smoke.js ..... pdks_puantaj_dialog_render.php > _test_puantaj_dialog.html
#   pdks_servis_dialog_smoke.js ...... PUANTAJ_SERVIS=1 ... > _test_servis_dialog.html
#   pdks_servis_fiyatsiz_smoke.js .... PUANTAJ_SERVIS=nofiyat ... > _test_servis_fiyatsiz.html
#   pdks_servis_scroll_smoke.js ...... -  (geçici router'ı kendisi)
#   pdks_sirala_smoke.js ............. PUANTAJ_FAZ8B=1 PUANTAJ_SIRALA=1 > _test_sirala.html + PUANTAJ_SIRALA=1 PUANTAJ_KAPALI=1 > _test_kapanis_notu.html
#   pdks_tanimli_giris_smoke.js ...... pdks_tanimli_giris_render.php > _test_tanimli_giris.html
#   pdks_ters_kart_smoke.js .......... -  (pdks_ters_kart_render.php kendisi)
#   pdks_toplu_duzelt_smoke.js ....... -  (render'ı kendisi)
#   pdks_toplu_ekle_smoke.js ......... -  (render'ı kendisi)
#   pdks_v296b_smoke.js .............. -  (satır içi fixture)
#   roles_modal_smoke.js ............. roles_modal_render.php > _test_roles.html
#   siparis_ui_smoke.js .............. SIPARIS_UI_OUT=<tmp> php scripts/siparis_ui_render.php  (test de SIPARIS_UI_OUT ile)
#   siparis_invoice_ui_smoke.js ...... aynı render (inv_*.html), SIPARIS_UI_OUT ile
#   siparis_yazdir_smoke.js .......... -  (siparis_ui_render.php + --json fixture'ları kendisi)
#   suggest_list_smoke.js ............ -
#   urun_siparis_smoke.js ............ URUN_SIPARIS_OUT=<tmp> php scripts/urun_siparis_render.php  (test de URUN_SIPARIS_OUT ile)
# ---------------------------------------------------------
GECICI=""
gecici_kur() {
    [ -n "$GECICI" ] && return 0
    GECICI="$(mktemp -d "${TMPDIR:-/tmp}/tum_testler.XXXXXX")" || { echo "Geçici klasör açılamadı" >&2; exit 2; }
    trap 'rm -rf "$GECICI"' EXIT
}

# render <hedef dosya> [ORTAM=DEĞER ...] — pdks_puantaj_dialog_render.php vb. çıktısını köke yazar
render_puantaj() { local hedef="$1"; shift; env "$@" php scripts/pdks_puantaj_dialog_render.php > "$hedef"; }

# js_hazirla <ad> — hazırlık adımlarını çalıştırır; testin ortamını JS_ORTAM dizisine yazar.
# Hazırlık başarısızsa 1 döner (test BAŞARISIZ sayılır).
JS_ORTAM=()
js_hazirla() {
    local ad="$1"
    JS_ORTAM=()
    case "$ad" in
        bottomnav_smoke.js)
            JS_ORTAM=("BOTTOMNAV_OUT=$GECICI/bn")
            env "${JS_ORTAM[@]}" php scripts/bottomnav_render.php ;;
        buton_gorunum_smoke.js|pdks_geri_ptak_smoke.js)
            render_puantaj _test_puantaj_liste.html PUANTAJ_SAYFA=liste PUANTAJ_TARIH=bugun ;;
        export_menu_smoke.js)
            php scripts/export_menu_render.php > _test_export_menu.html ;;
        mail_ui_smoke.js)
            JS_ORTAM=("MAIL_UI_OUT=$GECICI/mail-ui")
            env "${JS_ORTAM[@]}" php scripts/mail_ui_render.php ;;
        pdks_cavus_fiyat_smoke.js)
            CAVUS_FIYAT_SENARYO=dolu php scripts/pdks_cavus_fiyat_render.php > _test_cavus_fiyat.html \
            && CAVUS_FIYAT_SENARYO=bos php scripts/pdks_cavus_fiyat_render.php > _test_cavus_fiyat_bos.html ;;
        pdks_karisik_smoke.js)
            php scripts/pdks_ortak_cikis_render.php > _test_ortak_cikis.html \
            && render_puantaj _test_puantaj_dialog.html ;;
        pdks_kart_tanim_modal_smoke.js)
            php scripts/pdks_kart_tanim_render.php > _test_kart_tanim.html ;;
        pdks_kart_toplu_smoke.js)
            php scripts/pdks_kart_toplu_render.php > _test_kart_toplu.html ;;
        pdks_kiosk_renk_smoke.js|pdks_kiosk_v293_smoke.js|pdks_ortak_cikis_smoke.js)
            php scripts/pdks_ortak_cikis_render.php > _test_ortak_cikis.html ;;
        pdks_oto_filtre_smoke.js)
            render_puantaj _test_puantaj_liste.html PUANTAJ_SAYFA=liste PUANTAJ_TARIH=bugun \
            && render_puantaj _test_toplu_dokum.html PUANTAJ_SAYFA=toplu ;;
        pdks_puantaj_dialog_smoke.js)
            render_puantaj _test_puantaj_dialog.html ;;
        pdks_servis_dialog_smoke.js)
            render_puantaj _test_servis_dialog.html PUANTAJ_SERVIS=1 ;;
        pdks_servis_fiyatsiz_smoke.js)
            render_puantaj _test_servis_fiyatsiz.html PUANTAJ_SERVIS=nofiyat ;;
        pdks_sirala_smoke.js)
            render_puantaj _test_sirala.html PUANTAJ_FAZ8B=1 PUANTAJ_SIRALA=1 \
            && render_puantaj _test_kapanis_notu.html PUANTAJ_SIRALA=1 PUANTAJ_KAPALI=1 ;;
        pdks_tanimli_giris_smoke.js)
            php scripts/pdks_tanimli_giris_render.php > _test_tanimli_giris.html ;;
        roles_modal_smoke.js)
            php scripts/roles_modal_render.php > _test_roles.html ;;
        siparis_ui_smoke.js|siparis_invoice_ui_smoke.js)
            JS_ORTAM=("SIPARIS_UI_OUT=$GECICI/sip-ui")
            env "${JS_ORTAM[@]}" php scripts/siparis_ui_render.php ;;
        urun_siparis_smoke.js)
            JS_ORTAM=("URUN_SIPARIS_OUT=$GECICI/usip")
            env "${JS_ORTAM[@]}" php scripts/urun_siparis_render.php ;;
        *) return 0 ;;   # hazırlık gerekmez (test kendi fixture'ını üretir)
    esac
}

# ---------------------------------------------------------
# Çalışacak liste
# ---------------------------------------------------------
TESTLER=()
if [ "${#SECILI[@]}" -gt 0 ]; then
    for f in ${SECILI[@]+"${SECILI[@]}"}; do
        [ -f "$f" ] || { [ -f "scripts/$f" ] && f="scripts/$f"; }
        [ -f "$f" ] || { echo "Dosya yok: $f" >&2; exit 2; }
        TESTLER+=("$f")
    done
else
    shopt -s nullglob
    if [ "$PHP_AC" = 1 ]; then
        for f in scripts/*_smoke.php scripts/*_test.php; do
            [ -n "${PHP_HARIC[$(basename "$f")]+x}" ] && continue
            TESTLER+=("$f")
        done
    fi
    if [ "$JS_AC" = 1 ]; then
        for f in scripts/*.js; do TESTLER+=("$f"); done
    fi
    shopt -u nullglob
fi

if [ "$LISTE" = 1 ]; then
    for f in ${TESTLER[@]+"${TESTLER[@]}"}; do
        ad="$(basename "$f")"
        if [ -n "${JS_ATLA[$ad]+x}" ]; then echo "$f   (ATLANIR: ${JS_ATLA[$ad]})"
        elif [ -n "${JS_KARARSIZ[$ad]+x}" ]; then echo "$f   (kararsız, düşerse 1 kez yeniden denenir)"
        else echo "$f"; fi
    done
    echo "${#TESTLER[@]} test"
    if [ "${#SECILI[@]}" -eq 0 ] && [ "$PHP_AC" = 1 ]; then
        for k in "${!PHP_HARIC[@]}"; do echo "(hariç: scripts/$k — ${PHP_HARIC[$k]})"; done
    fi
    exit 0
fi

command -v timeout >/dev/null 2>&1 || { echo "GNU coreutils 'timeout' gerekli" >&2; exit 2; }
gecici_kur

BASLA=$SECONDS
TOPLAM=0; HATA=0; ATLANAN=0
HATALILAR=(); ATLANANLAR=(); KARARSIZLAR=()

goster_cikti() {
    [ "$AYRINTI" = 1 ] || return 0
    echo "----- $1 (son 40 satır) -----"
    tail -n 40 "$2"
    echo "-----"
}

for f in ${TESTLER[@]+"${TESTLER[@]}"}; do
    ad="$(basename "$f")"
    TOPLAM=$((TOPLAM + 1))
    log="$GECICI/${ad}.log"
    case "$f" in
        *.js)
            if [ -n "${JS_ATLA[$ad]+x}" ]; then
                ATLANAN=$((ATLANAN + 1)); ATLANANLAR+=("$f — ${JS_ATLA[$ad]}")
                echo "ATLA $f — ${JS_ATLA[$ad]}"
                continue
            fi
            if ! js_hazirla "$ad" > "$log" 2>&1; then
                HATA=$((HATA + 1)); HATALILAR+=("$f")
                echo "FAIL $f (hazırlık adımı başarısız)"
                goster_cikti "$f" "$log"
                continue
            fi
            env ${JS_ORTAM[@]+"${JS_ORTAM[@]}"} timeout "$JS_ZAMAN" node "$f" >> "$log" 2>&1
            kod=$?
            if [ "$kod" -ne 0 ] && [ -n "${JS_KARARSIZ[$ad]+x}" ]; then
                mv "$log" "$log.1"
                if js_hazirla "$ad" > "$log" 2>&1; then
                    env ${JS_ORTAM[@]+"${JS_ORTAM[@]}"} timeout "$JS_ZAMAN" node "$f" >> "$log" 2>&1
                    kod=$?
                else
                    kod=1
                fi
                if [ "$kod" -eq 0 ]; then
                    KARARSIZLAR+=("$f — 1. deneme düştü, 2. denemede geçti (${JS_KARARSIZ[$ad]})")
                    echo "KARARSIZ $f (1. deneme düştü, 2. denemede geçti)"
                    [ "$AYRINTI" = 1 ] && { grep -E '^FAIL' "$log.1" | head -5; }
                fi
            fi
            ;;
        *.php)
            timeout "$ZAMAN" php "$f" > "$log" 2>&1
            kod=$?
            ;;
        *)
            echo "Desteklenmeyen dosya türü: $f" >&2
            HATA=$((HATA + 1)); HATALILAR+=("$f"); continue ;;
    esac

    if [ "$kod" -ne 0 ]; then
        HATA=$((HATA + 1)); HATALILAR+=("$f")
        if [ "$kod" -eq 124 ]; then echo "FAIL $f (zaman aşımı)"; else echo "FAIL $f (çıkış $kod)"; fi
        goster_cikti "$f" "$log"
    elif [[ "$f" == *.js ]] && grep -qE '(Playwright|Chromium).*(ATLANDI|atlandı)' "$log"; then
        # Test kendini atladı (Playwright/Chromium yok) — hata değil, ama sayılır
        if [ "$ATLAMA_HATA" = 1 ]; then
            HATA=$((HATA + 1)); HATALILAR+=("$f")
            echo "FAIL $f (tarayıcı testi kendini ATLADI; TEST_ATLAMA_HATA=1)"
            goster_cikti "$f" "$log"
        else
            ATLANAN=$((ATLANAN + 1)); ATLANANLAR+=("$f — $(grep -m1 -E 'ATLANDI|atlandı' "$log")")
        fi
    fi
done

SURE=$((SECONDS - BASLA))
echo
if [ "$ATLANAN" -gt 0 ]; then
    echo "Atlananlar:"
    for x in "${ATLANANLAR[@]}"; do echo "  - $x"; done
fi
if [ "${#KARARSIZLAR[@]}" -gt 0 ]; then
    echo "Kararsız (yeniden denemede geçti):"
    for x in "${KARARSIZLAR[@]}"; do echo "  - $x"; done
fi
if [ "$HATA" -gt 0 ]; then
    echo "Başarısızlar:"
    for x in "${HATALILAR[@]}"; do echo "  - $x"; done
fi
ozet="$TOPLAM test, $HATA başarısız"
[ "$ATLANAN" -gt 0 ] && ozet="$ozet, $ATLANAN atlandı"
[ "${#KARARSIZLAR[@]}" -gt 0 ] && ozet="$ozet, ${#KARARSIZLAR[@]} kararsız"
echo "$ozet, $SURE sn"
[ "$HATA" -eq 0 ]
