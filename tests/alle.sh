#!/usr/bin/env bash
#
# Alle testene, i én kjoring. Sperren foer publisering.
#
# Eieren, 27. september 2026: funksjoner i admin, paa Min side og paa
# nettsida sluttet aa virke etter endringer andre steder. Testene fantes,
# men publiseringen kjorte dem ikke. Naa kjores denne foer hver publisering,
# og feiler noe her, legges ingenting ut.
#
#   tests/alle.sh                      alle testene (som foer)
#   ALLE_GRUPPE=backend tests/alle.sh  bare en gruppe (GitHub kjorer
#                                      gruppene parallelt, hver med egen base)
#   tests/alle.sh --grupper            listen over gruppene
#   ALLE_TORR=1 tests/alle.sh          tormodus: skriver «gruppe<TAB>navn», kjorer ingenting
#
# Hvert testsett maa staa under en «gruppe»-linje. Mangler det, feiler hele
# skriptet — saa ingen test kan bli staaende utenfor sperren.
#
# Krever app/secrets.php mot en database migrasjonene er kjort mot.
# GitHub setter opp det selv — se .github/workflows/tester.yml.

set -uo pipefail
cd "$(dirname "$0")/.."

GRUPPER=(statisk backend betaling diverse flyter1 flyter2 flyter3 flyter4 minside nyadmin)
if [ "${1:-}" = "--grupper" ]; then printf '%s\n' "${GRUPPER[@]}"; exit 0; fi

# Nettleserflytene (tests/nettleser/flyter.mjs) deles etter navnets begynnelse
# (E2E_BARE, skilt med |). Hver flyt maa treffe noyaktig én av delene, ellers
# feiler skriptet — ingen flyt kan falle ut. Malt lokalt 2. oktober 2026
# (sek.): «holder seg» ~450 (alene), «alle knapper» + «I dag» + menyer/skisser/
# frakt ~360, Regresjon + medlemsreisene + Prøv/timepakke ~290, resten ~190.
# Eieren 4. oktober 2026: admin2 slettet, og de tre flytene for den med.
# Del 1 er det som var igjen av del 2. Del 2 og 3 er den gamle del 3 delt i
# to, men «Prøv Lissom» og «Etter byttet» maa staa i samme del: «Etter
# byttet» bygger paa byttet «Prøv Lissom» gjorde (feilet 04.10 da de ble skilt).
FLYTDEL_1='Menyer og ark|Skisser:|Frakt ('
FLYTDEL_2='Regresjon:|Google-anmeldelser|Medlemsreise'
FLYTDEL_3='Prøv Lissom|Etter byttet|Oversikt paa mobil|Timepakke:|Utstempling'
FLYTDEL_4='Flytt deltaker|Delt betaling|Vervepremie|Galleri:|Tekst maler|Synlighet:|Oversikt: dagens|Bestill mer|Designmaler|Bilder:|Gavekortsida|Butikk:'
sjekk_flytdeler() {
  local navn n treff d p feil=0
  while IFS= read -r navn; do
    treff=0
    for d in 1 2 3 4; do
      local v="FLYTDEL_$d"; IFS='|' read -ra pre <<< "${!v}"
      for p in "${pre[@]}"; do [[ "$navn" == "$p"* ]] && treff=$((treff+1)); done
    done
    [ $treff -eq 1 ] || { echo "✗ flyten «$navn» treffer $treff deler av FLYTDEL_ (maa vaere 1)"; feil=1; }
  done < <(sed -n "s/^await flyt('\(.*\)', async.*/\1/p" tests/nettleser/flyter.mjs)
  for d in 1 2 3 4; do
    local v="FLYTDEL_$d"; IFS='|' read -ra pre <<< "${!v}"
    for p in "${pre[@]}"; do
      grep -q "^await flyt('$p" tests/nettleser/flyter.mjs || { echo "✗ delen «$p» treffer ingen flyt"; feil=1; }
    done
  done
  return $feil
}
if [ -n "${ALLE_TORR:-}" ]; then sjekk_flytdeler || exit 1; fi
if [ -n "${ALLE_GRUPPE:-}" ]; then
  [[ " ${GRUPPER[*]} " == *" $ALLE_GRUPPE "* ]] || { echo "Ukjent gruppe: $ALLE_GRUPPE"; exit 1; }
fi

GRUPPE=""; uten_gruppe=0; kjort=0
gruppe() {
  [[ " ${GRUPPER[*]} " == *" $1 "* ]] || { echo "Ukjent gruppe i alle.sh: $1"; exit 1; }
  GRUPPE="$1"
}

gikk=(); feilet=()
kjor() { # kjor "navn" kommando...
  local navn="$1"; shift
  if [ -z "$GRUPPE" ]; then
    echo "✗ «$navn» staar uten gruppe i tests/alle.sh"; uten_gruppe=$((uten_gruppe+1)); return
  fi
  if [ -n "${ALLE_TORR:-}" ]; then printf '%s\t%s\n' "$GRUPPE" "$navn"; return; fi
  if [ -n "${ALLE_GRUPPE:-}" ] && [ "$ALLE_GRUPPE" != "$GRUPPE" ]; then return; fi
  kjort=$((kjort+1))
  echo; echo "━━━ $navn"
  # En test som krasjer, kan avslutte med kode 0: appens feilhaandterer
  # skriver «Ubehandlet feil» og avslutter med 0. Da saa den gronn ut.
  # Derfor leses ogsaa det testen skrev, og et krasj teller som feil.
  local rapport kode
  rapport=$(mktemp) || { feilet+=("$navn"); return; }
  "$@" 2>&1 | tee "$rapport"
  local pipekoder=("${PIPESTATUS[@]}")
  kode=${pipekoder[0]}
  [ "${pipekoder[1]}" -eq 0 ] || kode=1
  if [ $kode -eq 0 ] && ! grep -qE '(PHP )?(Fatal error|Parse error|Uncaught )|Ubehandlet feil' "$rapport"; then
    gikk+=("$navn")
  else
    [ $kode -eq 0 ] && echo "  ✗ $navn krasjet (kode 0, men feil i utskriften)"
    feilet+=("$navn")
  fi
  rm -f -- "$rapport"
}

# --- Sjekkene av nettsida og admin (leser filene) --------------------------
gruppe statisk
for f in adminsjekk autolastsjekk innholdssjekk karusellsjekk knappesjekk \
         listesjekk metodesjekk seosjekk skjemasjekk skriftsjekk toutgaversjekk; do
  kjor "$f" node "bin/$f.mjs"
done

# --- Eierens vedtak (tests/godkjent/vedtak.json) ----------------------------
kjor "vedtak" node tests/vedtak.mjs

# --- Backend mot databasen --------------------------------------------------
gruppe statisk
kjor "testdatabasesperre" php tests/nettleser/testdatabase-test.php
gruppe betaling
kjor "betalingsidempotens" php tests/vipps-idempotens.php
kjor "webhook replay" php tests/webhook-replay.php
kjor "refusjonsjournal" php tests/refusjon.php
kjor "avbestillingsrefusjon" php tests/avbestill-refusjon.php
kjor "avbestilling med gavekort (L-2)" php tests/avbestill-gavekort.php
kjor "refusjon gjør opp kjøpet (L-1, L-3)" php tests/refusjon-formal.php
kjor "gavekort samtidig (L-4)" php tests/gavekort-samtidig.php
kjor "avslutning og manuell medlemsbetaling (L-11, L-12)" php tests/medlem-l11-l12.php
kjor "flytting, venteliste, portalrefusjon (L-7–L-9)" php tests/pamelding-flytt.php
kjor "pengehull 1–4 (frys, Kassa/Forny, skyldig, endre/fjern)" php tests/pengehull.php
kjor "kasse på iPad (tilgang, PIN, beløp på serveren, Vipps-QR)" php tests/kasse.php
kjor "enkel kasse (dagens kurs, ny kunde, endret pris og rabatt)" php tests/kasse-enkel.php
kjor "Ta betalt i ny admin → kassa (?booking=, alle datoer)" php tests/kasse-ta-betalt.php
kjor "avlyst dato (Min side, refusjon, kassa, melding)" php tests/avlyst-dato.php
kjor "refusjonsklient" node tests/refusjon-klient.mjs
gruppe backend
kjor "backend"       php -d memory_limit=-1 tests/backend.php
gruppe diverse
kjor "cronvakt"     php tests/cronvakt.php
kjor "gavekortspor"  php tests/gavekortspor.php
kjor "kjopslaas"     php tests/kjopslaas.php
kjor "verving"       php tests/verving.php
kjor "prøv lissom"   php tests/prove.php
kjor "timepakke"     php tests/timepakke.php
kjor "planbytte"     php tests/planbytte.php
kjor "nytt etter 20"  php tests/nytt-etter-20.php
kjor "vakt, datasjekk" php tests/vaktdata-regler.php
kjor "anmeldelser"   php tests/anmeldelser.php
kjor "meldinger 03.10 (anmeldelse kl. 10, maler av)" php tests/meldinger-0310.php
kjor "AI-kommentarsvar" php tests/autosvar.php
kjor "galleri"       php tests/galleri.php
kjor "lager"         php tests/lager.php
kjor "må gjøres: ovn, tregt, ikke innom, mandag" php tests/ma-gjores-mer.php
kjor "ny admin (/ny-admin): tilgang, PopPris, utsending, påminnelse-sperre, kursbevis, kanaler, lager" php tests/ny-admin.php
kjor "ny admin › Varer: faner, min/maks, handleliste, medlemskolleksjon" php tests/ny-admin-varer.php
kjor "malebord (Paint on Pots)" php tests/malebord.php
kjor "Paint on Pots: prisnivåer, 100 kr ved booking og kassa" php tests/pop-pris.php
kjor "skisser"       php tests/skisser.php
kjor "frakt"         php tests/frakt.php
kjor "mva, nytt admin" php tests/mva.php
kjor "bestill mer, lav aktivitet" php tests/daglig.php
kjor "eposter"       php tests/eposter.php
kjor "sikkerhet (Codex C)" php tests/sikkerhet-c.php
kjor "fryst medlem: ingen innstempling eller medlemstid" php tests/frys-tilgang.php
kjor "måling: GA4-økt og samtykke" php tests/maaling-okt-samtykke.php

# --- Betalingskjeden ende til ende mot en falsk Vipps -----------------------
# Betalingsflyten henter en publisert, betalt kursokt fram i tid fra basen,
# og den okta legger «backend» igjen. Derfor samme gruppe, backend foerst.
gruppe backend
kjor "betalingsflyt" bash tests/flyt.sh
kjor "delt betaling" bash tests/deltbetaling.sh
kjor "galleri, admin" bash tests/galleri.sh
kjor "avbestilling"  bash tests/avbestilling.sh
kjor "kursboost"     bash tests/kursboost.sh
kjor "dagens"        bash tests/dagens.sh
kjor "nye påmeldinger, medlemskap" bash tests/nye-pameldinger-medlem.sh
kjor "henting"       bash tests/henting.sh

# --- Hele flyter, klikket gjennom i en ekte nettleser ------------------------
gruppe flyter1
kjor "nettleser, del 1" env E2E_BARE="$FLYTDEL_1" bash tests/nettleser/kjor.sh
gruppe flyter2
kjor "nettleser, del 2" env E2E_BARE="$FLYTDEL_2" bash tests/nettleser/kjor.sh
gruppe flyter3
kjor "nettleser, del 3" env E2E_BARE="$FLYTDEL_3" bash tests/nettleser/kjor.sh
gruppe flyter4
kjor "nettleser, del 4" env E2E_BARE="$FLYTDEL_4" bash tests/nettleser/kjor.sh
gruppe minside
# Min side for medlemmer og kursdeltakere, hele veien, og fasiten over
# svarene Min side leser (tests/godkjent/minside-fasit/). Endringer krever
# brukerens bestilling og gjennomgått fasit. Eieren bestilte 1. oktober
# alle moduler med vis/skjul, samt sperring av ubetalt medlemskap.
kjor "min side-vakt"  bash tests/nettleser/kjor.sh minside.mjs
kjor "avlyst dato i nettleseren" bash tests/nettleser/kjor.sh avlyst-dato.mjs
gruppe nyadmin
# «minside-moduler-ut» (i admin-ny) venter at handlelista og skissene er
# slaatt paa — det gjorde flytene i flyter.mjs tidligere. Naa har gruppen sin
# egen base, saa det settes her (bare i gruppekjoring; ellers er det alt gjort).
if [ -n "${ALLE_GRUPPE:-}" ] && [ "$ALLE_GRUPPE" = "nyadmin" ] && [ -z "${ALLE_TORR:-}" ]; then
  php -r 'require "app/bootstrap.php"; foreach (["Vis/handleliste", "Vis/skisser"] as $k) { DB::kjor("INSERT INTO content_blocks (nokkel, verdi) VALUES (:k, \"ja\") ON DUPLICATE KEY UPDATE verdi = \"ja\"", ["k" => $k]); }' \
    || { echo "Fikk ikke satt oppsettet for nyadmin."; exit 1; }
fi
kjor "ny admin"       bash tests/nettleser/kjor.sh admin-ny
gruppe statisk
kjor "varsler SMTP"    node tests/varsler-smtp.mjs
gruppe minside
kjor "varsler og kursbevis" bash tests/nettleser/kjor.sh varsler.mjs
# /booking?plan=<navn> åpnet direkte (eieren, 2. oktober 2026).
kjor "booking-plan-lenke" bash tests/nettleser/kjor.sh booking-plan-lenke.mjs

[ $uten_gruppe -eq 0 ] || { echo "$uten_gruppe testsett uten gruppe."; exit 1; }
[ -z "${ALLE_TORR:-}" ] || exit 0
if [ $kjort -eq 0 ]; then echo "Ingen testsett kjort (gruppe ${ALLE_GRUPPE:-?} er tom)."; exit 1; fi

echo
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Gikk:   ${#gikk[@]}"
echo "Feilet: ${#feilet[@]}"
for n in "${feilet[@]}"; do echo "  ✗ $n"; done
[ ${#feilet[@]} -eq 0 ]
