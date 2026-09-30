#!/usr/bin/env bash
#
# Alle testene, i én kjoring. Sperren foer publisering.
#
# Eieren, 27. september 2026: funksjoner i admin, paa Min side og paa
# nettsida sluttet aa virke etter endringer andre steder. Testene fantes,
# men publiseringen kjorte dem ikke. Naa kjores denne foer hver publisering,
# og feiler noe her, legges ingenting ut.
#
#   tests/alle.sh
#
# Krever app/secrets.php mot en database migrasjonene er kjort mot.
# GitHub setter opp det selv — se .github/workflows/tester.yml.

set -uo pipefail
cd "$(dirname "$0")/.."

gikk=(); feilet=()
kjor() { # kjor "navn" kommando...
  local navn="$1"; shift
  echo; echo "━━━ $navn"
  # En test som krasjer, kan avslutte med kode 0: appens feilhaandterer
  # skriver «Ubehandlet feil» og avslutter med 0. Da saa den gronn ut.
  # Derfor leses ogsaa det testen skrev, og et krasj teller som feil.
  local ut kode
  ut=$("$@" 2>&1); kode=$?
  printf '%s\n' "$ut"
  if [ $kode -eq 0 ] && ! grep -qE '(PHP )?(Fatal error|Parse error|Uncaught )|Ubehandlet feil' <<<"$ut"; then
    gikk+=("$navn")
  else
    [ $kode -eq 0 ] && echo "  ✗ $navn krasjet (kode 0, men feil i utskriften)"
    feilet+=("$navn")
  fi
}

# --- Sjekkene av nettsida og admin (leser filene) --------------------------
for f in adminsjekk autolastsjekk innholdssjekk karusellsjekk knappesjekk \
         listesjekk metodesjekk seosjekk skjemasjekk skriftsjekk toutgaversjekk; do
  kjor "$f" node "bin/$f.mjs"
done

# --- Eierens vedtak (tests/godkjent/vedtak.json) ----------------------------
kjor "vedtak" node tests/vedtak.mjs
kjor "maalingscookies" php tests/maaling-cookie.php

# --- Backend mot databasen --------------------------------------------------
kjor "backend"       php tests/backend.php
kjor "cronvakt"      php tests/cronvakt.php
kjor "gavekortspor"  php tests/gavekortspor.php
kjor "kjopslaas"     php tests/kjopslaas.php
kjor "verving"       php tests/verving.php
kjor "prøv lissom"   php tests/prove.php
kjor "timepakke"     php tests/timepakke.php
kjor "planbytte"     php tests/planbytte.php
kjor "anmeldelser"   php tests/anmeldelser.php
kjor "galleri"       php tests/galleri.php
kjor "lager"         php tests/lager.php
kjor "skisser"       php tests/skisser.php
kjor "frakt"         php tests/frakt.php
kjor "mva, nytt admin" php tests/mva.php
kjor "bestill mer, lav aktivitet" php tests/daglig.php
kjor "eposter"       php tests/eposter.php
kjor "sikkerhet (Codex C)" php tests/sikkerhet-c.php

# --- Betalingskjeden ende til ende mot en falsk Vipps -----------------------
kjor "betalingsflyt" bash tests/flyt.sh
kjor "delt betaling" bash tests/deltbetaling.sh
kjor "galleri, admin" bash tests/galleri.sh
kjor "avbestilling"  bash tests/avbestilling.sh
kjor "kursboost"     bash tests/kursboost.sh
kjor "dagens"        bash tests/dagens.sh
kjor "henting"       bash tests/henting.sh

# --- Hele flyter, klikket gjennom i en ekte nettleser ------------------------
kjor "nettleser"      bash tests/nettleser/kjor.sh
# Min side for medlemmer og kursdeltakere, hele veien, og fasiten over
# svarene Min side leser (tests/godkjent/minside-fasit/). Eieren, 30.
# september 2026: det nye admin bygges ved siden av, og Min side skal ikke
# røres. Endres noe her, stopper publiseringen.
kjor "min side-vakt"  bash tests/nettleser/kjor.sh minside.mjs

echo
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Gikk:   ${#gikk[@]}"
echo "Feilet: ${#feilet[@]}"
for n in "${feilet[@]}"; do echo "  ✗ $n"; done
[ ${#feilet[@]} -eq 0 ]
