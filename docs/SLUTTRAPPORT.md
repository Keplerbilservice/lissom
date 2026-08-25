# Sluttrapport — Lissom Keramikk & Håndverk

**Skrevet 25. august 2026.** Erstatter versjonen fra 24. august, som hadde fem
seksjoner der du hadde bedt om tretten.

Rapporten følger de tretten punktene du ba om, i den rekkefølgen du satte dem.
Testresultatene i punkt 10 er kjørt på nytt samme dag som rapporten er skrevet
— de er ikke hentet fra hukommelsen.

Én ting må stå først, fordi den farger alt under: **jeg kommer ikke til
lissom.no herfra.** Utgående trafikk går gjennom en proxy som svarer 403 på
det domenet. Alt som står som testet, er testet mot den ekte koden og en ekte
MariaDB-base lokalt, med den samme fila som ligger ute. Det er ikke det samme
som å ha sett det på ditt nettsted. Punkt 11 sier hva det betyr i praksis.

---

## 1. Hvorfor endringer i admin ikke slo ut på nettsiden

Kort svar: **admin var tegnet, ikke koblet.**

Fila du fikk fra designverktøyet var én stor React-side der alle skjermene lå
ferdig tegnet, admin med. Men verdiene i dem var skrevet inn i fila som fast
tekst. «47 aktive medlemmer», «3 plasser igjen», «Ovnen går natt til torsdag»
— alt sammen sto som bokstaver i kildekoden, ikke som noe hentet fra en
database. Det fantes ingen database.

Det ga tre forskjellige feil, og de så like ut utenfra:

**a) Skjemaer uten mottaker.** Feltene hadde ingen `value` og ingen
`onChange`, og Send-knappen hadde ingen `onClick`. Du skrev, trykket Send, og
skjemaet sto der som før. Ingenting gikk noe sted, og ingenting sa fra.

**b) Lagring i din egen nettleser.** Noen skjermer lagret riktignok — i
`localStorage`. Medlemschatten og gaveskjermen var slik: Monica så sin egen
melding på sin egen skjerm, og ingen andre fikk noen ting. Det så ut som det
virket, helt til noen andre logget inn.

**c) To steder for det samme.** Prisene på medlemskap sto både under Kurs og
medlemskap og som fast tekst under Nettsiden → Innhold. Ordensreglene sto to
steder. Kortet «Fra medlemmene» leste samme tabell som lista over det. Da er
ett av dem alltid feil, og du kan ikke vite hvilket.

Løsningen var ikke å lappe skjermene, men å gi dem noe å snakke med: en
database, et sett endepunkter, og at hver skjerm leser og skriver til den ene
sannheten. Der to steder gjorde det samme, står det nå ett.

---

## 2. Hvilke filer som ble endret

Siden den siste fila du lastet opp (`f06a038`, 16. august) er det **246
commits**. Utenom bilder og tredjepartskode er **154 filer** endret, med
34 559 linjer lagt til og 20 319 fjernet.

| Område | Antall | Hva det er |
|---|---|---|
| `lissom-2108.html` | 1 fil, 22 353 linjer | Hele nettsiden og hele adminpanelet |
| `api/*.php` | 36 | Offentlige endepunkter — katalog, booking, butikk, Min side |
| `api/admin/*.php` | 29 | Adminendepunkter — kurs, medlemmer, økonomi, beskjeder |
| `app/` | 20 | Database, sesjon, booking, varsler, Vipps, konfigurasjon |
| `db/migrations/*.sql` | 45 | Databaseendringer, nummerert og kjørbare i rekkefølge |
| `bin/` | 8 | Cron-jobber og de tre kontrollskriptene |
| `tests/` | 3 | Testsuiten og en Vipps-etterligning |
| `docs/` | 3 | Denne rapporten, statusfila og oppsettet |
| Nye HTML-sider | 3 | `personvern.html`, `vilkar.html`, `e-post-signatur.html` |

---

## 3. Hva som endret seg i hver

**`lissom-2108.html`** — nettsiden og admin. Skjermene er de samme; det som
er byttet ut er hvor tallene og tekstene kommer fra. Alle lister leser nå fra
serveren. Alle skjemaer har verdi, endringshåndtering og en knapp som gjør noe.
Tre kontrollskript passer på at det holder seg slik (punkt 10).

**`api/`** — alt som ikke skal ligge åpent, ligger utenfor `public_html` og
nås gjennom disse. Hvert endepunkt krever riktig metode, sjekker at kallet kom
fra vår egen side, og svarer med samme feilform.

**`api/admin/`** — samme regler, pluss at alle krever admin-sesjon og svarer
404 til andre. De bekrefter ikke engang at de finnes.

**`app/`** — det de andre bygger på: databasetilkobling, sesjoner,
bookinglogikken med kapasitet og reservasjoner, varselkøen, Vipps-kallene og
lesing av nøkler.

**`db/migrations/`** — én fil per endring, nummerert. Kjøres fra Admin →
Oversikt → Vedlikehold, som leser `migrations`-tabellen mot filene på serveren
og sier hva som mangler. Migrasjonene tåler å kjøres om igjen der det er mulig
— lærdommen fra 22. august, da en migrering stoppet halvveis og Paint on Pots
sto til én krone i produksjon.

**`bin/`** — `paaminnelser`, `vedlikehold`, `varsler`, `betalinger` som
cron-jobber, og `innholdssjekk.mjs`, `knappesjekk.mjs`, `skjemasjekk.mjs` som
kontroller.

**`tests/`** — 113 sjekker i 19 grupper, med egne testdata.

---

## 4. Funksjoner som ble gjenbrukt

Regelen har vært: finnes funksjonen, skal den brukes — ikke kopieres.

| Ny vei inn | Bruker fra før |
|---|---|
| Manuell påmelding i admin | `Booking::ledigePlasser()`, samme `bookings`-tabell og samme deltakerliste |
| Uttak butikk (salg over disk) | Samme `orders`/`order_lines` og samme betalingsrad som en nettordre |
| Ny registrering | `handling=meld-inn` på medlemmer og `handling=legg-til` på påmelding — begge fantes |
| Oppsigelse av medlemskap | `medlemskapKall()`, som allerede håndterte de andre medlemskapsendringene |
| Gaveskjemaet under Deltakere | Nøyaktig samme skjema som under Medlemmer — ett skjema, to steder det kan åpnes |
| Kursbevis i registeret under Nyttig info | Samme `pameldingKall({handling:'bevis'})` som personruta |
| Kategoriknappene på kurssida | Samme `kursIKategori()` som lista under dem, så knapp og liste ikke kan svare forskjellig |
| Beskjed til én person fra personruta | `til: "en"` i beskjedendepunktet, som fantes |
| Alle varsler | `Varsel::epost()` og `Varsel::sms()` — én kø, ett sted å se hva som ikke kom fram |

---

## 5. Nye funksjoner

Det som ikke fantes fra før, og hvorfor:

| Ny | Hvorfor |
|---|---|
| Hele databasen (45 migrasjoner) | Det fantes ingen |
| `Booking::reserverOgBetal()` med låst plasstelling | To personer kunne booke den siste stolen samtidig |
| Varselkø med gjenforsøk | E-post som feilet forsvant uten spor |
| Innholdssystem (`content_blocks`) | All tekst lå fast i designfila |
| Kursbevis, og retting/tilbaketrekking av det | Fantes ikke |
| Medlemschat som virker begge veier | Lagret i nettleseren til den som skrev |
| Svar på henvendelser | Kunne bare merkes som besvart |
| Gavekort som betalingsmiddel i kassa | Feltet fantes, men var ikke koblet |
| Gaver fra verkstedet til medlemmene | Lagret i nettleseren til den som ga den |
| Kursholdere og timene deres | Fantes ikke |
| Bilder på kurs, og opplasting av egne | Fantes ikke |
| Tidsfilter på kurssida (dagtid/kveldstid/helg) | Bedt om 25. august |
| Tre kontrollskript | For at det ikke skal skje igjen |

---

## 6. Hvordan kalenderen og Program henger sammen

De leser **samme tabell**, `course_sessions`, gjennom hver sin vei — og det er
med vilje, fordi de svarer på to forskjellige spørsmål.

**Kalenderen** (offentlig, `/kalender`) leser `/api/kurs.php`. Hver dato kommer
med `startUtc` — det rå tidsstempelet — i tillegg til den ferdigskrevne norske
datoen. Kalenderen grupperer på ISO-ukenummer regnet i norsk tid. Uker uten noe
hoppes over, og er det tomt framover, sier siden det i stedet for å vise sju
tomme dager.

**Program** (admin, på Oversikt) leser `/api/admin/oversikt.php`, som gir
`kommende` med `startTid`, `pameldte`, `kapasitet` og `type`. Program filtrerer
bort drop-in-timer uten påmeldte — en åpen dør ingen har meldt seg på er ikke
en avtale — mens kurs og samlinger står uansett, fordi de er satt opp.

Konsekvensen som betyr noe for deg: **legger du inn en dato i admin, dukker
den opp begge steder uten videre.** Det er ingen synkronisering å huske på.
Avlyser du en dato, forsvinner den fra begge.

---

## 7. Hvordan medlemskap, priser, tekst og bilder styres fra admin

**Medlemskap og priser.** Planene ligger i `membership_plans` og redigeres
under Kurs og medlemskap → Medlemskap. Prisen leses samme sted av
medlemskapssiden, kassa og trekket. Prislistene som sto som fast tekst under
Nettsiden → Innhold er fjernet — de var et annet sted å endre den samme prisen,
og da er ett av dem alltid feil.

**Kursprisen** ligger på kurset. Endrer du den i admin, endrer den seg i
katalogen, på kortet, i bookingen og i det Vipps blir bedt om. Testet i dag:
Kurs boller satt til 1 234 kroner i admin, nettsiden viste «kr. 1 234,-», satt
tilbake til «kr. 1 490,-» (punkt 10, funksjonstest B).

**Tekst.** 199 felter på nettsiden er koblet til `content_blocks` og redigeres
under Nettsiden → Innhold. `bin/innholdssjekk.mjs` teller dem og sier fra hvis
et felt ikke lenger styrer noe.

**Bilder.** Kurs kan ha tre bilder, valgt i steg 3 i kursveiviseren
(migrasjon 044). Egne bilder kan lastes opp i admin; de lagres utenfor det som
publiseres, slik at de overlever neste utlegging.

---

## 8. Hvordan Ny registrering virker

Skjermen setter ikke opp noe nytt. Den bruker de to kallene som fantes fra før,
og det nye er veien dit.

1. **Søk først.** Du skriver navn, e-post eller telefon, og ser med én gang om
   personen finnes. Dette er hele poenget: uten det havnet den samme personen
   inn to ganger, og da stemmer ingen av radene.
2. **Velg hva registreringen er.** Medlem, eller deltaker på et kurs.
3. **Medlem** → `handling=meld-inn` på `api/admin/medlemmer.php`. Finnes
   personen fra før, beholdes kontoen — historikk og kursbevis følger med.
   Det opprettes ingen Vipps-avtale; en manuell innmelding gjøres opp slik dere
   avtaler.
4. **Deltaker** → `handling=legg-til` på `api/admin/pamelding.php`. Det blir en
   helt vanlig booking: den opptar en plass, teller i kapasiteten og står på
   deltakerlista. Du velger betalingsmåte, og «Gratis» er null kroner uansett
   hva som står i beløpsfeltet.
5. Er datoen full, stoppes du ikke — men du får beskjed om at den blir
   overbooket, så det ikke skjer uten at noen ser det.

---

## 9. Hvordan Uttak butikk virker

Ikke alle betaler på nett. Noen står i verkstedet med en kopp i hånda.

Salget blir **en helt vanlig ordre**: samme `orders`-tabell, samme
`order_lines`, samme betalingsrad som en nettbestilling. Det er derfor det
kommer med i økonomitallene uten videre arbeid, og derfor du slipper å føre en
liste ved siden av.

1. Du velger varer og antall.
2. Du velger hvordan det ble gjort opp — kontant, Vipps i verkstedet, faktura.
3. Ordren lagres som betalt, med måten skrevet i klartekst.
4. Er varen en medlemsvare, går oppgjøret til medlemmet som laget den, og det
   står på medlemssalgene.

---

## 10. Resultatet av hver enkelt test

Alt under er kjørt **25. august 2026**, mot den fila som ligger ute og en ekte
MariaDB-base med ekte data.

### 10a. Testsuiten — 113 av 113

`php tests/backend.php`, 19 grupper. Alle gikk gjennom:

Katalog · Kapasitet · Medlem og sesjon · Booking uten Vipps (gratis
medlemsarrangement) · Betaling markeres som betalt, én gang · Kapasitet teller
reservasjoner · Utløpt reservasjon frigir plassen · Overbooking avvises · Norsk
dato og kroner · Ratebegrensning · Medlemskap er ikke det samme som innlogging ·
Gaver til medlemmene · Kursbevis · Brukernavn og passord · Innstempling og timer ·
Grupperabatt · Medlemskap · Timer per måned · Plassen på den siste stolen

Suiten bygger sine egne testdata og leser forventningene fra basen, slik at en
lovlig endring i admin ikke får testene til å ryke.

### 10b. Kontrollskriptene

| Skript | Resultat |
|---|---|
| `bin/knappesjekk.mjs` | 386 av 386 bindinger har en verdi. Ingen knapper uten funksjon. |
| `bin/skjemasjekk.mjs` | 244 av 244 felter er koblet opp. Ingen felter uten funksjon. |
| `bin/innholdssjekk.mjs` | 199 av 199 innholdsfelter styrer noe på nettsiden. |

### 10c. Side for side, i tre bredder

44 sider lastet i **desktop 1400 px**, **tablet 834 px** og **mobil 390 px** —
132 sidelastinger. For hver: at siden faktisk tegner seg, at det ikke kommer
JavaScript-feil eller feil i konsollen, og at siden ikke kan skyves sideveis.
`k` er synlige knapper, `f` er synlige skjemafelter.

| Side | H1 som vises | Desktop 1400 | Tablet 834 | Mobil 390 |
|---|---|---|---|---|
| `/` | Skap noe med hendene. | OK · 35k 0f | OK · 34k 0f | OK · 32k 0f |
| `/kurs` | Finn en dato som passer | OK · 20k 0f | OK · 21k 0f | OK · 20k 0f |
| `/events` | Date Night, Paint on Pots og Sip & Clay | OK · 13k 0f | OK · 14k 0f | OK · 13k 0f |
| `/drop-in` | Drop-in i verkstedet | OK · 19k 3f | OK · 20k 3f | OK · 19k 3f |
| `/medlemskap` | Verkstedet er ditt, døgnet rundt | OK · 16k 0f | OK · 17k 0f | OK · 16k 0f |
| `/butikk` | Kopper, boller og fat | OK · 31k 0f | OK · 32k 0f | OK · 31k 0f |
| `/om-oss` | Keramikkverkstedet vårt | OK · 18k 0f | OK · 19k 0f | OK · 18k 0f |
| `/kontakt` | Kontakt oss | OK · 12k 0f | OK · 13k 0f | OK · 12k 0f |
| `/gavekort` | Gavekort på keramikkurs | OK · 14k 4f | OK · 15k 4f | OK · 14k 4f |
| `/bedrift` | Samle gjengen rundt dreieskiven. | OK · 13k 0f | OK · 14k 0f | OK · 13k 0f |
| `/paint-on-pots` | Mal din egen keramikk | OK · 6k 0f | OK · 7k 0f | OK · 6k 0f |
| `/kalender` | Alt som skjer, uke for uke | OK · 8k 0f | OK · 9k 0f | OK · 8k 0f |
| `/nyttig-info` | Kunnskapsbiblioteket | OK · 11k 0f | OK · 12k 0f | OK · 11k 0f |
| `/nyheter` | Nyheter og guider | OK · 2k 0f | OK · 3k 0f | OK · 2k 0f |
| `/nyttig-info/brennetabell` | Cone til grader | OK · 3k 0f | OK · 4k 0f | OK · 3k 0f |
| `/nyttig-info/medlemsinfo` | Informasjon til medlemmer | OK · 3k 0f | OK · 4k 0f | OK · 3k 0f |
| `/nyttig-info/trivselsregler` | Trivselsregler | OK · 3k 0f | OK · 4k 0f | OK · 3k 0f |
| `/kasse` | Handlekurv | OK · 15k 4f | OK · 16k 4f | OK · 15k 4f |
| `/logg-inn` | Logg inn | OK · 4k 2f | OK · 4k 2f | OK · 4k 2f |
| `/personvern` | Personvern | OK · 11k 0f | OK · 12k 0f | OK · 11k 0f |
| `/vilkar` | Vilkår og angrerett | OK · 11k 0f | OK · 12k 0f | OK · 11k 0f |
| `/min-side` | Hei, Testadmin | OK · 34k 11f | OK · 35k 11f | OK · 34k 11f |
| `/admin` | God dag, Testadmin | OK · 60k 19f | OK · 60k 19f | OK · 60k 19f |
| `/admin/innhold` | Innhold | OK · 46k 0f | OK · 46k 0f | OK · 46k 0f |
| `/admin/medlemskap` | Medlemskap | OK · 26k 0f | OK · 26k 0f | OK · 26k 0f |
| `/admin/ny-registrering` | Ny registrering | OK · 18k 9f | OK · 18k 9f | OK · 18k 9f |
| `/admin/uttak` | Uttak butikk | OK · 37k 2f | OK · 37k 2f | OK · 37k 2f |
| `/admin/kurs/alle` | Kurs og medlemskap | OK · 142k 0f | OK · 142k 0f | OK · 142k 0f |
| `/admin/kurs` | Kurs og medlemskap | OK · 22k 0f | OK · 22k 0f | OK · 22k 0f |
| `/admin/pameldte` | Påmeldte | OK · 29k 0f | OK · 29k 0f | OK · 29k 0f |
| `/admin/deltakere/alle` | Deltakere | OK · 25k 1f | OK · 25k 1f | OK · 25k 1f |
| `/admin/deltakere` | Kursdeltakere | OK · 20k 0f | OK · 20k 0f | OK · 20k 0f |
| `/admin/medlemmer/alle` | Medlemmer | OK · 37k 4f | OK · 37k 4f | OK · 37k 4f |
| `/admin/medlemmer` | Medlemmer | OK · 21k 0f | OK · 21k 0f | OK · 21k 0f |
| `/admin/brukere` | Brukere | OK · 18k 5f | OK · 18k 5f | OK · 18k 5f |
| `/admin/drop-in` | Drop-in | OK · 17k 3f | OK · 17k 3f | OK · 17k 3f |
| `/admin/butikk` | Butikk | OK · 98k 14f | OK · 98k 14f | OK · 98k 14f |
| `/admin/okonomi` | Økonomi i august | OK · 16k 0f | OK · 16k 0f | OK · 16k 0f |
| `/admin/beskjeder` | Beskjed til medlemmer | OK · 23k 3f | OK · 23k 3f | OK · 23k 3f |
| `/admin/oppskrifter` | Oppskrifter | OK · 13k 0f | OK · 13k 0f | OK · 13k 0f |
| `/admin/seo` | Søkemotoroppsett | OK · 51k 14f | OK · 51k 14f | OK · 51k 14f |
| `/admin/markedsforing` | Tavle | OK · 30k 0f | OK · 30k 0f | OK · 30k 0f |
| `/admin/nyttig` | Nyttig info | OK · 29k 7f | OK · 29k 7f | OK · 29k 7f |
| `/admin/varsler` | E-post og SMS | OK · 16k 11f | OK · 16k 11f | OK · 16k 11f |

**Avvik: ingen.** Ingen 404, ingen JavaScript-feil, ingen sideveis scroll i
noen bredde. `/nyheter` har mindre tekst på tablet og mobil enn på desktop —
det er menylinja som legger seg bak hamburgermenyen. Selve siden er lik, og
viser den riktige tomteksten «Ingenting her ennå» fordi ingen artikler er
publisert i testbasen.

### 10d. Funksjon for funksjon

| # | Funksjon | Resultat |
|---|---|---|
| 1 | Katalogen kundene ser | 13 kurs, 14 datoer — ekte data |
| 2 | Admin leser samme katalog | 15 kurs (to interne, som ikke skal ut) |
| 3 | Butikken | 21 varer fra basen |
| A | Prisendring i admin → nettsiden | Kurs boller satt til 1 234 kr i admin, nettsiden viste «kr. 1 234,-» |
| B | Prisen satt tilbake | «kr. 1 490,-», databasen 149000 øre |
| 4 | Medlemskapsplaner | Prøv Lissom, Basis 30, Årsmedlemskap, Fri tilgang — fra basen |
| 5 | Innholdsblokker | Leveres |
| 6 | Oversikt → kommende økter | 15 økter, med `type` og `pameldte` |
| 7 | Deltakerlista | 4 påmeldinger over 16 økter |
| 8 | Kursbevisregisteret | Svarer (0 bevis — ingen gjennomførte, betalte kurs i testbasen nå) |
| 9 | Sendte beskjeder med gruppe | Svarer med gruppeskille |
| 10 | Medlemsregisteret | 4 personer |
| 11 | Personruta med historikk | Åpner, med notatfelt |
| 12 | Økonomi | Svarer med måned, periode, år, omsetning, endring |
| 13 | Helsesjekken | Svarer |

**To av dem slo først ut som feil, og begge var mine egne testfeil**, ikke feil
i koden. Jeg skriver dem opp fordi du har bedt om resultatet av hver test, ikke
bare de som gikk bra:

- *«Butikkvarer: 0»* — skriptet mitt spurte `api/produkter.php`, som ikke
  finnes. Riktig endepunkt er `api/butikk.php`, og det svarer med 21 varer.
- *«Prisendring nådde ikke fram»* — skriptet sendte bare `{id, pris}`.
  Endepunktet krever hele raden og avviste kallet med «Kurset må ha en tittel»
  — altså riktig oppførsel. Med hele raden gikk endringen gjennom, som
  linje A og B over viser.

### 10e. Enkeltfunksjoner testet i nettleser samme dag

| Funksjon | Bredder | Resultat |
|---|---|---|
| Tidsfilter dagtid/kveldstid/helg | 1400, 390 | Dagtid ga kursene fra 08:20 og 10:00; kveldstid fra 16:00, 17:00 og 18:00; helg ga lørdagsworkshopen |
| Kortet viser datoen som passer valget | 1400 | «Helg» flyttet Keramikk Workshop fra onsdag 2. sep til lørdag 5. sep, med lørdagens eget plasstall |
| Booking åpner på den lovede datoen | 1400 | Lørdag 5. sep forhåndsvalgt, begge datoene fortsatt valgbare |
| Kategoriknapper vises bare når noe er satt opp | 1400, 390 | «Paint on pots» falt ut uten datoer; «Helg» kuttet rada til Vis alle, Workshop, Date Night |
| «Rediger bevis» på deltakerraden, uten konto | 1400 | Navnet rettet fra lista og skrevet til basen |
| «Trekk beviset» / «Gi tilbake» | 1400 | Kursbevisknappen forsvant og kom igjen; teksten ble «Beviset er trukket» |
| Kursbevis for intern samling | 1400 | Sto på Min side, i personruta og i registeret; arket viste navn, kurs og dato |
| Drop-in gir ikke kursbevis, heller ikke gjettet lenke | — | 404 |
| «Knytt til kontoen» | 1400 | Gjestepåmelding knyttet, `member_id` skrevet, knappen forsvant |
| Annen info om personen | 1400, 390 | Lagret, sto i basen etterpå |
| «Send beskjed til …» | 1400 | Beskjeder åpnet med «Én mottaker» og navn, e-post og telefon fylt inn |
| Beskjeder skiller deltakere fra medlemmer | 1400 | Deltakerskjermen viste bare deltakerbeskjeden, medlemsskjermen bare medlemsbeskjeden |
| KURS i toppmenyen | 1400 | Lander på alle kursene, «Vis alle» markert |

---

## 11. Tester som ikke lot seg kjøre, og hvorfor

Dette er den viktigste seksjonen i rapporten, fordi den sier hvor grensen går.

**1. Ingenting er sett på lissom.no.** Utgående trafikk fra maskinen jeg jobber
på går gjennom en proxy som svarer **403 Forbidden** på `lissom.no`. Jeg kan
ikke laste en eneste side der. Alt over er kjørt mot den samme fila og en ekte
database lokalt. Det fanger kodefeil, men ikke noe som skyldes serveren din:
filrettigheter, PHP-innstillinger, mangler i `secrets.php`, eller at en
migrering ikke er kjørt.

**2. Ekte Vipps-betaling er aldri gjennomført.** Vipps ePayment svarer **403**
på MSN 1142801. Testene bruker en etterligning (`tests/vipps-stub.php`) som
svarer slik Vipps dokumenterer. Det beviser at koden vår gjør riktig — ikke at
Vipps godtar den. I det øyeblikket 403 blir 201, virker booking og betaling
uten videre arbeid.

**3. Vipps Recurring er ikke godkjent.** Medlemskapstrekk kan derfor ikke
prøves i det hele tatt. Oppsigelse er testet mot vårt eget endepunkt; at
trekket faktisk stopper hos Vipps, er ikke verifisert.

**4. Ekte e-post og SMS er ikke sendt.** Varsler legges i kø og markeres som
sendt av testen. Om SMTP-en din slipper dem gjennom, og om Sveve er koblet på,
ser du under Markedsføring → E-post og SMS, som sender en testmelding og sier
hva som faktisk skjedde.

**5. Facebook-deling er ikke verifisert.** `developers.facebook.com` er
blokkert av samme proxy, så delingsfeilsøkeren kunne ikke kjøres. Bildet og
taggene er lagt inn i selve HTML-en, og robotene er unntatt webp-regelen.

**6. Ekte nettlesere er ikke prøvd.** Alt er kjørt i Chromium gjennom
Playwright. Safari på iPhone og Android-nettlesere er ikke testet.

**7. Utskrift av kursbevis er ikke prøvd på papir.** Arket er A5 og laget for
utskrift, og skjermvisningen stemmer med malen. Hvordan det ser ut ut av en
faktisk skriver, vet jeg ikke.

**8. Last er ikke testet.** Ingen har prøvd hva som skjer med tjue samtidige
bookinger. Låsingen rundt den siste plassen er testet i transaksjon
(gruppa «Plassen på den siste stolen»), men ikke under press.

---

## 12. Bekreftelse på at ingenting annet ble endret

Jeg bekrefter at det ikke er gjort endringer utover det du har bedt om, med
disse forbeholdene, som du skal ha framfor en ren bekreftelse:

**a) Følgeendringer jeg har gjort uten å spørre**, fordi det du ba om ikke
virket uten dem. Hver av dem står i commit-meldingen sin:

- Da «Kurs» ble fjernet fra kategorirada, ble menypunktet KURS stående på et
  filter uten knapp. Det er endret til å lande på alle kursene — du sa ja til
  dette da jeg spurte.
- Da kursbeviset kunne trekkes, sto «Kursbevis»-knappen igjen i deltakerlista
  og svarte 404. Den fjernes nå når beviset er trukket.
- Kursbevissiden nektet ikke drop-in. Ingen liste tilbød lenken, men den lot
  seg gjette. Den gjør det ikke lenger.
- Sammenslåingen av deltakere og kontoer gjettet feil person når to kontoer
  delte telefonnummer. Den slår ikke sammen ved tvil lenger.
- Sju tekster på Min side sto uten norske tegn («Kurset har vaert», «Naermere
  enn 7 dager», «Ingen dato ennaa»). Rettet — det er tekst kunden leser.

**b) Ting jeg har fjernet, alle på din beskjed:** «Andre kjøper også» i kassa,
«Nyttig info» fra deltakerområdet, «Bilder på denne siden» i
innholdsredigeringen, det brune «Ubesvart»-feltet i sidemenyen, og
kategoriknappene Kurs, Events og Drop-in.

**c) Ting jeg har fjernet uten at du ba om det:** duplikater. Der to skjermer
gjorde det samme og bare den ene virket, står det nå ett sted. Det gjelder
prislistene under Innhold, ordensreglene, kortet «Fra medlemmene», og en død
kopi av «Selg keramikk»-skjemaet som stjal navnene fra gaveskjemaet i admin.
Funksjonen er beholdt i alle tilfellene — det er den døde kopien som er borte.

**d) Designet er ikke rørt** utover det du har bedt om. Fargene,
typografien, komponentene og oppsettet er som de var. Der jeg har lagt til
noe synlig — tidsfilteret, «Når som helst», knappene på deltakerraden — har
jeg spurt først eller sagt fra i samme melding.

---

## 13. Feil funnet underveis, og hva som ble gjort med dem

Utover det du meldte inn, er dette funnet ved å lete. Alle er rettet.

| Feil | Hva som var galt | Rettet |
|---|---|---|
| Den siste plassen | To kunne booke samme stol samtidig | Låst plasstelling i transaksjon |
| Paint on Pots til én krone | En migrering stoppet halvveis 21. august og sto slik til 22. | Migrasjon 008; migrasjonene tåler nå å kjøres om igjen |
| Gavekortfeltet i kassa | Ikke koblet — koden ble skrevet inn og kunden betalte full pris | Migrasjon 040 |
| Medlemschatten | Lagret i `localStorage`; ingen andre så noe | Migrasjon 038 |
| Gaver til medlemmene | Lagret i nettleseren til den som ga dem | Migrasjon 043 |
| Svar på henvendelser | Kunne bare merkes som besvart | Migrasjon 039 |
| `gSend`/`gTekst` | En død kopi av «Selg keramikk» stjal navnene; «Send gaven» kjørte feil skjema | Den døde kopien fjernet |
| To manglende `</div>` | Lukket sidewrapperen for tidlig; Økonomi lå bak en tom skjermhøy boks | Rettet |
| Kurspris nullstilte temaet | `TEMA['Kurs']` er udefinert, så kurset falt ut av sitt eget filter | Beholder eksisterende tema |
| Butikkbilder | Portrettfoto i landskapsflis | 1:1, to varer i bredden på mobil |
| Seks adminskjermer på mobil | Sidepanelet lå oppå innholdet | `lx-split`, kollapser under 900 px |
| Ubesvart melding på Oversikt | Åpnet Beskjeder, men over kanten av skjermen | Ruller til meldingen |
| Internbutikken | Kunne ikke redigeres noe sted | Egen fane, varene kan åpnes |
| Medlemssiden på mobil | Viste alle drop-in-timene | Datolista følger fanen |
| Publisering av nyhetsbrev | Popup som ikke førte noe sted | Går til Beskjeder eller Tavla, med teksten med |
| Program viste tomme drop-in | Åpen dør uten påmeldte tok plass i programmet | Filtrert bort |
| «Helg» viste onsdagskurs | Kortet sto med første dato uansett | Kortet viser datoen som passer valget |
| Interne samlinger uten kursbevis | «Kun for medlemmer» sto ved siden av «Drop-in» i utelatelsen | Skilt fra hverandre |
| Deltakerraden var død | Personruta lå bak lenken «Se Min side» | Raden åpner personen |
| Gjestepåmelding uten konto | Samme menneske sto som to; beviset kunne rettes ett sted og ikke det andre | Slås sammen på e-post og telefon; «Knytt til kontoen» |
| Beskjeder blandet gruppene | «Sendt til medlemmene» sto der uansett | Varselet bærer gruppa; lista følger området |
| `api/status.php` | Sjekket etter tabeller som ikke fantes | Lista rettet |
| Sju tekster uten norske tegn | «vaert», «Naermere», «ennaa», «forst naar» | Rettet |
| STATUS.md punkt 8 og 10 | Sa at bilder ikke kunne lastes opp, og at migrasjonene ikke var kjørt — begge deler var gjort | Rettet |

---

## Det som står igjen

**Hos deg:**
1. Dublettene i medlemslista (2× Monica, 1× Joakim) — jeg når ikke basen.
2. Kursdatoene i katalogen er satt av meg ut fra designet. De må erstattes med
   de faktiske. Samme gjelder varer og priser i butikken.
3. Databasepassordet ble limt inn i en samtale under oppsettet og bør byttes.
4. Den gamle WordPress-installasjonen: filene er slettet 25. august, og
   `wp-login.php` svarer «finnes ikke». Igjen står `wp_`-tabellene i basen og
   en eventuell gammel databasebruker i cPanel.
5. Gjestebooking uten innlogging — designvalg jeg ikke tar alene.
6. Lagerstyring i butikken finnes ikke. Skal beholdningen telles?
7. Skal Medlemmer vise bare faktiske medlemmer som standard, med
   kursdeltakerne bak filteret?

**Utenfor koden:**
8. Vipps ePayment svarer 403 (MSN 1142801). Ingen kodeendring hjelper.
9. Vipps Recurring er ikke godkjent.

**Ikke bygget:**
10. Video på kurs. Feltet sier det selv i stedet for å late som.
