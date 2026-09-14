# Google Ads: ryddingen av «Lissom søk» (del B6)

Laget 14. september 2026. Eieren ba om «vis meg før du lagrer» — og Ads-fanen
frøs i nettleserøkten. Derfor ligger endringene som filer for **Google Ads
Editor**: du importerer, ser hver endring i lista før du trykker «Post», og
angrer med ett klikk om noe ser galt ut. Budsjettet røres ikke.

Ads Editor er gratis: https://ads.google.com/home/tools/ads-editor/

## Slik gjør du det (ca. 15 minutter)

1. Åpne Ads Editor → «Hent nylige endringer» (så den har kontoen slik den er nå).
2. **Konto → Importer → Fra fil** → velg `1-annonsegrupper-sokeord-annonser.csv`.
   Editor viser fem nye annonsegrupper under «Lissom søk», med søkeord i
   setningsmatch og én responsiv søkeannonse hver. Se gjennom — «Fullfør og
   se gjennom endringer».
3. Importer `2-negative-sokeord.csv` — 22 negative ord på kampanjen (tre,
   tredreiing, oslo, gratis, jobb, kjøpe leire …). Det er disse som stopper
   «tredreiekurs» og «håndlaget keramikk oslo».
4. Importer `3-leireord-pauses.csv` — de ni leire-/salgsordene i den gamle
   «Annonsegruppe 1» settes på pause. Dere selger ikke leire til folk utenfra.
5. **Sett hele «Annonsegruppe 1» på pause** (velg gruppa → Status → Paused).
   De nye gruppene overtar; den gamle med bred match ville ellers konkurrert
   med dem om de samme søkene. Ikke slett den — historikken er verdt å ha.
6. **Post** (knappen oppe til høyre). Endringene går live på minutter.

## Det som må gjøres i ads.google.com (finnes ikke i fil)

- **Infomeldinger** (Innholdselementer → + → Infomelding), fire stykker:
  «Alt inkludert» · «Book med Vipps» · «Gratis parkering» · «5 min fra sentrum».
- **Plassering** (Innholdselementer → + → Plassering): koble Bedriftsprofilen
  «Lissom - Keramikk & Håndverk AS». Da vises adressen og stjernene under
  annonsen.
- **Sted** (kampanjen → Innstillinger → Steder): Vestfold fylke, og en
  radius på 30 km rundt Tønsberg. Ikke hele Norge. (Ikke sjekket 13. sep —
  fanen frøs — så se hva som står der nå.)
- **Budstrategi**: la «Maksimer antall klikk» stå til kontoen har 15–30
  konverteringer (booking_fullfort + forespurt_kontakt er importert fra
  GA4). Bytt da til «Maksimer antall konverteringer».

## Hva filene inneholder

| Annonsegruppe | Lander på | Søkeord (setningsmatch) |
|---|---|---|
| Dreiekurs | /kurs/dreiekurs | dreiekurs, dreiekurs tønsberg, keramikkurs, keramikk kurs tønsberg, … (11) |
| Plateteknikk og håndbygging | /kurs | plateteknikk kurs, håndbygging keramikk, lage bolle keramikk, … (8) |
| Paint on Pots | /paint-on-pots | paint on pots, paint on pots tønsberg, male keramikk selv, … (8) |
| Medlemskap | /medlemskap | keramikkverksted tønsberg, leie dreieskive, keramikk fellesverksted, … (6) |
| Bedrift og teambuilding | /bedrift | teambuilding tønsberg, utdrikningslag tønsberg, kopper med logo, … (8) |

Hver annonse har 10 overskrifter (maks 30 tegn) og 4 beskrivelser (maks 90),
sjekket av skriptet som laget filene. Ingen priser i annonsene — de endres
på nettsiden.

Filene er UTF-8 med BOM og komma som skilletegn, slik Ads Editor vil ha dem.
