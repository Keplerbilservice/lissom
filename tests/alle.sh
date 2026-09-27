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
  if "$@"; then gikk+=("$navn"); else feilet+=("$navn"); fi
}

# --- Sjekkene av nettsida og admin (leser filene) --------------------------
for f in adminsjekk autolastsjekk innholdssjekk karusellsjekk knappesjekk \
         listesjekk metodesjekk seosjekk skjemasjekk skriftsjekk toutgaversjekk; do
  kjor "$f" node "bin/$f.mjs"
done

# --- Backend mot databasen --------------------------------------------------
kjor "backend"       php tests/backend.php
kjor "cronvakt"      php tests/cronvakt.php
kjor "gavekortspor"  php tests/gavekortspor.php

# --- Betalingskjeden ende til ende mot en falsk Vipps -----------------------
kjor "betalingsflyt" bash tests/flyt.sh

echo
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "Gikk:   ${#gikk[@]}"
echo "Feilet: ${#feilet[@]}"
for n in "${feilet[@]}"; do echo "  ✗ $n"; done
[ ${#feilet[@]} -eq 0 ]
