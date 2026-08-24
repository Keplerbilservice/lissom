# Sluttrapport — 24. august 2026

Hva som ble gjort denne dagen, hva som er prøvd i praksis, og hva som står
igjen. `docs/STATUS.md` er fortsatt oversikten over hele systemet; denne fila
er regnskapet for én arbeidsdag.

**Kort sagt:** 22 endringer er lagt ut. Det meste av dem handler om det samme:
knapper, felter og skjermer som så ut som de virket, men ikke gjorde noe.
Nettsiden viste ingen feil, og det var nettopp problemet.

---

## 1. Dette må du gjøre selv

Ingenting av det som ble bygget i dag virker på lissom.no før dette er gjort.

**1. Kjør migreringene 032–042.** Admin → Oversikt → Vedlikehold, eller
`/api/migrer.php?kjor=ja` innlogget som admin. Uten dem:

| Migrasjon | Hva som mangler uten den |
|---|---|
| 032, 034 | Medlemskapstekstene står tomme i admin |
| 033 | Noen innholdsnøkler peker feil |
| 035 | Disksalg kan ikke registreres |
| 036 | Oppsett for e-post og SMS kan ikke lagres |
| 037 | Plassene på kursene, og at Medlemsfrokost er strøket |
| 038 | Medlemschatten har ingen tabell — chatten er død |
| 039 | Monica kan ikke svare på henvendelser |
| 040 | Gavekort kan ikke brukes som betaling |
| 041 | Gavehilsen på gavekort lagres ikke |
| 042 | Kursholdere og timene deres finnes ikke |

Skjermene sier fra hver for seg når migrasjonen mangler, så du ser hvilken det
gjelder hvis noe står tomt.

**2. Bytt databasepassordet.** Det ble limt inn i en samtale under oppsettet.
Vipps-nøklene ble byttet 22. august; databasepassordet står igjen.

**3. Vipps.** Salgsenhet `1142801` har fortsatt ikke tilgang til
ePayment-produktet, og svarer 403. Det blokkerer booking, betaling og hele
gavekortflyten. Ingen kodeendring hjelper — dette må løses med Vipps. Vipps
Recurring (månedstrekk på medlemskap) er et eget produkt og heller ikke
godkjent ennå.

---

## 2. Hva som ble gjort

### Sikkerhet og deling i sosiale medier

Sikkerhetsrapporten ga 91/100, med ett avvik: manglende
Content-Security-Policy. Den ligger nå i `.htaccess` og er prøvd mot alle
sider og adminskjermer.

Facebook viste tom forhåndsvisning når noen delte lissom.no. Tittel,
beskrivelse og delingsbilde lå bare i skriptet, og roboter kjører ikke skript;
de står nå i selve HTML-en, i begge filene. Selve årsaken var likevel en annen:
en omskrivingsregel serverte `.jpg`-adressen som webp, og Facebook godtar ikke
webp som delingsbilde. Robotene er nå unntatt fra den regelen, og bildet har
fått sin egen adresse. Dette tok tre forsøk, og de to første var gjetninger —
det tredje kom først etter at jeg satte opp en ekte Apache lokalt med det ekte
`.htaccess` og kunne måle hva som faktisk ble sendt ut.

SEO-skjermen i admin målte feil ting og ga 24/100 for en side som var i orden.
Den teller riktig nå.

### E-post og SMS

Kan settes opp fra **Markedsføring → E-post og SMS**. Skjermen sier selv om
e-post går over SMTP eller over serverens `mail()`, og en knapp sender en
testmelding og forteller hva som faktisk skjedde. Står nøklene i `secrets.php`,
gjelder fila foran, og skjermen sier at feltet er låst i stedet for å la deg
skrive i noe uten virkning. Vipps-nøkler og databasepassord kan ikke settes
herfra — det er prøvd.

Én feil ble funnet og rettet underveis: å lagre én innstilling slettet de
andre.

### Kurs, plasser og datoer

Alle planlagte kurs har 12 plasser, dreiekurs 8, drop-in 8, Glasurkveld 12.
Medlemsfrokost er strøket. Kapasiteten settes aldri lavere enn antall som
allerede har betalt — en økt med ni påmeldte blir stående på ni.

«Rediger» ligger nå på hver eneste datorad, ved siden av «Legg til deltaker».
Før hadde bare noen av dem det.

### Chat og henvendelser

Medlemschatten gikk bare én vei og varslet ikke. Nå går den begge veier, og
Monica kan svare. Meldinger kan slettes, men bare dine egne — det håndheves på
serveren, ikke i nettleseren.

Henvendelser fra nettsiden kunne ikke besvares fra admin. Nå kan de det, svaret
går på e-post (SMS bare hvis det ikke finnes e-postadresse), spørsmålet siteres
i svaret, og medlemmet ser svaret på Min side, der de skrev.

To lister over henvendelser ble slått sammen til én. Å åpne en henvendelse i
admin spratt opp kundeskjemaet — en navnekollisjon i koden min.

### Tekst som kan redigeres

Paint on Pots, Kontakt, Gavekort og Vilkår kan nå skrives om fra admin. Teksten
som sto der er lagt inn som utgangspunkt, så ingenting forsvant.

Skrivefeilene du meldte er rettet: «fellehylle» → felleshyllene, punktum etter
«fat», og Paint on Pots-teksten sier nå «Velg et produkt fra vårt Paint on
Pots-sortiment». Ordensregelen om at døra låser seg selv er fjernet — det
stemte ikke; nå står det at siste person ut har ansvar for at døra er låst.

### Gavekort

Kunne kjøpes, men ikke brukes. Nå kan de brukes som betaling, i kassa og på
nett. Beløpet trekkes på serveren med en betingelse som gjør at samme kort
ikke kan trekkes to ganger, selv om Vipps melder fra flere ganger. Kort som
går i null merkes brukt. Gavehilsen kan skrives på et gavekort, og verkstedet
varsles når noen kjøper et.

### Knapper og felter som ikke gjorde noe

Dette er dagens største enkeltpost, og den ubehageligste.

* **15 felter** tok imot det du skrev og kastet det ved lagring.
* **9 knapper** beskrev en handling, viste en kvittering og lukket seg igjen
  uten å gjøre noe.
* **«Si opp abonnementet»** endret bare medlemmets egen nettleser. Verkstedet
  fikk aldri vite at noen hadde sagt opp, og avtalen i Vipps løp videre.
  Endepunktet på serveren hadde ligget der hele tiden. «Angre» lovet at alt
  fortsatte som før; en stoppet Vipps-avtale kan ikke startes igjen
  automatisk, og dialogen sier det nå og viser hvor man tar kontakt.

Vaktskriptet `bin/skjemasjekk.mjs` fant ikke felter uten *noen* binding i det
hele tatt — det hoppet over dem. Det er skrevet om, og fant da 15 problemer i
ett jafs. Tre slike skript kjøres nå ved hver endring:

```
bin/knappesjekk.mjs     370 bindinger i malen, 370 har en verdi
bin/skjemasjekk.mjs     238 felter i malen, 238 er koblet opp
bin/innholdssjekk.mjs   199 av 199 felter styrer noe på nettsiden
```

### Helsesjekken

`/api/status.php` så etter en tabell som ikke finnes lenger (`checkins` mot
`check_ins`) og manglet tre av de nye. Den ser etter riktige tabeller nå, og
lister også opp tabeller som ikke er i bruk.

---

## 3. Hvordan det er prøvd

Alt under er kjørt, ikke resonnert fram.

```
php tests/backend.php    107 av 107 grønne
tests/flyt.sh             10 av 10 grønne
```

Frontend kjøres i en ekte nettleser (Chromium) mot de ekte API-ene og en ekte
MariaDB. Oppsigelsen ble for eksempel prøvd slik: knappen ga en POST til
`/api/medlemskap.php`, `subscriptions` gikk til «stoppet», `members` til
«oppsagt», og `medlemsavtale_sagt_opp` havnet i revisjonsloggen. Kortet snudde
til «Sagt opp» uten omlasting, og sto slik etter omlasting også. Prøver du å si
opp to ganger, svarer serveren «Du har ingen løpende avtale».

E-post er prøvd ende til ende mot en lokal SMTP-mottaker, ikke bare til køen.

Tre av testene falt i dag fordi de lånte data fra katalogen: en booket
medlemsfrokosten som du ba meg stryke, en lånte en økt fra Paint on Pots og
gikk «grønn» på at det ikke fantes noen, og medlemskapstestene sto på det gamle
navnet «30 timer». De lager sin egen rigg nå, og leser fasiten fra basen.

### Dette er *ikke* prøvd

* **Alt som går gjennom Vipps.** 403-en gjør at booking, betaling, gavekort som
  betalingsmiddel og stopp av medlemsavtale ikke kan kjøres mot ekte Vipps.
  Logikken rundt er prøvd; selve kallet er det ikke.
* **Den publiserte siden.** Jeg når ikke lissom.no herfra — utgående trafikk
  dit er sperret i miljøet mitt. Alt er prøvd lokalt mot den samme koden og det
  samme `.htaccess`, men den endelige kontrollen på lissom.no må gjøres av deg.
* **Facebook-forhåndsvisningen** kan bare bekreftes ved å dele lenken. Del
  gjerne `https://lissom.no/?1` (tallet tvinger Facebook til å hente på nytt).

---

## 4. Det som fortsatt ikke finnes

* **Innstempling.** Timeforbruk på Min side står som «—». Et tall der hadde
  vært oppspinn.
* **Frys av medlemskap.** Gjøres manuelt under Medlemmer.
* **Månedstrekk på medlemskap** er simulert til Vipps Recurring er godkjent.
* **Kursdatoene i katalogen er satt av meg** ut fra designet. De må erstattes
  med de faktiske datoene deres.
* **Varene i butikken mangler bilde.** I den databasekopien jeg tester mot har
  ingen av de 21 varene et bilde; de vises med et standardbilde. Det finnes
  ingen opplasting for varebilder i admin ennå.
* **Paint on Pots står med 14 plasser i basen.** Du sa 12 på kurs og 8 på dreiekurs;
  Paint on Pots er registrert som event, og ble derfor ikke rørt. Si fra om
  den også skal til 12.
* **GA4 og Google Business Profile peker fortsatt på `ny.lissom.no`.**
  Sitemap er ikke meldt inn til Search Console.
* **Den gamle WordPress-installasjonen** i `public_html` deler konto med
  betalingsdataene og bør fjernes — men `public_html/ny.lissom.no` må spares,
  den nye siden ligger inni den mappa.
* **Tabellene `checkins` og `hour_usage`** er ikke i bruk. De slettes ikke uten
  at du sier fra — data slettes ikke på min gjetning.
* **«Vipps-flyt»-skjermen** nås ikke fra menyen, bare fra `/betaling`. Den
  venter på at du bestemmer om den skal være der.

---

## 5. Det jeg trenger svar på

1. Skal Paint on Pots ned til 12 plasser?
2. Skal `checkins` og `hour_usage` slettes?
3. Skal «Vipps-flyt»-skjermen bli værende, eller ut?
4. Skal jeg legge inn de ekte kursdatoene, eller gjør dere det i admin?

De øvrige åpne spørsmålene — samelue på logoen, gjestebooking uten innlogging,
lagerstyring i butikken — står som før i `docs/STATUS.md` under «Må tas stilling
til».
