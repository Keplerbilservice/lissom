#!/usr/bin/env bash
#
# Godkjenning til galleriet, gjennom admin-endepunktet.
#
# Eieren, 27. september 2026: et medlemsforslag godkjennes til Instagram,
# til galleriet paa forsida, eller begge. Bare galleriet skal ikke legge
# noe ut paa Instagram. Her er Instagram ikke koblet til: et kall dit gir en
# feil, saa en godkjenning som gaar gjennom med bare galleriet viser at
# Instagram aldri ble spurt.
#
#   tests/galleri.sh
#
# Krever app/secrets.php mot en database migrasjonene er kjort mot.

set -uo pipefail
cd "$(dirname "$0")/.."
ROT="$(pwd -W 2>/dev/null || pwd)"
PORT=${PORT_GALLERI:-8131}
T=$(mktemp -d); T=$(cd "$T" && (pwd -W 2>/dev/null || pwd))

ok=0; feil=0
sjekk() { # sjekk "navn" "ventet" "fikk"
  if [ "$2" = "$3" ]; then ok=$((ok+1)); printf '  OK    %s\n' "$1"
  else feil=$((feil+1)); printf '  FEIL  %s — ventet «%s», fikk «%s»\n' "$1" "$2" "$3"; fi
}

opprydding() {
  [ -n "${PID_WEB:-}" ] && kill "$PID_WEB" 2>/dev/null
  php -r 'require "'"$ROT"'/app/bootstrap.php";
    $m = array_column(DB::alle("SELECT id FROM members WHERE epost LIKE \"galleri-api-%@lissom.test\""), "id");
    foreach ($m as $i) {
      DB::kjor("DELETE FROM medlemsforslag WHERE member_id = :m", ["m" => $i]);
      DB::kjor("DELETE FROM sessions WHERE member_id = :m", ["m" => $i]);
      DB::kjor("DELETE FROM audit_log WHERE member_id = :m", ["m" => $i]);
      DB::kjor("DELETE FROM members WHERE id = :m", ["m" => $i]);
    }' 2>/dev/null
  rm -rf "$T"
}
trap opprydding EXIT

# --- Samme mappestruktur som webhotellet ----------------------------------
mkdir -p "$T/public_html" "$T/lissom-app" "$T/lissom-secrets" "$T/lissom-app/opplastinger/forslag"
cp -r api "$T/public_html/api"
cp -r app "$T/lissom-app/app"
echo '[]' > "$T/public_html/galleri-fyll.json"
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

# --- Testdata: admin, medlem, tre forslag med filer ------------------------
read -r TOKEN F1 F2 F3 FIL1 < <(php -r '
require "'"$ROT"'/app/bootstrap.php";
$admin = DB::settInn("members", ["navn" => "Galleri Admin", "epost" => "galleri-api-admin-" . bin2hex(random_bytes(3)) . "@lissom.test", "rolle" => "admin"]);
$t = bin2hex(random_bytes(32));
DB::settInn("sessions", ["token_hash" => hash("sha256", $t), "member_id" => $admin,
  "expires_at" => gmdate("Y-m-d H:i:s", time() + 3600)]);
$medlem = DB::settInn("members", ["navn" => "Kari Galleri", "epost" => "galleri-api-medlem-" . bin2hex(random_bytes(3)) . "@lissom.test", "rolle" => "medlem"]);
$ut = [$t];
$filer = [];
foreach (["Bolle i sandglasur", "Stort fat", "Kopper til hytta"] as $tekst) {
  $fil = bin2hex(random_bytes(16)) . ".jpg";
  $bilde = imagecreatetruecolor(40, 40);
  imagejpeg($bilde, "'"$T"'/lissom-app/opplastinger/forslag/" . $fil);
  $ut[] = DB::settInn("medlemsforslag", ["member_id" => $medlem, "type" => "bilde", "fil" => $fil, "tekst" => $tekst]);
  $filer[] = $fil;
}
echo implode(" ", $ut) . " " . $filer[0];
')

post() { # post fil kropp
  curl -s -m 20 -X POST -H "Content-Type: application/json" -H "$ORIG" \
    -H "Cookie: lissom_sesjon=$TOKEN" -d "$2" "$B/admin/$1"
}
felt() { # felt id kolonne
  php -r 'require "'"$ROT"'/app/bootstrap.php";
    echo (string) DB::verdi("SELECT '"$2"' FROM medlemsforslag WHERE id = :i", ["i" => '"$1"']);'
}
json() { php -r '$d = json_decode(stream_get_contents(STDIN), true); echo is_array($d) ? json_encode($d["'"$1"'"] ?? null, JSON_UNESCAPED_UNICODE) : "ugyldig";'; }

echo
echo "== Bare galleriet =="
SVAR=$(post medlemsforslag.php "{\"handling\":\"godkjenn\",\"id\":$F1,\"instagram\":false,\"galleri\":true}")
sjekk "godkjent til galleriet uten Instagram" "true" "$(echo "$SVAR" | json ok)"
sjekk "… statusen er galleri, ikke publisert" "galleri" "$(felt "$F1" status)"
sjekk "… og bildet er merket for galleriet" "1" "$(felt "$F1" galleri)"
sjekk "… og det er ingen Instagram-lenke" "" "$(felt "$F1" lenke)"
sjekk "bildet er offentlig mens det staar i galleriet" "200" \
  "$(curl -s -m 10 -o /dev/null -w '%{http_code}' "$B/bilde.php?forslag=$FIL1")"

echo
echo "== Ingen av dem =="
SVAR=$(post medlemsforslag.php "{\"handling\":\"godkjenn\",\"id\":$F2,\"instagram\":false,\"galleri\":false}")
sjekk "godkjenning uten valg avvises" "false" "$(echo "$SVAR" | json ok)"
sjekk "… og forslaget venter fortsatt" "venter" "$(felt "$F2" status)"

echo
echo "== Instagram og galleriet, uten Instagram koblet til =="
SVAR=$(post medlemsforslag.php "{\"handling\":\"godkjenn\",\"id\":$F3,\"instagram\":true,\"galleri\":true}")
sjekk "Instagram blir spurt, og sier nei" "false" "$(echo "$SVAR" | json ok)"
sjekk "… forslaget venter fortsatt" "venter" "$(felt "$F3" status)"
sjekk "… og havner ikke i galleriet halvveis" "0" "$(felt "$F3" galleri)"

echo
echo "== Galleri-lista i admin =="
LISTE=$(curl -s -m 10 -H "Cookie: lissom_sesjon=$TOKEN" "$B/admin/medlemsforslag.php" | php -r '
  $d = json_decode(stream_get_contents(STDIN), true);
  echo implode(",", array_map(static fn($g) => $g["id"], $d["galleri"] ?? []));')
sjekk "bildet staar i galleri-lista" "$F1" "$LISTE"

echo
echo "== Ta ut av galleriet =="
SVAR=$(post medlemsforslag.php "{\"handling\":\"ut-av-galleri\",\"id\":$F1}")
sjekk "tatt ut" "true" "$(echo "$SVAR" | json ok)"
sjekk "… ikke lenger merket" "0" "$(felt "$F1" galleri)"
sjekk "… og bildet er privat igjen" "404" \
  "$(curl -s -m 10 -o /dev/null -w '%{http_code}' "$B/bilde.php?forslag=$FIL1")"

echo
echo "── $ok gikk gjennom, $feil feilet"
[ "$feil" -eq 0 ]
