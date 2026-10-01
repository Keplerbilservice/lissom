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
for f in adminsjekk autolastsjekk innholdssjekk karusellsjekk knappesjekk \
         listesjekk metodesjekk seosjekk skjemasjekk skriftsjekk toutgaversjekk; do
  kjor "$f" node "bin/$f.mjs"
done

# --- Eierens vedtak (tests/godkjent/vedtak.json) ----------------------------
kjor "vedtak" node tests/vedtak.mjs

# --- Backend mot databasen --------------------------------------------------
kjor "testdatabasesperre" php tests/nettleser/testdatabase-test.php
kjor "betalingsidempotens" php tests/vipps-idempotens.php
kjor "webhook replay" php tests/webhook-replay.php
kjor "refusjonsjournal" php tests/refusjon.php
kjor "avbestillingsrefusjon" php tests/avbestill-refusjon.php
kjor "refusjonsklient" node tests/refusjon-klient.mjs
kjor "backend"       php -d memory_limit=-1 tests/backend.php
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
# svarene Min side leser (tests/godkjent/minside-fasit/). Endringer krever
# brukerens bestilling og gjennomgått fasit. Eieren bestilte 1. oktober
# alle moduler med vis/skjul, samt sperring av ubetalt medlemskap.
kjor "min side-vakt"  bash tests/nettleser/kjor.sh minside.mjs
kjor "ny admin"       bash tests/nettleser/kjor.sh admin-ny

echo
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Gikk:   ${#gikk[@]}"
echo "Feilet: ${#feilet[@]}"
for n in "${feilet[@]}"; do echo "  ✗ $n"; done
[ ${#feilet[@]} -eq 0 ]
