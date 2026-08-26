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
