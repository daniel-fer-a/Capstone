#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"
[[ $# = 2 && "$2" = --aplicar ]] || { echo 'Uso: bash operacion/scripts/restaurar_qa.sh ID_RESPALDO --aplicar'; exit 0; }
snapshot_path "$1"
bash "$cchia_root/operacion/scripts/verificar_respaldo.sh" "$1"
cchia_env="$cchia_snapshot/qa.env"
require_environment
cchia_project=cchia-restore-isolated
export CCHIA_QA_ENV_PATH="$cchia_env"
export QA_PORT=8089
[[ -z "$(dc --profile operation ps -a -q)" ]] || { echo 'Ya existe un entorno de restauración. No se sobrescribe.' >&2; exit 1; }
[[ -z "$(docker volume ls --quiet --filter label=com.docker.compose.project=cchia-restore-isolated)" ]] || { echo 'Existen volúmenes de otra restauración. Revisarlos manualmente.' >&2; exit 1; }
dc up -d --wait db
dc exec -T db pg_restore --exit-on-error --no-owner --no-privileges -U cchia_qa -d cchia_qa < "$cchia_snapshot/database.dump"
dc run --rm --no-deps --user root --entrypoint sh php -c 'mkdir -p /app/storage/app/private && tar --no-same-owner --no-same-permissions -xzf - -C /app/storage/app/private && chown -R www-data:www-data /app/storage' < "$cchia_snapshot/private-files.tar.gz"
dc up -d php web
dc exec -T php php artisan cchia:ops files --full --json
dc exec -T php php artisan workspace:ops files --full --json
echo 'Restauración aislada disponible en http://localhost:8089. Scheduler detenido. Validar acceso y expedientes antes de declarar recuperación comprobada.'
