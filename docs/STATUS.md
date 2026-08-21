# Status — 22. august 2026, natt

Oppdatert gjennom natta. Dette er hva som virker, hva som ikke gjør det, og
hva som venter på en avgjørelse.

**Kort sagt:** backend er nå testet mot en ekte database — det var den ikke da
du gikk. Booking, betaling, venteliste, avbestilling, admin og innholds-
redigering er koblet opp og verifisert. Det eneste som står igjen som ekte
hindring, er at Vipps ikke slipper salgsenheten til ePayment.

---

## Det som er ekte nå

| Flyt | Status |
|---|---|
| Innlogging med Vipps | Virker i produksjon |
| Sesjoner og admin-rolle | Virker, tre timers levetid |
| Kurskatalog fra database | Virker |
| Booking av kurs | Bygget, blokkert av Vipps |
| Betaling med Vipps | **Blokkert — se under** |
| Kvittering på e-post | Bygget, går i kø til betaling virker |
| Admin: oversikt, påmeldte, medlemmer, økonomi | Ekte data |
| Admin: kurs og datoer | Kan opprettes og endres, og vises på siden |
| Venteliste | Ekte, med dublettvern og bekreftelse |
| Avbestilling med refusjon | Ekte, regner ut beløp etter vilkårene |
| Min side: dine plasser | Ekte |
| Innholdsredigering i admin | Lagres og vises for alle |
| Butikk med bestilling | Ekte, server regner ut summen |
| Internbutikk for medlemmer | Ekte, nektet for gjester |
| Gavekort | Ekte, kode først ved betaling |
| Ekte adresser (/kurs, /medlemskap) | Virker |
| Favicon | Hjertemerket |
| Sesjon utløper etter 3 timer | Virker |
| Vilkår og personvern | Publisert som egne sider |

## Det som fortsatt er simulering

**Medlemskap.** Månedstrekk krever Vipps Recurring, som er et eget produkt og
en egen godkjenning. Knappen sier nå fra i stedet for å påstå at en avtale er
opprettet.

Admin-sidene for drop-in, beskjeder, oppskrifter og SEO viser fortsatt
designdata.

Timeforbruk på Min side står som «—». Innstempling er ikke koblet opp, og et
tall der ville vært oppspinn.

---

## Testdekning

Backend kjører nå mot en ekte MariaDB 10.11 — samme versjon som webhotellet.

```
php tests/backend.php    26 sjekker, alle grønne
tests/flyt.sh            10 sjekker, alle grønne
```

`backend.php` dekker kapasitetsberegning, at siste plass ikke kan bookes to
ganger, at en betaling kvitteres nøyaktig én gang uansett hvor mange ganger
Vipps melder fra, at utløpte reservasjoner frigir plassen, tidssoner og
ratebegrensning.

`flyt.sh` kjører hele betalingskjeden mot en stubbet Vipps: booking, webhook
med signaturkontroll begge veier, og duplikatvern. Alle cron-jobbene er også
kjørt, og kurspåminnelser er verifisert med en økt som starter dagen etter.

---

## Blokkeringen

Vipps svarer **403 Forbidden** når vi prøver å opprette en betaling.

Adgangstokenet går gjennom med HTTP 200, så nøklene, MSN-et og miljøet
stemmer. Det er derfor innlogging virker. Men salgsenhet `1142801` har ikke
tilgang til ePayment-produktet.

Diagnosen kan kjøres når som helst:

```
/api/vipps-test.php?nokkel=<cron_nokkel>
```

Dette må løses med Vipps. Ingen kodeendring hjelper. I det øyeblikket 403 blir
201, virker booking og betaling uten videre arbeid.

---

## Må tas stilling til

**1. Samelue på logoen.** Bedt om, ikke utført. To grunner: jeg vet ikke
hvilken logo det gjelder eller hvordan den skal se ut, og samisk
tradisjonsdrakt er kulturelt betydningsfull. Brukt dekorativt av en virksomhet
uten samisk tilknytning kan det oppfattes som en tilegnelse. Er det en
tilknytning her, eller er det ment til samefolkets dag 6. februar, er saken en
annen — men det bør være et bevisst valg, ikke min gjetning.

**2. Gjestebooking uten innlogging.** I dag sendes uinnloggede til Vipps Login
før booking, fordi bookingskjemaet i designet ikke har felter for navn, e-post
og telefon. Alternativet er å bygge de feltene. Vipps-veien gir riktigere data
og færre feilkilder; egne felter senker terskelen for de som ikke vil logge
inn.

**3. Butikkens varebeholdning.** Varene er lagt inn med prisene fra designet,
uten lagerstyring — det går an å bestille flere enn dere har. Skal lager
telles, eller holder det å følge med på ordrene?

**4. Kurs boller.** Flyttet fra Dreiing til Plateteknikk, og beskrivelsen
skrevet om, etter beskjed. Verdt en ny lesning — jeg skrev den uten å kjenne
kurset.

**5. Datoene i katalogen** er satt av meg ut fra designet. De må erstattes med
de faktiske kursdatoene deres, enten i admin eller ved at jeg legger dem inn.
Det samme gjelder varene i butikken og prisene på dem.

**6. WordPress i `public_html`.** En gammel installasjon som ikke oppdateres
er en vanlig vei inn for angripere, og den deler konto med betalingsdataene.
Bør fjernes — men `public_html/ny.lissom.no` må spares, den nye siden ligger
inni den mappa.

**7. Testkurset og prisen på Paint on Pots.** Paint on Pots står midlertidig
til én krone for å kunne teste betaling. `docs/006_etter_test.sql.venter`
setter den tilbake til 690 og avlyser testkurset — flytt den til
`db/migrations/` og kjør migrering når testen er gjennomført.

**8. Nytt kurs uten bilde.** Kurs opprettet i admin får verkstedbildet som
standard. Det finnes ingen måte å laste opp bilde i admin ennå — si fra om det
skal bygges.

**9. Cron-jobbene.** To av fire er satt opp. `varsler` og `betalinger` trengs
ikke — trafikk på nettsiden gjør den jobben. `paaminnelser` og `vedlikehold`
bør stå.

---

## Sikkerhet — verdt å vite

**Vipps-nøkler i chatlogg.** Client secret og subscription key ble limt inn i
en samtale under oppsettet. De bør byttes i portalen.

**Admin-markupen er offentlig.** Ingen kommer til dataene — alle admin-
endepunkter krever admin-sesjon og svarer 404 til andre. Men selve HTML-en for
admin-sidene ligger i den offentlige fila. Å skille den ut er en større jobb,
verdt å ta når innholdet virker.

**Databasen deles med WordPress.** 55 tabeller, hvorav 23 er våre.
Sikkerhetskopier inneholder begge.

---

## Nyttige adresser

Alle krever nøkkelen fra `cron_nokkel` i `secrets.php`, unntatt der det står.

| Adresse | Hva den gjør |
|---|---|
| `/api/status.php?nokkel=` | Hele oppsettet: PHP, database, tabeller, nøkler |
| `/api/sjekk-secrets.php` | Skrivefeil i nøkkelfila — ingen nøkkel nødvendig |
| `/api/vipps-test.php?nokkel=` | Prøvekall mot Vipps, med det ekte feilsvaret |
| `/api/migrer.php?nokkel=&kjor=ja` | Kjører databasemigrasjoner |
| `/api/kurs.php` | Katalogen slik kundene ser den — åpen |
