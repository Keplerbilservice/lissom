#!/usr/bin/env bash
#
# Nye medlemskap i «Nye påmeldinger» og «Dagens bestillinger» på Oversikt.
#
# Eieren, 2. oktober 2026: «det meldte seg på et nytt medlem, men det vises
# ikke under nye påmeldinger». Lista tok bare kursplasser.
#
#   tests/nye-pameldinger-medlem.sh
#
# Krever app/secrets.php mot en database migrasjonene er kjort mot.
# Sender ingenting: ingen SMS, ingen e-post, ingen Vipps.

set -uo pipefail
cd "$(dirname "$0")/.."
ROT="$(pwd -W 2>/dev/null || pwd)"
PORT=${PORT_NYEMEDLEM:-8167}
T=$(mktemp -d); T=$(cd "$T" && (pwd -W 2>/dev/null || pwd))

ok=0; feil=0
sjekk() { # sjekk "navn" "ventet" "fikk"
  if [ "$2" = "$3" ]; then ok=$((ok+1)); printf '  OK    %s\n' "$1"
  else feil=$((feil+1)); printf '  FEIL  %s — ventet «%s», fikk «%s»\n' "$1" "$2" "$3"; fi
}

opprydding() {
  [ -n "${PID_WEB:-}" ] && kill "$PID_WEB" 2>/dev/null
  php -r 'require "'"$ROT"'/app/bootstrap.php";
    $k = array_column(DB::alle("SELECT id FROM courses WHERE slug = \"nyemedlem-test\""), "id");
    foreach ($k as $c) {
      DB::kjor("UPDATE bookings SET payment_id = NULL WHERE course_id = :c", ["c" => $c]);
      DB::kjor("DELETE FROM bookings WHERE course_id = :c", ["c" => $c]);
      DB::kjor("DELETE FROM course_sessions WHERE course_id = :c", ["c" => $c]);
      DB::kjor("DELETE FROM courses WHERE id = :c", ["c" => $c]);
    }
    $m = array_column(DB::alle("SELECT id FROM members WHERE epost LIKE \"nyemedlem-%@lissom.test\""), "id");
    foreach ($m as $i) {
      DB::kjor("UPDATE payments SET subscription_id = NULL WHERE member_id = :m", ["m" => $i]);
      DB::kjor("DELETE FROM payments WHERE member_id = :m", ["m" => $i]);
      DB::kjor("DELETE FROM subscriptions WHERE member_id = :m", ["m" => $i]);
      DB::kjor("DELETE FROM sessions WHERE member_id = :m", ["m" => $i]);
      DB::kjor("DELETE FROM audit_log WHERE member_id = :m OR (objekt_type = \"member\" AND objekt_id = :m2)", ["m" => $i, "m2" => $i]);
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

# --- Testdata ---------------------------------------------------------------
#   Nytt Betalt   — medlemskap kjøpt nå, avtalen er aktiv           → med
#   Ny Prove      — lagt inn i admin som Prøv Lissom (revisjonen)   → med
#   Avbrutt Vipps — avtalen venter, betalingen er avbrutt           → ikke med
#   Glemt Vipps   — avtalen har ventet i en time uten svar          → ikke med
#   Gammelt Medl  — aktivt medlemskap kjøpt for fire dager siden    → ikke med
#   Kurs Gjest    — en betalt kursplass nå                          → med, som før
read -r TOKEN < <(php -r '
require "'"$ROT"'/app/bootstrap.php";
$r = static fn(): string => bin2hex(random_bytes(3));
$uuid = static fn(): string => sprintf("%08x-0000-4000-8000-%012x", random_int(0, 0xffffffff), random_int(0, 0xffffffffffff));
$admin = DB::settInn("members", ["navn" => "Nyemedlem Admin", "epost" => "nyemedlem-admin-" . $r() . "@lissom.test", "rolle" => "admin"]);
$t = bin2hex(random_bytes(32));
DB::settInn("sessions", ["token_hash" => hash("sha256", $t), "member_id" => $admin, "expires_at" => gmdate("Y-m-d H:i:s", time() + 3600)]);

$plan = (string) DB::verdi("SELECT navn FROM membership_plans WHERE engangs = 0 ORDER BY sortering, navn LIMIT 1");
$prove = (string) DB::verdi("SELECT navn FROM membership_plans WHERE engangs = 1 ORDER BY sortering, navn LIMIT 1");
$naa = gmdate("Y-m-d H:i:s", time() - 60);
$medlem = static fn(string $navn, string $status, ?string $type) => DB::settInn("members", [
    "navn" => $navn, "epost" => "nyemedlem-" . bin2hex(random_bytes(3)) . "@lissom.test", "rolle" => "medlem",
    "status" => $status, "medlemskap_type" => $type, "start_dato" => gmdate("Y-m-d")]);

// Nytt Betalt: engangsbetaling gjort opp, avtalen aktiv.
$a = $medlem("Nytt Betalt", "aktiv", $plan);
$s = DB::settInn("subscriptions", ["member_id" => $a, "plan" => $plan, "pris_ore" => 259000, "status" => "aktiv", "created_at" => $naa]);
DB::settInn("payments", ["type" => "epayment", "formal" => "medlemskap", "member_id" => $a, "subscription_id" => $s,
    "vipps_reference" => "NYEMEDLEM-" . $r(), "idempotency_key" => $uuid(), "belop_ore" => 259000, "status" => "betalt", "created_at" => $naa]);

// Ny Prove: lagt inn i admin. Ingen avtale — bare revisjonen.
$p = $medlem("Ny Prove", "prove", $prove);
DB::settInn("audit_log", ["member_id" => $admin, "handling" => "medlem_meldt_inn", "objekt_type" => "member", "objekt_id" => $p,
    "detaljer" => json_encode(["type" => $prove, "nytt" => true]), "created_at" => $naa]);

// Avbrutt Vipps: trykket Avbryt i Vipps.
$v = $medlem("Avbrutt Vipps", "ingen", null);
$sv = DB::settInn("subscriptions", ["member_id" => $v, "plan" => $plan, "pris_ore" => 259000, "status" => "venter", "created_at" => $naa]);
DB::settInn("payments", ["type" => "epayment", "formal" => "medlemskap", "member_id" => $v, "subscription_id" => $sv,
    "vipps_reference" => "NYEMEDLEM-" . $r(), "idempotency_key" => $uuid(), "belop_ore" => 259000, "status" => "avbrutt", "created_at" => $naa]);

// Glemt Vipps: avtalen ble aldri godkjent, og det er en time siden.
$g = $medlem("Glemt Vipps", "ingen", null);
DB::settInn("subscriptions", ["member_id" => $g, "plan" => $plan, "pris_ore" => 259000, "status" => "venter",
    "vipps_agreement_id" => "agr-nyemedlem-" . $r(), "created_at" => gmdate("Y-m-d H:i:s", time() - 3600)]);

// Gammelt Medl: kjøpt for fire dager siden.
$o = $medlem("Gammelt Medl", "aktiv", $plan);
DB::settInn("subscriptions", ["member_id" => $o, "plan" => $plan, "pris_ore" => 259000, "status" => "aktiv",
    "created_at" => gmdate("Y-m-d H:i:s", time() - 4 * 86400)]);

// Kurs Gjest: en vanlig kursplass, som før.
$kurs = DB::settInn("courses", ["slug" => "nyemedlem-test", "tittel" => "Nyemedlem testkurs", "type" => "kurs", "pris_ore" => 100000, "kapasitet" => 8, "status" => "publisert"]);
$okt = DB::settInn("course_sessions", ["course_id" => $kurs, "start_tid" => gmdate("Y-m-d H:i:s", time() + 86400 * 5)]);
DB::settInn("bookings", ["course_id" => $kurs, "course_session_id" => $okt, "gjest_navn" => "Kurs Gjest", "gjest_epost" => "nyemedlem-k@lissom.test",
    "antall" => 1, "belop_ore" => 100000, "status" => "betalt", "created_at" => gmdate("Y-m-d H:i:s", time() - 120)]);

// Flere Avtaler: tre avtaler i dag på samme medlem. Én rad, og det er den
// første — de to neste er planbytter (eieren 02.10: bare helt nye).
$f = $medlem("Flere Avtaler", "prove", $prove);
foreach ([[300, $plan], [200, $plan], [90, $prove]] as [$sek, $pl]) {
    DB::settInn("subscriptions", ["member_id" => $f, "plan" => $pl, "pris_ore" => 99000, "status" => "aktiv",
        "created_at" => gmdate("Y-m-d H:i:s", time() - $sek)]);
}

// Planbytte: medlem siden forrige måned (avtalen stoppet), ny plan i dag.
$b = $medlem("Planbytte Medl", "aktiv", $prove);
$sb = DB::settInn("subscriptions", ["member_id" => $b, "plan" => $plan, "pris_ore" => 259000, "status" => "stoppet",
    "siste_trekk" => gmdate("Y-m-d", time() - 25 * 86400), "created_at" => gmdate("Y-m-d H:i:s", time() - 30 * 86400)]);
DB::settInn("payments", ["type" => "recurring_charge", "formal" => "medlemskap", "member_id" => $b, "subscription_id" => $sb,
    "vipps_reference" => "NYEMEDLEM-" . $r(), "idempotency_key" => $uuid(), "belop_ore" => 259000, "status" => "betalt",
    "created_at" => gmdate("Y-m-d H:i:s", time() - 25 * 86400)]);

// Avbrutt Trekk: ny kunde starter fast trekk, avbryter (avlysForsok setter
// «stoppet»), og betaler så engangs. Hun er ny (kontrolløren 02.10).
$at = $medlem("Avbrutt Trekk", "aktiv", $plan);
DB::settInn("subscriptions", ["member_id" => $at, "plan" => $plan, "pris_ore" => 259000, "status" => "stoppet",
    "vipps_agreement_id" => "agr-nyemedlem-" . $r(), "created_at" => gmdate("Y-m-d H:i:s", time() - 300)]);
$se = DB::settInn("subscriptions", ["member_id" => $at, "plan" => $plan, "pris_ore" => 259000, "status" => "aktiv", "created_at" => $naa]);
DB::settInn("payments", ["type" => "epayment", "formal" => "medlemskap", "member_id" => $at, "subscription_id" => $se,
    "vipps_reference" => "NYEMEDLEM-" . $r(), "idempotency_key" => $uuid(), "belop_ore" => 259000, "status" => "betalt", "created_at" => $naa]);
DB::settInn("subscriptions", ["member_id" => $b, "plan" => $prove, "pris_ore" => 99000, "status" => "aktiv", "created_at" => $naa]);

// Sendt Avtale: lagt inn i admin for ti dager siden, avtalen sendt og godkjent i dag.
$sa = $medlem("Sendt Avtale", "aktiv", $plan);
DB::settInn("audit_log", ["member_id" => $admin, "handling" => "medlem_meldt_inn", "objekt_type" => "member", "objekt_id" => $sa,
    "created_at" => gmdate("Y-m-d H:i:s", time() - 10 * 86400)]);
DB::settInn("subscriptions", ["member_id" => $sa, "plan" => $plan, "pris_ore" => 259000, "status" => "aktiv", "created_at" => $naa]);

// Admin Igjen: lagt inn i admin for ti dager siden, og på nytt i dag (byttet plan).
$ai = $medlem("Admin Igjen", "aktiv", $plan);
foreach ([10 * 86400, 60] as $sek) {
    DB::settInn("audit_log", ["member_id" => $admin, "handling" => "medlem_meldt_inn", "objekt_type" => "member", "objekt_id" => $ai,
        "created_at" => gmdate("Y-m-d H:i:s", time() - $sek)]);
}

// Mengde 01–55: flere enn 50 nye medlemskap i dag. Ingen skal falle ut av
// «Dagens bestillinger» (kontrolløren 02.10: LIMIT 50 kom før dedup).
for ($i = 1; $i <= 55; $i++) {
    $x = $medlem(sprintf("Mengde %02d", $i), "aktiv", $plan);
    DB::settInn("subscriptions", ["member_id" => $x, "plan" => $plan, "pris_ore" => 259000, "status" => "aktiv",
        "created_at" => gmdate("Y-m-d H:i:s", time() - 400 - $i)]);
}

echo $t;')
# Plannavnene kan ha mellomrom («30 timer», «Prøv Lissom»).
PLAN=$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT navn FROM membership_plans WHERE engangs = 0 ORDER BY sortering, navn LIMIT 1");')
PROVE=$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT navn FROM membership_plans WHERE engangs = 1 ORDER BY sortering, navn LIMIT 1");')
ID_A=$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT id FROM members WHERE navn = \"Nytt Betalt\" AND epost LIKE \"nyemedlem-%@lissom.test\" ORDER BY id DESC LIMIT 1");')
ID_P=$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT id FROM members WHERE navn = \"Ny Prove\" AND epost LIKE \"nyemedlem-%@lissom.test\" ORDER BY id DESC LIMIT 1");')

O=$(curl -s -m 30 -H "Origin: https://lissom.no" -H "Cookie: lissom_sesjon=$TOKEN" "http://127.0.0.1:$PORT/api/admin/oversikt.php")
felt() { php -r '$d = json_decode(stream_get_contents(STDIN), true) ?: []; $v = eval("return " . $argv[1] . ";"); echo is_bool($v) ? ($v ? "ja" : "nei") : $v;' "$1" 2>/dev/null; }
rad() { # rad "liste" "navn" "felt"
  echo "$O" | felt 'implode(",", array_map(fn($r) => is_bool($r["'"$3"'"] ?? null) ? ($r["'"$3"'"] ? "ja" : "nei") : (string) ($r["'"$3"'"] ?? ""), array_values(array_filter($d["'"$1"'"] ?? [], fn($r) => ($r["navn"] ?? "") === "'"$2"'"))))'
}

echo
echo "── Nye påmeldinger ──"
sjekk "lista finnes" "ja" "$(echo "$O" | felt 'is_array($d["nyeste"] ?? null)')"
sjekk "nytt betalt medlemskap står med" "medlemskap" "$(rad nyeste 'Nytt Betalt' slag)"
sjekk "… med plannavnet der kursnavnet står" "$PLAN" "$(rad nyeste 'Nytt Betalt' hva)"
sjekk "… og pilla sier betalt" "Betalt" "$(rad nyeste 'Nytt Betalt' status)"
sjekk "… og raden åpner medlemmet" "$ID_A" "$(rad nyeste 'Nytt Betalt' medlemId)"
sjekk "… men ikke en påmelding" "0" "$(rad nyeste 'Nytt Betalt' id)"
sjekk "Prøv Lissom lagt inn i admin står med" "$PROVE" "$(rad nyeste 'Ny Prove' hva)"
sjekk "… og åpner medlemmet" "$ID_P" "$(rad nyeste 'Ny Prove' medlemId)"
sjekk "avbrutt i Vipps står ikke med" "" "$(rad nyeste 'Avbrutt Vipps' slag)"
sjekk "avtale som aldri ble godkjent står ikke med" "" "$(rad nyeste 'Glemt Vipps' slag)"
sjekk "kjøpt for fire dager siden står ikke med" "" "$(rad nyeste 'Gammelt Medl' slag)"
sjekk "kursplassen står som før" "kurs" "$(rad nyeste 'Kurs Gjest' slag)"
sjekk "… med kursnavnet" "Nyemedlem testkurs" "$(rad nyeste 'Kurs Gjest' hva)"
sjekk "nyeste først (medlemskapet kom etter kursplassen)" "ja" "$(echo "$O" | felt '(function($l){ $a = array_search("Nytt Betalt", array_column($l, "navn")); $k = array_search("Kurs Gjest", array_column($l, "navn")); return $a !== false && $k !== false && $a < $k; })($d["nyeste"])')"
# Telleren paa kortet er lengden paa lista (gamle admin, nyadmin og admin-ny).
sjekk "telleren tar dem med (3 av testradene)" "3" "$(echo "$O" | felt 'count(array_filter($d["nyeste"], fn($r) => in_array($r["navn"], ["Nytt Betalt", "Ny Prove", "Kurs Gjest"], true)))')"

echo
echo "── Dagens bestillinger ──"
sjekk "nytt medlemskap står med" "Medlemskap · $PLAN" "$(rad dagensBestillinger 'Nytt Betalt' hva)"
sjekk "… med medlemmet på raden" "$ID_A" "$(rad dagensBestillinger 'Nytt Betalt' medlemId)"
sjekk "Prøv Lissom lagt inn i admin står med" "Medlemskap · $PROVE" "$(rad dagensBestillinger 'Ny Prove' hva)"
sjekk "avbrutt i Vipps står ikke med" "" "$(rad dagensBestillinger 'Avbrutt Vipps' hva)"
sjekk "flere enn 50 nye i dag: alle 55 står med" "55" "$(echo "$O" | felt 'count(array_filter($d["dagensBestillinger"], fn($r) => str_starts_with($r["navn"] ?? "", "Mengde ")))')"
sjekk "tre avtaler på samme medlem gir én rad, den første" "Medlemskap · $PLAN" "$(rad dagensBestillinger 'Flere Avtaler' hva)"
sjekk "… og én rad i Nye påmeldinger" "$PLAN" "$(rad nyeste 'Flere Avtaler' hva)"

echo
echo "── Bare helt nye medlemmer (eieren 02.10) ──"
sjekk "planbytte står ikke i Nye påmeldinger" "" "$(rad nyeste 'Planbytte Medl' hva)"
sjekk "… og ikke i Dagens bestillinger" "" "$(rad dagensBestillinger 'Planbytte Medl' hva)"
sjekk "avtale sendt til et medlem lagt inn før står ikke" "" "$(rad nyeste 'Sendt Avtale' hva)"
sjekk "… og ikke i Dagens bestillinger" "" "$(rad dagensBestillinger 'Sendt Avtale' hva)"
sjekk "lagt inn i admin på nytt står ikke" "" "$(rad nyeste 'Admin Igjen' hva)"
sjekk "avbrutt fast trekk, så engangs: står som ny" "$PLAN" "$(rad nyeste 'Avbrutt Trekk' hva)"
sjekk "… og i Dagens bestillinger" "Medlemskap · $PLAN" "$(rad dagensBestillinger 'Avbrutt Trekk' hva)"
sjekk "tredagerslista er fortsatt begrenset (høyst 12 medlemskap)" "ja" "$(echo "$O" | felt 'count(array_filter($d["nyeste"], fn($r) => ($r["slag"] ?? "") === "medlemskap")) <= 12')"

echo
echo "── $ok gikk gjennom, $feil feilet"
[ "$feil" -eq 0 ]
