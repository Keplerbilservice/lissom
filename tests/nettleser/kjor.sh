#!/usr/bin/env bash
#
# Nettlesertestene: hele flyter, klikket gjennom i en ekte Chromium.
#
# Eieren, 27. september 2026: «du og Gemini maa funksjonsteste alt, se at
# alt er klikkbart, se hva som paavirkes, at alt lagres og alle som skal faa
# beskjed faar beskjed, hele flyten». Testene i tests/backend.php leser
# koden; disse trykker paa knappene og ser etter i basen etterpaa.
#
#   bash tests/nettleser/kjor.sh
#
# Krever app/secrets.php mot en migrert base, PHP, Node og Playwright
# (npm install playwright && npx playwright install chromium).

set -euo pipefail
cd "$(dirname "$0")/../.."
ROT="$(pwd -W 2>/dev/null || pwd)"
PORT=${PORT_NETTLESER:-8140}
PORT_VIPPS=${PORT_NETTLESER_VIPPS:-8145}
VERT="lokal.lissom.no"
export E2E_ADRESSE="http://$VERT:$PORT"

[ -f app/secrets.php ] || { echo "Fant ikke app/secrets.php — sett den opp forst."; exit 1; }
php tests/nettleser/testdatabase.php
[ ! -e app/secrets.php.e2e-kopi ] || { echo "En tidligere testkopi finnes. Gjenopprett den forst."; exit 1; }
cp app/secrets.php app/secrets.php.e2e-kopi

opprydding() {
  local resultat=$?
  set +e
  [ -n "${PID_WEB:-}" ] && kill "$PID_WEB" 2>/dev/null
  [ -n "${PID_VIPPS:-}" ] && kill "$PID_VIPPS" 2>/dev/null
  # Paa Windows (Git Bash) naar ikke kill fram til node og php. Da tas de
  # paa portene de lytter paa.
  if command -v powershell >/dev/null 2>&1; then
    powershell -NoProfile -Command "Get-CimInstance Win32_Process | Where-Object { (\$_.Name -eq 'php.exe' -and \$_.CommandLine -match '127[.]0[.]0[.]1:$PORT') -or (\$_.Name -eq 'node.exe' -and \$_.CommandLine -like '*$ROT/tests/falsk-vipps.mjs*') } | ForEach-Object { Stop-Process -Id \$_.ProcessId -Force -ErrorAction SilentlyContinue }" >/dev/null 2>&1
  fi
  php tests/nettleser/seed.php --rydd || { echo "Opprydding feilet; kontroller testdatabasen." >&2; resultat=1; }
  if [ -f app/secrets.php.e2e-kopi ]; then
    mv -f app/secrets.php.e2e-kopi app/secrets.php || resultat=1
  fi
  exit "$resultat"
}
trap opprydding EXIT

# Adressen testene bruker slutter paa lissom.no: admin henter bare data
# naar den tror den ligger ute (erPublisert()). Chromium faar beskjed om aa
# sende den til 127.0.0.1, og serveren maa godta den som opphav.
php -r '
$s = require "app/secrets.php";
$s["miljo"] = "test";
$s["nettsted"] = getenv("E2E_ADRESSE");
$s["tillatte_opphav"] = array_values(array_unique(array_merge((array) ($s["tillatte_opphav"] ?? []), [getenv("E2E_ADRESSE")])));
$s["vipps_base"] = "http://127.0.0.1:'"$PORT_VIPPS"'";
foreach (["vipps_msn", "vipps_client_id", "vipps_client_secret", "vipps_sub_key"] as $n) { if (empty($s[$n])) $s[$n] = "test"; }
// Webhooken avviser alt uten hemmelighet. Testene signerer med denne (tests/kursstart-krav.php).
if (empty($s["vipps_webhook_secret"])) $s["vipps_webhook_secret"] = "e2e-webhook-hemmelighet";
file_put_contents("app/secrets.php", "<?php return " . var_export($s, true) . ";");
'

FALSK_VIPPS_PORT=$PORT_VIPPS node "$ROT/tests/falsk-vipps.mjs" >/dev/null 2>&1 &
PID_VIPPS=$!
LISSOM_VIPPS_BASE="http://127.0.0.1:$PORT_VIPPS" php -S "127.0.0.1:$PORT" -t "$ROT" tests/nettleser/ruter.php >/tmp/e2e-php.log 2>&1 &
PID_WEB=$!
for _ in $(seq 1 30); do
  curl -s -m 2 -o /dev/null "http://127.0.0.1:$PORT/api/kurs.php" && break
  sleep 1
done

SEED=$(php tests/nettleser/seed.php) || { echo "Fikk ikke laget testdata."; exit 1; }
if [ "${1:-}" = "admin-ny" ]; then
  for fil in admin-ny admin-ny-penger regnskapsforer-nyadmin nyadmin-regnskap-innlogging nyadmin-medlemsbetaling nyadmin-kursbetaling nyadmin-refusjon-ui nyadmin-avsluttende nyadmin-butikk nyadmin-utkast nyadmin-samling nyadmin-kalender-mobil nyadmin-kalender-pc nyadmin-kalender-ark nyadmin-kursstart kursstart-krav nyadmin-kalender-gjenta kalender-gjenta nyadmin-kalender-meny ubetalt-medlem minside-moduler-ut nyadmin-minside nyadmin-stemple-meny nyadmin-kursholder-bruker trekkplan trekk-l5-l6-l10 frys-trekk; do
    node "tests/$fil.mjs"
  done
  HENTING_TEST_URL="http://127.0.0.1:$PORT" HENTING_TEST_ORIGIN="$E2E_ADRESSE" node tests/henting.mjs
else
  E2E_SEED="$SEED" E2E_PORT="$PORT" node "tests/nettleser/${1:-flyter.mjs}"
fi
