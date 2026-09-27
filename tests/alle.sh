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

# --- Backend mot databasen --------------------------------------------------
kjor "backend"       php tests/backend.php
kjor "cronvakt"      php tests/cronvakt.php
kjor "gavekortspor"  php tests/gavekortspor.php
kjor "verving"       php tests/verving.php
kjor "galleri"       php tests/galleri.php
kjor "eposter"       php tests/eposter.php

# --- Betalingskjeden ende til ende mot en falsk Vipps -----------------------
kjor "betalingsflyt" bash tests/flyt.sh
kjor "delt betaling" bash tests/deltbetaling.sh
kjor "galleri, admin" bash tests/galleri.sh
kjor "avbestilling"  bash tests/avbestilling.sh

echo
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Gikk:   ${#gikk[@]}"
echo "Feilet: ${#feilet[@]}"
for n in "${feilet[@]}"; do echo "  ✗ $n"; done
[ ${#feilet[@]} -eq 0 ]
