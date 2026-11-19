#!/usr/bin/env bash
set -euo pipefail
umask 077
cchia_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cchia_private="$cchia_root/operacion/private"
cchia_env="$cchia_private/qa.env"
cchia_project=cchia-qa-isolated
dc() {
    docker compose --project-name "$cchia_project" --env-file "$cchia_env" -f "$cchia_root/operacion/compose.qa.yaml" "$@"
}
require_environment() {
    command -v docker >/dev/null || { echo 'Se requiere Docker con Compose.' >&2; exit 1; }
    [[ -f "$cchia_env" && ! -L "$cchia_env" ]] || { echo 'Preparar primero el entorno QA privado.' >&2; exit 1; }
}
snapshot_path() {
    [[ "${1:-}" =~ ^[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}$ ]] || { echo 'Identificador de respaldo inválido.' >&2; return 1; }
    cchia_snapshot="$cchia_private/backups/$1"
    [[ -d "$cchia_snapshot" && ! -L "$cchia_snapshot" ]] || { echo 'No existe ese respaldo local.' >&2; return 1; }
}
