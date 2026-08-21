# Status — 21. august 2026

Skrevet ved slutten av første arbeidsøkt. Dette er hva som virker, hva som
ikke gjør det, og hva som venter på en avgjørelse.

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
| Admin: kurs og datoer | Kan opprettes og endres |
| Vilkår og personvern | Publisert som egne sider |
| Ekte adresser (/kurs, /medlemskap) | Virker |

## Det som fortsatt er simulering

Butikk-kassen, gavekort og medlemskap. De ser ekte ut, men flytter ingen
penger. Knappene sier nå fra om det i stedet for å påstå at noe er betalt —
tidligere svarte de «Betalingen er gjennomført» uten at noe skjedde.

Admin-sidene for innhold, drop-in, butikk, beskjeder, oppskrifter og SEO
viser fortsatt designdata.

Timeforbruk på Min side står som «—». Innstempling er ikke koblet opp, og et
tall der ville vært oppspinn.

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

**3. Butikken.** Skal den kobles til ekte betaling, eller skjules til den er
klar? Den viser i dag varer man ikke kan kjøpe.

**4. Kurs boller.** Flyttet fra Dreiing til Plateteknikk, og beskrivelsen
skrevet om, etter beskjed. Verdt en ny lesning — jeg skrev den uten å kjenne
kurset.

**5. Datoene i katalogen** er satt av meg ut fra designet. De må erstattes med
de faktiske kursdatoene deres, enten i admin eller ved at jeg legger dem inn.

**6. WordPress i `public_html`.** En gammel installasjon som ikke oppdateres
er en vanlig vei inn for angripere, og den deler konto med betalingsdataene.
Bør fjernes — men `public_html/ny.lissom.no` må spares, den nye siden ligger
inni den mappa.

**7. Testkurset og prisen på Paint on Pots.** Paint on Pots står midlertidig
til én krone for å kunne teste betaling. `docs/006_etter_test.sql.venter`
setter den tilbake til 690 og avlyser testkurset — flytt den til
`db/migrations/` og kjør migrering når testen er gjennomført.

**8. Cron-jobbene.** To av fire er satt opp. `varsler` og `betalinger` trengs
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
