#!/usr/bin/env bash
#
# Kursboost som ferdig flyt, gjennom admin-endepunktene.
#
# Eieren, 27. september 2026, GO paa skissen: bilde av kursets egne bilder
# (tre forslag, ingen forhaandsvalgt) og én knapp per del. Her gaar Gemini og
# Meta til en falsk tjener (tests/falsk-kursboost.php) — aldri de ekte — og
# testen ser i loggen dens hva som faktisk ble spurt om.
#
#   tests/kursboost.sh
#
# Krever app/secrets.php mot en database migrasjonene er kjort mot.

set -uo pipefail
cd "$(dirname "$0")/.."
ROT="$(pwd -W 2>/dev/null || pwd)"   # pwd -W: Windows-sti i Git Bash, saa PHP finner filene
PORT=${PORT_KURSBOOST:-8151}
PORT_FALSK=${PORT_KB_FALSK:-8152}
T=$(mktemp -d); T=$(cd "$T" && (pwd -W 2>/dev/null || pwd))
LOGG="$T/falsk-logg.txt"
: > "$LOGG"

ok=0; feil=0
sjekk() { # sjekk "navn" "ventet" "fikk"
  if [ "$2" = "$3" ]; then ok=$((ok+1)); printf '  OK    %s\n' "$1"
  else feil=$((feil+1)); printf '  FEIL  %s — ventet «%s», fikk «%s»\n' "$1" "$2" "$3"; fi
}

opprydding() {
  [ -n "${PID_WEB:-}" ] && kill "$PID_WEB" 2>/dev/null
  [ -n "${PID_FALSK:-}" ] && kill "$PID_FALSK" 2>/dev/null
  php -r 'require "'"$ROT"'/app/bootstrap.php";
    DB::kjor("DELETE FROM notifications WHERE mottaker LIKE \"kb-%@lissom.test\"");
    DB::kjor("DELETE FROM medlemsbeskjeder WHERE tittel = \"Kursboost-testkurs\"");
    DB::kjor("DELETE FROM articles WHERE tittel LIKE \"Kursboost-test%\"");
    DB::kjor("DELETE FROM ai_utkast WHERE kontekst LIKE \"Kursboost-testkurs%\"");
    $k = array_column(DB::alle("SELECT id FROM courses WHERE slug = \"kursboost-test\""), "id");
    foreach ($k as $c) { DB::kjor("DELETE FROM courses WHERE id = :c", ["c" => $c]); }
    $m = array_column(DB::alle("SELECT id FROM members WHERE epost LIKE \"kb-%@lissom.test\""), "id");
    foreach ($m as $i) {
      DB::kjor("DELETE FROM sessions WHERE member_id = :m", ["m" => $i]);
      DB::kjor("DELETE FROM audit_log WHERE member_id = :m", ["m" => $i]);
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
$s["meta_token"] = "test-token";
$s["meta_ig_id"] = "ig1";
$s["meta_side_id"] = "side1";
$s["gemini_api_key"] = "test-noekkel-som-er-lang-nok";
file_put_contents("'"$T"'/lissom-secrets/secrets.php", "<?php return " . var_export($s, true) . ";");
' || { echo "Fant ikke app/secrets.php — sett den opp forst."; exit 1; }
# Kursets eget bilde, der nettsida har sine bilder.
php -r '$b = imagecreatetruecolor(60, 60); imagejpeg($b, "'"$T"'/public_html/kursboost-test-kurs.jpg");'

KB_LOGG="$LOGG" php -S "127.0.0.1:$PORT_FALSK" "$ROT/tests/falsk-kursboost.php" >/dev/null 2>&1 &
PID_FALSK=$!
LISSOM_GEMINI_BASE="http://127.0.0.1:$PORT_FALSK/v1beta/models" \
LISSOM_META_BASE="http://127.0.0.1:$PORT_FALSK" \
  php -S "127.0.0.1:$PORT" -t "$T/public_html" >/dev/null 2>&1 &
PID_WEB=$!
for _ in $(seq 1 20); do
  curl -s -m 2 -o /dev/null "http://127.0.0.1:$PORT/api/kurs.php" && break
  sleep 1
done
B="http://127.0.0.1:$PORT/api/admin"
ORIG="Origin: https://lissom.no"

# --- Testdata: admin, ett aktivt medlem, et kurs og en kursboost -----------
read -r TOKEN UTKAST < <(php -r '
require "'"$ROT"'/app/bootstrap.php";
$admin = DB::settInn("members", ["navn" => "Kb Admin", "epost" => "kb-admin-" . bin2hex(random_bytes(3)) . "@lissom.test", "rolle" => "admin"]);
$t = bin2hex(random_bytes(32));
DB::settInn("sessions", ["token_hash" => hash("sha256", $t), "member_id" => $admin,
  "expires_at" => gmdate("Y-m-d H:i:s", time() + 3600)]);
DB::settInn("members", ["navn" => "Kari Kb", "epost" => "kb-medlem-" . bin2hex(random_bytes(3)) . "@lissom.test", "rolle" => "medlem", "status" => "aktiv"]);
$kurs = DB::settInn("courses", ["slug" => "kursboost-test", "tittel" => "Kursboost-testkurs", "type" => "kurs",
  "pris_ore" => 100000, "kapasitet" => 8, "status" => "publisert", "bilde" => "kursboost-test-kurs.jpg"]);
$data = ["artikkel" => ["tittel" => "Kursboost-test: fra leire til fat", "tekst" => "Artikkelen om kurset."],
  "facebook" => "Innlegg til Facebook.", "instagram" => "Innlegg til Instagram.", "hashtags" => ["keramikk", "#teie"],
  "epost" => ["emne" => "Kursboost-test: ledige plasser", "tekst" => "E-post til kundelista."],
  "medlemmer" => "Tips gjerne venner!", "kursId" => $kurs];
$u = DB::settInn("ai_utkast", ["type" => "kursboost", "tittel" => "Kursboost: Kursboost-testkurs", "tekst" => "Artikkelen om kurset.",
  "data" => json_encode($data, JSON_UNESCAPED_UNICODE), "kontekst" => "Kursboost-testkurs", "kostnad_ore" => 0]);
echo "$t $u";')
C="Cookie: lissom_sesjon=$TOKEN"
post() { curl -s -m 60 -X POST -H "Content-Type: application/json" -H "$ORIG" -H "$C" -d "$2" "$B/$1"; }
felt() { php -r '$d = json_decode(stream_get_contents(STDIN), true) ?: []; $v = eval("return " . $argv[1] . ";"); echo is_bool($v) ? ($v ? "True" : "False") : $v;' "$1" 2>/dev/null; }
antall_i_logg() { grep -c "$1" "$LOGG" 2>/dev/null || true; }

echo
echo "── Ingenting gaar ut av seg selv ──"
P=$(curl -s -m 20 -H "$ORIG" -H "$C" "$B/kursboost.php?id=$UTKAST")
sjekk "pakken hentes" "5" "$(echo "$P" | felt 'count($d["pakke"]["deler"])')"
sjekk "… uten bilder og uten noe valgt" "0|" "$(echo "$P" | felt 'count($d["pakke"]["bilder"]) . "|" . $d["pakke"]["valgt"]')"
sjekk "… og ingen del er gjort" "True" "$(echo "$P" | felt 'array_filter(array_column($d["pakke"]["deler"], "gjort")) === []')"
sjekk "… Instagram-teksten har emneknaggene" "Innlegg til Instagram.\n\n#keramikk #teie" "$(echo "$P" | felt 'str_replace("\n", "\\n", $d["pakke"]["deler"]["instagram"]["tekst"])')"
sjekk "ingenting er spurt hos Gemini eller Meta" "0" "$(wc -l < "$LOGG" | tr -d ' ')"

echo
echo "── Instagram er laast uten bilde ──"
R=$(post kursboost.php "{\"handling\":\"del\",\"id\":$UTKAST,\"del\":\"instagram\"}")
sjekk "Instagram uten bilde avvises" "Velg et bilde først. Instagram tar ikke imot innlegg uten." "$(echo "$R" | felt '$d["feil"] ?? ""')"
sjekk "… og Meta ble ikke spurt" "0" "$(antall_i_logg '/media')"

echo
echo "── Tre bildeforslag av kursets egne bilder ──"
R=$(post kursboost.php "{\"handling\":\"bilder\",\"id\":$UTKAST}")
sjekk "tre forslag" "3" "$(echo "$R" | felt 'count($d["pakke"]["bilder"])')"
sjekk "… ingen valgt paa forhaand" "" "$(echo "$R" | felt '$d["pakke"]["valgt"]')"
sjekk "… tre kall til Gemini" "3" "$(antall_i_logg ':generateContent')"
BILDE=$(echo "$R" | felt '$d["pakke"]["bilder"][0]')
sjekk "… og kostnaden er ført i ai_logg" "3" "$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT COUNT(*) FROM ai_logg WHERE formal = \"Kursbilde\" AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE)");')"
R=$(post kursboost.php "{\"handling\":\"velg\",\"id\":$UTKAST,\"bilde\":\"nytt.jpg\"}")
sjekk "et bilde som ikke er et forslag kan ikke velges" "Velg ett av forslagene." "$(echo "$R" | felt '$d["feil"] ?? ""')"
R=$(post kursboost.php "{\"handling\":\"velg\",\"id\":$UTKAST,\"bilde\":\"$BILDE\"}")
sjekk "forslaget velges" "$BILDE" "$(echo "$R" | felt '$d["pakke"]["valgt"]')"

echo
echo "── Hver del gaar ut én gang ──"
R=$(post kursboost.php "{\"handling\":\"del\",\"id\":$UTKAST,\"del\":\"instagram\",\"tekst\":\"Rettet tekst til Instagram\"}")
sjekk "Instagram legges ut" "Lagt ut på Instagram ✓" "$(echo "$R" | felt '$d["beskjed"] ?? ""')"
sjekk "… med én publisering hos Meta" "1" "$(antall_i_logg '/media_publish')"
sjekk "… og den rettede teksten er lagret" "Rettet tekst til Instagram" "$(echo "$R" | felt '$d["pakke"]["deler"]["instagram"]["tekst"]')"
R=$(post kursboost.php "{\"handling\":\"del\",\"id\":$UTKAST,\"del\":\"instagram\"}")
sjekk "samme del kan ikke gaa ut to ganger" "Denne delen er alt gjort. Lag en ny kursboost hvis den skal ut igjen." "$(echo "$R" | felt '$d["feil"] ?? ""')"
sjekk "… og Meta ble ikke spurt paa nytt" "1" "$(antall_i_logg '/media_publish')"
R=$(post kursboost.php "{\"handling\":\"del\",\"id\":$UTKAST,\"del\":\"facebook\"}")
sjekk "Facebook legges ut" "Lagt ut på Facebook ✓" "$(echo "$R" | felt '$d["beskjed"] ?? ""')"
sjekk "… paa sida, med bildet" "1" "$(antall_i_logg '/photos')"
R=$(post kursboost.php "{\"handling\":\"del\",\"id\":$UTKAST,\"del\":\"artikkel\"}")
sjekk "artikkelen publiseres" "Publisert ✓" "$(echo "$R" | felt '$d["beskjed"] ?? ""')"
sjekk "… paa nettsida, med bildet som hovedbilde" "publisert|$BILDE" "$(php -r 'require "'"$ROT"'/app/bootstrap.php"; $a = DB::en("SELECT status, bilde FROM articles WHERE tittel LIKE \"Kursboost-test%\" ORDER BY id DESC LIMIT 1"); echo $a["status"] . "|" . $a["bilde"];')"
R=$(post kursboost.php "{\"handling\":\"del\",\"id\":$UTKAST,\"del\":\"nyhetsbrev\"}")
sjekk "nyhetsbrevet blir et utkast" "Lagt som utkast i Tilbud / nyhetsbrev ✓" "$(echo "$R" | felt '$d["beskjed"] ?? ""')"
sjekk "… og ingen e-post er sendt derfra" "1|0" "$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT COUNT(*) FROM ai_utkast WHERE type = \"nyhetsbrev\" AND kontekst = \"Kursboost-testkurs\" AND status = \"utkast\"") . "|" . DB::verdi("SELECT COUNT(*) FROM notifications WHERE ref_type = \"beskjed-medlem\" AND mottaker LIKE \"kb-%@lissom.test\"");')"

echo
echo "── Til medlemmene, gjennom den vanlige utsendingen ──"
R=$(curl -s -m 20 -X POST -H "Content-Type: application/json" -H "$ORIG" -H "$C" -d '{"til":"medlemmer","handling":"antall","emne":"Kursboost-testkurs","tekst":"Tips gjerne venner!"}' "$B/beskjed.php")
sjekk "tallet foer utsending" "True" "$(echo "$R" | felt '($d["antall"] ?? 0) >= 1')"
sjekk "… uten at noe er sendt" "0" "$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT COUNT(*) FROM notifications WHERE mottaker LIKE \"kb-%@lissom.test\"");')"
R=$(curl -s -m 20 -X POST -H "Content-Type: application/json" -H "$ORIG" -H "$C" -d "{\"til\":\"medlemmer\",\"emne\":\"Kursboost-testkurs\",\"tekst\":\"Tips gjerne venner!\",\"kursboost\":$UTKAST}" "$B/beskjed.php")
sjekk "meldingen legges i varselkoen" "1" "$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT COUNT(*) FROM notifications WHERE mottaker LIKE \"kb-medlem-%@lissom.test\" AND ref_type = \"beskjed-medlem\"");')"
sjekk "… og delen staar som gjort" "True" "$(curl -s -m 20 -H "$ORIG" -H "$C" "$B/kursboost.php?id=$UTKAST" | felt '($d["pakke"]["deler"]["medlemmer"]["gjort"] ?? null) !== null')"
R=$(curl -s -m 20 -X POST -H "Content-Type: application/json" -H "$ORIG" -H "$C" -d "{\"til\":\"medlemmer\",\"emne\":\"Kursboost-testkurs\",\"tekst\":\"Tips gjerne venner!\",\"kursboost\":$UTKAST}" "$B/beskjed.php")
sjekk "den kan ikke sendes to ganger" "Denne meldingen er alt sendt til medlemmene." "$(echo "$R" | felt '$d["feil"] ?? ""')"
sjekk "… og koen fikk ikke en til" "1" "$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT COUNT(*) FROM notifications WHERE mottaker LIKE \"kb-medlem-%@lissom.test\" AND ref_type = \"beskjed-medlem\"");')"

echo
echo "── Eget bilde og «Publiser» (eieren, 27. september 2026) ──"
# En ny kursboost for samme kurs, og et liggende bilde eieren laster opp.
UTKAST2=$(php -r 'require "'"$ROT"'/app/bootstrap.php";
$k = DB::verdi("SELECT id FROM courses WHERE slug = \"kursboost-test\"");
$data = ["artikkel" => ["tittel" => "Kursboost-test to: publisert", "tekst" => "Artikkel nummer to."],
  "facebook" => "Facebook to.", "instagram" => "Instagram to.", "hashtags" => ["teie"],
  "epost" => ["emne" => "Kursboost-test to", "tekst" => "E-post to."], "medlemmer" => "Til medlemmene to.", "kursId" => (int) $k];
echo DB::settInn("ai_utkast", ["type" => "kursboost", "tittel" => "Kursboost: Kursboost-testkurs to", "tekst" => "Artikkel nummer to.",
  "data" => json_encode($data, JSON_UNESCAPED_UNICODE), "kontekst" => "Kursboost-testkurs to", "kostnad_ore" => 0]);')
php -r '$b = imagecreatetruecolor(1200, 800); imagefill($b, 0, 0, imagecolorallocate($b, 160, 110, 70)); imagejpeg($b, "'"$T"'/eget.jpg");'
R=$(curl -s -m 30 -X POST -H "$ORIG" -H "$C" -F "handling=last-opp" -F "bilde=@$T/eget.jpg;type=image/jpeg" "$B/bilder.php")
EGET=$(echo "$R" | felt '$d["url"] ?? ""')
sjekk "eget bilde lastes opp til biblioteket" "True" "$(echo "$EGET" | grep -qE '^api/bilde\.php\?artikkel=[0-9a-f]{32}\.jpg$' && echo True || echo False)"
R=$(post kursboost.php "{\"handling\":\"velg\",\"id\":$UTKAST2,\"bilde\":\"$EGET\"}")
sjekk "… og kan velges i kursboost" "$EGET" "$(echo "$R" | felt '$d["pakke"]["valgt"]')"
R=$(post kursboost.php "{\"handling\":\"velg\",\"id\":$UTKAST2,\"bilde\":\"kursboost-test-kurs.jpg\"}")
sjekk "et av nettsidas egne bilder kan ogsaa velges" "kursboost-test-kurs.jpg" "$(echo "$R" | felt '$d["pakke"]["valgt"]')"
R=$(post kursboost.php "{\"handling\":\"velg\",\"id\":$UTKAST2,\"bilde\":\"https://ondsinnet.example/x.jpg\"}")
sjekk "… men ikke en adresse utenfra" "Velg ett av forslagene." "$(echo "$R" | felt '$d["feil"] ?? ""')"
post kursboost.php "{\"handling\":\"velg\",\"id\":$UTKAST2,\"bilde\":\"$EGET\"}" >/dev/null
FOER_IG=$(antall_i_logg '/media_publish'); FOER_FB=$(antall_i_logg '/photos')
R=$(post kursboost.php "{\"handling\":\"publiser\",\"id\":$UTKAST2,\"tekster\":{\"instagram\":\"Rettet Instagram to\"}}")
sjekk "Publiser gjoer Instagram, Facebook og artikkelen" "True|True|True" "$(echo "$R" | felt 'implode("|", array_map(fn($x) => ($x["ok"] ?? false) ? "True" : "False", [$d["resultat"]["instagram"] ?? [], $d["resultat"]["facebook"] ?? [], $d["resultat"]["artikkel"] ?? []]))')"
sjekk "… én gang hver hos Meta" "$((FOER_IG + 1))|$((FOER_FB + 1))" "$(antall_i_logg '/media_publish')|$(antall_i_logg '/photos')"
IGURL=$(php -r 'require "'"$ROT"'/app/bootstrap.php"; $u = Kursboost::utkast('"$UTKAST2"'); echo (string) ($u["data"]["kb"]["ig"]["'"$EGET"'"] ?? "");')
IGFIL=$(find "$T" -name "${IGURL##*=}" 2>/dev/null | head -1)
sjekk "… Instagram fikk bildet i 4:5" "True" "$([ -n "$IGFIL" ] && php -r '$i = getimagesize($argv[1]); echo abs($i[0] / $i[1] - 0.8) < 0.01 ? "True" : "False";' "$IGFIL" || echo False)"
sjekk "… og artikkelen er publisert med det egne bildet" "publisert|$EGET" "$(php -r 'require "'"$ROT"'/app/bootstrap.php"; $a = DB::en("SELECT status, bilde FROM articles WHERE tittel LIKE \"Kursboost-test to%\" ORDER BY id DESC LIMIT 1"); echo ($a["status"] ?? "") . "|" . ($a["bilde"] ?? "");')"
sjekk "… med den rettede Instagram-teksten" "Rettet Instagram to" "$(echo "$R" | felt '$d["pakke"]["deler"]["instagram"]["tekst"]')"
sjekk "Publiser sender ikke e-post eller melding til medlemmene" "0|0|False|False" "$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo DB::verdi("SELECT COUNT(*) FROM ai_utkast WHERE type = \"nyhetsbrev\" AND kontekst = \"Kursboost-testkurs to\"") . "|" . DB::verdi("SELECT COUNT(*) FROM notifications WHERE mottaker LIKE \"kb-medlem-%@lissom.test\" AND created_at > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) AND ref_type = \"beskjed-medlem\" AND ref_id = '"$UTKAST2"'");')|$(echo "$R" | felt 'isset($d["pakke"]["deler"]["nyhetsbrev"]["gjort"]) ? "True" : "False"')|$(echo "$R" | felt 'isset($d["pakke"]["deler"]["medlemmer"]["gjort"]) ? "True" : "False"')"
R=$(post kursboost.php "{\"handling\":\"publiser\",\"id\":$UTKAST2}")
sjekk "Publiser igjen hopper over det som er gjort" "True|True|True" "$(echo "$R" | felt 'implode("|", array_map(fn($x) => !empty($x["hoppetOver"]) ? "True" : "False", [$d["resultat"]["instagram"] ?? [], $d["resultat"]["facebook"] ?? [], $d["resultat"]["artikkel"] ?? []]))')"
sjekk "… og Meta ble ikke spurt paa nytt" "$((FOER_IG + 1))|$((FOER_FB + 1))" "$(antall_i_logg '/media_publish')|$(antall_i_logg '/photos')"
sjekk "… og det staar i endringsloggen" "True" "$(php -r 'require "'"$ROT"'/app/bootstrap.php"; echo (int) DB::verdi("SELECT COUNT(*) FROM audit_log WHERE handling = \"kursboost_publiser\"") >= 2 ? "True" : "False";' 2>/dev/null || echo False)"

echo
echo "── $ok gikk gjennom, $feil feilet"
[ "$feil" -eq 0 ]
