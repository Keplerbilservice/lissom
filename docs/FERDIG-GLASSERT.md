# Ferdig glassert — kartlegging før bygging

Bestilt 26. august: bygg ferdig funksjonaliteten i kortet «Ferdig glassert».
Bestillingen ber om at dagens løsning kartlegges først, og at hver endring i
datamodellen begrunnes før den gjøres. Dette er den kartleggingen.

---

## 1. Hvor «Ferdig glassert» ligger i dag

| Del | Fil | Hva den gjør |
|---|---|---|
| Kortet | `lissom-2108.html`, `ovKort` | Står på Oversikt når noe venter. Viser antall kursdatoer som ikke er meldt. |
| Skjermen | `lissom-2108.html`, `erAdminFerdigbrent` → `/admin/ferdigbrent` | Liste over gjennomførte kursdatoer, med «Meld klar» per dato. |
| Admin-API | `api/admin/ferdigbrent.php` | GET: datoer som er ferdige. POST `meld` / `angre`. |
| Offentlig API | `api/ferdigbrent.php` | Lista på `lissom.no/ferdigbrent`, uten navn og antall. |
| Offentlig side | `lissom-2108.html`, `erFerdigbrent` | «Klar til henting»-meldingene, tre uker. |
| Datamodell | migrasjon 053 | `course_sessions.hentemelding_at`, `.hentemelding_av` |
| Varselmal | migrasjon 002, tekst endret i 053 | `ferdig_brent` |

**Nivået i dag er kursdatoen, ikke deltakeren.** Ett trykk sender til alle på
datoen, og ett tidspunkt lagres på økta. Det finnes ingen sted å se hvem som
har fått beskjed og hvem som ikke har.

---

## 2. Kurs og deltakere

```
courses            kurset:   tittel, type, tema, pris_ore, kapasitet, bilde, bilder
course_sessions    datoen:   course_id, start_tid, slutt_tid, kapasitet, status
bookings           plassen:  course_id, course_session_id, member_id,
                             gjest_navn, gjest_epost, gjest_telefon,
                             antall, status, notat, bevis_*, created_at
members            personen: navn, epost, telefon, rolle, status
```

En deltaker er en rad i `bookings`. Er hen medlem, peker `member_id` til
`members`; ellers står navn og kontakt i `gjest_*`-feltene. Alt annet i
systemet — deltakerlista, beskjedene, kursbeviset, dagsoppgjøret — leser
deltakere på nøyaktig denne måten.

Aktuelle deltakere på en dato: `status IN ('betalt','reservert')`.

---

## 3. Hvordan meldinger sendes

```
notification_templates    navn, kanal (epost|sms|epost_sms), emne, tekst, aktiv
notifications             mal, kanal, mottaker, emne, tekst,
                          status (ko|sendt|feilet), forsok, feilmelding,
                          ref_type, ref_id, send_etter, sendt_at, created_at
```

`Varsel::mal($malNavn, $mottaker, $felter, $refType, $refId)` slår opp malen,
fletter inn feltene, og legger én rad i `notifications` per kanal. En egen
kø-jobb sender og setter `status` til `sendt` eller `feilet` med
`feilmelding`.

**Dette dekker allerede alt DEL 3 ber om å registrere:** mottaker, kanal,
emne, tekst, tidspunkt, og om det gikk eller feilet. `ref_type`/`ref_id`
knytter raden til det den gjelder.

Malen `ferdig_brent` finnes og brukes:

> Hei {navn}! Keramikken din fra {kurs} er ferdig og klar til henting. Vi
> oppbevarer den hos oss i tre uker.

---

## 4. Min side i dag

| Endepunkt | Gir |
|---|---|
| `api/meg.php` | Hvem er innlogget |
| `api/mine-plasser.php` | Kursene hen er påmeldt, med status, frist, avbestilling, kursbevis |
| `api/mine-kjop.php` | Kjøpshistorikk |
| `api/kursbevis.php` | Kursbevis som PDF |

`mine-plasser.php` returnerer allerede én rad per booking for den innloggede,
med `id` = booking-id. **Det er akkurat den koblingen bildeopplasting
trenger** — kurset, datoen og deltakeren i én rad som serveren alt har
autorisert.

Innlogging: `krev_medlem()` for deltakere, `krev_admin()` for admin. Begge i
`app/lib/auth.php`, sjekkes på serveren i hvert endepunkt.

---

## 5. Bilder, notater og status i dag

**Bilder.** `app/lib/bilder.php` tar imot en opplasting, tegner den om med GD,
skalerer til 1400 px lengste kant, lagrer som JPEG utenfor det som
publiseres. `api/bilde.php` serverer dem, lager mindre utgaver ved behov, og
**har allerede tilgangskontroll**: et bilde til en vare som ikke er godkjent
vises bare til eieren og til admin.

`api/medlemssalg.php` er allerede en kundevendt opplasting gjennom samme
`Bilder::taImot()`. Mønsteret finnes og er i drift.

**Notat.** `bookings.notat` — VARCHAR(255). Brukes i dag av systemet selv til
å merke hvor påmeldingen kom fra («Fra ventelista»).

**Status.** `bookings.status` — `reservert | betalt | avbestilt | refundert |
ikke_mott`. Dette er betalingsstatus, ikke keramikkstatus.

---

## 6. Hva som gjenbrukes

| Behov i bestillingen | Gjenbrukes |
|---|---|
| Kurs og datoer | `courses`, `course_sessions` |
| Deltakere | `bookings` + `members` |
| Sende beskjed | `Varsel::mal()`, malen `ferdig_brent` |
| Meldingshistorikk, kanal, tekst, feil | `notifications` |
| Bildeopplasting og skalering | `Bilder::taImot()` |
| Servering med tilgangskontroll | `api/bilde.php` |
| Deltakerens egne kurs | `api/mine-plasser.php` |
| Innlogging og rettigheter | `krev_medlem()`, `krev_admin()` |
| Kort, knapper, farger, typografi | Malen i `lissom-2108.html`, `LissomDesignSystem` |

---

## 7. Hva som må endres i datamodellen, og hvorfor

Tre spørsmål. To krever en endring, ett gjør det ikke.

### 7a. «Hvem har fått beskjed?» — ingen endring nødvendig

Bestillingen ber om at det registreres hvem som fikk beskjed, når, på hvilken
kanal, hvilken tekst, og om det gikk. **Alt dette ligger allerede i
`notifications`.**

Det som må endres er ikke tabellen, men hva vi skriver i `ref_id`. I dag
sendes beskjeden med `ref_type = 'ferdig-brent'` og `ref_id = ` kursdatoen.
Sendes den i stedet med `ref_id = ` booking-id, kan spørsmålet «har denne
deltakeren fått beskjed?» besvares eksakt:

```sql
SELECT status FROM notifications
 WHERE mal = 'ferdig_brent' AND ref_type = 'booking' AND ref_id = :b
 ORDER BY id DESC
```

Det gir også resten gratis: full historikk, flere utsendelser uten at noe
overskrives, og «feilet» som en egen tilstand — akkurat som bestilt.

`course_sessions.hentemelding_at` beholdes. Den styrer den offentlige sida
`lissom.no/ferdigbrent`, som er noe annet enn hvem som har fått e-post.

### 7b. Bilder fra deltakeren — ny tabell, `deltaker_bilder`

Det finnes ingen struktur for flere bilder knyttet til én rad. `courses.bilder`
er en JSON-liste med filnavn på et kurs; `member_sales.bilde` er ett bilde på
én vare; `bilde_fokus` er filnavn → utsnitt. Ingen av dem kan holde «disse
fire bildene hører til denne deltakerens påmelding, lastet opp av hen selv».

Å legge en JSON-liste i `bookings` ville gjort det umulig å svare på «hvem
lastet opp dette bildet» og «vis meg alle bilder fra denne uka» uten å lese
hele tabellen.

```sql
deltaker_bilder
  id           BIGINT UNSIGNED PK
  booking_id   BIGINT UNSIGNED   -- deltakeren, kurset og datoen i én
  fil          VARCHAR(64)       -- filnavnet Bilder::taImot() ga
  lastet_opp_av BIGINT UNSIGNED  -- medlem, eller NULL når admin la det inn
  created_at   DATETIME
```

Selve filene går gjennom `Bilder::taImot()` som alt annet, i en egen mappe
`deltakere/`. Ingen ny lagringsløsning.

### 7c. Internt notat — én ny kolonne på `bookings`

`bookings.notat` finnes, men er 255 tegn og brukes av systemet selv til å
merke hvor påmeldingen kom fra. Skriver verkstedet «grønn skål, hylle 3» der,
forsvinner «Fra ventelista», og en manuell påmelding ser ut som en
nettbestilling i ettertid.

```sql
ALTER TABLE bookings ADD COLUMN internt_notat TEXT NULL;
```

Én kolonne på raden som alt er deltakeren. Ingen ny tabell, og ingen fare for
å overskrive noe systemet trenger.

---

## 8. Statuser

Bestillingen foreslår seks statuser. Fem av dem er ikke en tilstand som må
lagres — de kan regnes ut av det som allerede finnes:

| Status | Hvor den kommer fra |
|---|---|
| Ikke sendt | ingen `notifications`-rad for denne bookingen |
| Sendt beskjed | siste rad har `status = 'sendt'` |
| Utsendelse feilet | siste rad har `status = 'feilet'` |
| Klar til kontroll | kursdatoen er passert, ingen beskjed sendt |
| Klar til henting | beskjed sendt, ikke hentet |

**«Hentet» er den ene som ikke kan regnes ut.** Ingen vet at noen kom innom
og tok med seg skåla si. Den lagres som en kolonne på `bookings`:

```sql
ALTER TABLE bookings ADD COLUMN hentet_at DATETIME NULL;
```

---

## 9. Filer som endres

| Fil | Endring |
|---|---|
| `db/migrations/055_ferdig_glassert.sql` | ny tabell `deltaker_bilder`, to kolonner på `bookings` |
| `api/admin/ferdigbrent.php` | per deltaker: liste, sende til én, sende til alle, notat, status |
| `api/mine-bilder.php` | ny: deltakerens opplasting, sletting og visning |
| `api/bilde.php` | ny kilde `?deltaker=`, med eierskapssjekk |
| `api/mine-plasser.php` | tar med bildene deltakeren har lastet opp |
| `lissom-2108.html` | skjermen «Ferdig glassert», deltakerkort, detaljvisning, Min side |
| `docs/FERDIG-GLASSERT.md` | dette dokumentet |

---

## 10. Testene som skal kjøres

De seksten punktene i bestillingen, ført opp med resultat etter hvert som de
gjøres. Fylles ut under «Testresultat» nederst i dette dokumentet.

---

## 11. Testresultat

Kjørt 26. august mot testdatabasen, med en kursdato satt opp med fire
deltakere: én med bilde og notat, én uten e-post og telefon, én som fikk
utsendelsen til å feile, og én vanlig.

E-posten er sendt av den ekte køjobben (`Utsending::tomKo()`), ikke etterlignet.
«Feilet» er en ekte feil: fem forsøk mot en avsender som ikke svarte, og
statusen satt av `merkFeilet()` slik den gjøres i drift.

| # | Test | Resultat | Hvordan |
|---|---|---|---|
| 1 | Kurs vises i «Ferdig glassert» | ✅ | To kursdatoer i skjermbildet: «Kurs boller · 1 deltaker» og «Nybegynner dreiekurs · 4 deltakere · 1 bilde» |
| 2 | Kurset kan åpnes | ✅ | Trykk på kortet åpner deltakerne i samme skjerm, med «← Alle kurs» tilbake |
| 3 | Alle riktige deltakere vises | ✅ | Alle fire kom fram, både `betalt` og `reservert`, og ingen andre |
| 4 | Deltakeren kan laste opp bilder fra Min side | ✅ | På telefonstørrelse 390 × 844: 1 → 2 bilder, sletting 2 → 1, feltet bærer `capture="environment"` |
| 5 | Bildene vises på riktig deltaker i admin | ✅ | Miniatyren på Kari Deltakers rad er hennes egen fil (`api/bilde.php?deltaker=…`), ikke på noen annen rad |
| 6 | Notat kan legges til og lagres | ✅ | «Grønn skål, hylle 3.» lagret, kom tilbake på raden etter ny henting |
| 7 | Beskjed til én deltaker | ✅ | «Beskjed lagt i kø til Kari Deltaker.» |
| 8 | Sendestatus, dato og kanal registreres | ✅ | Raden viser «Sendt beskjed» og «E-post · onsdag 26. august, 20:51» |
| 9 | Send til alle som ikke har fått | ✅ *etter retting* | Se avsnittet under |
| 10 | Vellykket utsendelse markeres som sendt | ✅ | To e-poster faktisk levert av køjobben, begge deltakerne står som «Sendt beskjed» |
| 11 | Feilet utsendelse forblir usendt og viser feil | ✅ | Carl Feilesen: «Utsendelse feilet», med «Leverandøren svarte med feil» på raden |
| 12 | Kurset flyttes til «Sendt beskjed» først når alle har fått | ✅ | Sto under «Klar til å sende beskjed» med «2 av 4 mangler beskjed», og flyttet seg først da siste deltaker var i havn |
| 13 | Historikken beholdes | ✅ | Carl har to linjer: «Feilet · E-post 20:51» og «Sendt · E-post 20:52». Ingenting overskrevet |
| 14 | Tilgangskontrollen | ✅ | Se tabellen under |
| 15 | Mobil og datamaskin | ✅ | Se tabellen under |
| 16 | Eksisterende funksjoner virker fortsatt | ✅ | 119 av 119 i `tests/backend.php`. `api/kurs.php`, `api/admin/kurs.php`, `api/admin/oversikt.php`, `api/admin/varsler.php`, `api/mine-plasser.php` og den offentlige `api/ferdigbrent.php` svarer som før |

### Test 9 fant en feil, og den er rettet

Første gjennomkjøring: én deltaker fikk beskjed, og «send til alle» sendte
den til henne **en gang til**. Køen hadde to like e-poster til samme person.

Grunnen var at «send til alle» hoppet over dem som var *levert*, ikke dem som
var *sendt herfra*. En beskjed som lå i køen — sendt, men ikke kommet fram
ennå — så ut som om den ikke fantes.

Rettet i `api/admin/ferdigbrent.php`: deltakeren har nå et felt `venter`, og
både «send til alle» og skjermbildet regner den som ivaretatt.

Etter rettingen:

```
Første trykk:  «2 deltakere har fått beskjed. 1 lå alt i kø og fikk den ikke
                på nytt. Bjorn Utenkontakt står uten e-post og telefon og må
                kontaktes selv.»
Andre trykk:   «Alle beskjedene ligger alt i kø. Ingen fikk den to ganger.»
Køen:           én rad per deltaker. Ingen fikk to.
```

Samme gjennomkjøring fant en ting til: en beskjed som feilet første gang og
gikk gjennom andre gang viste fortsatt feilmeldingen fra første forsøk, under
en linje som sa «Sendt». `feilmelding` blir stående i basen. Den vises nå
bare på linjer som faktisk står som feilet.

### Test 14 — tilgang

| Hvem | Hva | Svar |
|---|---|---|
| Kari | sitt eget bilde | 200 |
| Ola | Karis bilde | 404 |
| Uinnlogget | samme bilde | 404 |
| Admin | samme bilde | 200 |
| Ola | `mine-bilder.php?bookingId=` Karis | «Fant ikke påmeldingen.» |
| Ola | slett Karis bilde | «Fant ikke bildet.» |
| Ola | last opp på Karis påmelding | «Fant ikke påmeldingen.» |
| Ola | admin-lista over deltakere | «Fant ikke siden.» |

Filnavnet er 32 tegn tilfeldig, men det er ikke det som beskytter bildet:
`api/bilde.php` slår opp eieren i basen ved hvert eneste oppslag.

`internt_notat` leses ikke av noe endepunkt utenfor `api/admin/`. Sjekket med
søk over hele `api/`.

### Test 15 — mobil og datamaskin

| | Mobil 390 × 844 | Datamaskin 1400 × 1000 |
|---|---|---|
| Kursene vises | ✅ 2 kort | ✅ 2 kort |
| Kurset åpner deltakerne | ✅ | ✅ |
| Detaljpanelet åpner | ✅ notat og historikk | ✅ |
| Vannrett rulling | ✅ ingen | ✅ ingen |
| Knappehøyde | 34–35 px | 28–29 px |
| Min side, opplasting | ✅ kamera, opplasting, sletting | — |

Knappene er designsystemets egen `sm`-knapp, samme som resten av admin.
Admin-menyen ligger som før i en vannrett rad som skyves ut på smal skjerm —
det er slik den er fra før, og er ikke rørt her.

### Filer som ble endret

| Fil | Hva |
|---|---|
| `db/migrations/055_ferdig_glassert.sql` | ny tabell `deltaker_bilder`, kolonnene `internt_notat` og `hentet_at` på `bookings` |
| `api/admin/ferdigbrent.php` | skrevet om til deltakernivå: liste, historikk, status, bilder, notat, hentet, send til én, send til alle |
| `api/mine-bilder.php` | ny — deltakerens egen opplasting, visning og sletting |
| `api/bilde.php` | ny kilde `?deltaker=`, med eierskapssjekk på serveren |
| `api/mine-plasser.php` | tar med bildene på hver påmelding |
| `lissom-2108.html` | skjermen «Ferdig glassert» med to seksjoner, kurskort, deltakerrader og detaljpanel — og blokka «Bilder av det du laget» på Min side |
| `docs/FERDIG-GLASSERT.md` | kartleggingen og dette testresultatet |

Ingen eksisterende funksjon er fjernet. Ingen nye kort, knapper eller
komponenter er laget der malen alt hadde en.

### Det som ikke kunne testes herfra

- Ekte e-post ut til en ekte mottaker. Utsendelsen er kjørt av den ekte
  køjobben, men mot en lokal avsender — ikke gjennom SMTP-en på webhotellet.
- SMS. Det er ikke satt opp noen SMS-leverandør i testmiljøet.
- Det som ligger på lissom.no. Nettadressen er ikke tilgjengelig herfra.
