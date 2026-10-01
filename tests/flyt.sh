#!/usr/bin/env bash
#
# Hele betalingskjeden, ende til ende, mot en stubbet Vipps.
#
# Bygger opp samme mappestruktur som webhotellet — nettsiden i
# public_html, koden i lissom-app ved siden av — starter en
# webserver og en Vipps-stubbe, og kjorer gjennom booking, retur og webhook.
#
#   tests/flyt.sh
#
# Krever at app/secrets.php peker paa en database migrasjonene er kjort mot.

set -uo pipefail
cd "$(dirname "$0")/.."
ROT="$(pwd -W 2>/dev/null || pwd)"

PORT_WEB=${PORT_WEB:-8123}
PORT_VIPPS=${PORT_VIPPS:-8144}
T=$(mktemp -d); T=$(cd "$T" && (pwd -W 2>/dev/null || pwd))
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
mkdir -p "$T/public_html" "$T/lissom-app" "$T/lissom-secrets"
cp -r api "$T/public_html/api"
cp -r app "$T/lissom-app/app"
cp -r db/migrations "$T/lissom-app/migrations"
rm -f "$T/lissom-app/app/secrets.php"

php -r '
$s = require "'"$ROT"'/app/secrets.php";
$s["vipps_base"] = "http://127.0.0.1:'"$PORT_VIPPS"'";
$s["vipps_webhook_secret"] = "'"$HEMMELIGHET"'";
$s["nettsted"] = "https://lissom.no";
$s["miljo"] = "test";
file_put_contents("'"$T"'/lissom-secrets/secrets.php", "<?php return " . var_export($s, true) . ";");
' || { echo "Fant ikke app/secrets.php — sett den opp forst."; exit 1; }

php -S "127.0.0.1:$PORT_WEB" -t "$T/public_html" >/dev/null 2>&1 &
PID_WEB=$!
VIPPS_STUB_STYR="$T/styr.json" php -S "127.0.0.1:$PORT_VIPPS" tests/vipps-stub.php >/dev/null 2>&1 &
PID_VIPPS=$!

for _ in $(seq 1 20); do
  curl -s -m 2 -o /dev/null "http://127.0.0.1:$PORT_WEB/api/kurs.php" && break
  sleep 1
done

B="http://127.0.0.1:$PORT_WEB/api"
ORIG="Origin: https://lissom.no"

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
  WHERE c.status = \"publisert\" AND c.pris_ore > 0 AND cs.start_tid > UTC_TIMESTAMP() ORDER BY cs.start_tid LIMIT 1");')

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
REF=$(echo "$SVAR" | php -r '$j = json_decode(stream_get_contents(STDIN), true); echo is_array($j) ? ($j["referanse"] ?? "") : "";' 2>/dev/null)
sjekk "booking gir en referanse" "ja" "$([ -n "$REF" ] && echo ja || echo nei)"
[ -z "$REF" ] && echo "    oekt $OKT, svar: ${SVAR:0:300}"
sjekk "reservasjon opprettet" "reservert" "$(php -r 'require "'"$ROT"'/app/bootstrap.php";
  $p = DB::en("SELECT id FROM payments WHERE vipps_reference = :r", ["r" => "'"$REF"'"]);
  echo $p ? DB::verdi("SELECT status FROM bookings WHERE payment_id = :p", ["p" => $p["id"]]) : "mangler";')"

echo
echo "== Webhook =="
KROPP="{\"eventId\":\"flyt-$$\",\"name\":\"AUTHORIZED\",\"reference\":\"$REF\"}"
# Signert slik Vipps gjor det: «POST\n<sti>\n<dato>;<host>;<innholdshash>»,
# ikke bare kroppen. https://developer.vippsmobilepay.com/docs/APIs/webhooks-api/request-authentication/
DATO="Tue, 30 Sep 2026 12:00:00 GMT"
VERT="127.0.0.1:$PORT_WEB"
signer() { # signer kropp → «hash|autorisasjon»
  php -r '$h = base64_encode(hash("sha256", $argv[1], true));
    echo $h, "|HMAC-SHA256 SignedHeaders=x-ms-date;host;x-ms-content-sha256&Signature=",
      base64_encode(hash_hmac("sha256", "POST\n/api/vipps-webhook.php\n" . $argv[2] . ";" . $argv[3] . ";" . $h, $argv[4], true));' \
    "$1" "$DATO" "$VERT" "$HEMMELIGHET"
}
SIGNERT=$(signer "$KROPP")
HASH="${SIGNERT%%|*}"; SIG="${SIGNERT#*|}"
VH=(-H 'Content-Type: application/json' -H "x-ms-date: $DATO" -H "x-ms-content-sha256: $HASH")

sjekk "feil signatur avvises" "401" "$(curl -s -m 10 -o /dev/null -w '%{http_code}' -X POST \
  "${VH[@]}" -H 'Authorization: HMAC-SHA256 SignedHeaders=x-ms-date;host;x-ms-content-sha256&Signature=tull' \
  -d "$KROPP" "$B/vipps-webhook.php")"
sjekk "bare kroppen signert (gammelt format) avvises" "401" "$(curl -s -m 10 -o /dev/null -w '%{http_code}' -X POST \
  "${VH[@]}" -H "Authorization: HMAC-SHA256 $(php -r 'echo base64_encode(hash_hmac("sha256", $argv[1], $argv[2], true));' "$KROPP" "$HEMMELIGHET")" \
  -d "$KROPP" "$B/vipps-webhook.php")"
sjekk "endret kropp avvises" "401" "$(curl -s -m 10 -o /dev/null -w '%{http_code}' -X POST \
  "${VH[@]}" -H "Authorization: $SIG" -d "{\"eventId\":\"tull-$$\",\"name\":\"AUTHORIZED\",\"reference\":\"$REF\"}" "$B/vipps-webhook.php")"
sjekk "riktig signatur godtas" "200" "$(curl -s -m 15 -o /dev/null -w '%{http_code}' -X POST \
  "${VH[@]}" -H "Authorization: $SIG" -d "$KROPP" "$B/vipps-webhook.php")"
sjekk "bookingen er betalt" "betalt" "$(php -r 'require "'"$ROT"'/app/bootstrap.php";
  $p = DB::en("SELECT id FROM payments WHERE vipps_reference = :r", ["r" => "'"$REF"'"]);
  echo DB::verdi("SELECT status FROM bookings WHERE payment_id = :p", ["p" => $p["id"]]);')"
# Kundens kvittering. Siden 13. september gaar det ogsaa et internt varsel
# til verkstedet paa samme booking (46d2eed) — det teller ikke her.
sjekk "noyaktig én kvittering" "1" "$(php -r 'require "'"$ROT"'/app/bootstrap.php";
  $p = DB::en("SELECT id FROM payments WHERE vipps_reference = :r", ["r" => "'"$REF"'"]);
  $b = DB::en("SELECT id FROM bookings WHERE payment_id = :p", ["p" => $p["id"]]);
  echo (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE ref_type = \"booking\" AND ref_id = :i AND mal = \"ordrebekreftelse\"", ["i" => $b["id"]]);')"

echo
echo "== Samme webhook om igjen =="
sjekk "duplikat gjor ingenting" "1" "$(curl -s -m 15 -X POST -H 'Content-Type: application/json' \
  "${VH[@]}" -H "Authorization: $SIG" -d "$KROPP" "$B/vipps-webhook.php" >/dev/null;
  php -r 'require "'"$ROT"'/app/bootstrap.php";
  $p = DB::en("SELECT id FROM payments WHERE vipps_reference = :r", ["r" => "'"$REF"'"]);
  $b = DB::en("SELECT id FROM bookings WHERE payment_id = :p", ["p" => $p["id"]]);
  echo (int) DB::verdi("SELECT COUNT(*) FROM notifications WHERE ref_type = \"booking\" AND ref_id = :i AND mal = \"ordrebekreftelse\"", ["i" => $b["id"]]);')"

# --- Retur fra Vipps: trekket feiler, og trekket er alt gjort ---------------
ny_betaling() { # ny_betaling ref
  php -r 'require "'"$ROT"'/app/bootstrap.php";
  DB::settInn("payments", ["vipps_reference" => $argv[1], "type" => "epayment", "formal" => "ordre",
    "belop_ore" => 100, "status" => "venter", "idempotency_key" => Vipps::uuid()]);' "$1"
}
status_for() { php -r 'require "'"$ROT"'/app/bootstrap.php";
  echo DB::verdi("SELECT status FROM payments WHERE vipps_reference = :r", ["r" => $argv[1]]);' "$1"; }
utfall() { curl -s -m 15 -o /dev/null -w '%{redirect_url}' "$B/betaling-retur.php?ref=$1" | sed 's/.*#betaling=\([a-z]*\).*/\1/'; }
antall_trekk() { if [ -f "$T/styr.json.trekk" ]; then wc -l < "$T/styr.json.trekk" | tr -d ' '; else echo 0; fi; }

echo
echo "== Retur: trekket feiler =="
R1="FLYT-FEIL-$$"
ny_betaling "$R1"
echo '{"trekkFeiler":true}' > "$T/styr.json"; rm -f "$T/styr.json.trekk"
sjekk "kunden sendes til «venter»" "venter" "$(utfall "$R1")"
sjekk "betalingen er IKKE betalt" "venter" "$(status_for "$R1")"
sjekk "det ble bedt om trekk" "1" "$(antall_trekk)"

echo
echo "== Retur: Vipps har alt trukket (AUTHORIZED + capturedAmount) =="
echo '{"trukket":100}' > "$T/styr.json"; rm -f "$T/styr.json.trekk"
sjekk "kunden sendes til «ok»" "ok" "$(utfall "$R1")"
sjekk "betalingen er betalt" "betalt" "$(status_for "$R1")"
sjekk "ingen nytt trekk" "0" "$(antall_trekk)"

echo
echo "== Retur: vanlig trekk =="
R2="FLYT-OK-$$"
ny_betaling "$R2"
echo '{}' > "$T/styr.json"; rm -f "$T/styr.json.trekk"
sjekk "kunden sendes til «ok»" "ok" "$(utfall "$R2")"
sjekk "betalingen er betalt" "betalt" "$(status_for "$R2")"
sjekk "ett trekk" "1" "$(antall_trekk)"
rm -f "$T/styr.json"

echo
echo "── $ok gikk gjennom, $feil feilet"
[ "$feil" -eq 0 ]
