#!/usr/bin/env bash
# One-time bootstrap of the managed Postgres cluster for Familywell.
#
# Creates the `familywell` database and role, then hands the role ownership of the public schema.
# That last part is the non-obvious bit: since Postgres 15, a non-owner role gets no CREATE on
# `public`, so `php artisan migrate` fails with
#   SQLSTATE[42501]: permission denied for schema public
# even though the connection itself is fine. DO's managed PG creates the cluster owned by `doadmin`,
# so a fresh app role hits this every time.
#
# Idempotent: re-running is a no-op, so it is safe after a restore or a cluster rebuild.
# Nothing here prints a credential.
#
# Usage: .do/bootstrap-db.sh [cluster-name]
set -euo pipefail

CLUSTER="${1:-familywell-db}"
APP_DB="familywell"
APP_USER="familywell"

DB_ID="$(fw-do databases list --format ID,Name --no-header | awk -v c="$CLUSTER" '$2==c{print $1}')"
[ -n "$DB_ID" ] || { echo "no database cluster named '$CLUSTER'" >&2; exit 1; }

# `databases db/user create` have no --format flag and print the new password on success, so their
# output is dropped wholesale rather than filtered. Failures are expected when they already exist.
fw-do databases db create "$DB_ID" "$APP_DB" >/dev/null 2>&1 && echo "==> created database $APP_DB" \
    || echo "==> database $APP_DB already exists"
fw-do databases user create "$DB_ID" "$APP_USER" >/dev/null 2>&1 && echo "==> created role $APP_USER" \
    || echo "==> role $APP_USER already exists"

PW="$(fw-do databases user get "$DB_ID" doadmin --format Password --no-header 2>/dev/null)"
HOST="$(fw-do databases get "$DB_ID" --format URI --no-header 2>/dev/null | sed -E 's|.*@([^:]+):.*|\1|')"
PORT="$(fw-do databases get "$DB_ID" --format Port --no-header 2>/dev/null)"
[ -n "$PW" ] && [ -n "$HOST" ] || { echo "could not resolve admin credentials" >&2; exit 1; }

# psql via docker so the host needs no postgres client installed. PGPASSWORD keeps the secret out
# of the process arguments; DO's managed PG requires TLS.
echo "==> granting $APP_USER ownership of schema public in $APP_DB"
docker run --rm -i -e PGPASSWORD="$PW" postgres:16-alpine \
    psql "postgresql://doadmin@${HOST}:${PORT}/${APP_DB}?sslmode=require" -v ON_ERROR_STOP=1 >/dev/null <<SQL
GRANT ALL ON DATABASE ${APP_DB} TO ${APP_USER};
ALTER SCHEMA public OWNER TO ${APP_USER};
GRANT ALL ON SCHEMA public TO ${APP_USER};
SQL

echo "==> done. ${APP_USER} can now run migrations against ${APP_DB}."
