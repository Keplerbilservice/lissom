# Slik jobber vi på Lissom

Dette er eierens egne arbeidsregler. De gjelder i hver økt, ikke bare den
de ble sagt i.

## Spørsmål

- **Ett spørsmål om gangen.** Still det, vent på svar, og gå videre.
  Ikke samle flere spørsmål i én melding, og ikke still et nytt før det
  forrige er besvart. (Eieren, 4. september 2026.)
- Maks én linje per spørsmål, forslag, risiko og forklaring.
- Er noe uklart: stopp den endringen og spør. Ikke gjett.
- Bruk formatet ved nye funn:
  `Funnet:` / `Påvirkning:` / `Forslag:` / `Spørsmål:` — én linje hver.

## Tekst

- **Aldri legg til tekst uten at eieren vet om det.** Alt en kunde eller et
  medlem kan lese — knappenavn, overskrifter, hjelpetekster, e-poster,
  kvitteringer, feilmeldinger — skal vises og godkjennes før det bygges.
  Gjelder også korte navn på noe som alt finnes. (Eieren, 5. september 2026.)
- Er teksten uunngåelig for å få noe til å virke: vis den ordrett i svaret,
  og si at den er ny.

## Endringer

- Aldri gjør mer enn det som er bedt om.
- Ikke fjern funksjonalitet uten godkjenning.
- Ikke endre en arbeidsflyt uten å forklare konsekvensen først.
- Ikke lag en parallell løsning når funksjonen finnes. Se etter den først.
- Større endringer skal vises visuelt og godkjennes med «GO» før de bygges.
- Godkjente endringer gjøres globalt, på alle relevante steder — ikke bare
  der feilen ble oppdaget.

## Kontroll

- Ikke påstå at noe er testet hvis det ikke er testet.
- Mål i nettleseren, ikke bare i koden. En test kan være grønn av feil grunn.
- Test på PC, nettbrett og mobil.
- Behold historikk, sporbarhet og endringslogg.

## Publisering

- «Publiser» betyr: merge til `main`. Deployen går av seg selv.
- Migrasjoner kjøres ikke automatisk — eieren trykker **⚙ Kjør oppdateringer**
  i admin etterpå.
- Claude har **ikke** tilgang til produksjonsdatabasen og kan ikke rette
  data der. Bare kode.

## Priser

- Ingen hardkodede priser. Kr 500 er kr 500 — alt annet fjernes.

## Sikkerhetskopi

- Gjenopprettingspunkt før den store adminomleggingen:
  `sikkerhetskopi/2026-09-04-for-adminomlegging` (= deploy 552).
