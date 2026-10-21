#!/bin/sh
set -eu
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" -v owner="$POSTGRES_USER" <<'SQL'
CREATE DATABASE cchia_test OWNER :"owner";
SQL
