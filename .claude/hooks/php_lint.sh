#!/usr/bin/env bash
# PostToolUse (Edit|Write|MultiEdit): düzenlenen dosya .php ise `php -l` çalıştırır.
# Sözdizimi hatasında hata metni stderr'e yazılır ve exit 2 ile Claude'a iletilir
# (araç zaten çalıştı; bu yalnız geri bildirimdir). .php olmayan dosyaya dokunmaz.
set -u
. "$(dirname "${BASH_SOURCE[0]}")/_ortak.sh"

arac_var php || exit 0
girdi_oku
dosya="$(json_al tool_input.file_path)"
[ -n "$dosya" ] || dosya="$(json_al tool_response.filePath)"
case "$dosya" in
    *.php) ;;
    *) exit 0 ;;
esac
[ -f "$dosya" ] || exit 0

if ! cikti="$(php -d display_errors=1 -d error_reporting=-1 -l "$dosya" 2>&1)"; then
    {
        echo "PHP sözdizimi hatası — dosyayı düzeltin: $dosya"
        printf '%s\n' "$cikti" | grep -v '^Errors parsing' | sed '/^[[:space:]]*$/d'
    } >&2
    exit 2
fi
exit 0
