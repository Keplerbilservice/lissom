# Åpne punkter

Alt Lissom har bedt om som ikke er gjort, og alt som venter på et svar.

**Hvorfor denne fila finnes:** lista sto i hodet mitt, og samtaler komprimeres
når de blir lange. Da forsvinner det som er sagt tidlig, og jeg svarte
«gjenstår ingenting» på ting som gjensto. Fila her overlever det. Den skal
oppdateres i samme commit som arbeidet gjøres — ikke etterpå.

Sist gjennomgått: 26. august 2026.

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

### 6. Datoene fra den første katalogen

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

---

## Må gjøres av Lissom

### 8. Fire nøkler byttes

Ble limt inn i en chat 26. august og er kompromittert:

| Nøkkel | Hvor |
|---|---|
| `db_passord` | cPanel → MySQL Databases → Change Password |
| `vipps_client_secret` | portal.vipps.no → Utvikler |
| `smtp_passord` | cPanel → Email Accounts |
| `cron_nokkel` | `php -r "echo bin2hex(random_bytes(24));"` |

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

### 12. Video på kurs

Ikke bygget. Feltet i veiviseren sier fra at det ikke er koblet opp. Venter
på bestilling — film på et delt webhotell er en annen sak enn et bilde, både
i plass, båndbredde og avspilling.

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
- **«Maks N deltakere» regnes ut av kurset.** Sto som fast tekst seks steder
  og spriket mot tallene under.

- **Migrasjon 046–051 er kjørt** på lissom.no. Dagsoppgjør, plasser på
  events, ventelistens e-posttekst, standard bekreftelsestekst og
  bildeutsnitt er aktive.
- **Dagsoppgjøret leveres som CSV** under Økonomi. Kolonnene følger
  regnskapsførerens oppsett, men er ikke verifisert mot en importmal fra
  Tripletex. Prøv én måned først.
- **AI-en er koblet til** siden 26. august. Tak 300 kr i måneden, endres
  under Markedsføring → Innstillinger.
