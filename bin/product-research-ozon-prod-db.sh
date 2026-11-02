#!/bin/sh
# Explicit production provisioning. No migration, bucket creation or marketplace I/O.
# Password is a protected file value, passed to psql over stdin, never argv.
set -eu
C=${COMPOSE:-docker compose -f docker-compose.prod.yml}
RESEARCH_ENV_FILE=${RESEARCH_ENV_FILE:-research.env}
HERE=$(dirname "$0")
fail() { echo 'research: invalid database provisioning configuration' >&2; exit 1; }
[ -f "$RESEARCH_ENV_FILE" ] || fail
case $(stat -c '%a' "$RESEARCH_ENV_FILE") in 600|400) ;; *) fail ;; esac
password=$(sed -n 's/^RESEARCH_DB_PASSWORD=//p' "$RESEARCH_ENV_FILE")
[ "${#password}" -eq 64 ] || fail
printf '%s' "$password" | grep -Eq '^[0-9a-f]{64}$' || fail
{
    printf "\\\\set pw '%s'\n" "$password"
    cat <<'SQL'
SELECT 'CREATE ROLE svc_product_research_ozon LOGIN'
WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'svc_product_research_ozon')\gexec
DO $$ BEGIN
IF (SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = 'product_research_ozon') <> 'svc_product_research_ozon'
OR EXISTS (SELECT 1 FROM pg_auth_members WHERE member = (SELECT oid FROM pg_roles WHERE rolname = 'svc_product_research_ozon'))
THEN RAISE EXCEPTION 'unexpected_research_database_ownership'; END IF;
END $$;
ALTER ROLE svc_product_research_ozon LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS PASSWORD :'pw';
SELECT 'CREATE DATABASE product_research_ozon OWNER svc_product_research_ozon'
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'product_research_ozon')\gexec
REVOKE CONNECT ON DATABASE product_research_ozon FROM PUBLIC;
SQL
} | $C exec -T postgres sh -c 'psql -q -v ON_ERROR_STOP=1 -U "$POSTGRES_USER" -d "$POSTGRES_DB"' >/dev/null 2>&1 \
    || { echo 'research: database provisioning failed' >&2; exit 1; }
$C exec -T postgres sh -s < "$HERE/04-product-research-ozon-hba.sh" >/dev/null 2>&1 \
    || { echo 'research: HBA configuration failed' >&2; exit 1; }
echo 'research: own database, role and HBA provisioned; migrations not applied'
