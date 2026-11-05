#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"
[[ "${1:-}" = --crear && $# = 1 ]] || { echo 'Uso: bash operacion/scripts/preparar_qa.sh --crear'; exit 0; }
command -v openssl >/dev/null || { echo 'Se requiere OpenSSL para generar secretos locales.' >&2; exit 1; }
[[ ! -L "$cchia_private" && ! -e "$cchia_env" && ! -L "$cchia_env" ]] || { echo 'El entorno ya existe o la ruta no es segura; no se sobrescribe.' >&2; exit 1; }
mkdir -p "$cchia_private"
chmod 0700 "$cchia_private"
mkdir "$cchia_private/.prepare-lock" || { echo 'Hay otra preparación en curso.' >&2; exit 1; }
cchia_tmp="$(mktemp "$cchia_private/.qa-env.XXXXXX")"
trap 'rm -f "$cchia_tmp"; rmdir "$cchia_private/.prepare-lock"' EXIT
[[ ! -e "$cchia_env" ]] || exit 1
cchia_key="$(openssl rand -base64 32)"
cchia_password="$(openssl rand -hex 24)"
while IFS= read -r cchia_line || [[ -n "$cchia_line" ]]; do
    case "$cchia_line" in
        APP_KEY=*) printf 'APP_KEY=base64:%s\n' "$cchia_key" ;;
        DB_PASSWORD=*) printf 'DB_PASSWORD=%s\n' "$cchia_password" ;;
        *) printf '%s\n' "$cchia_line" ;;
    esac
done < "$cchia_root/operacion/qa.env.example" > "$cchia_tmp"
chmod 0600 "$cchia_tmp"
mv -n "$cchia_tmp" "$cchia_env"
echo 'Entorno QA privado creado. No se iniciaron servicios ni se mostraron secretos.'
