#!/usr/bin/env bash
# Ekte admin-dialog og backend mot isolert DB og egen idempotent Vipps-stubbe.
set -euo pipefail
cd "$(dirname "$0")/../.."
php tests/nettleser/testdatabase.php
PORT=${REFUND_BROWSER_PORT:-8160}
STUBPORT=${REFUND_STUB_PORT:-8165}
export E2E_REFUND_ADRESSE="http://lokal.lissom.no:$PORT"
export E2E_REFUND_VIPPS="http://127.0.0.1:$STUBPORT"
export REFUND_STUB_PORT="$STUBPORT"
export LISSOM_VIPPS_BASE="$E2E_REFUND_VIPPS"
if curl -s -m 1 "$E2E_REFUND_VIPPS/health" >/dev/null 2>&1; then
  echo 'Testporten er opptatt.' >&2; exit 1
fi
KOPI=$(mktemp)
cp app/secrets.php "$KOPI"
opprydding() {
  [ -z "${WEB_PID:-}" ] || kill "$WEB_PID" 2>/dev/null || true
  [ -z "${STUB_PID:-}" ] || kill "$STUB_PID" 2>/dev/null || true
  cp "$KOPI" app/secrets.php
  rm -f "$KOPI"
}
trap opprydding EXIT
php -r '
require "tests/nettleser/testdatabase.php";
$s = krev_testdatabase(getcwd());
$s["tillatte_opphav"] = array_values(array_unique(array_merge((array)($s["tillatte_opphav"] ?? []), [getenv("E2E_REFUND_ADRESSE")])));
file_put_contents("app/secrets.php", "<?php return " . var_export($s, true) . ";");
'
LOGG=$(mktemp -d)
node tests/nettleser/refusjon-vipps.mjs >"$LOGG/vipps.log" 2>&1 &
STUB_PID=$!
php -S "127.0.0.1:$PORT" -t "$PWD" tests/nettleser/ruter.php >"$LOGG/php.log" 2>&1 &
WEB_PID=$!
for _ in $(seq 1 30); do
  curl -fsS -m 1 "$E2E_REFUND_VIPPS/health" >/dev/null 2>&1 && break
  sleep 1
done
for _ in $(seq 1 30); do
  curl -s -m 1 "http://127.0.0.1:$PORT/api/meg.php" >/dev/null 2>&1 && break
  sleep 1
done
node tests/nettleser/refusjon.mjs
