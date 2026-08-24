# Status — 24. august 2026

Hva som virker, hva som ikke gjør det, og hva som venter på en avgjørelse.

**Kort sagt:** hele veien fra admin til nettsiden er koblet opp og prøvd mot
en ekte database. Arbeidet 24. august står oppsummert i
[`SLUTTRAPPORT.md`](SLUTTRAPPORT.md). Det eneste som står igjen som ekte hindring, er at Vipps
ikke slipper salgsenheten til ePayment.

> **Om denne fila.** Utgaven fra 22. august motsa seg selv: tabellen sa at
> e-post over SMTP virket, mens punkt 11 lenger nede sa at det ikke var satt
> opp. Den motsigelsen ble stående i to dager og sendte arbeidet i feil
> retning. Staar det noe her som ikke stemmer med det du ser paa skjermen, er
> det skjermen som har rett — og da skal denne fila rettes samme dag.

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
| Beskjed til deltakere og medlemmer | Ekte, e-post og SMS |
| Ekte adresser (/kurs, /medlemskap) | Virker |
| Favicon | Hjertemerket |
| Sesjon utløper etter 3 timer | Virker |
| Logg ut-knapp på Min side | Virker |
| Medlemskap krever godkjenning | Virker — søknad i admin |
| Handlekurv synlig på mobil | Virker |
| Gavekort med valgfritt beløp | Virker |
| Kursbevis på Min side og i admin | Virker |
| Forespørsler fra nettsiden | Lagres, varsles, følges opp i admin |
| Brukernavn og passord for verkstedet | Virker, med brukeradministrasjon |
| E-post over SMTP | Settes opp i admin — se punkt 11 |
| Kursbevis fra admin | Virker |
| Logg ut og «se nettsiden» i admin | Virker |
| Ingen oppdiktede data på publisert side | Verifisert side for side |
| Ingen sporing, ingen tredjeparter | Verifisert i nettleser |
| Workshop og Sip & Clay som kategori | Virker |
| Vilkår og personvern | Publisert som egne sider |
| Favicon i fane, adresselinje og hjemskjerm | Ekte ikonsett, 16–512 px |
| Ingen avhengighet til unpkg.com | React og ikoner ligger på egen server |
| Mobilvisning verifisert i ekte nettleser | Se under |

## Nytt 23.–24. august

| Hva | Status |
|---|---|
| Oversikt bygget om: ni kort (Nytt kurs, Planlagte kurs, Medlemskap, Priser/tekst/bilder, Program, Ny registrering, Uttak butikk, Butikk, Kursholdere) | Virker |
| Ny registrering — melde noen på kurs, event eller drop-in fra admin | Virker |
| Uttak butikk — salg over disk, blir en vanlig ordre med betaling | Virker |
| Hele admin virker paa mobil (den egne mobilsida er fjernet) | Verifisert i nettleser |
| Alle 144 innholdsfeltene styrer noe paa nettsiden (var 6) | Kontrolleres av `bin/innholdssjekk.mjs` |
| Content-Security-Policy | Lagt inn, prøvd mot alle sider og adminskjermer |
| Tittel, beskrivelse og delingsbilde i selve HTML-en | Lagt inn — robotene kjører ikke skript |
| E-post og SMS kan settes opp fra admin | Virker — se punkt 11 |
| Delingsbilde til Facebook og andre | Virker — robotene er unntatt webp-regelen |
| Plasser paa kursene (12 / 8 dreiing / 8 drop-in), Medlemsfrokost stroket | Migrasjon 037 |
| «Rediger» paa hver datorad, ikke bare noen | Virker |
| Medlemschat begge veier, med varsel | Migrasjon 038 |
| Monica kan svare paa henvendelser, og svaret vises paa Min side | Migrasjon 039 |
| Gavekort kan brukes som betaling, med gavehilsen | Migrasjon 040, 041 |
| Kursholdere og timene deres | Migrasjon 042 |
| Paint on Pots, Kontakt, Gavekort og Vilkaar kan redigeres fra admin | Virker |
| 15 felter og 9 knapper som ikke gjorde noe | Koblet opp |
| Oppsigelse av medlemskap gaar til serveren | Virker — Vipps-trekket er ikke proevd |
| Gaver fra verkstedet til medlemmene | Migrasjon 043 — gitt i admin, vises paa Min side |

## Det som fortsatt er simulering

**Medlemskap.** Månedstrekk krever Vipps Recurring, som er et eget produkt og
en egen godkjenning. Knappen sier nå fra i stedet for å påstå at en avtale er
opprettet.

**Frys av medlemskap** finnes ikke. Bryteren som lovet det er erstattet med en
setning som sier at det gjøres manuelt under Medlemmer.

Timeforbruk på Min side står som «—». Innstempling er ikke koblet opp, og et
tall der ville vært oppspinn.

**Ikke lenger simulering:** admin-sidene for drop-in, beskjeder, oppskrifter og
SEO henter alle fra sine egne endepunkter. Kontrollert 24. august ved aa laste
hver skjerm i nettleser og se hvilke kall som faktisk gikk ut. Sto som
«designdata» her fram til da; det var feil.

---

## Testdekning

Backend kjører nå mot en ekte MariaDB 10.11 — samme versjon som webhotellet.

```
php tests/backend.php    113 sjekker, alle grønne
tests/flyt.sh             10 sjekker, alle grønne
```

Fra 22. august kjøres nettsiden også mot den ekte backend-en lokalt, med
`ny.lissom.no` pekt på en lokal PHP-server. Det er slik forespørselsskjemaet,
innloggingen og admin-sidene er verifisert — ikke bare at koden er
syntaktisk riktig, men at flyten faktisk virker fra skjerm til database.

`backend.php` dekker kapasitetsberegning, at siste plass ikke kan bookes to
ganger, at en betaling kvitteres nøyaktig én gang uansett hvor mange ganger
Vipps melder fra, at utløpte reservasjoner frigir plassen, tidssoner og
ratebegrensning.

`flyt.sh` kjører hele betalingskjeden mot en stubbet Vipps: booking, webhook
med signaturkontroll begge veier, og duplikatvern. Alle cron-jobbene er også
kjørt, og kurspåminnelser er verifisert med en økt som starter dagen etter.

---

## Nytt 22. august: siden kan endelig ses

Fram til nå er hver endring i frontend verifisert med syntakssjekk og
telling av tagger — jeg har aldri sett siden tegnet opp, fordi jeg ikke når
ny.lissom.no herfra.

Nå kjøres siden i en ekte nettleser lokalt (Chromium via Playwright), og
skjermbilder tas på mobilbredde. Det avdekket med én gang to feil som ingen
syntakssjekk kunne finne: karusellen på mobil klemte fire kort sammen på én
linje, og logoen i toppmenyen var presset ned til tre piksler av knappene
ved siden av. Begge er rettet og etterpå kontrollert på skjermbilde.

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

**1. Joika Design-logoen.** Gjort. Luen ligger som en gradert strektegning bak
navnet, slik du ba om, og står i bunnteksten på nettsiden. Punktet sto her som
«bedt om, ikke utført» helt til 24. august — det var feil, og fila sa det i to
uker etter at logoen var laget.

**2. Gjestebooking uten innlogging.** I dag sendes uinnloggede til Vipps Login
før booking, fordi bookingskjemaet i designet ikke har felter for navn, e-post
og telefon. Alternativet er å bygge de feltene. Vipps-veien gir riktigere data
og færre feilkilder; egne felter senker terskelen for de som ikke vil logge
inn.

**3. Butikkens varebeholdning.** Varene er lagt inn med prisene fra designet,
uten lagerstyring — det går an å bestille flere enn dere har. Skal lager
telles, eller holder det å følge med på ordrene?

**4. Kurstekstene.** Gjennomlest i verkstedet 22. august og rettet
(migrasjon 009): «Store fat kurs» krever ikke erfaring, og plateteknikk
beskrives som å bygge sin egen gjenstand — sentrere, dreie og trimme hører
bare hjemme på dreiekurs. Migrasjon 009 må kjøres på serveren før teksten
endrer seg der.

**5. Datoene i katalogen** er satt av meg ut fra designet. De må erstattes med
de faktiske kursdatoene deres, enten i admin eller ved at jeg legger dem inn.
Det samme gjelder varene i butikken og prisene på dem.

**6. WordPress i `public_html`.** En gammel installasjon som ikke oppdateres
er en vanlig vei inn for angripere, og den deler konto med betalingsdataene.
Bør fjernes — men `public_html/ny.lissom.no` må spares, den nye siden ligger
inni den mappa.

**7. Testkurset og prisen på Paint on Pots.** Gjort. Migrasjon 008 satte
Paint on Pots tilbake til 690 kroner og avlyste testkurset.

**8. Nytt kurs uten bilde.** Kurs opprettet i admin får butikkbildet som
standard. Det finnes ingen måte å laste opp bilde i admin ennå — si fra om det
skal bygges.

**9. Beskjed-knappene i admin.** Gjort 24. august. Skjemaet er koblet til, og
teksten du skriver når fram. Det sto to kopier av samme skjema, og den ene
ignorerte mottakervalget og sendte alltid til alle medlemmer — den er borte.

**10. Migreringene 032–043 er ikke kjørt.** De ligger klare i `db/migrations/`
og kjøres fra Admin → Oversikt → Vedlikehold. Uten dem står medlemskapstekstene
tomme i admin (032, 034), noen innholdsnøkler peker feil (033), disksalg kan
ikke registreres (035), oppsettet for e-post og SMS kan ikke lagres (036),
plassene på kursene står som før (037), medlemschatten har ingen tabell (038),
henvendelser kan ikke besvares (039), gavekort kan ikke brukes som betaling
(040), gavehilsen lagres ikke (041), kursholdere finnes ikke (042) og gaver
til medlemmene kan ikke gis (043).
Skjermene sier fra hver for seg.

007 til 011 ble kjørt 22. august. Fram til da
hadde databasen stått på 006 siden dagen før — det betyr at Paint on Pots
sto til én krone i produksjon i mellomtiden, og at butikken var tom.
Lærdommen er tatt inn i migrasjonene: 007, 010 og 011 tåler nå å kjøres om
igjen, slik en migrering som stopper halvveis må gjøre.

Migreringen kjøres fra `/api/migrer.php?kjor=ja` når du er innlogget som
admin. Nøkkelen fra secrets.php virker fortsatt, som reserve for den dagen
innloggingen er ødelagt.

**11. E-post og SMS settes opp i admin.** Gå til **Markedsføring → E-post og SMS**.
Skjermen sier selv om e-post går over SMTP eller over serverens egen `mail()`,
og en knapp sender en testmelding og forteller hva som faktisk skjedde.

Står nøklene allerede i `secrets.php`, gjelder fila foran — og da sier skjermen
at feltet er låst av den, i stedet for å la noen skrive i noe uten virkning.
Skjermen er altså også svaret på spørsmålet «er dette satt opp?», som denne
fila tidligere ga to motstridende svar på.

Uten SMTP går e-post fortsatt gjennom `mail()`, som ofte kommer fram, men aldri
sier fra når noe blir avvist. Uten Sveve sendes meldinger som skulle gått på
SMS som e-post i stedet.

**12. Ordensreglene om mat og drikke.** Du ba meg fjerne «ikke spis eller
drikk ved arbeidsbenkene» og sofakroken. Det er gjort. Punktet «bruk hansker
ved glasering, ikke spis eller drikk i glasurrommet» står fortsatt — det
handler om kjemikalier og ikke om benkene, så jeg lot det stå. Si fra hvis
det også skal ut.

**13. «Store former, viderekomne»** sier fortsatt «for deg som allerede
dreier stødig». Det er et internkurs for medlemmer, så jeg tolket «gjelder
alle våre kurs» som de åpne kursene. Skal den også endres, sier du fra.

**14. Cron-jobbene.** To av fire er satt opp. `varsler` og `betalinger` trengs
ikke — trafikk på nettsiden gjør den jobben. `paaminnelser` og `vedlikehold`
bør stå.

---

## Sikkerhet — verdt å vite

**Vipps-nøkler i chatlogg.** Client secret, subscription key og databasepassordet
ble limt inn i samtaler under oppsettet. Vipps-nøklene er byttet 22. august, og
de gamle verdiene er dermed verdiløse. **Databasepassordet står igjen og bør
byttes.**

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
