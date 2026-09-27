#!/usr/bin/env bash
#
# Dagens omsetning og dagens bestillinger på Oversikt.
#
# Eieren, 27. september 2026: «jeg vil at du alltid har dagens omsetning
# øverst, klikkbar, så jeg kan gå inn å se på den, deretter dagens bestilling
# selv om den ikke er betalt».
#
#   tests/dagens.sh
#
# Krever app/secrets.php mot en database migrasjonene er kjort mot.

set -uo pipefail
cd "$(dirname "$0")/.."
ROT="$(pwd -W 2>/dev/null || pwd)"
PORT=${PORT_DAGENS:-8161}
T=$(mktemp -d); T=$(cd "$T" && (pwd -W 2>/dev/null || pwd))

ok=0; feil=0
sjekk() { # sjekk "navn" "ventet" "fikk"
  if [ "$2" = "$3" ]; then ok=$((ok+1)); printf '  OK    %s\n' "$1"
  else feil=$((feil+1)); printf '  FEIL  %s — ventet «%s», fikk «%s»\n' "$1" "$2" "$3"; fi
}

opprydding() {
  [ -n "${PID_WEB:-}" ] && kill "$PID_WEB" 2>/dev/null
  php -r 'require "'"$ROT"'/app/bootstrap.php";
    $k = array_column(DB::alle("SELECT id FROM courses WHERE slug = \"dagens-test\""), "id");
    foreach ($k as $c) {
      DB::kjor("UPDATE bookings SET payment_id = NULL WHERE course_id = :c", ["c" => $c]);
      DB::kjor("DELETE FROM bookings WHERE course_id = :c", ["c" => $c]);
      DB::kjor("DELETE FROM course_sessions WHERE course_id = :c", ["c" => $c]);
      DB::kjor("DELETE FROM courses WHERE id = :c", ["c" => $c]);
    }
    DB::kjor("DELETE FROM payments WHERE vipps_reference LIKE \"DAGENS-%\"");
    $m = array_column(DB::alle("SELECT id FROM members WHERE epost LIKE \"dagens-%@lissom.test\""), "id");
    foreach ($m as $i) {
      DB::kjor("DELETE FROM sessions WHERE member_id = :m", ["m" => $i]);
      DB::kjor("DELETE FROM audit_log WHERE member_id = :m", ["m" => $i]);
      DB::kjor("DELETE FROM members WHERE id = :m", ["m" => $i]);
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

php -S "127.0.0.1:$PORT" -t "$T/public_html" >/dev/null 2>&1 &
PID_WEB=$!
for _ in $(seq 1 20); do
  curl -s -m 2 -o /dev/null "http://127.0.0.1:$PORT/api/kurs.php" && break
  sleep 1
done

# --- Testdata: admin, et kurs, to påmeldinger i dag (én betalt, én ubetalt),
#     én i går, og én som ble avbrutt i Vipps i dag.
read -r TOKEN < <(php -r '
require "'"$ROT"'/app/bootstrap.php";
$admin = DB::settInn("members", ["navn" => "Dagens Admin", "epost" => "dagens-admin-" . bin2hex(random_bytes(3)) . "@lissom.test", "rolle" => "admin"]);
$t = bin2hex(random_bytes(32));
DB::settInn("sessions", ["token_hash" => hash("sha256", $t), "member_id" => $admin, "expires_at" => gmdate("Y-m-d H:i:s", time() + 3600)]);
$kurs = DB::settInn("courses", ["slug" => "dagens-test", "tittel" => "Dagens testkurs", "type" => "kurs", "pris_ore" => 100000, "kapasitet" => 8, "status" => "publisert"]);
$okt = DB::settInn("course_sessions", ["course_id" => $kurs, "start_tid" => gmdate("Y-m-d H:i:s", time() + 86400 * 5)]);
$naa = gmdate("Y-m-d H:i:s", time() - 60);
$igaar = gmdate("Y-m-d H:i:s", time() - 86400 * 2);
$p = DB::settInn("payments", ["type" => "epayment", "formal" => "booking", "vipps_reference" => "DAGENS-BETALT", "idempotency_key" => sprintf("%08x-0000-4000-8000-%012x", random_int(0, 0xffffffff), random_int(0, 0xffffffffffff)), "belop_ore" => 100000, "status" => "betalt", "created_at" => $naa]);
DB::settInn("bookings", ["course_id" => $kurs, "course_session_id" => $okt, "gjest_navn" => "Betalt Idag", "gjest_epost" => "dagens-b@lissom.test", "antall" => 1, "belop_ore" => 100000, "status" => "betalt", "payment_id" => $p, "created_at" => $naa]);
DB::settInn("bookings", ["course_id" => $kurs, "course_session_id" => $okt, "gjest_navn" => "Ubetalt Idag", "gjest_epost" => "dagens-u@lissom.test", "antall" => 2, "belop_ore" => 200000, "status" => "reservert", "created_at" => $naa]);
DB::settInn("bookings", ["course_id" => $kurs, "course_session_id" => $okt, "gjest_navn" => "Betalt Igaar", "gjest_epost" => "dagens-g@lissom.test", "antall" => 1, "belop_ore" => 100000, "status" => "betalt", "created_at" => $igaar]);
$pa = DB::settInn("payments", ["type" => "epayment", "formal" => "booking", "vipps_reference" => "DAGENS-AVBRUTT", "idempotency_key" => sprintf("%08x-0000-4000-8000-%012x", random_int(0, 0xffffffff), random_int(0, 0xffffffffffff)), "belop_ore" => 100000, "status" => "avbrutt", "created_at" => $naa]);
DB::settInn("bookings", ["course_id" => $kurs, "course_session_id" => $okt, "gjest_navn" => "Avbrutt Idag", "gjest_epost" => "dagens-a@lissom.test", "antall" => 1, "belop_ore" => 100000, "status" => "reservert", "payment_id" => $pa, "reservert_til" => gmdate("Y-m-d H:i:s", time() + 600), "created_at" => $naa]);
echo $t;')

O=$(curl -s -m 30 -H "Origin: https://lissom.no" -H "Cookie: lissom_sesjon=$TOKEN" "http://127.0.0.1:$PORT/api/admin/oversikt.php")
felt() { php -r '$d = json_decode(stream_get_contents(STDIN), true) ?: []; $v = eval("return " . $argv[1] . ";"); echo is_bool($v) ? ($v ? "ja" : "nei") : $v;' "$1" 2>/dev/null; }

echo
echo "── Dagens bestillinger ──"
sjekk "lista finnes" "ja" "$(echo "$O" | felt 'is_array($d["dagensBestillinger"] ?? null)')"
sjekk "betalt påmelding i dag står med" "Betalt" "$(echo "$O" | felt 'implode(",", array_column(array_values(array_filter($d["dagensBestillinger"], fn($r) => $r["navn"] === "Betalt Idag")), "status"))')"
sjekk "… og ubetalt står også med" "Ikke betalt" "$(echo "$O" | felt 'implode(",", array_column(array_values(array_filter($d["dagensBestillinger"], fn($r) => $r["navn"] === "Ubetalt Idag")), "status"))')"
sjekk "… med antall plasser" "Dagens testkurs · 2 plasser" "$(echo "$O" | felt 'implode(",", array_column(array_values(array_filter($d["dagensBestillinger"], fn($r) => $r["navn"] === "Ubetalt Idag")), "hva"))')"
sjekk "i går står ikke med" "0" "$(echo "$O" | felt 'count(array_filter($d["dagensBestillinger"], fn($r) => $r["navn"] === "Betalt Igaar"))')"
sjekk "avbrutt i Vipps står ikke med" "0" "$(echo "$O" | felt 'count(array_filter($d["dagensBestillinger"], fn($r) => $r["navn"] === "Avbrutt Idag"))')"
sjekk "nyeste først" "ja" "$(echo "$O" | felt '(function($l){ for($i=1;$i<count($l);$i++) if(strcmp($l[$i-1]["naar"],$l[$i]["naar"])<0) return false; return true; })($d["dagensBestillinger"])')"
sjekk "raden kan åpne Påmeldte på riktig dato" "ja" "$(echo "$O" | felt 'count(array_filter($d["dagensBestillinger"], fn($r) => $r["navn"] === "Ubetalt Idag" && $r["slag"] === "kurs" && (int) $r["oktId"] > 0)) === 1')"

echo
echo "── Dagens omsetning er det samme som dagsoppgjøret ──"
D=$(curl -s -m 30 -H "Origin: https://lissom.no" -H "Cookie: lissom_sesjon=$TOKEN" "http://127.0.0.1:$PORT/api/admin/dagsoppgjor.php")
IDAG=$(php -r 'echo (new DateTimeImmutable("now", new DateTimeZone("Europe/Oslo")))->format("Y-m-d");')
OMS=$(echo "$O" | felt '(int) ($d["omsetning"]["idagOre"] ?? -1)')
DAG=$(echo "$D" | php -r '$d = json_decode(stream_get_contents(STDIN), true) ?: []; $s = 0; foreach (($d["bilag"] ?? []) as $b) if ($b["dato"] === $argv[1]) $s += (int) $b["sumOre"]; echo $s;' "$IDAG")
sjekk "omsetningen i dag = dagsoppgjøret i dag" "$DAG" "$OMS"
sjekk "… og den betalte påmeldingen er med" "ja" "$( [ "${OMS:-0}" -ge 100000 ] && echo ja || echo nei)"

echo
echo "── $ok gikk gjennom, $feil feilet"
[ "$feil" -eq 0 ]
