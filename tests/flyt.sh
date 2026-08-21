#!/usr/bin/env bash
#
# Hele betalingskjeden, ende til ende, mot en stubbet Vipps.
#
# Bygger opp samme mappestruktur som webhotellet — nettsiden i
# public_html/ny.lissom.no, koden i lissom-app ved siden av — starter en
# webserver og en Vipps-stubbe, og kjorer gjennom booking, retur og webhook.
#
#   tests/flyt.sh
#
# Krever at app/secrets.php peker paa en database migrasjonene er kjort mot.

set -uo pipefail
cd "$(dirname "$0")/.."
ROT="$(pwd)"

PORT_WEB=${PORT_WEB:-8123}
PORT_VIPPS=${PORT_VIPPS:-8144}
T=$(mktemp -d)
HEMMELIGHET="hemmelig-test-$$"

ok=0; feil=0
sjekk() { # sjekk "navn" "ventet" "fikk"
  if [ "$2" = "$3" ]; then ok=$((ok+1)); printf '  \033[32m✓\033[0m %s\n' "$1"
  else feil=$((feil+1)); printf '  \033[31m✗\033[0m %s — ventet «%s», fikk «%s»\n' "$1" "$2" "$3"; fi
}

opprydding() {
  [ -n "${PID_WEB:-}" ] && kill "$PID_WEB" 2>/dev/null
  [ -n "${PID_VIPPS:-}" ] && kill "$PID_VIPPS" 2>/dev/null
  rm -rf "$T"
}
trap opprydding EXIT

# --- Bygg opp serverens mappestruktur ------------------------------------
mkdir -p "$T/public_html/ny.lissom.no" "$T/lissom-app" "$T/lissom-secrets"
cp -r api "$T/public_html/ny.lissom.no/api"
cp -r app "$T/lissom-app/app"
cp -r db/migrations "$T/lissom-app/migrations"
rm -f "$T/lissom-app/app/secrets.php"

php -r '
$s = require "'"$ROT"'/app/secrets.php";
$s["vipps_base"] = "http://127.0.0.1:'"$PORT_VIPPS"'";
$s["vipps_webhook_secret"] = "'"$HEMMELIGHET"'";
$s["nettsted"] = "https://ny.lissom.no";
$s["miljo"] = "test";
file_put_contents("'"$T"'/lissom-secrets/secrets.php", "<?php return " . var_export($s, true) . ";");
' || { echo "Fant ikke app/secrets.php — sett den opp forst."; exit 1; }

php -S "127.0.0.1:$PORT_WEB" -t "$T/public_html/ny.lissom.no" >/dev/null 2>&1 &
PID_WEB=$!
php -S "127.0.0.1:$PORT_VIPPS" tests/vipps-stub.php >/dev/null 2>&1 &
PID_VIPPS=$!

for _ in $(seq 1 20); do
  curl -s -m 2 -o /dev/null "http://127.0.0.1:$PORT_WEB/api/kurs.php" && break
  sleep 1
done

B="http://127.0.0.1:$PORT_WEB/api"
ORIG="Origin: https://ny.lissom.no"

# --- Testmedlem og sesjon -------------------------------------------------
TOKEN=$(php -r '
require "'"$ROT"'/app/bootstrap.php";
$m = DB::en("SELECT id FROM members WHERE vipps_sub = :s", ["s" => "flyt-test"]);
$id = $m ? $m["id"] : DB::settInn("members", [
  "vipps_sub" => "flyt-test", "navn" => "Flyt Test",
  "epost" => "flyt@example.com", "telefon" => "+4791234567",
]);
$t = bin2hex(random_bytes(32));
DB::settInn("sessions", ["token_hash" => hash("sha256", $t), "member_id" => $id,
  "expires_at" => gmdate("Y-m-d H:i:s", time() + 3600)]);
echo $t;
')

OKT=$(php -r 'require "'"$ROT"'/app/bootstrap.php";
echo (int) DB::verdi("SELECT cs.id FROM course_sessions cs JOIN courses c ON c.id = cs.course_id
  WHERE c.status = \"publisert\" AND cs.start_tid > UTC_TIMESTAMP() ORDER BY cs.start_tid LIMIT 1");')

echo
echo "== Uten innlogging =="
sjekk "admin avvises" "401" "$(curl -s -m 10 -o /dev/null -w '%{http_code}' "$B/admin/oversikt.php")"
sjekk "katalogen er aapen" "200" "$(curl -s -m 10 -o /dev/null -w '%{http_code}' "$B/kurs.php")"
sjekk "fremmed opphav avvises" "403" "$(curl -s -m 10 -o /dev/null -w '%{http_code}' -X POST \
  -H 'Content-Type: application/json' -H 'Origin: https://ondsinnet.example' \
  -d '{"kursId":1,"navn":"X","epost":"x@example.com"}' "$B/venteliste.php")"

echo
echo "== Booking =="
SVAR=$(curl -s -m 15 -X POST -H "Content-Type: application/json" -H "$ORIG" \
  -H "Cookie: lissom_sesjon=$TOKEN" -d "{\"oktId\":$OKT,\"antall\":1}" "$B/book.php")
REF=$(echo "$SVAR" | python3 -c "import sys,json;print(json.load(sys.stdin).get('referanse',''))" 2>/dev/null)
sjekk "booking gir en referanse" "ja" "$([ -n "$REF" ] && echo ja || echo nei)"
sjekk "reservasjon opprettet" "reservert" "$(php -r 'require "'"$ROT"'/app/bootstrap.php";
  $p = DB::en("SELECT id FROM payments WHERE vipps_reference = :r", ["r" => "'"$REF"'"]);
  echo $p ? DB::verdi("SELECT status FROM bookings WHERE payment_id = :p", ["p" => $p["id"]]) : "mangler";')"

echo
echo "== Webhook =="
KROPP="{\"eventId\":\"flyt-$$\",\"name\":\"AUTHORIZED\",\"reference\":\"$REF\"}"
SIG=$(python3 -c "
import hmac, hashlib, base64, sys
print('HMAC-SHA256 ' + base64.b64encode(
    hmac.new(sys.argv[1].encode(), sys.argv[2].encode(), hashlib.sha256).digest()).decode())" \
  "$HEMMELIGHET" "$KROPP")

sjekk "feil signatur avvises" "401" "$(curl -s -m 10 -o /dev/null -w '%{http_code}' -X POST \
  -H 'Content-Type: application/json' -H 'Authorization: HMAC-SHA256 tull' \
  -d "{\"eventId\":\"tull-$$\",\"name\":\"AUTHORIZED\",\"reference\":\"$REF\"}" "$B/vipps-webhook.php")"
sjekk "riktig signatur godtas" "200" "$(curl -s -m 15 -o /dev/null -w '%{http_code}' -X POST \
  -H 'Content-Type: application/json' -H "Authorization: $SIG" -d "$KROPP" "$B/vipps-webhook.php")"
sjekk "bookingen er betalt" "betalt" "$(php -r 'require "'"$ROT"'/app/bootstrap.php";
  $p = DB::en("SELECT id FROM payments WHERE vipps_reference = :r", ["r" => "'"$REF"'"]);
  echo DB::verdi("SELECT status FROM bookings WHERE payment_id = :p", ["p" => $p["id"]]);')"
sjekk "noyaktig én kvittering" "1" "$(php -r 'require "'"$ROT"'/app/bootstrap.php";
  $p = DB::en("SELECT id FROM payments WHERE vipps_reference = :r", ["r" => "'"$REF"'"]);
  $b = DB::en("SELECT id FROM bookings WHERE payment_id = :p", ["p" => $p["id"]]);
  echo (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE ref_type = \"booking\" AND ref_id = :i", ["i" => $b["id"]]);')"

echo
echo "== Samme webhook om igjen =="
sjekk "duplikat gjor ingenting" "1" "$(curl -s -m 15 -X POST -H 'Content-Type: application/json' \
  -H "Authorization: $SIG" -d "$KROPP" "$B/vipps-webhook.php" >/dev/null;
  php -r 'require "'"$ROT"'/app/bootstrap.php";
  $p = DB::en("SELECT id FROM payments WHERE vipps_reference = :r", ["r" => "'"$REF"'"]);
  $b = DB::en("SELECT id FROM bookings WHERE payment_id = :p", ["p" => $p["id"]]);
  echo (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE ref_type = \"booking\" AND ref_id = :i", ["i" => $b["id"]]);')"

echo
echo "── $ok gikk gjennom, $feil feilet"
[ "$feil" -eq 0 ]
