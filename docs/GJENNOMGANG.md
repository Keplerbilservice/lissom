# Gjennomgang av struktur, innhold og brukerflyt — kartlegging

Bestilt 26. august: en samlet gjennomgang av admin, forsiden, kurs,
medlemskap, Min side, publisering og innholdsproduksjon, i sytten punkter.

Bestillingen krever at dagens løsning kartlegges før noe bygges. Dette er den
kartleggingen. Hvert punkt står med **hva som finnes**, **hva som mangler**,
og **hva som skal gjenbrukes**.

---

## 1. Min side — todeling for medlemmer og kursdeltakere

**Finnes.** Skillet finnes allerede, og virker: `erMedlem` i `renderVals`
avgjør hva som vises. Alt som gjelder verkstedet — timer, dørkode,
abonnement, dreieskivene denne uka, interne kurs, internbutikken,
ordensreglene — ligger inne i `<sc-if value="{{ erMedlem }}">`. Alle andre
får «Bli medlem» i stedet, og beholder sine egne plasser og kvitteringer
lenger nede.

Status leses av serveren: `api/meg.php` gir `erMedlem`, satt av
`members.status` ∈ (`prove`, `aktiv`, `pause`). Ikke av nettleseren.

| Blokk | Vises for |
|---|---|
| Hilsen, stemplingsknapp, snarveier | begge |
| Timer igjen, dreieskivene, abonnement, dørkode | medlem |
| `minside-internkurs`, `minside-internbutikk` | medlem |
| `minside-ordensregler` | medlem |
| `minside-pameldinger` — kursene mine, med bilder og kursbevis | begge |
| `minside-beskjeder` — svar fra verkstedet | begge |
| Kjøpshistorikk | begge |

**Mangler.** Todelingen er der, men den er ikke *synlig* som en todeling: en
kursdeltaker ser en side med hull der medlemsdelen ville vært, uten at det
står noe om at det finnes en medlemsside i det hele tatt. Og admin har ingen
måte å se hva de to gruppene faktisk ser, annet enn å logge inn som en av
dem.

**Skal bygges.** To kort under «Kurs og medlemskap» — «Hva medlemmer ser» og
«Hva kursdeltakere ser» — som viser Min side slik den er, med data fra en
ekte bruker i hver gruppe. Ikke en tegning: samme skjerm, samme komponenter,
med et tydelig bånd øverst om at dette er en forhåndsvisning.

---

## 2. Kurs og medlemskap — samle det som hører sammen

**Finnes.** Området finnes (`adminomrkurs`), med en hurtigrad og sju kort:
Planlagte kurs, Opprett kurs, Medlemskap, Nytt medlemskap, Datoer som ligger
ute, Drop-in, Påmeldte. Pluss Videokurs, lagt til 26. august.

**Mangler.** «Nytt medlemskap» står som eget hovedkort ved siden av
«Medlemskap» — to kort for det samme. Bestillingen ber om at det flyttes inn.
Det mangler også kort for Kursdeltakere, Ny kursdato, og de to
forhåndsvisningene fra punkt 1.

**Skal gjøres.** Kortene settes opp slik bestillingen ber om, «Nytt
medlemskap» blir en knapp i hurtigraden og inne i Medlemskap-kortet framfor
et eget kort. Ingen funksjon fjernes.

---

## 3. Ny kursdato

**Finnes.** `apneNyKursdato()` og `case 'nydato'` i `api/admin/kurs.php`.
Den tar kurs, start og slutt — og ikke noe mer.

**Mangler.** Plasser på datoen kan settes etterpå (`case 'plasser'`), men
ikke i samme skjerm. Pris per dato og informasjon som gjelder bare denne
datoen finnes ikke i det hele tatt.

**Datamodell.** `course_sessions` har `kapasitet` (kan avvike fra kurset) og
`status`. Den har ingen pris og ingen tekst.

```sql
ALTER TABLE course_sessions
  ADD COLUMN pris_ore INT UNSIGNED NULL,   -- NULL = bruk kursets pris
  ADD COLUMN info TEXT NULL;               -- gjelder bare denne datoen
```

To kolonner på raden som alt er datoen. `NULL` betyr «som kurset», så alle
datoer som ligger der i dag oppfører seg nøyaktig som før.

---

## 4. Flyt i kursoppsettet

**Finnes.** Tre steg: 1 Grunninfo, 2 Dager og gjentakelse, 3 Bilder.

**Mangler.** Bestillingen ber om tolv seksjoner. De fleste av dem finnes ikke
som felter i det hele tatt: «Hvem kurset passer for», «Dette lærer
deltakerne», «Praktisk informasjon», «Allergener og kommentarer»,
«Forhåndsvisning». Punktlista under «Alt som er inkludert» ligger fortsatt
fast i koden — det er punkt 1b på den gamle lista over åpne punkter.

**Skal gjøres.** Steg 1 deles i tydelige seksjoner med overskrift og
forklarende tekst, og punktlista flyttes til basen slik at den kan redigeres.
Ingen kurstekst endres automatisk.

---

## 5. Flerdagerskurs

**Finnes, delvis.** En kursdato kan i dag spenne over flere dager:
`course_sessions.start_tid` onsdag og `slutt_tid` torsdag gir «onsdag 9. –
torsdag 10. september». Det er **én sammenhengende blokk**, ikke tre
samlinger.

**Mangler.** Tre samlinger med hver sin dato, klokkeslett, overskrift og
tekst finnes ikke. Det finnes ingen tabell for det.

**Datamodell.** `course_sessions` er den bookbare enheten — én påmelding
peker på én rad. Det skal den fortsette å være: én påmelding til et
flerdagerskurs, ikke tre. Samlingene henger under den:

```sql
okt_samlinger
  id            BIGINT UNSIGNED PK
  session_id    BIGINT UNSIGNED   -- kursdatoen påmeldingen gjelder
  nummer        SMALLINT          -- Samling 1, 2, 3 — rekkefølgen
  dato          DATE
  fra, til      TIME
  overskrift    VARCHAR(191) NULL
  tekst         TEXT NULL
```

Hvorfor ikke flere rader i `course_sessions`: da ville hver samling vært
bookbar for seg, og en deltaker kunne meldt seg på samling 2 uten 1. Og
plasstellingen ville talt samme person tre ganger.

---

## 6. Allergener ved påmelding

**Finnes.** Ingenting. `bookings` har `notat` (VARCHAR 255, brukes av
systemet selv til «Fra ventelista») og `internt_notat` (TEXT, skrevet av
verkstedet, aldri synlig for deltakeren).

**Mangler.** Alt. Ingen avhukingsboks, intet felt, ingen visning i admin.

**Datamodell.** Dette er opplysninger deltakeren selv gir om egen helse. Det
kan ikke deles kolonne med noe annet:

```sql
ALTER TABLE bookings ADD COLUMN allergier TEXT NULL;
```

`notat` er systemets, `internt_notat` er verkstedets, `allergier` er
deltakerens. Tre ulike kilder, tre kolonner. Skriver vi allergier i
`internt_notat`, kan verkstedet slette dem uten å vite hva de var.

**Skal gjenbrukes.** `Checkbox` og `Input` fra designsystemet, deltakerraden
i «Ferdig glassert» som mønster for hvordan noe merkes uten å vise innholdet
før man åpner.

---

## 7. Kalender og push til iPhone

**Finnes.** `api/kalender-abonnement.php` lager en ekte iCalendar-feed:

- Fast `UID:okt-<id>@lissom.no` per dato, så en endring **oppdaterer**
  hendelsen framfor å legge en ny ved siden av.
- `STATUS:CANCELLED` på avlyste datoer, som sendes med — uten dem ville de
  blitt stående på telefonen for alltid.
- `REFRESH-INTERVAL:PT2H` og `X-PUBLISHED-TTL:PT2H`.
- Drop-in uten påmeldte tas ut; kurs og samlinger står uansett.
- Adressen er beskyttet med en nøkkel og vises under Oversikt.

**Mangler.**

1. **`SEQUENCE` finnes ikke.** Apple Kalender og Outlook bruker `SEQUENCE`
   sammen med `LAST-MODIFIED` for å avgjøre om en hendelse er endret. Uten
   dem kan en klient som alt har hendelsen la være å oppdatere den. Feeden
   serveres hel hver gang, så de fleste klienter tar den likevel — men det
   er ikke garantert, og det er den mest sannsynlige grunnen til at en endret
   kursdato ikke dukker opp på telefonen.
2. **`LAST-MODIFIED` finnes ikke**, og kan ikke settes: `course_sessions` har
   ingen endringstid. En kolonne `updated_at` må til.
3. **Det finnes ingen push.** Dette er verdt å si tydelig: et
   kalenderabonnement er *henting*, ikke *sending*. Telefonen spør serveren
   med jevne mellomrom. Apple bestemmer selv hvor ofte — `PT2H` er et ønske,
   ikke en regel, og i praksis kan det gå lengre. Ingenting i løsningen kan
   få en melding til å dukke opp på en iPhone i det øyeblikket noe endres.
4. **Ingen `VALARM`.** Hendelsene foreslår ingen varsling. Varselet en bruker
   eventuelt får, kommer fra hens egne innstillinger i Kalender.

**Skal gjøres.** `updated_at` på `course_sessions`, `SEQUENCE` og
`LAST-MODIFIED` i feeden, og en valgfri `VALARM`. Og det skal stå i
dokumentasjonen, ikke bare her, at push ikke finnes og ikke kan finnes med
denne løsningen.

**Kan ikke testes herfra.** Ingen iPhone. Feeden kan kontrolleres teknisk —
at den er gyldig iCalendar, at UID-ene er stabile, at en endring gir nytt
`SEQUENCE` — men at den faktisk dukker opp i Apple Kalender må kontrolleres
på en fysisk telefon. Det skal ikke merkes som verifisert.

---

## 8. ~~Tekst i programmodulen~~ — gjort 26. august

«Ingenting denne måneden. Det neste som skjer:» er fjernet. Modulen og lista
over hva som kommer står som før.

---

## 9. Artikler, nyhetsbrev og sosiale medier

**Finnes, og mer enn bestillingen antar.**

| Del | Hvor |
|---|---|
| Artikler | `articles` (migrasjon 018, utvidet i 024): tittel, kategori, slug, ingress, **ett** bilde, innhold, status kladd/publisert, fokusord, kilde manuell/ai |
| AI-utkast | `ai_utkast`: artikkel, nyhetsbrev, sosialt, seo, kursboost, medlemsbrev — med status utkast/godkjent/publisert/forkastet |
| Kostnad | `ai_logg`: tokens inn og ut, kostnad i øre, per kall |
| Admin | `api/admin/artikler.php`, `api/admin/marked.php`, `api/admin/ai.php` |

**«Shutter».** Bestillingen ber om at det ikke antas at dette er
Shutterstock. Det er kontrollert: det finnes ingen «Shutter» i kodebasen.
Søk på ordet gir null treff utenom Shutterstock. Integrasjonen som finnes er
**Shutterstock**, i `api/admin/shutterstock.php` og
`api/admin/shutterstock-kobling.php`, med søk, miniatyrer gjennom vår egen
tjener, og lisensiert nedlasting etter at Lissom selv har koblet til.

**Mangler.** En artikkel har **ett** bilde. Bestillingen ber om flere bilder
plassert mellom avsnitt, med bildetekst, alt-tekst, plassering og størrelse.
Det finnes ingen struktur for det.

```sql
artikkel_bilder
  id           BIGINT UNSIGNED PK
  artikkel_id  BIGINT UNSIGNED
  fil          VARCHAR(64)
  rekkefolge   SMALLINT      -- hvilket avsnitt det står etter
  bildetekst   VARCHAR(255) NULL
  alt_tekst    VARCHAR(255) NULL
  plassering   ENUM('full','venstre','hoyre','midtstilt')
  storrelse    ENUM('liten','medium','stor')
  hovedbilde   TINYINT(1)
```

`articles.bilde` beholdes — den brukes av lista og av delingsbildet, og skal
ikke rives ut.

---

## 10. Publiser-knappen

**Finnes.** `articles.status` er `kladd` eller `publisert`, og
`api/admin/artikler.php` `handling=status` bytter mellom dem. Knappen i
admin heter «Publiser» eller «Ta ned».

**Mangler.** «Planlagt» og «Avpublisert» finnes ikke som statuser.
Publiseringstidspunkt, hvem som publiserte og lenke til det publiserte
lagres ikke. Og det finnes ingen forhåndsvisning før publisering.

```sql
ALTER TABLE articles
  MODIFY status ENUM('kladd','planlagt','publisert','avpublisert')
                NOT NULL DEFAULT 'kladd',
  ADD COLUMN publisert_at DATETIME NULL,
  ADD COLUMN publisert_av BIGINT UNSIGNED NULL,
  ADD COLUMN planlagt_til DATETIME NULL;
```

Alle rader som står som `publisert` i dag blir stående som `publisert`.

---

## 11. E-postsignatur

**Finnes — men ikke i systemet.** `e-post-signatur.html` er en egen side som
ligger ved siden av nettsiden. Den viser signaturen ferdig satt opp, med en
«Kopier signaturen»-knapp og bruksanvisning for webmail og Outlook. Den er
laget for å limes inn i e-postprogrammet **manuelt**.

E-postene systemet selv sender går gjennom `Varsel::mal()` og
`notification_templates`, og bruker **ikke** denne signaturen. De har ingen
signatur i det hele tatt.

**Skal gjøres.** Signaturen inn i systemet: en innstilling under
Innstillinger → E-post og varsler, der den kan limes inn, forhåndsvises, og
velges av eller på per malgruppe (systemmeldinger, ordrebekreftelser,
kursmeldinger, nyhetsbrev). Ingen ny signaturfunksjon der det alt finnes en
— den ferdige signaturen fra `e-post-signatur.html` blir startverdien.

E-postene sendes i dag som **ren tekst**. En HTML-signatur i en
ren-tekst-e-post blir en klump med taggkode hos mottakeren. Skal signaturen
være HTML, må utsendelsen sende `multipart/alternative` med begge deler. Det
er en endring i `Varsel::sendEpost()`.

---

## 12. Bilder i «Laget her på verkstedet»

**Funnet.** De to stedene bruker to helt ulike kort:

| | Butikksida | Forsiden |
|---|---|---|
| Kort | `.lx-varekort`, håndbygget | `LissomDesignSystem.CourseCard` |
| Ramme | `aspect-ratio: 1 / 1` | kursformat, `hint-size="100%,420px"` |
| Fyll | `background-size: cover` | komponentens eget |
| Utsnitt | `background-position: {{ p.fokus }}` — valgt punkt | ingen |

`CourseCard` er laget for kurs: et liggende bilde med tekst under.
Produktbildene er kvadratiske. Et kvadratisk bilde i en liggende ramme blir
enten strukket eller beskåret feil — og utsnittet eieren har valgt for bildet
følger ikke med, fordi CourseCard ikke vet om det.

**Skal gjøres.** Forsiden bruker samme kort som butikken. Det er ikke en ny
komponent — det er den som allerede virker.

---

## 13. Åpningstider i footeren

**Finnes.** Tre faste linjer, skrevet inn i koden:

```
Verkstedet      Etter avtale
Kurs og events  Se datoer under kurs
Medlemmer       Døgnåpent, 24 timer
```

De kan ikke redigeres noe sted i admin.

**Mangler.** Alt bestillingen ber om: utregning fra planlagte kurs, manuell
overstyring, stengt-dager.

**Datamodell.** Kursdatoene finnes: `course_sessions.start_tid`,
`slutt_tid`, `status`. Det som mangler er overstyringen:

```sql
apningstider
  id       BIGINT UNSIGNED PK
  dato     DATE          -- én bestemt dag
  stengt   TINYINT(1)
  fra, til TIME NULL
  merknad  VARCHAR(191) NULL
```

Regel: en rad her går foran alt som regnes ut av kursene den dagen.

**Merk.** Bestillingen sier «når det finnes et planlagt og publisert kurs,
regnes lokalet som åpent i kursets tidsrom». Det er verkstedet som er åpent
for **kursdeltakerne** i det tidsrommet — ikke butikken, og ikke for hvem som
helst. Teksten på nettsiden må si hvilken av delene den gjelder, ellers står
det «åpent 10–19» og noen kommer for å handle midt i et kurs. Det er også
det bestillingen ber om til slutt.

---

## 14. ~~Tekst på kurssiden~~ — gjort 26. august

«Små grupper · Leire, glasur og brenning inkludert» er fjernet. Feltene står
igjen tomme under Nettsiden → Innhold.

---

## 15. Referansekunder

**Finnes ikke.** Ingen tabell, ingen seksjon.

**Rotasjonen som skal gjenbrukes finnes.** Forsiden har allerede en
automatisk skiftende visning på mobil, brukt av butikkortene:
`butKortStil`, `butPrikker`, `butVelg`, med et kort om gangen og prikker
under. Samme mønster brukes for events og medlemskap.

```sql
referansekunder
  id         BIGINT UNSIGNED PK
  navn       VARCHAR(191)
  bilde      VARCHAR(64) NULL
  tekst      TEXT NULL
  sitat      VARCHAR(500) NULL
  lenke      VARCHAR(255) NULL
  sortering  SMALLINT
  aktiv      TINYINT(1)
```

**Krav som må huskes:** `prefers-reduced-motion` skal stoppe rotasjonen, og
den skal stå stille når musepekeren er over eller en finger tar i den.
Bestillingen ber også om at det er kontrollert at vi har lov til å vise navn,
logo, bilde og sitat før noe publiseres — det er en beskjed til Lissom, ikke
noe koden kan avgjøre.

---

## 16. Global struktur og design

Alt over bruker `LissomDesignSystem`-komponentene som finnes: `Button`,
`Input`, `Checkbox`, `Dialog`, `Toast`, `CourseCard`, kortstilen i admin.
Ingen nye hovedmenypunkter. Nye skjermer legges under områdene som finnes.

---

## 17. Testing

Kjøres og føres opp under «Testresultat» nederst etter hvert som punktene
bygges, med hva som ble testet, hvordan, og resultatet. Det som ikke kan
kontrolleres herfra — iPhone, ekte e-post, den levende nettsiden — merkes
som ikke verifisert, ikke som bestått.

---

## Rekkefølgen jeg bygger i

Minst avhengig først, og slik at ingenting står halvferdig:

| # | Punkt | Hvorfor her |
|---|---|---|
| 1 | 12 bildeskalering | Selvstendig, og synlig for alle som er innom forsiden |
| 2 | 6 allergener | Selvstendig, og det som har størst konsekvens om det mangler |
| 3 | 3 ny kursdato | Trenger to kolonner, ingen andre punkter |
| 4 | 5 flerdagerskurs | Bygger på 3 |
| 5 | 13 åpningstider | Leser kursdatoene fra 3 og 5 |
| 6 | 7 kalender | Leser de samme datoene |
| 7 | 15 referansekunder | Selvstendig, gjenbruker rotasjonen |
| 8 | 1, 2, 16 Min side og admin | Flytting og forhåndsvisning, best når resten står |
| 9 | 9, 10, 11 innhold | Størst, og henger sammen |
