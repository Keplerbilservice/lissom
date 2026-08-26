# Åpne punkter

Alt Lissom har bedt om som ikke er gjort, og alt som venter på et svar.

**Hvorfor denne fila finnes:** lista sto i hodet mitt, og samtaler komprimeres
når de blir lange. Da forsvinner det som er sagt tidlig, og jeg svarte
«gjenstår ingenting» på ting som gjensto. Fila her overlever det. Den skal
oppdateres i samme commit som arbeidet gjøres — ikke etterpå.

Sist gjennomgått: 26. august 2026, etter at «Ferdig glassert» ble bygd ferdig.

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

Løsningen er den samme: flytte punktene til databasen med et felt i
kursveiviseren.

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

### 2. Full redigering av et kurs på ett sted

Samme sak som over. Navn, pris, plasser, beskrivelse, bekreftelse, bilder og
datoer kan redigeres. Punktlista — det kunden leser under «Alt som er
inkludert» — kan ikke.

### 3b. ~~Bildesøk med API~~ — gjort 26. august

Søk og nedlasting virker begge veier. Se «Ferdig» nederst.

### 3c. ~~«Klar til henting»-liste~~ — gjort 26. august

Bygget og testet. Se «Ferdig» nederst.

### 3. «Fjern bildet»

Knappen i billedvelgeren heter «Bruk standardbildet i stedet». Misvisende på
bilderute 2 og 3, der det ikke finnes noe standardbilde. Skal hete «Fjern
bildet» der.

### 4. ~~Hva som skal stå i stedet for «10 ledige»~~ — gjort 26. august

Bestilt og bygget. Se «Ferdig» nederst.

Én ting å bekrefte: teksten når det er få igjen står som «Få plasser» på
Dreiing og «Få ledige plasser» på de andre — slik Lissom skrev det. Skal de
være like?

---

## Venter på et svar

### 5. Gjentakelse-feltet i steg 2

«Ukentlig / Månedlig / Egendefinert» + «F.eks. 10 ganger» sendes aldri til
serveren og lagres ingen steder. Feltet gjør ingenting. Skal det kobles opp
så det lager datoene, eller fjernes?

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

### 7. Manuelle betalinger uten betalingsmåte

Bokføres mot Vipps (1510) i dagsoppgjøret. Skal den heller kreve at
betalingsmåte er satt?

Lissom 26. august: «denne må jeg komme tilbake til senere.» Ligger til hen
har bestemt seg.

---

## Må gjøres av Lissom

### 7b. Migrasjon 052–056 må kjøres

Under Admin → Oversikt → Vedlikehold. Uten dem finnes ikke `deltaker_bilder`,
`internt_notat` og `hentet_at`, og «Ferdig glassert» sier fra at den mangler
dem framfor å virke. 056 gir gjentakelsen i steg 2 «annenhver uke» og
«månedlig» — uten den virker bare det ukentlige, og skjermen sier fra.

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

### 9. Dublettene i medlemslista

Flere rader for samme person. Slettes for hånd under Medlemmer.

### 10. `wp_`-tabellene og den gamle WordPress-brukeren

Ligger igjen i databasen fra den forrige nettsiden. Kan fjernes i cPanel.

---

## Stopper på noe utenfor

### 11. Vipps

ePayment svarer 403 på salgsenhet 1142801. Recurring er ikke godkjent.
Betaling virker ikke før Vipps åpner. Ingenting å gjøre i koden.

Vipps ba 26. august om å se hvor og hvordan kunden sier opp medlemskapet.
Det står nå i salgsvilkårene, begge steder, og er publisert. Venter på svar.

### 12. Video på kurs

Ikke bygget. Feltet i veiviseren sier fra at det ikke er koblet opp. Film på
et delt webhotell er en annen sak enn et bilde, både i plass, båndbredde og
avspilling — det må bestemmes hvor filmene skal ligge før noe kan bygges.

Lissom 26. august: «videokurs er noe vi kan se på senere, men du kan bygge et
kort under kurs og medlemskap som heter videokurs, og la det være tomt.»
Kortet står der nå, og fører til en skjerm som sier hva som mangler.

---

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
