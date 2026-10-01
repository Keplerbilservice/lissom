#!/usr/bin/env bash
#
# Delt betaling i «Ta betalt», ende til ende gjennom de ekte endepunktene.
#
# Eieren, 27. september 2026: «hvordan kan admin ta i mot betalingen? Litt
# gavekort og litt penger og litt vipps, dele betaling?» — GO paa skissen
# samme dag. Denne kjorer en kursplass, et ubetalt kassesalg og et
# medlemskap gjennom delt oppgjor, og sjekker det som skal staa etterpaa:
# én betaling per del, gavekortet trukket én gang, status betalt, og at feil
# sum eller for lite paa kortet ikke lagrer noe.
#
#   tests/deltbetaling.sh
#
# Krever app/secrets.php mot en database migrasjonene er kjort mot.

set -uo pipefail
cd "$(dirname "$0")/.."
ROT="$(pwd -W 2>/dev/null || pwd)"

PORT=${PORT_DELT:-8127}
T=$(mktemp -d); T=$(cd "$T" && (pwd -W 2>/dev/null || pwd))

ok=0; feil=0
sjekk() { # sjekk "navn" "ventet" "fikk"
  if [ "$2" = "$3" ]; then ok=$((ok+1)); echo "  OK    $1"
  else feil=$((feil+1)); echo "  FEIL  $1 — ventet «$2», fikk «$3»"; fi
}

opprydding() {
  [ -n "${PID_WEB:-}" ] && kill "$PID_WEB" 2>/dev/null
  php -r 'require "'"$ROT"'/app/bootstrap.php";
    $k = array_column(DB::alle("SELECT id FROM courses WHERE slug = \"testdelt\""), "id");
    foreach ($k as $c) {
      // Bookingen peker paa betalingen (fk_bookings_payment): lenka loeses foerst.
      DB::kjor("UPDATE bookings SET payment_id = NULL WHERE course_id = :c", ["c" => $c]);
      DB::kjor("DELETE FROM payments WHERE booking_id IN (SELECT id FROM bookings WHERE course_id = :c)", ["c" => $c]);
      DB::kjor("DELETE FROM bookings WHERE course_id = :c", ["c" => $c]);
      DB::kjor("DELETE FROM course_sessions WHERE course_id = :c", ["c" => $c]);
      DB::kjor("DELETE FROM courses WHERE id = :c", ["c" => $c]);
    }
    DB::kjor("UPDATE gift_cards SET payment_id = NULL WHERE kode LIKE \"DELT-TEST-%\"");
    DB::kjor("UPDATE orders SET payment_id = NULL WHERE ordrenr LIKE \"TEST-DELTOPP%\"");
    DB::kjor("DELETE FROM payments WHERE order_id IN (SELECT id FROM orders WHERE ordrenr LIKE \"TEST-DELTOPP%\")");
    DB::kjor("DELETE FROM orders WHERE ordrenr LIKE \"TEST-DELTOPP%\"");
    DB::kjor("DELETE FROM gift_card_uses WHERE gift_card_id IN (SELECT id FROM gift_cards WHERE kode LIKE \"DELT-TEST-%\")");
    DB::kjor("DELETE FROM gift_cards WHERE kode LIKE \"DELT-TEST-%\"");
    $m = array_column(DB::alle("SELECT id FROM members WHERE epost LIKE \"delt-%@lissom.test\""), "id");
    foreach ($m as $i) {
      DB::kjor("UPDATE orders SET payment_id = NULL WHERE payment_id IN (SELECT id FROM payments WHERE member_id = :m OR registrert_av = :m2)", ["m" => $i, "m2" => $i]);
      DB::kjor("UPDATE bookings SET payment_id = NULL WHERE payment_id IN (SELECT id FROM payments WHERE member_id = :m OR registrert_av = :m2)", ["m" => $i, "m2" => $i]);
      DB::kjor("DELETE FROM payments WHERE member_id = :m OR registrert_av = :m2", ["m" => $i, "m2" => $i]);
      DB::kjor("DELETE FROM sessions WHERE member_id = :m", ["m" => $i]);
      DB::kjor("DELETE FROM members WHERE id = :m", ["m" => $i]);
    }' 2>/dev/null
  rm -rf "$T"
}
trap opprydding EXIT

# --- Samme mappestruktur som webhotellet ----------------------------------
mkdir -p "$T/public_html" "$T/lissom-app" "$T/lissom-secrets"
cp -r api "$T/public_html/api"
cp -r app "$T/lissom-app/app"
rm -f "$T/lissom-app/app/secrets.php"
php -r '
$s = require "'"$ROT"'/app/secrets.php";
$s["nettsted"] = "https://lissom.no";
$s["miljo"] = "test";
file_put_contents("'"$T"'/lissom-secrets/secrets.php", "<?php return " . var_export($s, true) . ";");
' || { echo "Fant ikke app/secrets.php — sett den opp forst."; exit 1; }

php -S "127.0.0.1:$PORT" -t "$T/public_html" >/dev/null 2>&1 &
PID_WEB=$!
for _ in $(seq 1 20); do
  curl -s -m 2 -o /dev/null "http://127.0.0.1:$PORT/api/kurs.php" && break
  sleep 1
done
B="http://127.0.0.1:$PORT/api/admin"
ORIG="Origin: https://lissom.no"

# --- Testdata: admin, kursplass, kassesalg, medlem, to gavekort ------------
read -r TOKEN BOOKING ORDRE MEDLEM < <(php -r '
require "'"$ROT"'/app/bootstrap.php";
$admin = DB::settInn("members", ["navn" => "Delt Admin", "epost" => "delt-admin-" . bin2hex(random_bytes(3)) . "@lissom.test", "rolle" => "admin"]);
$t = bin2hex(random_bytes(32));
DB::settInn("sessions", ["token_hash" => hash("sha256", $t), "member_id" => $admin,
  "expires_at" => gmdate("Y-m-d H:i:s", time() + 3600)]);
$kurs = DB::settInn("courses", ["slug" => "testdelt", "tittel" => "Testdelt", "type" => "kurs",
  "pris_ore" => 280000, "kapasitet" => 8, "status" => "publisert"]);
$okt = DB::settInn("course_sessions", ["course_id" => $kurs,
  "start_tid" => gmdate("Y-m-d", time() + 864000) . " 10:00:00", "kapasitet" => 8]);
$bok = DB::settInn("bookings", ["course_id" => $kurs, "course_session_id" => $okt, "antall" => 1,
  "gjest_navn" => "Delt Deltaker", "gjest_epost" => "delt-gjest@lissom.test",
  "belop_ore" => 280000, "status" => "reservert"]);
$ordre = DB::settInn("orders", ["ordrenr" => "TEST-DELTOPP-" . strtoupper(bin2hex(random_bytes(2))),
  "kunde_navn" => "Testkunde", "sum_ore" => 150000, "status" => "hentet", "betalt_maate" => "Ikke betalt", "payment_id" => null]);
DB::settInn("order_lines", ["order_id" => $ordre, "product_id" => null, "tittel" => "Produkt", "antall" => 1, "pris_ore" => 150000]);
$medlem = DB::settInn("members", ["navn" => "Delt Medlem", "epost" => "delt-medlem-" . bin2hex(random_bytes(3)) . "@lissom.test", "rolle" => "medlem"]);
foreach (["DELT-TEST-A" => 100000, "DELT-TEST-B" => 50000] as $k => $o) {
  DB::settInn("gift_cards", ["kode" => $k, "opprinnelig_ore" => $o, "saldo_ore" => $o,
    "gyldig_til" => gmdate("Y-m-d", time() + 86400 * 365), "status" => "aktivt", "opprinnelse" => "gitt"]);
}
echo "$t $bok $ordre $medlem";
')

post() { # post fil json
  curl -s -m 15 -X POST -H "Content-Type: application/json" -H "$ORIG" \
    -H "Cookie: lissom_sesjon=$TOKEN" -d "$2" "$B/$1"
}
felt() { php -r '$d = json_decode(stream_get_contents(STDIN), true); echo is_array($d) ? json_encode($d["'"$1"'"] ?? null, JSON_UNESCAPED_UNICODE) : "ikke-json";'; }
sql() { php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("'"$1"'");'; }

echo
echo "── Kursplass: gavekort + kontant + Vipps ──"
SVAR=$(post kursbetaling.php "{\"handling\":\"delt\",\"bookingId\":$BOOKING,\"deler\":[{\"maate\":\"Kontant\",\"belop\":\"800\"},{\"maate\":\"Vipps\",\"belop\":\"900\"}]}")
sjekk "feil sum avvises" "false" "$(echo "$SVAR" | felt ok)"
sjekk "… og ingenting er lagret" "0" "$(sql "SELECT COUNT(*) FROM payments WHERE booking_id = $BOOKING")"

SVAR=$(post kursbetaling.php "{\"handling\":\"delt\",\"bookingId\":$BOOKING,\"deler\":[{\"maate\":\"Gavekort\",\"belop\":\"1100\",\"kode\":\"DELT-TEST-A\"},{\"maate\":\"Kontant\",\"belop\":\"800\"},{\"maate\":\"Vipps\",\"belop\":\"900\"}]}")
sjekk "mer enn saldoen paa kortet avvises" "false" "$(echo "$SVAR" | felt ok)"
sjekk "… og kortet er urort" "100000" "$(sql "SELECT saldo_ore FROM gift_cards WHERE kode = 'DELT-TEST-A'")"

SVAR=$(post kursbetaling.php "{\"handling\":\"delt\",\"bookingId\":$BOOKING,\"deler\":[{\"maate\":\"Gavekort\",\"belop\":\"1000\",\"kode\":\"DELT-TEST-A\"},{\"maate\":\"Kontant\",\"belop\":\"800\"},{\"maate\":\"Vipps\",\"belop\":\"1000\"}]}")
sjekk "delt betaling godtas" "true" "$(echo "$SVAR" | felt ok)"
sjekk "plassen er betalt" "betalt" "$(sql "SELECT status FROM bookings WHERE id = $BOOKING")"
sjekk "tre betalinger, én per del" "3" "$(sql "SELECT COUNT(*) FROM payments WHERE booking_id = $BOOKING AND status = 'betalt'")"
sjekk "kontanter inn: kr 800" "80000" "$(sql "SELECT belop_ore FROM payments WHERE booking_id = $BOOKING AND maate = 'Kontant'")"
sjekk "Vipps inn: kr 1 000" "100000" "$(sql "SELECT belop_ore FROM payments WHERE booking_id = $BOOKING AND maate = 'Vipps'")"
sjekk "gavekortdelen er null kroner i penger" "0" "$(sql "SELECT belop_ore FROM payments WHERE booking_id = $BOOKING AND maate = 'Gavekort'")"
sjekk "… og kr 1 000 paa kortet" "100000" "$(sql "SELECT gavekort_ore FROM payments WHERE booking_id = $BOOKING AND maate = 'Gavekort'")"
sjekk "kortet er trukket" "0" "$(sql "SELECT saldo_ore FROM gift_cards WHERE kode = 'DELT-TEST-A'")"
sjekk "… én gang, med spor" "1" "$(sql "SELECT COUNT(*) FROM gift_card_uses WHERE gift_card_id = (SELECT id FROM gift_cards WHERE kode = 'DELT-TEST-A')")"

SVAR=$(post kursbetaling.php "{\"handling\":\"delt\",\"bookingId\":$BOOKING,\"deler\":[{\"maate\":\"Kontant\",\"belop\":\"800\"},{\"maate\":\"Vipps\",\"belop\":\"1000\"}]}")
sjekk "en plass som er gjort opp kan ikke deles en gang til" "false" "$(echo "$SVAR" | felt ok)"

GAVERAD=$(sql "SELECT id FROM payments WHERE booking_id = $BOOKING AND maate = 'Gavekort'")
SVAR=$(post kursbetaling.php "{\"handling\":\"annuller\",\"betalingId\":$GAVERAD}")
sjekk "gavekortdelen kan annulleres" "true" "$(echo "$SVAR" | felt ok)"
sjekk "… og beloepet er tilbake paa kortet" "100000" "$(sql "SELECT saldo_ore FROM gift_cards WHERE kode = 'DELT-TEST-A'")"
sjekk "… og plassen staar ubetalt igjen" "reservert" "$(sql "SELECT status FROM bookings WHERE id = $BOOKING")"

echo
echo "── Dagsoppgjoret viser hver maate for seg ──"
# Delene paa nytt (gavekortdelen ble annullert over), og saa dagens bilag.
post kursbetaling.php "{\"handling\":\"delt\",\"bookingId\":$BOOKING,\"deler\":[{\"maate\":\"Gavekort\",\"belop\":\"1000\",\"kode\":\"DELT-TEST-A\"}]}" >/dev/null
IDAG=$(php -r 'echo (new DateTime("now", new DateTimeZone("Europe/Oslo")))->format("Y-m-d");')
DAG=$(curl -s -m 15 -H "Cookie: lissom_sesjon=$TOKEN" "$B/dagsoppgjor.php?dato=$IDAG")
MAATER=$(echo "$DAG" | php -r '$d = json_decode(stream_get_contents(STDIN), true);
  $m = [];
  array_walk_recursive($d, function ($v, $k) use (&$m) { if ($k === "maate" && is_string($v)) $m[$v] = 1; });
  ksort($m); echo implode(",", array_keys($m));')
sjekk "kontantdelen staar i dagsoppgjoret" "ja" "$(echo "$MAATER" | grep -q 'Kontant' && echo ja || echo nei)"
sjekk "… og Vipps-delen for seg" "ja" "$(echo "$MAATER" | grep -q 'Vipps' && echo ja || echo nei)"
sjekk "plassen er betalt igjen med kortet" "betalt" "$(sql "SELECT status FROM bookings WHERE id = $BOOKING")"

echo
echo "── Kassesalg som sto ubetalt: kontant + Vipps ──"
SVAR=$(post uttak.php "{\"handling\":\"gjorOpp\",\"ordreId\":$ORDRE,\"deler\":[{\"maate\":\"Kontant\",\"belop\":\"500\"},{\"maate\":\"Vipps\",\"belop\":\"900\"}]}")
sjekk "feil sum avvises" "false" "$(echo "$SVAR" | felt ok)"
SVAR=$(post uttak.php "{\"handling\":\"gjorOpp\",\"ordreId\":$ORDRE,\"deler\":[{\"maate\":\"Kontant\",\"belop\":\"500\"},{\"maate\":\"Vipps\",\"belop\":\"1000\"}]}")
sjekk "delt oppgjor godtas" "true" "$(echo "$SVAR" | felt ok)"
sjekk "to betalinger paa salget" "2" "$(sql "SELECT COUNT(*) FROM payments WHERE order_id = $ORDRE")"
sjekk "salget peker paa en betaling" "1" "$(sql "SELECT payment_id IS NOT NULL FROM orders WHERE id = $ORDRE")"
sjekk "maaten sier begge" "Kontant + Vipps" "$(sql "SELECT betalt_maate FROM orders WHERE id = $ORDRE")"

echo
echo "── Medlemskap: kontant + gavekort ──"
SVAR=$(post medlemmer.php "{\"handling\":\"betaling\",\"medlemId\":$MEDLEM,\"deler\":[{\"maate\":\"Kontant\",\"belop\":\"300\"},{\"maate\":\"Gavekort\",\"belop\":\"200\",\"kode\":\"DELT-TEST-B\"}]}")
sjekk "delt medlemsbetaling godtas" "true" "$(echo "$SVAR" | felt ok)"
sjekk "to betalinger paa medlemskapet" "2" "$(sql "SELECT COUNT(*) FROM payments WHERE member_id = $MEDLEM AND formal = 'medlemskap'")"
sjekk "kortet er trukket kr 200" "30000" "$(sql "SELECT saldo_ore FROM gift_cards WHERE kode = 'DELT-TEST-B'")"

echo
echo "── $ok gikk gjennom, $feil feilet"
[ "$feil" -eq 0 ]
