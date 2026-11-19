#!/usr/bin/env bash
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/comun.sh"
[[ $# = 1 ]] || { echo 'Uso: bash operacion/scripts/verificar_respaldo.sh ID_RESPALDO'; exit 1; }
snapshot_path "$1"
for cchia_name in database.dump private-files.tar.gz qa.env VERSION.json SHA256SUMS; do
    [[ -f "$cchia_snapshot/$cchia_name" && ! -L "$cchia_snapshot/$cchia_name" ]] || exit 1
done
[[ "$(LC_ALL=C head -c 5 "$cchia_snapshot/database.dump")" = PGDMP ]] || { echo 'Formato de dump inesperado.' >&2; exit 1; }
[[ "$(wc -l < "$cchia_snapshot/SHA256SUMS")" = 4 ]] || exit 1
declare -A cchia_seen=()
while read -r cchia_hash cchia_name; do
    [[ "$cchia_hash" =~ ^[a-f0-9]{64}$ ]] || exit 1
    [[ -z "${cchia_seen[$cchia_name]:-}" ]] || exit 1
    cchia_seen[$cchia_name]=1
    case "$cchia_name" in database.dump|private-files.tar.gz|qa.env|VERSION.json) ;; *) exit 1 ;; esac
done < "$cchia_snapshot/SHA256SUMS"
(cd "$cchia_snapshot" && sha256sum --check --status SHA256SUMS)
LC_ALL=C tar -tvzf "$cchia_snapshot/private-files.tar.gz" | awk 'substr($0,1,1)!="d" && substr($0,1,1)!="-" {exit 1}'
while IFS= read -r cchia_entry; do
    [[ "$cchia_entry" != /* && "/$cchia_entry/" != *'/../'* ]] || exit 1
done < <(tar -tzf "$cchia_snapshot/private-files.tar.gz")
echo 'Checksums y estructura del respaldo correctos. Falta demostrar su restauración en PostgreSQL y verificar los documentos.'
