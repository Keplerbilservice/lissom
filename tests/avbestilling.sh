#!/usr/bin/env bash
#
# Avbestilling fra admin gir e-post til kunden.
#
# Eieren, 27. september 2026: «en avbestilling fra admin, må sende epost til
# kunden». Testet paa lissom.no samme dag: «Avbestill» under Paameldte
# frigjorde plassen, men varselkoen sto stille.
#
# Her gaar kallet gjennom det ekte endepunktet (api/admin/pamelding.php,
# handling=fjern) for en ubetalt og en betalt-i-verkstedet paamelding. Begge
# skal gi avbestillingsmalen i koen — uten refusjonsrad, for her refunderes
# ingenting. En Vipps-betalt avvises som foer, uten e-post. Og er malen slaatt
# av i Tekst maler, sendes ingenting.
#
#   tests/avbestilling.sh
#
# Krever app/secrets.php mot en database migrasjonene er kjort mot.

set -uo pipefail
cd "$(dirname "$0")/.."
ROT="$(pwd)"
PORT=${PORT_AVBESTILLING:-8133}
T=$(mktemp -d)
MERKE="avb-$$-$RANDOM"
# Paa Windows (Git Bash) maa stiene inne i php -r vaere Windows-stier.
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
    $k = array_column(DB::alle("SELECT id FROM courses WHERE slug = :s", ["s" => $m]), "id");
    foreach ($k as $c) {
      DB::kjor("UPDATE bookings SET payment_id = NULL WHERE course_id = :c", ["c" => $c]);
      DB::kjor("DELETE FROM payments WHERE vipps_reference LIKE :m", ["m" => $m . "%"]);
      DB::kjor("DELETE FROM bookings WHERE course_id = :c", ["c" => $c]);
      DB::kjor("DELETE FROM course_sessions WHERE course_id = :c", ["c" => $c]);
      DB::kjor("DELETE FROM courses WHERE id = :c", ["c" => $c]);
    }
    $a = array_column(DB::alle("SELECT id FROM members WHERE epost LIKE :e", ["e" => $m . "%"]), "id");
    foreach ($a as $i) {
      DB::kjor("DELETE FROM sessions WHERE member_id = :i", ["i" => $i]);
      DB::kjor("DELETE FROM audit_log WHERE member_id = :i", ["i" => $i]);
      DB::kjor("DELETE FROM members WHERE id = :i", ["i" => $i]);
    }
    if (is_file("'"$T"'/aktiv")) {
      DB::kjor("UPDATE notification_templates SET aktiv = :a WHERE navn = \"avbestilling\"", ["a" => (int) file_get_contents("'"$T"'/aktiv")]);
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
B="http://127.0.0.1:$PORT/api"
ORIG="Origin: https://lissom.no"

# --- Testdata: admin, kurs, tre paameldinger ------------------------------
read -r TOKEN UBETALT BETALT VIPPS AV < <(php -r '
require "'"$ROT"'/app/bootstrap.php";
$m = "'"$MERKE"'";
file_put_contents("'"$T"'/aktiv", (string) (int) DB::verdi("SELECT aktiv FROM notification_templates WHERE navn = \"avbestilling\""));
DB::kjor("UPDATE notification_templates SET aktiv = 1 WHERE navn = \"avbestilling\"");
$admin = DB::settInn("members", ["navn" => "Avb Admin", "epost" => $m . "-admin@example.com", "rolle" => "admin"]);
$t = bin2hex(random_bytes(32));
DB::settInn("sessions", ["token_hash" => hash("sha256", $t), "member_id" => $admin,
  "expires_at" => gmdate("Y-m-d H:i:s", time() + 3600)]);
$k = DB::settInn("courses", ["slug" => $m, "tittel" => "Avbestillingstest", "type" => "kurs",
  "pris_ore" => 100000, "kapasitet" => 8, "status" => "publisert"]);
$o = DB::settInn("course_sessions", ["course_id" => $k, "start_tid" => gmdate("Y-m-d H:i:s", time() + 9 * 86400)]);
$ny = static function (string $hvem, string $status, ?int $betaling = null) use ($k, $o, $m): int {
  return DB::settInn("bookings", ["course_id" => $k, "course_session_id" => $o, "gjest_navn" => "Ola Test",
    "gjest_epost" => $m . "-" . $hvem . "@example.com", "antall" => 1, "belop_ore" => 100000,
    "status" => $status, "payment_id" => $betaling, "betalt_maate" => $status === "betalt" ? "Kontant" : "Betaler ved oppmøte"]);
};
$p = DB::settInn("payments", ["vipps_reference" => $m . "-ref", "belop_ore" => 100000, "status" => "betalt", "formal" => "booking", "idempotency_key" => substr(bin2hex(random_bytes(18)), 0, 36)]);
echo implode(" ", [$t, $ny("ubetalt", "reservert"), $ny("betalt", "betalt"), $ny("vipps", "betalt", $p), $ny("av", "reservert")]);
')

fjern() {
  curl -s -m 15 -X POST -H "Content-Type: application/json" -H "$ORIG" \
    -H "Cookie: lissom_sesjon=$TOKEN" -d "{\"handling\":\"fjern\",\"id\":$1}" "$B/admin/pamelding.php"
}
ko() { # ko <hvem> <felt>
  php -r 'require "'"$ROT"'/app/bootstrap.php";
    $r = DB::en("SELECT * FROM notifications WHERE mottaker = :m AND kanal = \"epost\" ORDER BY id DESC LIMIT 1",
      ["m" => "'"$MERKE"'-'"$1"'@example.com"]);
    if ($r === null) { echo "ingen"; exit; }
    $h = (string) ($r["html"] ?? "");
    echo match ("'"$2"'") {
      "mal"      => (string) $r["mal"],
      "refusjon" => (str_contains($h, "Refusjon") || str_contains($h, "Vipps")) ? "ja" : "nei",
      "kurs"     => str_contains($h, "Avbestillingstest") ? "ja" : "nei",
    };'
}

echo
echo "== Avbestilling fra admin =="
sjekk "ubetalt påmelding avbestilles" "true" "$(fjern "$UBETALT" | php -r 'echo json_encode((json_decode(stream_get_contents(STDIN), true) ?: [])["ok"] ?? false);')"
sjekk "… og kunden får avbestillingsmalen" "avbestilling" "$(ko ubetalt mal)"
sjekk "… med kurset" "ja" "$(ko ubetalt kurs)"
sjekk "… uten refusjonsrad" "nei" "$(ko ubetalt refusjon)"

sjekk "betalt i verkstedet avbestilles" "true" "$(fjern "$BETALT" | php -r 'echo json_encode((json_decode(stream_get_contents(STDIN), true) ?: [])["ok"] ?? false);')"
sjekk "… og kunden får avbestillingsmalen" "avbestilling" "$(ko betalt mal)"
sjekk "… uten løfte om refusjon (det skjer ingen)" "nei" "$(ko betalt refusjon)"

sjekk "Vipps-betalt avvises som før" "false" "$(fjern "$VIPPS" | php -r 'echo json_encode((json_decode(stream_get_contents(STDIN), true) ?: [])["ok"] ?? false);')"
sjekk "… og ingen e-post" "ingen" "$(ko vipps mal)"

php -r 'require "'"$ROT"'/app/bootstrap.php"; DB::kjor("UPDATE notification_templates SET aktiv = 0 WHERE navn = \"avbestilling\"");'
sjekk "malen slått av: avbestillingen går likevel" "true" "$(fjern "$AV" | php -r 'echo json_encode((json_decode(stream_get_contents(STDIN), true) ?: [])["ok"] ?? false);')"
sjekk "… men ingen e-post" "ingen" "$(ko av mal)"

echo
echo "── $ok gikk gjennom, $feil feilet"
[ "$feil" -eq 0 ]
