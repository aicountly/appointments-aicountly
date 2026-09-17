#!/usr/bin/env bash
# Run the Appointments integration tests against a throwaway PostgreSQL database
# and a local stub standing in for Calendar, Contacts and Manage.
#
#   server-php/tests/run.sh
#
# Requires: php with pdo_pgsql and curl, and a reachable PostgreSQL.
#
# The .env it writes is a TEST .env and overwrites any local one — which is why
# this script exists rather than the instructions saying "set these by hand".
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

DB_NAME="${TEST_DB_NAME:-appointments_test}"
DB_USER="${TEST_DB_USER:-appointments_test}"
DB_PASS="${TEST_DB_PASS:-appointments_test}"
DB_HOST="${TEST_DB_HOST:-127.0.0.1}"
DB_PORT="${TEST_DB_PORT:-5432}"
STUB_PORT="${STUB_PORT:-8793}"

cat > "$ROOT/.env" <<ENVEOF
APP_ENV=local
APP_PRODUCT_KEY=appointments
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS

# Every cross-app client points at the one stub.
CALENDAR_API_BASE=http://127.0.0.1:$STUB_PORT
CONTACTS_API_BASE=http://127.0.0.1:$STUB_PORT
MANAGE_API_BASE=http://127.0.0.1:$STUB_PORT

# Calendar is the only integration Appointments cannot work without, so the
# tests run with it configured. Everything else stays off, which is also what
# exercises the graceful-degradation paths.
CALENDAR_SERVICE_KEY=test-calendar-service-key-0123456789
APPOINTMENTS_CONTACTS_ENABLED=1
ENVEOF

php "$ROOT/bin/migrate.php" > /dev/null

php -S "127.0.0.1:$STUB_PORT" "$ROOT/tests/stub/router.php" > /dev/null 2>&1 &
STUB_PID=$!
trap 'kill $STUB_PID 2>/dev/null || true' EXIT

# Wait for the stub rather than sleeping a guessed amount.
for _ in $(seq 1 40); do
  if curl -fsS --noproxy '*' "http://127.0.0.1:$STUB_PORT/api/health" > /dev/null 2>&1; then break; fi
  sleep 0.25
done

php "$ROOT/tests/integration.php"
