# Gjennomgang av struktur, innhold og brukerflyt — kartlegging

Bestilt 26. august: en samlet gjennomgang av admin, forsiden, kurs,
medlemskap, Min side, publisering og innholdsproduksjon, i sytten punkter.

Bestillingen krever at dagens løsning kartlegges før noe bygges. Dette er den
kartleggingen. Hvert punkt står med **hva som finnes**, **hva som mangler**,
og **hva som skal gjenbrukes**.

---

## 1. Min side — todeling for medlemmer og kursdeltakere — **gjort 27. august**

Min side sier nå hvilken av de to sidene den er. Under hilsenen står et bånd:
**Medlemsside** eller **Kursdeltaker**, med én linje om hva som ligger der —
og for kursdeltakeren en lenke videre til medlemskapene, som ikke fantes noe
sted før.

Admin har fått de to forhåndsvisningene under «Kurs og medlemskap»: «Hva
medlemmer ser» og «Hva kursdeltakere ser». De åpner **den ekte skjermen**
med rollen satt for hånd, med et gult bånd øverst og «Tilbake til admin».

Skillet var skrevet ut fem steder med samme uttrykk. Det står nå ett sted,
`medlemsvisning()`, som de fem leser. Stemplingsknappen lå utenfor sperren
og er tatt inn: den hører til medlemsdelen.

**Avvik fra bestillingen, med grunn.** Kartleggingen sa «med data fra en
ekte bruker i hver gruppe». Forhåndsvisningen bruker adminbrukerens egne
data, ikke en annen persons. Å hente en ekte deltakers Min side hit ville
lagt medlemschatten, dørkoden, beskjedene og kjøpshistorikken til den
personen åpen for den som ser på. Det er oppsettet som skal kontrolleres,
og båndet sier rett ut at navn og tall er dine egne.

Nedenfor står kartleggingen som lå til grunn.

**Fantes.** Skillet finnes allerede, og virker: `erMedlem` i `renderVals`
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

## 2. Kurs og medlemskap — samle det som hører sammen — **gjort 27. august**

«Nytt medlemskap» er ikke lenger et eget hovedkort. Det er en rad nederst i
Medlemskap-kortet, og knappen i hurtigraden står som før. «Datoer som ligger
ute» har fått samme behandling: «Legg ut en ny dato» ligger i kortet datoene
bor i. Nye kort: Kursdeltakere, og de to forhåndsvisningene fra punkt 1.

Kortet er nå en ramme med en knapp inni, ikke en knapp med ramme — en knapp
kan ikke ligge inni en annen knapp. Rammen har samme bakgrunn, kant, radius
og luft som før; det er målt.

Nedenfor står kartleggingen som lå til grunn.

**Fantes.** Området finnes (`adminomrkurs`), med en hurtigrad og sju kort:
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

## 3. Ny kursdato — **gjort 27. august**

Kortet har nå sluttdato (et dreiekurs går over to dager), gjentakelse
— Ingen, Ukentlig, Annenhver uke, Månedlig, med «antall ganger» — og pris og
informasjon som gjelder bare den ene datoen. Ukedagen og datoen i måneden
leses av datoen du valgte, så det samme ikke fylles ut to ganger.

Nedenfor står kartleggingen som lå til grunn.

**Fantes.** `apneNyKursdato()` og `case 'nydato'` i `api/admin/kurs.php`.
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

## 4. Flyt i kursoppsettet — **gjort 27. august**

Steg 1 er delt i tolv seksjoner, hver med en overskrift og en linje som sier
hva den er til:

| # | Seksjon |
|---|---|
| 01 | Navn |
| 02 | Kategori |
| 03 | Pris |
| 04 | Plasser |
| 05 | Beskrivelse |
| 06 | Hvem kurset passer for |
| 07 | Dette lærer deltakerne |
| 08 | Alt som er inkludert |
| 09 | Praktisk informasjon |
| 10 | Allergener og kommentarer |
| 11 | Bekreftelse og påminnelse |
| 12 | Forhåndsvisning |

Seksjon 06 til 10 er nye felter (migrasjon 065). Seksjon 12 viser kurset slik
det blir ute, regnet av det som står i skjemaet akkurat nå — ikke av basen,
for poenget er å se det før man lagrer.

**Punktlista er ute av koden.** «Alt som er inkludert» sto som fire faste
linjer og kunne ikke endres noe sted. Verkstedet ba i juni om å ta «verktøy»
ut av Kurs boller; det måtte gjøres av meg. Nå står den på kurset, ett punkt
per linje. Skriver du ingenting, står den samme teksten som i dag — ingen
kurs endrer seg av at migrasjonen kjøres. Det ene som ble bestilt endret,
«verktøy» ut av Kurs boller, gjør migrasjonen selv.

«Maks N deltakere» står ikke i det redigerbare feltet. Den regnes fortsatt av
kapasiteten, så tallet ikke kan bli uenig med det som står rett under —
det var nettopp den feilen som ble rettet i juni.

**Merkingen** — nivå, hvem, metode, varighet — er fire grupper med korte,
faste lister. Flere valg per gruppe: et kurs kan passe både for venner og for
familie. De står på kurssiden, og de er det Kursveilederen skal lese når den
skal foreslå noe (punkt 7b i `docs/KURSVEILEDER.md`).

**Testet:** 15 kontroller gjennom hele veien — lagring, opprydding av
punktlista, at en lagring uten seksjonene ikke rører dem, katalogen, og at
alt står riktig på kurssiden med «Maks N deltakere» nøyaktig én gang. Og 15 i
admin — tolv seksjoner, alle feltene, merkene som kan trykkes,
forhåndsvisningen som følger med, lagring og gjenåpning.

Nedenfor står kartleggingen som lå til grunn.

**Fantes.** Tre steg: 1 Grunninfo, 2 Dager og gjentakelse, 3 Bilder.

**Mangler.** Bestillingen ber om tolv seksjoner. De fleste av dem finnes ikke
som felter i det hele tatt: «Hvem kurset passer for», «Dette lærer
deltakerne», «Praktisk informasjon», «Allergener og kommentarer»,
«Forhåndsvisning». Punktlista under «Alt som er inkludert» ligger fortsatt
fast i koden — det er punkt 1b på den gamle lista over åpne punkter.

**Skal gjøres.** Steg 1 deles i tydelige seksjoner med overskrift og
forklarende tekst, og punktlista flyttes til basen slik at den kan redigeres.
Ingen kurstekst endres automatisk.

---

## 5. Flerdagerskurs — **gjort 27. august**

`okt_samlinger` er bygget slik den er beskrevet under. Hver samling har
dato, klokkeslett, overskrift og tekst, og redigeres der datoen bor — under
«Datoer som ligger ute». Deltakeren ser alle samlingene før hen betaler, og
datovelgeren sier «2 samlinger» på den datoen.

Nedenfor står kartleggingen som lå til grunn.

**Fantes, delvis.** En kursdato kan i dag spenne over flere dager:
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

## 6. Allergener ved påmelding — **gjort 27. august**

Avhukingsboks og et felt som må fylles ut når den står. Egen kolonne
`bookings.allergier` (migrasjon 057), merke på raden i admin, teksten først
når deltakeren åpnes, og med på deltakerlista som tas med inn i verkstedet.
Testet: ingen andre deltakere ser den, og ingen kundevendt endepunkt leser
kolonnen.

Nedenfor står kartleggingen som lå til grunn.

**Fantes.** Ingenting. `bookings` har `notat` (VARCHAR 255, brukes av
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

**Gjort 27. august** (migrasjon 059):

- `course_sessions.updated_at`, som holder seg selv oppdatert uansett hvor i
  koden en økt endres.
- `SEQUENCE` i feeden, regnet som antall minutter siden 1. januar 2020. Den
  må bare vokse når noe endres, og det gjør den — også når en økt rettes to
  ganger på en halvtime.
- `LAST-MODIFIED` på hver hendelse.
- `VALARM` som foreslår et varsel dagen før, klokka atten. Det er en
  anbefaling til kalenderen, ikke en push fra oss.
- En hendelse som sluttet når den begynte fikk tre timers varighet. Datoer
  lagret før sluttiden ble tatt vare på hadde det, og et streif i kalenderen
  er vanskelig å se.
- Teksten i admin sier nå hva et abonnement er: telefonen henter, vi sender
  ikke, og det kan gå timer.

**Testet teknisk:** feeden hentet, `SEQUENCE` lest før og etter at en
kursdato ble flyttet en time — 3499622 → 3499623, og `LAST-MODIFIED` fulgte
med. 34 hendelser, alle med `SEQUENCE`, `LAST-MODIFIED` og `VALARM`. Avlyste
datoer står med `STATUS:CANCELLED`.

**Ikke verifisert:** at endringen faktisk dukker opp i Apple Kalender på en
iPhone. Det finnes ingen telefon her, og det kan ikke prøves fra dette
miljøet. Det må kontrolleres fysisk før det kan kalles bekreftet.

**Push finnes ikke, og kan ikke finnes med denne løsningen.** Et
kalenderabonnement er henting, ikke sending.

---

## 8. ~~Tekst i programmodulen~~ — gjort 26. august

«Ingenting denne måneden. Det neste som skjer:» er fjernet. Modulen og lista
over hva som kommer står som før.

---

## 9. Artikler, nyhetsbrev og sosiale medier — **gjort 27. august**

En artikkel kan nå ha bilder inne i teksten (migrasjon 064): hvert med
bildetekst, alt-tekst, plassering (full bredde, venstre, høyre, midtstilt)
og størrelse (liten, medium, stor). «Står etter» velger hvilket avsnitt
bildet kommer etter — lista over avsnitt regnes av teksten du har skrevet,
så du velger «Avsnitt 2», ikke et tall.

`articles.bilde` står som før. Det er bildet lista viser og det som følger
med når noen deler lenken, og det er noe annet enn bildene i teksten.

Alt-teksten kan stå tom. Det er et valg, ikke en mangel: da er bildet pynt,
og skjermleseren hopper over det framfor å lese opp et filnavn. Feltet sier
det.

På mobil flyter ingen bilder ved siden av teksten — det er ikke plass til
begge deler. Da står bildet i full bredde over avsnittet.

**Testet:** 17 kontroller gjennom hele veien — tre bilder lagret med hver
sin plassering, rekkefølgen på nettsiden (bilde, avsnitt, avsnitt, bilde,
avsnitt, bilde), alt-tekstene, bildetekstene, at et høyrestilt bilde faktisk
flyter til høyre, og at ingenting flyter på 390 piksler. Og 17 i admin —
legge til, velge fil, fylle ut, lagre, åpne igjen, fjerne.

Nedenfor står kartleggingen som lå til grunn.

**Fantes, og mer enn bestillingen antok.**

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

## 10. Publiser-knappen — **gjort 27. august**

Fire tilstander (migrasjon 063): kladd, planlagt, publisert, avpublisert.
Under hver artikkel står det nå hva som gjelder — «Ute siden 27. august ·
lagt ut av Monica», «Går ut 3. september kl. 09:00», «Tatt ned · lå ute fra
…». Alt som sto som publisert står som publisert.

«Tatt ned» og «kladd» ser like ut for en besøkende — begge er borte. For
verkstedet er forskjellen at den ene har ligget ute, og at det kan finnes
lenker der ute som nå er døde.

**Planlagt** går ut av seg selv. Ikke med en egen cron-jobb: da hadde
tidspunktet vært avhengig av at det ble lagt inn en linje til i cPanel, og en
artikkel som skulle ut klokka ni ville blitt liggende til noen husket det.
Forfalte planlagte flyttes hver gang noen spør etter artikler — av nettsiden
eller av admin.

**Forhåndsvisning.** `/nyheter/<adresse>` har stått under hver artikkel i
admin siden kunnskapsbanken kom, men adressen fantes ikke: den som fulgte
den havnet på «finner ikke siden». Nå åpner den artikkelen — og for den som
er logget inn som admin også en som ikke ligger ute, med et gult bånd som
sier at dette er en forhåndsvisning. Ingen andre slipper til.

**Testet:** 18 kontroller gjennom hele veien — kladd usynlig for andre og
synlig for admin, publisering med tidspunkt og navn, ta ned, et tidspunkt
som har vært avvist, et planlagt tidspunkt satt og ventet ut til artikkelen
gikk ut av seg selv. Og ti i nettleseren.

Nedenfor står kartleggingen som lå til grunn.

**Fantes.** `articles.status` er `kladd` eller `publisert`, og
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

## 11. E-postsignatur — **gjort 27. august**

Signaturen ligger nå under Innstillinger → E-post og varsler, med feltet,
forhåndsvisning av hvordan den blir, og forhåndsvisning av hva den som leser
ren tekst får. Fire brytere sier hvilke meldinger den står på:
systemmeldinger, ordrebekreftelser, kursmeldinger, nyhetsbrev. Gruppa står
på malen (migrasjon 062), ikke i en liste ved siden av, og hvert kort viser
hvilke e-poster gruppa faktisk omfatter.

Startverdien er signaturen som alt fantes, hentet fra `e-post-signatur.html`.
Ingen ny signatur der det finnes en. Den sida står som før — den gjelder
meldingene Monica skriver selv.

Utsendingen sender nå `multipart/alternative` når signaturen er på: tekst til
den som leser tekst, HTML til den som leser HTML, og de sier det samme.
Beskjeder til verkstedet selv får aldri kundesignaturen.

**Testet:** en kursmelding lagt i kø og lest ut igjen — tekstdelen har
signaturen skrevet ut uten taggkode, HTML-delen har både meldingen og
signaturen. Bryteren av: signaturen borte fra begge. Og hele veien ut: en
ordrebekreftelse sendt gjennom en SMTP-tjener som skriver ned det den får,
med to deler, riktig grense og CRLF.

Nedenfor står kartleggingen som lå til grunn.

**Fantes — men ikke i systemet.** `e-post-signatur.html` er en egen side som
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

## 12. Bilder i «Laget her på verkstedet» — **gjort 27. august**

Forsiden bruker nå samme kort som butikken, både i rutenettet og i
mobilvisningen. Målt på 390, 820 og 1400 piksler: `cover`, kvadratiske
ruter, lik korthøyde. Butikksiden er urørt.

Nedenfor står kartleggingen som lå til grunn.

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

## 13. Åpningstider i footeren — **gjort 27. august**

Regnes nå av kursene: går det kurs i dag, står tidsrommet fra det første
begynner til det siste slutter, og under står det neste. Er det ingenting i
dag, står det at verkstedet er åpent etter avtale. Overstyring per dato
ligger i `apningstider` (migrasjon 060) og går foran alt som regnes ut.
Avlyste datoer og kladder teller ikke. Og det står hva tidene gjelder.

Nedenfor står kartleggingen som lå til grunn.

**Fantes.** Tre faste linjer, skrevet inn i koden:

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

## 15. Referansekunder — **gjort 27. august**

Seksjonen står på forsiden og bruker rotasjonen som alt fantes. Admin ligger
under Nettsiden → Referanser. Ingenting publiseres uten at samtykket er
huket av — koden kan ikke avgjøre om verkstedet har lov, men den nekter å
vise noe før noen har bekreftet at lov finnes.

Nedenfor står kartleggingen som lå til grunn.

**Fantes ikke.** Ingen tabell, ingen seksjon.

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

## 16. Global struktur og design — **holdt gjennom hele veien**

Alt over bruker `LissomDesignSystem`-komponentene som finnes: `Button`,
`Input`, `Checkbox`, `Dialog`, `Toast`, `CourseCard`, kortstilen i admin.
Ingen nye hovedmenypunkter. Nye skjermer ligger under områdene som finnes.

Forhåndsvisningene fra punkt 1 er ingen ny skjerm: de åpner Min side, den
som allerede er der. Referansekundene på forsiden bruker rotasjonen
butikkortene bruker. Kortet i admin er målt før og etter: samme bakgrunn,
samme kant, samme radius, samme luft.

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
| 1 | 12 bildeskalering | ✅ gjort 27. august |
| 2 | 6 allergener | ✅ gjort 27. august |
| 3 | 3 ny kursdato | ✅ gjort 27. august |
| 4 | 5 flerdagerskurs | ✅ gjort 27. august |
| 5 | 13 åpningstider | ✅ gjort 27. august |
| 6 | 7 kalender | ✅ gjort 27. august (push kan ikke verifiseres herfra) |
| 7 | 15 referansekunder | ✅ gjort 27. august |
| 8 | 1, 2, 16 Min side og admin | ✅ gjort 27. august |
| 9 | 9, 10, 11 innhold | ✅ gjort 27. august |

Punkt 8 og 14 ble gjort 26. august. **Alle sytten punktene er ferdige.**

Det som står igjen er ikke fra denne bestillingen, men fra den andre:
Kursveilederen, kartlagt i `docs/KURSVEILEDER.md`. Merkefeltene den trenger
— steg 6 i den planen — kom med punkt 4 her.

---

## Testresultat — punkt 1, 2 og 16

Kjørt 27. august mot den lokale tjeneren, som admin, i Chromium.

| Hva | Hvordan | Resultat |
|---|---|---|
| «Nytt medlemskap» er borte som eget kort | leste alle synlige bladtekster på skjermen | ett treff igjen, og det er knappen i hurtigraden |
| «Lag et nytt medlemskap» ligger i Medlemskap-kortet | målte at knappen ligger inne i samme ramme, under overskriften | bestått, 390 og 1400 px |
| «Legg ut en ny dato» ligger i datokortet | samme | bestått |
| Kortene Kursdeltakere, Hva medlemmer ser, Hva kursdeltakere ser finnes | leste kortoverskriftene | bestått |
| Forhåndsvisning som kursdeltaker | trykket kortet | båndet står øverst, rollen står som «Kursdeltaker», medlemsdelen er borte, «Bli medlem» står i stedet |
| Forhåndsvisning som medlem | trykket kortet | rollen står som «Medlemsside», timer og dørkode er med, «Bli medlem» er borte |
| «Tilbake til admin» | trykket knappen | tilbake på Kurs og medlemskap |
| Forhåndsvisningen henger ikke igjen | gikk videre fra forhåndsvisningen med en snarvei | båndet er borte |
| Kortene virker som før | trykket «Se medlemskapene» og «Lag et nytt medlemskap» | begge åpner det de skal |
| Ekte Min side, uten forhåndsvisning | logget inn og gikk rett til /min-side | rollebåndet står, forhåndsvisningsbåndet gjør det ikke |
| Kortet ser ut som før | målte bakgrunn, kant, radius, luft og størrelse | 1 px kant, 14 px radius, 20 px luft, 344 × 194 — likt for alle kort |

22 sjekker i den ene kjøringen, 8 i den andre. Alle gikk gjennom.
`119 av 119` i `tests/backend.php`, og alle tre vaktskriptene.

**Ikke verifisert:** den levende nettsiden. Utgående trafikk til `lissom.no`
er sperret fra dette miljøet, så alt over er kjørt lokalt.
