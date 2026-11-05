#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"
cchia_action="${1:-ayuda}"
if [[ "$cchia_action" = ayuda ]]; then
    echo 'Uso: bash operacion/scripts/qa.sh config|construir|iniciar|migrar|probar|scheduler|estado|parar'
    exit 0
fi
[[ $# = 1 ]] || { echo 'Usar exactamente una acción.' >&2; exit 1; }
case "$cchia_action" in config|construir|iniciar|migrar|probar|scheduler|estado|parar) ;; *) echo 'Acción inválida.' >&2; exit 1 ;; esac
require_environment
case "$cchia_action" in
    config) dc config --quiet ;;
    construir) dc build php web tests ;;
    iniciar) dc up -d db php web ;;
    migrar) dc exec -T php php artisan migrate --force; dc exec -T php php artisan admissions:diagnose ;;
    probar)
        mkdir -p "$cchia_private/evidencias"
        cchia_status=0
        dc --profile tests run --rm tests || cchia_status=$?
        cchia_report="$(mktemp "$cchia_private/evidencias/.qa.XXXXXX")"
        if dc --profile tests run --rm --no-deps tests sh -c 'cat /app/storage/logs/qa.xml' > "$cchia_report"; then
            chmod 0600 "$cchia_report"
            mv "$cchia_report" "$cchia_private/evidencias/qa_$(date -u +%Y%m%dT%H%M%SZ).xml"
        else
            rm -f "$cchia_report"
            [[ "$cchia_status" != 0 ]] || cchia_status=1
        fi
        exit "$cchia_status"
        ;;
    scheduler) dc --profile operation up -d scheduler ;;
    estado) dc --profile operation ps; dc exec -T php php artisan cchia:ops summary --json ;;
    parar) dc --profile operation stop ;;
esac
