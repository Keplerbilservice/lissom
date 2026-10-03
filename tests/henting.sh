#!/usr/bin/env bash
#
# «Betal ved henting» i nettbutikken uten Vipps-innlogging.
#
# Eieren, 28. september 2026: henting skal virke som «Betal ved oppmøte» paa
# kurs — kunden skriver navn, e-post og mobil, og trenger ikke logge inn med
# Vipps. Testen kjorer de ekte endepunktene som en gjest uten sesjon:
#
#   - gjest bestiller med henting → ordren staar ubetalt, uten betalingsrad,
#     med gjestens navn, e-post og mobil, og varen er lagt til side
#   - kunden faar «Butikkbestilling — hentes» i koen
#   - uten mobil → avvist, og ingenting lagres
#   - «Annuller» i admin → kansellert, varen tilbake paa lager én gang,
#     kunden faar «Bestillingen er annullert»; en betalt ordre avvises
#
#   tests/henting.sh

set -uo pipefail
cd "$(dirname "$0")/.."
ROT="$(pwd)"
PORT=${PORT_HENTING:-8136}
T=$(mktemp -d)
MERKE="hent-$$-$RANDOM"
command -v cygpath >/dev/null 2>&1 && { ROT="$(cygpath -m "$ROT")"; T="$(cygpath -m "$T")"; }

ok=0; feil=0
sjekk() { # sjekk "navn" "ventet" "fikk"
  if [ "$2" = "$3" ]; then ok=$((ok+1)); printf '  OK    %s\n' "$1"
  else feil=$((feil+1)); printf '  FEIL  %s — ventet «%s», fikk «%s»\n' "$1" "$2" "$3"; fi
}

opprydding() {
  [ -n "${PID_WEB:-}" ] && kill "$PID_WEB" 2>/dev/null
  php -r 'require "'"$ROT"'/app/bootstrap.php";
    $m = "'"$MERKE"'";
    DB::kjor("DELETE FROM notifications WHERE mottaker LIKE :m", ["m" => "%" . $m . "%"]);
    $o = array_column(DB::alle("SELECT id FROM orders WHERE kunde_epost LIKE :m", ["m" => "%" . $m . "%"]), "id");
    foreach ($o as $i) {
      DB::kjor("DELETE FROM order_lines WHERE order_id = :i", ["i" => $i]);
      DB::kjor("DELETE FROM orders WHERE id = :i", ["i" => $i]);
    }
    DB::kjor("DELETE FROM payments WHERE vipps_reference LIKE :m", ["m" => "%" . $m . "%"]);
    DB::kjor("DELETE FROM products WHERE tittel = :t", ["t" => $m]);
    foreach (array_column(DB::alle("SELECT id FROM members WHERE epost LIKE :m", ["m" => "%" . $m . "%"]), "id") as $a) {
      DB::kjor("DELETE FROM audit_log WHERE member_id = :a", ["a" => $a]);
      DB::kjor("DELETE FROM sessions WHERE member_id = :a", ["a" => $a]);
      DB::kjor("DELETE FROM members WHERE id = :a", ["a" => $a]);
    }
    if (is_file("'"$T"'/bryter")) {
      $v = trim(file_get_contents("'"$T"'/bryter"));
      if ($v === "") { DB::kjor("DELETE FROM content_blocks WHERE nokkel = \"Vis/oppmotebutikk\""); }
      else { DB::kjor("UPDATE content_blocks SET verdi = :v WHERE nokkel = \"Vis/oppmotebutikk\"", ["v" => $v]); }
    }' 2>/dev/null
  rm -rf "$T"
}
trap opprydding EXIT

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

# Bryteren «Betal ved oppmøte → Butikken» maa staa paa. Den gamle verdien
# legges tilbake til slutt.
VARE=$(php -r 'require "'"$ROT"'/app/bootstrap.php";
  $gml = DB::verdi("SELECT verdi FROM content_blocks WHERE nokkel = \"Vis/oppmotebutikk\"");
  file_put_contents("'"$T"'/bryter", $gml === null || $gml === false ? "" : (string) $gml);
  DB::kjor("INSERT INTO content_blocks (nokkel, verdi) VALUES (\"Vis/oppmotebutikk\", \"ja\") ON DUPLICATE KEY UPDATE verdi = \"ja\"");
  $felt = ["tittel" => "'"$MERKE"'", "pris_ore" => 16900, "lager" => 3, "status" => "publisert", "kun_medlemmer" => 0];
  if (DB::harKolonne("products", "uten_forskudd")) { $felt["uten_forskudd"] = 1; }
  echo DB::settInn("products", $felt);')

php -S "127.0.0.1:$PORT" -t "$T/public_html" >/dev/null 2>&1 &
PID_WEB=$!
for _ in $(seq 1 20); do
  curl -s -m 2 -o /dev/null "http://127.0.0.1:$PORT/api/kurs.php" && break
  sleep 1
done
B="http://127.0.0.1:$PORT/api"
ORIG="Origin: https://lissom.no"
EPOST="gjest-$MERKE@example.com"

felt() { php -r '$d = json_decode(stream_get_contents(STDIN), true) ?: []; $v = eval("return " . $argv[1] . ";"); echo is_bool($v) ? ($v ? "True" : "False") : $v;' "$1" 2>/dev/null; }

echo
echo "── Uten mobil avvises ──"
R=$(curl -s -m 20 -X POST -H "Content-Type: application/json" -H "$ORIG" \
  -d "{\"linjer\":[{\"id\":$VARE,\"antall\":1}],\"betaling\":\"henting\",\"levering\":\"hent\",\"navn\":\"TEST Gjest\",\"epost\":\"$EPOST\",\"telefon\":\"\"}" "$B/ordre.php")
sjekk "henting uten mobil avvises" "Vi trenger et mobilnummer." "$(echo "$R" | felt '$d["feil"] ?? ""')"
sjekk "… og ingen ordre er lagret" "0" "$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT COUNT(*) FROM orders WHERE kunde_epost = :e", ["e" => "'"$EPOST"'"]);')"

echo
echo "── Sending avvises (eieren 03.10: kun henting) ──"
R=$(curl -s -m 20 -X POST -H "Content-Type: application/json" -H "$ORIG" \
  -d "{\"linjer\":[{\"id\":$VARE,\"antall\":1}],\"betaling\":\"vipps\",\"levering\":\"pakke\",\"navn\":\"TEST Gjest\",\"epost\":\"$EPOST\",\"telefon\":\"40603093\",\"adresse\":\"Testveien 1\",\"postnr\":\"3120\",\"poststed\":\"Teie\"}" "$B/ordre.php")
sjekk "levering=pakke avvises med norsk melding" "Vi sender ikke varer. Bestillingen hentes i butikken på Teie." "$(echo "$R" | felt '$d["feil"] ?? ""')"
sjekk "… og ingen ordre er lagret" "0" "$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT COUNT(*) FROM orders WHERE kunde_epost = :e", ["e" => "'"$EPOST"'"]);')"

echo
echo "── Gjest bestiller med henting, uten innlogging ──"
R=$(curl -s -m 20 -X POST -H "Content-Type: application/json" -H "$ORIG" \
  -d "{\"linjer\":[{\"id\":$VARE,\"antall\":1}],\"betaling\":\"henting\",\"levering\":\"hent\",\"navn\":\"TEST Gjest\",\"epost\":\"$EPOST\",\"telefon\":\"40603093\"}" "$B/ordre.php")
sjekk "bestillingen gaar gjennom uten sesjon" "True" "$(echo "$R" | felt '!empty($d["ok"]) && !empty($d["ferdig"])')"
sjekk "… og sender ingen til Vipps" "" "$(echo "$R" | felt '$d["url"] ?? ""')"
O=$(php -r 'require "'"$ROT"'/app/bootstrap.php";
  $o = DB::en("SELECT * FROM orders WHERE kunde_epost = :e", ["e" => "'"$EPOST"'"]);
  echo $o ? implode("|", [$o["member_id"] === null ? "gjest" : "medlem", $o["kunde_navn"], $o["kunde_telefon"], $o["status"], $o["payment_id"] === null ? "ingen-betaling" : "betaling", (int) ($o["uten_forskudd"] ?? 0)]) : "mangler";')
sjekk "ordren staar paa gjesten, ubetalt, betales ved henting" "gjest|TEST Gjest|+4740603093|ny|ingen-betaling|1" "$O"
sjekk "varen er lagt til side (lager 3 → 2)" "2" "$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT lager FROM products WHERE id = :i", ["i" => '"$VARE"']);')"
sjekk "kunden faar «Butikkbestilling — hentes» i koen" "1" "$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT COUNT(*) FROM notifications WHERE mottaker = :e AND mal = \"butikkordre\"", ["e" => "'"$EPOST"'"]);')"

# ── Annuller i admin ──────────────────────────────────────────────────────
#
# Eieren, 28. september 2026: «Annuller» for ubetalte henteordrer — varen
# tilbake paa lager, kunden faar e-post. En betalt ordre refunderes, og
# avvises her.
TOKEN=$(php -r 'require "'"$ROT"'/app/bootstrap.php";
  $a = DB::settInn("members", ["navn" => "Hente Admin", "epost" => "admin-'"$MERKE"'@example.com", "rolle" => "admin"]);
  $t = bin2hex(random_bytes(32));
  DB::settInn("sessions", ["token_hash" => hash("sha256", $t), "member_id" => $a,
    "expires_at" => gmdate("Y-m-d H:i:s", time() + 3600)]);
  echo $t;')
admin() { # admin kropp
  curl -s -m 20 -X POST -H "Content-Type: application/json" -H "$ORIG" \
    -H "Cookie: lissom_sesjon=$TOKEN" -d "$1" "$B/admin/uttak.php"
}
OID=$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT id FROM orders WHERE kunde_epost = :e", ["e" => "'"$EPOST"'"]);')

echo
echo "── Henteordren staar under «Ikke betalt» i admin ──"
sjekk "ordren er i lista, merket som henteordre" "True" "$(curl -s -m 20 -H "Cookie: lissom_sesjon=$TOKEN" "$B/admin/oversikt.php" | felt 'count(array_filter($d["ubetalte"] ?? [], fn($r) => ($r["slag"] ?? "") === "ordre" && (int) ($r["id"] ?? 0) === '"$OID"' && !empty($r["henteordre"]))) === 1')"

echo
echo "── Annuller ──"
R=$(admin "{\"handling\":\"annuller\",\"ordreId\":$OID}")
sjekk "annulleringen gaar gjennom" "True" "$(echo "$R" | felt '!empty($d["ok"])')"
# «og kunden har fått beskjed» bare naar malen ordre_annullert er paa.
ANNULLERT_PAA=$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo (int) (DB::verdi("SELECT aktiv FROM notification_templates WHERE navn = \"ordre_annullert\"") ?? 0);')
if [ "$ANNULLERT_PAA" = "1" ]; then KVITT="er annullert. Varene er lagt tilbake på lager, og kunden har fått beskjed."; else KVITT="er annullert. Varene er lagt tilbake på lager."; fi
sjekk "… med kvittering (beskjed til kunden bare naar malen er paa)" "True" "$(echo "$R" | felt 'str_ends_with($d["beskjed"] ?? "", "'"$KVITT"'")')"
sjekk "ordren staar som kansellert" "kansellert" "$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT status FROM orders WHERE id = :i", ["i" => '"$OID"']);')"
sjekk "varen er tilbake paa lager (2 → 3)" "3" "$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT lager FROM products WHERE id = :i", ["i" => '"$VARE"']);')"
# Malen ordre_annullert ble slaatt av 3. oktober 2026 (eieren, migrasjon 251).
# Av: ingen e-post. Paa: én.
ANNULLERT_PAA=$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo (int) (DB::verdi("SELECT aktiv FROM notification_templates WHERE navn = \"ordre_annullert\"") ?? 0);')
sjekk "kunden faar «Bestillingen er annullert» i koen bare naar malen er paa" "$ANNULLERT_PAA" "$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT COUNT(*) FROM notifications WHERE mottaker = :e AND mal = \"ordre_annullert\"", ["e" => "'"$EPOST"'"]);')"
sjekk "annulleringen staar i revisjonsloggen" "1" "$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT COUNT(*) FROM audit_log WHERE handling = \"henteordre_annullert\" AND objekt_id = :i", ["i" => '"$OID"']);')"

R=$(admin "{\"handling\":\"annuller\",\"ordreId\":$OID}")
sjekk "et nytt trykk avvises" "False" "$(echo "$R" | felt '!empty($d["ok"])')"
sjekk "… og legger ikke varen tilbake en gang til" "3" "$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT lager FROM products WHERE id = :i", ["i" => '"$VARE"']);')"
sjekk "… og sender ingen ny e-post" "$ANNULLERT_PAA" "$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT COUNT(*) FROM notifications WHERE mottaker = :e AND mal = \"ordre_annullert\"", ["e" => "'"$EPOST"'"]);')"

echo
echo "── En betalt ordre kan ikke annulleres her ──"
BETALT=$(php -r 'require "'"$ROT"'/app/bootstrap.php";
  $p = DB::settInn("payments", ["vipps_reference" => "'"$MERKE"'-p", "formal" => "ordre", "belop_ore" => 16900,
    "status" => "betalt", "idempotency_key" => substr(md5("'"$MERKE"'"), 0, 36)]);
  $f = ["ordrenr" => "T-'"$MERKE"'", "kunde_navn" => "TEST Betalt", "kunde_epost" => "betalt-'"$MERKE"'@example.com",
    "sum_ore" => 16900, "status" => "betalt", "payment_id" => $p];
  if (DB::harKolonne("orders", "uten_forskudd")) { $f["uten_forskudd"] = 1; }
  echo DB::settInn("orders", $f);')
R=$(admin "{\"handling\":\"annuller\",\"ordreId\":$BETALT}")
sjekk "betalt ordre avvises" "Dette salget er gjort opp i Vipps. Det må refunderes der." "$(echo "$R" | felt '$d["feil"] ?? ""')"
sjekk "… og staar fortsatt som betalt" "betalt" "$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT status FROM orders WHERE id = :i", ["i" => '"$BETALT"']);')"

echo
echo "── $ok gikk gjennom, $feil feilet"
[ "$feil" -eq 0 ]
