#!/usr/bin/env bash
# Testsperren ved utlegging (deploy.yml). Kjører bare testene som hører til de
# endrede filene, slik tests/kobling.tsv sier.
#
# Eieren, 03.10.2026 og senere: «Aldri hele testpakken automatisk … Kjør bare
# kontroller som er relevante for endrede filer.» Hele pakken (tester.yml)
# kjøres bare når en endret kodefil ikke har noen kobling.
#
#   bash bin/kontroll-endret.sh velg <base>   finn filene og testene (skriver til $GITHUB_OUTPUT)
#   bash bin/kontroll-endret.sh kjor          kjør $KOMMANDOER, stopp ved første røde
#   bash bin/kontroll-endret.sh sjekk         sjekk at koblingsfila er gyldig og testfilene finnes
set -uo pipefail
cd "$(dirname "$0")/.."
KOBLING=tests/kobling.tsv

# Leser kobling.tsv: «mønster<TAB>kommando», uten kommentarer og tomme linjer.
regler() { grep -vE '^\s*(#|$)' "$KOBLING"; }

velg() {
  local base="${1:-}"
  if [ -z "$base" ] || [[ "$base" =~ ^0+$ ]] || ! git cat-file -e "$base^{commit}" 2>/dev/null; then base=HEAD^; fi
  mapfile -t filer < <(git diff --name-only --diff-filter=ACMR "$base" HEAD)
  local -a kommandoer=() ukoblet=()
  local fil monster kommando treff
  while IFS=$'\t' read -r monster kommando; do
    [ "$monster" = "ALLTID" ] && kommandoer+=("$kommando")
  done < <(regler)
  for fil in "${filer[@]}"; do
    treff=0
    while IFS=$'\t' read -r monster kommando; do
      [ "$monster" = "ALLTID" ] && continue
      # shellcheck disable=SC2053
      if [[ "$fil" == $monster ]]; then
        treff=1
        [ "$kommando" != "-" ] && kommandoer+=("$kommando")
      fi
    done < <(regler)
    [ $treff -eq 0 ] && ukoblet+=("$fil")
  done
  # Samme kommando bare én gang, i rekkefølgen den først ble funnet.
  mapfile -t kommandoer < <(printf '%s\n' "${kommandoer[@]}" | awk 'NF && !sett[$0]++')
  local alle=false
  [ ${#ukoblet[@]} -gt 0 ] && alle=true
  echo "Endrede filer (${#filer[@]}):"; printf '  %s\n' "${filer[@]}"
  echo "Tester som kjøres:"; printf '  %s\n' "${kommandoer[@]}"
  if [ "$alle" = true ]; then echo "Uten kobling (hele pakken kjøres):"; printf '  %s\n' "${ukoblet[@]}"; fi
  if [ -n "${GITHUB_OUTPUT:-}" ]; then
    { echo "kommandoer<<SLUTT"; printf '%s\n' "${kommandoer[@]}"; echo "SLUTT"
      echo "filer<<SLUTT"; printf '%s\n' "${filer[@]}"; echo "SLUTT"
      echo "alle=$alle"; } >> "$GITHUB_OUTPUT"
  fi
}

kjor() {
  local feil=0 kommando rapport
  while IFS= read -r kommando; do
    [ -z "$kommando" ] && continue
    echo; echo "━━━ $kommando"
    rapport=$(mktemp)
    bash -c "$kommando" 2>&1 | tee "$rapport"
    local kode=${PIPESTATUS[0]}
    # Som tests/alle.sh: en test som krasjer teller som feil, også med kode 0.
    if [ "$kode" -ne 0 ] || grep -qE '(PHP )?(Fatal error|Parse error|Uncaught )|Ubehandlet feil' "$rapport"; then
      echo "✗ $kommando"; feil=1
    fi
    rm -f -- "$rapport"
  done <<< "${KOMMANDOER:-}"
  [ $feil -eq 0 ] && echo && echo "Alle koblede tester er grønne."
  return $feil
}

sjekk() {
  local feil=0 monster kommando fil
  while IFS=$'\t' read -r monster kommando; do
    if [ -z "$monster" ] || [ -z "$kommando" ]; then echo "✗ ugyldig linje i $KOBLING (mangler tab): $monster"; feil=1; continue; fi
    [ "$kommando" = "-" ] && continue
    for fil in $kommando; do
      case "$fil" in tests/*|bin/*) [ -f "$fil" ] || { echo "✗ $KOBLING peker på $fil, som ikke finnes"; feil=1; } ;; esac
    done
  done < <(regler)
  [ $feil -eq 0 ] && echo "Koblingsfila er gyldig."
  return $feil
}

case "${1:-}" in
  velg) velg "${2:-}" ;;
  kjor) kjor ;;
  sjekk) sjekk ;;
  *) echo "Bruk: $0 velg <base> | kjor | sjekk"; exit 2 ;;
esac
