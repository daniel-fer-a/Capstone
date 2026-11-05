#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"
[[ "${1:-}" = --crear && $# = 1 ]] || { echo 'Uso: bash operacion/scripts/respaldo_qa.sh --crear'; exit 0; }
require_environment
command -v openssl >/dev/null
mkdir -p "$cchia_private/backups"
chmod 0700 "$cchia_private/backups"
mkdir "$cchia_private/.backup-lock" || { echo 'Ya hay un respaldo en curso.' >&2; exit 1; }
cchia_services=()
cchia_work=''
finish() {
    cchia_exit=$?
    if (( ${#cchia_services[@]} )); then dc --profile operation start "${cchia_services[@]}" >/dev/null || cchia_exit=1; fi
    rmdir "$cchia_private/.backup-lock" || cchia_exit=1
    if [[ "$cchia_exit" != 0 && -n "$cchia_work" ]]; then echo 'Respaldo incompleto; no utilizar el directorio temporal.' >&2; fi
    exit "$cchia_exit"
}
trap finish EXIT
cchia_running_key="$(dc exec -T php php -r 'echo hash("sha256",(string)getenv("APP_KEY"));')"
cchia_configured_key="$(dc run --rm --no-deps --entrypoint php php -r 'echo hash("sha256",(string)getenv("APP_KEY"));')"
[[ -n "$cchia_running_key" && "$cchia_running_key" = "$cchia_configured_key" ]] || { echo 'La clave del entorno y la del servicio no coinciden.' >&2; exit 1; }
dc exec -T php php artisan cchia:ops files --full --json >/dev/null
mapfile -t cchia_services < <(dc --profile operation ps --status running --services | sed -n '/^php$/p; /^web$/p; /^scheduler$/p')
(( ${#cchia_services[@]} )) || { echo 'No hay servicios QA activos para respaldar.' >&2; exit 1; }
dc --profile operation stop "${cchia_services[@]}" >/dev/null
cchia_id="$(date -u +%Y%m%dT%H%M%SZ)-$(openssl rand -hex 4)"
cchia_work="$cchia_private/backups/.$cchia_id.incomplete"
mkdir "$cchia_work"
dc exec -T db pg_dump -U cchia_qa -d cchia_qa -Fc > "$cchia_work/database.dump"
dc run --rm --no-deps --user root --entrypoint tar php -C /app/storage/app/private -czf - . > "$cchia_work/private-files.tar.gz"
cp "$cchia_env" "$cchia_work/qa.env"
cp "$cchia_root/operacion/VERSION.json" "$cchia_work/VERSION.json"
[[ -s "$cchia_work/database.dump" && -s "$cchia_work/private-files.tar.gz" ]]
(cd "$cchia_work" && sha256sum database.dump private-files.tar.gz qa.env VERSION.json > SHA256SUMS)
chmod 0600 "$cchia_work"/*
mv "$cchia_work" "$cchia_private/backups/$cchia_id"
cchia_work=''
printf 'Respaldo privado creado: %s\n' "$cchia_id"
