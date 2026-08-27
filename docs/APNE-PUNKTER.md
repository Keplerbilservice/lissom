# Åpne punkter

Alt Lissom har bedt om som ikke er gjort, og alt som venter på et svar.

**Hvorfor denne fila finnes:** lista sto i hodet mitt, og samtaler komprimeres
når de blir lange. Da forsvinner det som er sagt tidlig, og jeg svarte
«gjenstår ingenting» på ting som gjensto. Fila her overlever det. Den skal
oppdateres i samme commit som arbeidet gjøres — ikke etterpå.

Sist gjennomgått: 27. august 2026, kveld — etter runden med kassa,
mobilmenyen og kursfeltene.

---

## Bestilt, ikke gjort

### 1. ~~Punktlista på kursene ligger fast i koden~~ — gjort 26. august

Plasstallet regnes nå ut av kurset, og «Maks 8 deltakere» sto i seks ulike
tekster som ikke fulgte med når plassene ble endret. Se «Ferdig» nederst.

### 1b. Resten av punktlista kan fortsatt ikke redigeres

Plasslinja er løst, men de andre punktene — «Leire, verktøy, glasur og
brenning er inkludert», «Du tar med deg to til tre boller hjem» — ligger
fortsatt fast i koden. Lissom ba også om å fjerne «verktøy» fra hva som er
inkludert på Kurs boller. Det står igjen.

**Gjort 27. august.** Punktlista ligger nå på kurset (migrasjon 065) og
redigeres i seksjon 08 i kursoppsettet, ett punkt per linje. Tomt felt betyr
«som før», så ingen kurs endret seg av at migrasjonen ble kjørt. «Verktøy» er
tatt ut av Kurs boller — det gjør migrasjonen selv. «Maks N deltakere» står
ikke i feltet: den regnes fortsatt av kapasiteten, så tallet ikke kan bli
uenig med det som står rett under.

### 1c. Gammelt punkt (beholdt for historikken)

På Kurs boller står det i dag, rett over hverandre på samme side:

```
✓ Maks åtte deltakere.          ← fast tekst fra da siden ble bygget
  torsdag 3. september · 10 ledige
  Maks 12 plasser på dette kurset  ← ekte tall fra basen
```

Bestilt: fjerne «verktøy» fra hva som er inkludert, og endre åtte til tolv.

Gjelder alle kursene — «Maks seks deltakere» på Store fat, «Maks åtte» på
dreiekurs. Ingen av dem kan redigeres noe sted i admin.

Løsningen er å flytte punktene til databasen med et felt i kursveiviseren,
ikke å rette de fire linjene i koden. Ellers må Lissom spørre meg neste gang.

### 2. ~~Full redigering av et kurs på ett sted~~ — gjort 27. august

Punktlista ligger nå i seksjon 08 i kursoppsettet, sammen med resten. Alt på
et kurs redigeres på ett sted: navn, kategori, pris, plasser, beskrivelse,
hvem det passer for, hva de lærer, hva som er inkludert, praktisk
informasjon, allergener, bekreftelse — og en forhåndsvisning nederst.

### 3b. ~~Bildesøk med API~~ — gjort 26. august

Søk og nedlasting virker begge veier. Se «Ferdig» nederst.

### 3c. ~~«Klar til henting»-liste~~ — gjort 26. august

Bygget og testet. Se «Ferdig» nederst.

### 3. ~~«Fjern bildet»~~ — gjort

Knappen het «Bruk standardbildet i stedet» og lovet noe den ikke gjør på
bilderute 2 og 3. Den heter «Fjern bildet fra ruta» — det er alt den gjør.

### 4. ~~Hva som skal stå i stedet for «10 ledige»~~ — gjort 26. august

Bestilt og bygget. Se «Ferdig» nederst.

Én ting å bekrefte: teksten når det er få igjen står som «Få plasser» på
Dreiing og «Få ledige plasser» på de andre — slik Lissom skrev det. Skal de
være like?

---

## Venter på et svar

### 5. ~~Gjentakelse-feltet i steg 2~~ — gjort 27. august

Feltet er koblet opp. Ingen, Ukentlig, Annenhver uke og Månedlig, med
«antall ganger», lagres som en regel på kurset (migrasjon 056) og lager
datoene. Ukedagen og datoen i måneden leses av datoen du valgte.

### 6. ~~Datoene fra den første katalogen~~ — avklart 26. august

Lissom: «alt kan stå, for nå er det jo redigerbart, og vi styrer det fra
admin.» Ingen migrasjon som sletter noe. Punktet er lukket.

### 6b. Gammelt punkt (beholdt for historikken)

`db/migrations/003_kurs.sql`, 21. august: jeg fylte katalogen fra
designutkastet og publiserte den med datoer jeg fant på — Paint on Pots
6. september, Date Night 28. august og 11. september, dreiekurs i september.
De ligger fortsatt i basen.

Skal jeg lage en migrasjon som fjerner alle datoer fra 003 som ingen har
booket?

Kursene selv ble laget samme sted. «Glasurkveld for medlemmer» kostet
verkstedet 10 000 kroner i innleid kursholder før den ble slettet.

### 7. ~~Manuelle betalinger uten betalingsmåte~~ — avklart 27. august

Lissom oppga kontoene: **Vipps 1510, Kontant 1900, Faktura 1920**, og
**gavekort 2905** som gjeld. Migrasjon 078 setter dem — men bare der feltet
står tomt, så noe som alt er skrevet inn under Økonomi → Regnskap blir stående.

Gavekortregelen hun beskrev er den koden alt følger: salget føres som gjeld på
2905, og når kortet løses inn blir beløpet inntekt på kontoen for det det
faktisk ble brukt til — kurs, drop-in eller butikk — mens 2905 skrives ned
tilsvarende.

**Fortsatt ikke oppgitt:** kontoene for kurs, medlemskap, butikk og drop-in, og
alle mva-kodene. De står tomme med vilje; et kontonummer jeg finner på er verre
enn et tomt felt, for det tomme feltet sier fra i dagsoppgjøret.

**Drop-in er ikke undervisning.** Slik den står i basen: «To timer i verkstedet.
Krever at du har gått kurs hos oss, eller kommer sammen med et aktivt medlem.»
Det er tilgang til verkstedet på egen hånd, ikke et kurs med lærer — så etter
Lissoms egen regel er det en tjeneste med 25 %.

---

## Må gjøres av Lissom

### 7b. Migrasjon 052–078 må kjøres

**Dette er det ene som står igjen før alt som er bygget virker.** Kjøres fra
menyen nederst til venstre i admin: «Kjør N oppdateringer». Rekka er
kontrollert på en tom base — alle 66 går gjennom, og de tåler å bli kjørt om
igjen om en av dem skulle stanse underveis.

061 gir referansekundene på forsiden.
062 gir e-postsignaturen i meldingene systemet sender, og malgruppene.
063 gir artiklene fire tilstander: kladd, planlagt, publisert, avpublisert.
064 gir flere bilder inne i en artikkel, med bildetekst og alt-tekst.
065 gir punktlista og merkene på kurset — «Alt som er inkludert» ut av koden.
066 gir Kursveilederen: spørsmålene og svarene i basen, og redigering som
faktisk lagres.
067 gir logoen på referansekundene, ved siden av bildet av det som ble laget
— og utvider bildefeltet fra 64 til 255 tegn, så et opplastet bilde ikke blir
klippet.
068 legger inn Kepler som referansekunde, med bilde og tekst. Logoen står tom
til den er lastet opp.
069 legger til nettadressen til Kepler.
070 gir bildetekst og alt-tekst på toppbildet i en artikkel.
071 gir frys av medlemskap: søknaden, svaret og perioden.
072 gir nivå, varighet og de redigerbare kurstekstene: «Dette lager du»,
«Dette får du med hjem», «Når er den ferdig» og «Godt å vite».
073 retter «faar» til «får» og «verktoy» til «verktøy» i kursteksten på
Keramikk Workshop.
074 gir «Gjenstanden betales i verkstedet», og slår den på for Paint on
Pots.
075 setter plassen på Paint on Pots til 0. Prisen var 690 — prisen *med*
gjenstanden — og etter 074 ville kunden betalt den summen for plassen og
gjenstanden i tillegg.
076 gir `course_sessions.fra_apningstid`: Paint on Pots legges ut på de åpne
tidene, og markeringen holder dem utenfor åpningstidsregnestykket.
077 fjerner «Glasurkveld for medlemmer», som ikke finnes. Avlyser i stedet for
å slette hvis noen har meldt seg på.
078 setter motkontoene — Vipps 1510, Kontant 1900, Faktura 1920 — og
gavekortkontoen 2905. Bare der feltet står tomt.

Under Admin → Oversikt → Vedlikehold. Uten dem finnes ikke `deltaker_bilder`,
`internt_notat` og `hentet_at`, og «Ferdig glassert» sier fra at den mangler
dem framfor å virke. 056 gir gjentakelsen i steg 2 «annenhver uke» og
«månedlig» — uten den virker bare det ukentlige, og skjermen sier fra.
057 gir feltet for allergier ved påmelding. Uten den går påmeldingen
gjennom som før, men opplysningen blir ikke lagret.
058 gir flerdagerskurs med samlinger, og pris og informasjon per kursdato.
059 gir kalenderen SEQUENCE og LAST-MODIFIED, så en endret kursdato faktisk
oppdaterer seg på telefonen.
060 gir overstyring av åpningstidene — helligdager, ferie og stengt-dager.

### 8. ~~Nøklene byttes~~ — gjort 26. august

Lissom: «nøklene er byttet.» Tabellen står igjen for historikken.

Ble limt inn i en chat 26. august og var kompromittert:

| Nøkkel | Hvor |
|---|---|
| `db_passord` | cPanel → MySQL Databases → Change Password |
| `vipps_client_secret` | portal.vipps.no → Utvikler |
| `smtp_passord` | cPanel → Email Accounts |
| `cron_nokkel` | `php -r "echo bin2hex(random_bytes(24));"` |
| Shutterstock consumer key og secret | shutterstock.com → appen din |

`claude_api_key` er allerede byttet.

Framgangsmåte: bytt ett sted, oppdater `~/lissom-secrets/secrets.php`, test.
Én om gangen.

### 8b. «Glasurkveld for medlemmer» finnes ikke — fjernet 27. august

Kurset ble lagt inn av migrasjon 003, den gangen katalogen var gjettet framfor
hentet. Lissom har ingen glasurkveld. Migrasjon 077 sletter det — men bare hvis
ingen har meldt seg på; er det påmeldte, avlyses det i stedet, så påmeldingen
står igjen.

De to andre interne samlingene fra samme seed står fortsatt: **«Store former,
viderekomne»** og **«Medlemsfrokost»**. De er ikke spurt om, og ikke rørt.

### 9. Dublettene i medlemslista

Flere rader for samme person. Slettes for hånd under Medlemmer.

### 10. `wp_`-tabellene og den gamle WordPress-brukeren

Ligger igjen i databasen fra den forrige nettsiden. Kan fjernes i cPanel.

---

## Stopper på noe utenfor

### 11. Vipps — **i drift 27. august**

Godkjent, nøklene lagt inn, og kontrollert med «Test Vipps» mot produksjon:

| | |
|---|---|
| Miljø | Produksjon, mot api.vipps.no |
| Nøkler for betaling | Godtatt. Salgsenhet **1143163**, eget sett |
| Trekk for medlemskap | Virker — Recurring er åpen |
| Innlogging med Vipps | Eget sett, salgsenhet **1142801** |

To salgsenheter, som antatt: betaling og innlogging hver for seg.

**Prøvebetalingen feilet på første forsøk**, og det var min feil, ikke
oppsettets. Den sto på 1 øre. Vipps godtar ikke beløp under 100 øre og svarer
400 «Invalid amount» med en tekst om at beløp må være heltall uten desimaler
— som er sant, men ikke det som er galt. Prøvebeløpet er nå én krone, og
`Vipps::MINSTE_BELOP_ORE` stanser for små beløp før de sendes, med en
melding som sier hva som faktisk er galt.

Samme grense gjelder en ekte handel: dekker et gavekort alt bortsett fra
under én krone, regnes resten som dekket. Vipps kan ikke ta beløpet, og å
runde opp ville vært å kreve mer enn kunden skylder.

**Kontrolleres med «Test Vipps»** nederst i sidemenyen i admin. Den gjør de
ekte kallene og svarer i klartekst på fire ting: miljø, nøkler, betaling for
kurs, trekk for medlemskap. Prøvebetalingen er på én krone og avbrytes med det
samme — ingen penger flyttes.

**To ting må settes i `app/secrets.php` på serveren:**

```php
'miljo'      => 'produksjon',
'vipps_base' => 'https://api.vipps.no',

// Betalingen har sitt eget sett nøkler, på sin egen salgsenhet.
'vipps_betaling_msn'           => '',
'vipps_betaling_client_id'     => '',
'vipps_betaling_client_secret' => '',
'vipps_betaling_sub_key'       => '',
```

Står det fortsatt `test` og `apitest.vipps.no`, går ingen ekte betalinger
gjennom — og nøklene fra Vipps virker uansett bare mot det miljøet de hører
til. «Test Vipps» sier fra om dette på første linje.

**Innlogging og betaling er to produkter hos Vipps**, og godkjennes hver for
seg. Da kommer de med hvert sitt sett nøkler, på hver sin salgsenhet. De fire
`vipps_betaling_*`-feltene er for betalingen; `vipps_msn`, `vipps_client_id`,
`vipps_client_secret` og `vipps_sub_key` blir stående som innloggingens.

Står `vipps_betaling_*` tomme, brukes innloggingens fire — som før. Det er
riktig hvis dere bare har fått ett sett. Har dere fått to, må de inn her: et
token fra én salgsenhet sammen med et MSN fra en annen gir 401 fra Vipps, og
det er akkurat den feilen som er vanskeligst å lese seg til.

«Test Vipps» viser hvilken salgsenhet den prøvde, og om betalingen bruker sitt
eget sett eller deler med innloggingen.

**Webhooken registreres fra samme sted.** Nederst i «Test Vipps» står det om
Vipps melder fra hit når noen betaler, og en knapp som registrerer den.
Hemmeligheten Vipps signerer med vises bare den ene gangen webhooken
opprettes — derfor lagres den av serveren med det samme, i `innstillinger`.
Den kan ikke hentes fram igjen, bare erstattes av en ny registrering.

Uten webhooken går ingenting tapt: cron spør Vipps hvert femte minutt. Men da
kan kunden vente like lenge på kvitteringen.

Vipps ba 26. august om å se hvor og hvordan kunden sier opp medlemskapet.
Det står i salgsvilkårene, begge steder, og er publisert.

### 12. Video på kurs

Ikke bygget. Feltet i veiviseren sier fra at det ikke er koblet opp. Film på
et delt webhotell er en annen sak enn et bilde, både i plass, båndbredde og
avspilling — det må bestemmes hvor filmene skal ligge før noe kan bygges.

Lissom 26. august: «videokurs er noe vi kan se på senere, men du kan bygge et
kort under kurs og medlemskap som heter videokurs, og la det være tomt.»
Kortet står der nå, og fører til en skjerm som sier hva som mangler.

---

## Paint on Pots — bygget etter alternativ B

Lissom valgte B: **plassen bookes, gjenstanden betales i verkstedet.**

Slik var det: Paint on Pots lå som et vanlig kurs til 690 kroner. Alle betalte
det samme uansett hva de valgte å male — en sommerfuglkopp til 300 og en tekopp
med skål til 750 kostet likt. Prislista på siden sto skrevet inn i koden med
fire linjer, og ingen av tallene stemte: «Bolle fra 340» og «Figurer fra 190»
fantes ikke som kategorier i butikken i det hele tatt.

Slik er det nå:

- **Ny kolonne `courses.gjenstand_i_kassa`** (migrasjon 074), på for Paint on
  Pots og av på alt annet.
- **Prislista på `/paint-on-pots` kommer fra butikkvarene.** Kategori og
  laveste pris, regnet av det som faktisk står publisert og ikke er utsolgt.
  Endrer prisen eller utvalget seg, følger siden etter.
- **«Slik betaler du»** står under prislista: hva plassen koster, og at
  gjenstanden velges i verkstedet og betales der.
- **Bookingen sier det samme** i en egen ramme under prisen — «Gjenstanden
  kommer i tillegg», med laveste pris fra butikken. Den vises bare på kurs der
  haken er på.
- **Haken står i kursoppsettet**, i prisseksjonen. Den advarer når prisen på
  plassen fortsatt ser ut som gjenstandsprisen: *«Plassen står til 690 kroner.
  Var det prisen med gjenstanden, betaler kunden nå den summen for plassen og
  gjenstanden i tillegg.»*
- **Gjenstanden slås inn i kassa** under «Varer i butikken» — da trekkes
  lageret, og salget føres på butikkontoen med mva, som alt annet butikksalg.

**Plassen er gratis** (migrasjon 075, bestemt av Lissom 27. august). Du booker
et bord, og betaler bare det du tar med deg hjem. Bookingen tåler det fra før:
et beløp under Vipps sitt minstebeløp markeres som betalt uten å sende noen til
Vipps, og kunden får bekreftelse som vanlig.

**Priser står ikke i teksten.** Prislista er tatt bort fra siden, og kortene
står uten pris. Prisen står i bestillingen, som «Fra kr. N,-» — plassen pluss
den rimeligste varen som faktisk er publisert og på lager. Er den billigste
koppen 300, står det 300; settes noe til 290, står det 290.

### Fire feil som lå bak dette

- **«Gratis» og «kun for medlemmer» var det samme** i `app/lib/booking.php`:
  null kroner betydde medlemsarrangement, punktum. Det holdt så lenge det
  eneste gratis vi hadde var medlemssamlinger. Paint on Pots med gratis plass
  svarte «Dette arrangementet er kun for medlemmer» til alle. Nå er kurs der
  gjenstanden betales i verkstedet unntatt; vanlige gratiskurs krever fortsatt
  medlemskap, og det er kontrollert.
- **Booket du fra Paint on Pots-siden, sto dreiekursets beskrivelse der.**
  Kortene ble bygget for hånd med ti felter, og alt som ikke sto der falt
  tilbake på designlista. Kortene bygges nå på kurset selv.
- **Den samme dreiekursteksten var siste utvei for alle kurs uten egen
  beskrivelse.** Nå kommer den fra kursets mal, og finnes den ikke, står det
  ingenting.
- **`api/admin/kurs.php` satte kapasiteten til 8** hver gang noe lagret et kurs
  uten å sende plasstallet. Paint on Pots gikk fra 12 til 8. Samme gjaldt pris,
  tema, beskrivelse og SMS-haken. Feltene skrives nå bare når de er med.

Og `Kursmal::forKurs` falt på standardmalen når tema var tomt — Paint on Pots
sto med tema NULL og fikk «Innføring i plateteknikk og dekor» på et kurs der man
maler ferdig brent keramikk. Uten tema leses det av navnet nå, og Paint on Pots
har egne punkter: det er ingen leire i dem.

### Stegene og spørsmålene — gjort 27. august

De fire stegene og de fire spørsmålene lå fast i koden. Nå ligger de under
**Nettsiden → Innhold → Paint on Pots**, som blokk 7 «Slik gjør du det» og
blokk 8 «Spørsmål og svar». Tømmer du både tittel og tekst på et steg, faller
steget ut og de andre nummereres om; tømmer du et spørsmål, faller det ut.

Blokkene ligger **sist** med vilje: nøklene er `Paint on Pots/indeks/Felt`, og
en ny blokk midt i lista ville flyttet indeksen på alt under — da havner teksten
Lissom har skrevet på feil sted.

**Underveis: `popFaq` var slettet.** Den forsvant sammen med prislista tidligere
samme dag, og spørsmålene hadde vært borte fra siden siden da. Ingen vakt sa
fra: en `sc-for` over en liste som ikke finnes tegner bare ingenting, og
knappesjekken teller bindinger som *har* en verdi — en binding som er borte
forsvinner også ut av tellingen. `bin/listesjekk.mjs` er ny og fanger det: den
finner hver `sc-for` og `sc-if` i malen og sier fra om navnet ikke settes noe
sted. Kontrollert ved å fjerne `popFaq` med vilje — den fanget det.

**Bookbar når verkstedet er åpent — gjort 27. august.** Paint on Pots følger
døren: hver åpen periode gir bookbare plasser, og de settes ikke opp for hånd.

- Regelen for når det er åpent lå inne i `api/apningstider.php`, og bare der.
  Den er flyttet til `app/lib/apent.php`; endepunktet bruker den, og
  utleggingen bruker den. Endepunktet svarer nøyaktig som før — kontrollert mot
  den gamle fila på samme data.
- Migrasjon 076 gir `course_sessions.fra_apningstid`. Den trengs til to ting:
  rydding (en generert plass ingen har booket, og som ikke lenger svarer til en
  åpen tid, tas bort igjen — en plass lagt inn for hånd røres aldri), og
  sirkelen (åpningstiden regnes av øktene som står ute, så talte de genererte
  med, ville verkstedet holdt seg åpent av sin egen skygge).
- **Plassene er to timer**, og de ligger inne i de faktiske øktene — ikke
  spredt over timene mellom dem. Ingen kan booke klokka 15 når huset er tomt.
  Inntil fem tidspunkt per dag, så det er noe å velge mellom på en lang dag.
- **Ett kort, ikke ett per dato.** Paint on Pots står som ett kort på siden, med
  bildet fra kurslista og «N ledige tider». Datoen velger man inne i
  bestillingen. Fjorten kort med samme bilde og samme tekst, der bare datoen
  skilte dem, var en vegg.
- **Velg dato, så tidspunkt.** Bestillingen viser **tre dager** om gangen, med
  «Vis N datoer til» under. Trykker du en dag, kommer tidspunktene den dagen —
  som klokkeslett: 10:00, 16:00, 18:00. Du har plassen i **to timer** fra
  tidspunktet du velger, og det står under knappene.

  Gjelder alle kurs. Har dagen bare ett tidspunkt — som på et vanlig kurs — er
  det valgt med det samme, men det **vises likevel**: trykker man en dag og
  ingenting skjer, ser det ut som knappen er i stykker. `api/kurs.php` sender
  `dag`, `klokke` og `klokkeStart` som egne felter; å klippe dem ut av
  «tirsdag 1. september, 10:00–12:00» med et komma ryker på første
  flerdagerskurs.

- **«Se datoer og book» går rett i bestillingen.** Den rullet ned til
  datolista, der man måtte trykke «Book plass» én gang til. Nå som Paint on
  Pots står som ett kort, og dag og tidspunkt uansett velges inne i
  bestillingen, var mellomsteget et trykk uten innhold. Finnes det ingen
  datoer, ruller den ned som før — der står det hva man gjør i stedet.

- **Ramma «Gjenstanden kommer i tillegg» er fjernet** (27. august, på
  bestilling). Det samme står i «Godt å vite» og «Dette får du med hjem» på
  kurset, og prisen sier allerede «Fra».

- **Hele to timer, eller ingenting.** Er det under to timer igjen av det åpne
  vinduet, settes det ikke opp et tidspunkt. En åpen periode 10–13 gir ett
  tidspunkt (10:00), ikke to der det andre bare varer én time.

- **Flere kurs samme dag** blir til perioder, ikke én lang åpning. 3. september
  i testdataene: Store fat 10–13, drop-in 16–19, Store fat 17–20, Date Night
  18–21. Det er to perioder — 10–13 og 16–21 — og tidspunktene blir 10:00,
  16:00 og 18:00. Mellom 13 og 16 står huset tomt, og der settes ingenting opp.
  (20–21 faller også bort: under to timer.)

- **Et flerdagerskurs åpner ikke natta.** Økta lagres som én rad fra første dag
  til siste — dreiekurset går 17–20 to kvelder og står som «9. sept 17:00 →
  10. sept 20:00». Hver dag ble klippet mot døgnet, så dag to sto som åpen fra
  **00:00**: bunnteksten sa «00:00–20:00», og etter at Paint on Pots begynte å
  følge åpningstidene ble natta bookbar. Nå går et flerdagerskurs de samme
  klokkeslettene hver dag, som varigheten alt regnes («3 timer per gang ·
  2 ganger»). En ekte nattevakt — 22:00 til 02:00 — klippes mot døgnet som før.
- **Interne samlinger teller med** — bestemt av Lissom 27. august. En
  medlemskveld gjør både at bunnteksten sier åpent, og at Paint on Pots kan
  bookes den kvelden. Se punkt 13.
- **Et vindu som alt har begynt** gir en plass fra nå, ikke fra i formiddag.
  Ellers kunne ingen booke samme dag etter at dagens første kurs startet.
  Økta gjenkjennes på sluttiden, så den ikke slettes og lages på nytt hvert
  kvarter mens døren står åpen.
- **Stemplet inn** åpner tre timer fram, også når kalenderen er tom.
  Utleggingen kjøres på stempling inn og ut, og ellers på bakgrunnstikket.

---

## Bestilt 27. august, kveld — gjort samme kveld

- **Min side var nede.** `renderVals()` kalte `this.hentFrys()` på Min side og
  `this.hentFrysAdmin()` under Medlemmer, og ingen av dem fantes: frys av
  medlemskap var bygget ferdig i skjermen og i API-et, men de fire metodene
  som henter og sender lå aldri i fila. Hele nettsiden stoppet med
  «this.hentFrys is not a function» — for alle. Metodene er skrevet, og
  `bin/metodesjekk.mjs` er ny: den finner alt som kalles med `this.<navn>()`
  og sier fra om noe av det ikke er definert. Den fanger dette neste gang.
- **Adminmenyen på telefon.** Hele menyen lå som én rad man måtte dra
  sidelengs i: ti punkter, tre synlige om gangen, og underpunktene under hvert
  område var ikke synlige i det hele tatt. Nå står det hvor du er, og ett
  trykk viser hele kartet — to spalter, alt på skjermen, med underpunktene til
  området du står i under det.
- **«Uttak butikk» heter Kasse.** Den åpner på **Salg**: ett beløp, kontant
  eller Vipps, og om det var kurs, medlemskap eller produkt. Raden øverst har
  tre valg — Salg, Varer i butikken, Internbutikk. Varelista er delt i butikk
  og internbutikk; internvarene lå blandet inn mellom koppene.

  «Hva var det» setter `payments.formal`, som er det dagsoppgjøret og
  transaksjonsuttrekket grupperer på — kontoen og mva-koden hentes derfra, fra
  oppsettet under Økonomi → Regnskap. Et kassesalg havner dermed på samme
  konto som det samme salget gjort på nett, uten et eget regelverk ved siden
  av. Kurs, event, drop-in og medlemskap sto først som egne valg i raden; de
  er tatt ut igjen samme kveld på bestilling — påmeldinger legges inn for hånd
  fra «Meld noen på» på Oversikt.
- **«Ferdig glassert» heter «Klar til henting»**, som ute på nettsiden. Den
  lagrede rekkefølgen på kortene følger med.
- **Nytt kort på Oversikt: kurs som går tomme for datoer.** Kurssidene lover
  tre datoer å velge mellom, og datoer tar slutt uten at noen sier fra. Kortet
  sier hvilke kurs det gjelder og går rett til kurslista.
- **Kursfeltene lar seg redigere.** Ny seksjon 08 i kursoppsettet: nivå
  (internt Nybegynner/Videregående, og den offentlige nivåteksten), varighet,
  kort beskrivelse, «Dette lager du», «Dette får du med hjem», «Når er den
  ferdig» og «Godt å vite». Tomt felt betyr den anbefalte teksten — den står
  under feltet, og «Gjenopprett anbefalt tekst» tømmer feltet framfor å lime
  inn ordlyden, så en senere endring i malen når fram til kurset.
- **`api/admin/kurs.php` tømte de nye kurstekstene.** De ble skrevet ved hver
  lagring, også når kallet ikke hadde dem med — hurtigskjemaet på mobil ville
  dermed slettet «Dette lager du» og resten på et kurs de var skrevet på. Nå
  er de i samme vakt som de øvrige tekstfeltene.
- **Ny fane «Kurs» under Kurs og medlemskap:** bare kursene, ett per rad,
  sortert på navn, uten kalender og datoliste. Det er her man retter noe.
- **Oversikt er ryddet.** «Medlemmer og timer» er tatt bort — lista står under
  Medlemmer → Alle medlemmer. «Interne samlinger» og «Ikke fornyet — minn dem
  på» er flyttet til Medlemmer, hos menneskene de gjelder.

## Bestilt 27. august — gjort samme dag

- **«Hva medlemmer ser» og «Hva kursdeltakere ser»** flyttet fra Kurs og
  medlemskap til henholdsvis Medlemmer og Deltakere.
- **«Mine kursbevis» som eget kort på Min side.** Beviset lå bare som en knapp
  på raden til påmeldingen; var kurset et halvt år siden, måtte man lete
  nedover i lista. Kortet samler dem, nyeste først, med den samme lenken.
- **Tilbakeknappen tok deg alltid til forsiden.** Det lå to rutinger ved siden
  av hverandre: den ekte leser stien i adressen, den andre leste «#»-delen —
  og det står ingen hash der, så den falt tilbake på «forside» og skrev
  adressen om til «/». Hash-rutingen er fjernet.
- **«Ferdig glassert» strakk kortene** over hele bredden når det bare var ett
  kurs. Faste kort på 280 piksler nå.
- **Kalenderen ute førte til fellessida.** Trykket gjettet kurset ved å lese
  tittelen ut av etiketten; bommet den, havnet du på kurslista. Oppføringen
  bærer nå nummeret på kurset og på datoen, og åpner akkurat den.
- **Kurslista i admin sto sortert på når kursene ble opprettet.** Nå etter når
  de går neste gang; kurs uten datoer framover står sist. Kolonnen «neste»
  viste den *første* datoen når det ikke fantes noen framover — altså en som
  hadde vært. Nå står det «Ingen dato framover».
- **Kalender i Kurs og medlemskap**, som ute på nettsiden, men med interne
  samlinger og fulle datoer i tillegg. Trykk en dato for å se de påmeldte.
- **Søkefelt i kurslista.** Kalenderen og datolista står urørt av det.
- **Kursvelgeren** flyttet fra fanerekka under Nettsiden til et kort på
  Oversikt.
- **Medlemslista åpner på «Aktive»**, ikke «Alle».
- **Bildesøk rett i markedsføringen.** «Finn bilder» åpnet shutterstock.com i
  en ny fane. Artikler, nyhetsbrev og sosiale medier har nå en bilderute i
  skjemaet — den samme velgeren kursene bruker — og bildet følger utkastet
  helt inn i artikkelen. Video er ikke med: det krever eget abonnement og
  egen API-tilgang hos Shutterstock.
- **«Kursene våre»** på `/kursene-vare`: hvert kurs med beskrivelsen fra
  kursoppsettet, varighet, pris, nivå og hva som er inkludert. Knappen «Les
  mer om våre kurs her» står under Kurs.
- **Datoene til kurset du har åpent.** Datolista under kursskjemaet viste alle
  datoene i basen; nå viser den kurset sine, de neste åtte ukene, med en
  knapp for resten. «Tilbake til kurslista» står øverst i skjemaet.
- **Referansekundene rullerer sammen med events og medlemskap** på forsiden.
  De sto i en egen seksjon rett under, med sin egen klokke og sine egne
  prikker — det samme oppsettet to ganger på rad. Nå er det ett felt. En kunde
  må ha et bilde for å stå ute; venstre halvdel av feltet *er* bildet, og
  admin-lista sier «Mangler bilde» framfor «På forsiden».
- **Bildetekst og alt-tekst på toppbildet i artikler** (migrasjon 070).
  Bildene inne i teksten hadde det fra før; toppbildet — det flest ser, og det
  som følger med når noen deler lenken — sto uten. Artikkelen under Nyttig
  info hadde bildet som en tom `div` med bakgrunnsbilde, altså ingen alt-tekst
  i det hele tatt. Begge tegnes nå som `figure` med `figcaption`.
- **Kategoriene som ett trykk** i redigeringen av en artikkel. Feltet var fritt
  tekstfelt; de seks faste står nå som knapper ved siden av. *Ikke* under
  Nyttig info: de sidene hentes nettopp på at de ikke har kategori.
- **Skjemaet under Nyttig info fikk hele bildebiblioteket.** Før var det fem
  faste fotografier og ingenting annet.
- **Rettet:** «lagre» på en artikkel skrev alltid bilde-kolonnen, også når
  ingen hadde sendt et bilde. Å lagre tittelen og teksten slettet dermed
  bildet. Kolonnen røres nå bare når den står i det som ble sendt.
- **Deltakerkortet under Ferdig glassert** sto som én lang stripe i full
  bredde: bildene, notatet, knappene og hele meldingshistorikken under
  hverandre. Nå to spalter med historikken under, 454 piksler høyt.
- **«Les mer om våre kurs»** er blitt et gult felt med kicker, overskrift,
  lengre tekst og tre stikkord — ikke en tynn ramme man ruller forbi. Teksten
  redigeres under Nettsiden → Innhold som før, og «Foreslå tekst» lar AI-en
  lese kursene som ligger ute og skrive et forslag rett i feltene. Ingenting
  lagres før du trykker Lagre.
- **Frys av medlemskap** (migrasjon 071). Bryteren lovet en funksjon som ikke
  fantes: medlemmene fikk en knapp som ikke gjorde noe. Nå søker medlemmet om
  en periode fra Min side, verkstedet svarer under Medlemmer, og medlemskapet
  settes i pause den dagen frysen begynner og åpner seg igjen av seg selv når
  den er over. Trekket stopper ikke av seg selv — Vipps kan ikke sette en
  avtale på pause, bare stoppe den — og det står i klartekst begge steder.

## Venter på svar fra Lissom

### 13. Teller interne samlinger med i åpningstiden?

Åpningstiden i bunnteksten regnes av alle publiserte kursdatoer den dagen,
fra det første starter til det siste slutter. Den skiller ikke på hva slags
kurs det er: en intern samling for medlemmer teller med, selv om den ikke
vises i kurskalenderen ute. Lissom spurte 27. august hvilket kurs som lå til
19:00 og fant det ikke — det er sannsynligvis dette.

**Avklart 27. august: de teller med.** Lissom valgte at en intern samling både
skal gjøre at bunnteksten sier åpent, og at Paint on Pots kan bookes den
kvelden. Regelen står som den er.

Verdt å vite hva det betyr: går det en glasurkveld for medlemmer 20–22, kan en
kunde utenfra booke en Paint on Pots-plass 20–22 samme kveld. Det var et
bevisst valg — verkstedet er bemannet, og døren er åpen.

### 14. ~~Skal innstempling fra et vanlig medlem åpne verkstedet?~~ — avklart 27. august

**Nei.** Lissom: «innstempling er kun når admin logger inne.» Det er slik det
virker i dag — `Stempling::verkstedetBemannet()` teller bare `rolle = 'admin'`.
Ingen endring; punktet lukkes.

Det betyr også at et medlem alene i verkstedet ikke gjør Paint on Pots
bookbart. Bare når Lissom selv er stemplet inn.

### 15. ~~Date Night-seksjonen på Paint on Pots~~ — avklart 27. august

Lissom: «la den bli.» Teksten står, og kan redigeres under Nettsiden → Innhold
→ Paint on Pots → Date Night.

## Ferdig, men verdt å vite

- **Plassene følger kategorien** (migrasjon 052): Dreiing 8, og
  Plateteknikk, Workshop, Sip & Clay, Date Night og Paint on pots 12.
  Gjelder kursene, datoene som ligger ute, og de faste ukedagene.
- **Date Night og Paint on Pots lå i kategorien «Events»**, som ble fjernet
  25. august. De var usynlige under sine egne filtre. Flyttet i 052.
- **Ledige plasser vises etter regel, ikke som råtall.** Dreiing: 6 og opp
  «Ledige plasser», 4–5 tallet, 1–3 «Få plasser». De andre: 7 og opp
  «Ledige plasser», 5–6 tallet, 1–4 «Få ledige plasser». Ett sted i koden,
  brukt på kortene, i datovelgeren, på kurssida og i medlemslista.
- **Miniatyrene i bildesøket ble blokkert av vår egen sikkerhetsregel.**
  Søket ga tjuefire ruter og ingen bilder. `img-src 'self'` i `.htaccess`
  slipper bare bilder fra vår egen tjener, og det gjelder også
  bakgrunnsbilder i CSS. Miniatyrene hentes nå hit og sendes videre, med
  signert adresse, framfor å åpne regelen for hele nettstedet.
- **Søket går på norsk.** Språkkoden er `nb`. Svarer Shutterstock 400 på
  den, søkes det uten framfor å gi en feilmelding.
- **Bildesøk hos Shutterstock, med nedlasting.** Søket ligger i
  billedvelgeren og henter bildet rett inn i biblioteket, lisensiert.
  Tre ting måtte på plass før det gikk: språkkoden `nb` (norsk gir null treff
  uten), miniatyrene måtte gjennom vår egen tjener fordi `img-src 'self'`
  blokkerte dem, og nedlasting krever at Lissom selv har sagt ja én gang hos
  Shutterstock — en nøkkel fra appsida får ikke bruke abonnementet. Den
  tilkoblingen gjøres med «Koble til Shutterstock» og lagres i basen.
- **Forhåndsvisninga i steg 3 viste et annet bilde enn kortet.** Ramma var
  4:3 mot kortets 16:10, og utsnittet sto låst i midten uansett hva som var
  valgt — skriptet som legger på utsnittet ser bare på `<img>`, og rutene er
  knapper med bakgrunnsbilde. Karusellen på kurssida hadde samme hull
  motsatt vei. Bilder uten valgt utsnitt står som før.
- **«Klar til henting».** Kortet «Ferdig glassert» på Oversikt viser
  kursdatoer som er ferdige, med antall deltakere. Ett trykk sender e-post
  til alle på datoen og legger ut en melding på lissom.no/ferdigbrent i tre
  uker. «Ta meldingen ned» angrer linja; e-post som er sendt står som sendt.
  Varselmalen `ferdig_brent` hadde ligget i basen siden migrasjon 002 uten at
  noe noen gang sendte den. Migrasjon 053.
- **Gjentakelse i steg 2 legger endelig ut datoer** (migrasjon 056).
  «Ukentlig / Månedlig / Egendefinert» og «Antall ganger» lot seg trykke på,
  men ble aldri sendt til serveren — den som satte «Ukentlig · 10 ganger» og
  trodde høsten var planlagt, fikk en tom kalender. «Egendefinert» var
  fritekst som ingen maskin kan lese. Valgene er nå Ingen, Ukentlig,
  Annenhver uke og Månedlig, og de mater den samme regelen i `kurs_serier`
  som allerede lå der — ikke et system nummer to ved siden av.
- **Notisen «Ingenting denne måneden. Det neste som skjer:»** er tatt bort
  fra programmodulen. Lista over hva som kommer står som før, og den tomme
  tilstanden «Ingenting satt opp i september» likeså.
- **«Små grupper · Leire, glasur og brenning inkludert»** er tatt bort fra
  kurssida. Feltene står igjen under Nettsiden → Innhold, tomme, så det kan
  skrives noe annet der uten å gå i koden. Prikken mellom dem vises bare når
  det faktisk står noe på begge sider.
- **«Ferdig glassert» går nå på deltakeren, ikke på datoen** (migrasjon 055).
  Hver deltaker har egen status, egen meldingshistorikk med kanal, tekst og
  feil, internt notat som bare verkstedet ser, og «hentet». Beskjed kan sendes
  til én eller til alle som mangler. Kurset flytter seg til «Sendt beskjed»
  først når alle har fått den. Deltakeren legger selv inn bilder av
  keramikken sin under Min side — det er det som avgjør hvem som har laget
  hva når det står tjue ting på hylla. Hele testresultatet står i
  `docs/FERDIG-GLASSERT.md`.
- **«Send til alle» sendte til den som alt stod i køen.** Vi hoppet over dem
  som var *levert*, ikke dem som var *sendt herfra*. En beskjed som lå i køen
  så ut som om den ikke fantes, og deltakeren fikk to like e-poster. Funnet
  av testen, ikke av en kunde.
- **«Maks N deltakere» regnes ut av kurset.** Sto som fast tekst seks steder
  og spriket mot tallene under.
- **Utløpt innlogging sier fra.** Innloggingen varer tre timer. Gikk den ut,
  svarte serveren 401 og sida hørte ikke etter: admin så innlogget ut mens
  hvert trykk stille feilet — billedlista sto på «Henter bildene …» for
  alltid, og opplasting ga «Gikk ikke». Nå sier den fra og sender til
  innlogging.
- **Haken «vis kurset uten datoer» virker på nye kurs.** Den lagret rett til
  serveren, og et kurs som ikke var opprettet ennå hadde ingen id å lagre på
  — da lot den seg ikke huke av i det hele tatt.
- **Plassene i skjemaet følger kategorien.** Sto alltid på 8; et nytt
  workshop ble lagret med åtte plasser mot de tolv som gjelder.

- **Migrasjon 046–051 er kjørt** på lissom.no. Dagsoppgjør, plasser på
  events, ventelistens e-posttekst, standard bekreftelsestekst og
  bildeutsnitt er aktive.
- **Dagsoppgjøret leveres som CSV** under Økonomi. Kolonnene følger
  regnskapsførerens oppsett, men er ikke verifisert mot en importmal fra
  Tripletex. Prøv én måned først.
- **AI-en er koblet til** siden 26. august. Tak 300 kr i måneden, endres
  under Markedsføring → Innstillinger.
