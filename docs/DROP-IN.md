# Drop-in — hvordan det virket, og hvordan det tas fram igjen

Drop-in er tatt ned 31. august 2026. Ingenting er slettet som ikke kan lages
igjen. Dette dokumentet er kartet tilbake.

> **Eieren, 31. august:**
> «Lagre hvordan drop inn virker slik at jeg kan be deg hente det frem
> senere. Nå vil jeg at du fjerner det som har med drop in, i admin, min
> side, og nettsiden globalt i alle steder, alle kalendere, og du skal
> faktisk sjekke at det er borte.»

Den første setningen er grunnen til at dette dokumentet finnes. Den andre er
grunnen til at `bin/dropinsjekk.mjs` finnes.

---

## 1. Hva drop-in var

En enkelt økt i verkstedet, uten kurs. Du tok med det du holdt på med, eller
begynte på noe nytt, og brukte tida som du ville.

| | |
|---|---|
| **Pris** | kr. 490,- per økt |
| **Varighet** | 1,5 timer (`Apent::PLASS_MINUTTER`) |
| **Plasser** | 8 samtidig (`courses.kapasitet`) |
| **Når** | Hver dag 08:00–22:00 |
| **Inkludert** | Leire, verktøy, glasur, brenning av ett arbeid opptil 20 × 20 cm |
| **Krav** | Du måtte ha gått kurs hos Lissom, eller komme sammen med et aktivt medlem som var til stede hele tiden |
| **Betaling** | Vipps, på nettsiden, ved booking |

Kravet om godkjenning var ikke automatisert. Kunden krysset selv av for «jeg
har gått kurs hos Lissom» eller «jeg kommer sammen med et aktivt medlem», og
skrev i så fall navnet på medlemmet (`bookings.folge_medlem`). Verkstedet
kontrollerte det manuelt.

## 2. Hvordan det virket teknisk

### Kurset

Drop-in var **ett kurs** i `courses`, ikke et eget system:

```
id 6 · slug 'drop-in' · tittel 'Drop-in i verkstedet'
type 'dropin' · tema 'Drop-in' · kapasitet 8 · pris 490
folger_apningstid 1 · fast_fra 08:00 · fast_til 22:00
```

Alt annet — booking, betaling, kapasitet, Min side, kvitteringer — gikk gjennom
den vanlige kursmaskineriet. Det er derfor det var så mange steder å rydde i:
drop-in arvet hver eneste liste som viser kurs.

### Datoene lagde seg selv

Ingen la inn drop-in-datoer for hånd. `Apent::leggUtPaaApneTider()` i
`app/lib/apent.php` lagde dem, og den henter kursene sine slik:

```sql
SELECT id, kapasitet, fast_fra, fast_til FROM courses
 WHERE folger_apningstid = 1 AND status = 'publisert'
```

Står `fast_fra`/`fast_til`, klippes vinduet i plasser på halvannen time, hver
dag, uavhengig av kurs og åpningstider. Uten dem følger kurset åpningstidene i
stedet — det er slik **Paint on Pots** fortsatt virker, og Paint on Pots er
urørt av nedtakingen.

Metoden kalles fra `api/stempling.php` (ved innstempling) og fra
`api/admin/dropin.php`.

Det er dette som gjorde drop-in så påtrengende: 14 timer delt på 1,5 gir ni
plasser i døgnet, hver dag, mot noen få titalls kursdatoer i året.

### To generatorer, én i bruk

Det fantes **to** måter å lage drop-in-datoer på:

1. **Det faste vinduet** — `fast_fra`/`fast_til`. Dette var i bruk.
2. **Ukeregler** — tabellen `dropin_tider`: «tirsdag 10–13», én rad per
   åpningstid. Disse ble satt inaktive i migrasjon 102, fordi de to
   generatorene la plasser oppi hverandre. Radene står fortsatt i basen.

## 3. Databasen — alt som står igjen

Ingenting av dette er slettet:

| Hva | Hvor | Status nå |
|---|---|---|
| Kurset | `courses` id 6 | `status = 'kladd'` |
| Ukereglene | `dropin_tider`, 7 rader | `aktiv = 0` |
| Merket på genererte økter | `course_sessions.fra_dropin_tid` | kolonnen står |
| Det faste vinduet | `courses.fast_fra`, `fast_til` | verdiene står (08:00–22:00) |
| Følger åpningstid | `courses.folger_apningstid` | står som 1 |
| Navnet på medlemmet | `bookings.folge_medlem` | kolonnen står |
| Regelteksten | `content_blocks` nøkkel `Dropin/regel` | står |
| Malene | `Kursmal::KATEGORIER` har fortsatt `'Drop-in'` | står |

**Slettet:** de 126 øktene som lå ute framover og som ingen hadde booket
(migrasjon 110). De lages på nytt av seg selv i det øyeblikket kurset settes
til `publisert` igjen.

**Ikke rørt:** økter og bookinger noen har betalt for. Se punkt 6.

## 4. Hva som ble tatt bort i koden

### Bryteren

Én rad gjør to ting på én gang — den stopper generatoren *og* tar kurset ut av
den offentlige katalogen:

```sql
UPDATE courses SET status = 'kladd' WHERE slug = 'drop-in';
```

Det er `db/migrations/110_drop_in_tas_ned.sql`.

### Vaktene i koden

Fire steder passer på at drop-in ikke sniker seg tilbake selv om noen skulle
publisere kurset igjen uten å lese dette dokumentet:

| Fil | Hva |
|---|---|
| `api/admin/kurs.php` | filtrerer kurset bort fra adminkatalogen — adminlistene viser kladder med vilje |
| `lissom-2108.html` · `kursIListene()` | samme vakt i nettleseren, brukt av `kursData()` |
| `lissom-2108.html` · `oktIKurslista()` | datoene, i fem kurslister |
| `lissom-2108.html` · `visesIKalenderen()` | den offentlige kalenderen |

Adminkalenderen filtrerer i tillegg på `e.type !== 'dropin'` i `klVals()`.

### Ting som er borte

* **Ruter:** `/drop-in` og `/admin/drop-in`
* **Adminskjermen** «Drop-in» (213 linjer markup, ~100 linjer bindinger) og
  menypunktet under Kurs og medlemskap
* **Kortet** «Drop-in» på Oversikt
* **Toppmenyen:** menypunktet, og `goNav`-ruta
* **Forsida:** linja «Tatt kurs, eller med et medlem? Book drop-in →»
* **Medlemskapssida:** hele inngangsseksjonen (`mdi*`-bindingene)
* **Kurssida:** drop-in-visningen — pris, hva som er inkludert, fire steg,
  passer for / ikke for, egen FAQ (~130 linjer markup, `di*`-bindingene)
* **Bookingen:** godkjenningssteget («Jeg har gått kurs hos Lissom» /
  «Jeg kommer sammen med et aktivt medlem» + navnefeltet), og `folgeMedlem`
  sendes ikke lenger
* **Min side:** knappen «Drop-in · kr. 490,-» under «Timene er brukt opp»
* **Kursoppsettet:** «Drop-in» i Type-nedtrekket og i kategoribrikkene
* **Ny registrering:** «Drop-in» som ett av fire valg
* **Økonomi:** kontoen og mva-koden for drop-in
* **SEO:** hele sida `dropin` (fokusord «drop-in keramikk Tønsberg»,
  canonical `https://lissom.no/drop-in`) og oppføringa i sitemap
* **Spørsmål og svar:** «Hvordan fungerer drop-in?» og «Hva er inkludert i
  drop-in?». Lista gikk fra 12 til 10 spørsmål
* **Prislistene:** «Drop-in-time» kr. 490,- i internsalget
* **Innhold:** seksjonen «Drop-in» og seksjonen «Slik fungerer det», som bare
  tegnet på drop-in-visningen

### Filer som står urørt, og som trengs ved gjeninnføring

`api/admin/dropin.php` (403 linjer) er ikke slettet. Endepunktet svarer
fortsatt for en innlogget admin, men ingen skjerm kaller det. Det er der
tidene, regelen, prisen og «legg ut øktene» ligger.

`app/lib/apent.php` er urørt. Den kan fortsatt alt om det faste vinduet.

## 5. Slik tas det fram igjen

1. **Publiser kurset.**
   ```sql
   UPDATE courses SET status = 'publisert' WHERE slug = 'drop-in';
   ```
   Øktene begynner å lage seg selv igjen ved neste innstempling, eller med én
   gang hvis noen kaller `Apent::leggUtPaaApneTider()`.

2. **Slett `bin/dropinsjekk.mjs`.** Ikke kommenter den bort, ikke skru ned
   kravene — da står det igjen en vakt som lyver. Den skal bort i samme
   slengen.

3. **Snu testene.** I `tests/backend.php` står blokka «Drop-in er tatt ned»
   med ni sjekker som er det motsatte av det de var. De er skrevet slik at de
   kan snus tilbake, og kommentarene sier hva de var før.

4. **Ta vaktene ut igjen** — de fire i tabellen under punkt 4.

5. **Hent markupen fra git.** Alt ligger i historikken:
   ```
   git log --oneline -- docs/DROP-IN.md
   git show <commit-før-nedtakingen>:lissom-2108.html
   ```
   Adminskjermen, drop-in-visningen på kurssida, inngangen på
   medlemskapssida og godkjenningssteget i bookingen kan hentes ut derfra
   som de sto.

6. **Ukereglene**, hvis de skal brukes i stedet for det faste vinduet:
   ```sql
   UPDATE dropin_tider SET aktiv = 1;
   UPDATE courses SET fast_fra = NULL, fast_til = NULL WHERE slug = 'drop-in';
   ```
   Ikke ha begge på samtidig — se migrasjon 102 for hvorfor.

## 6. Det som med vilje ikke er fjernet

**Betalte bookinger står.** En kunde som har betalt kr. 490,- for en drop-in
skal fortsatt se den på Min side, og den skal fortsatt ligge i regnskapet.
Migrasjon 110 rører ikke økter noen har booket, og disse tre stedene viser
derfor fortsatt ordet «Drop-in» når det finnes en slik betaling:

* **Min side** — kundens egne kjøp og plasser
* **Oversikt** — salg per kurs
* **Påmeldte** — raden for deltakeren

Kodestedene som gjør dette er `api/admin/okonomi.php` (`'dropin' => 'Drop-in'`
i `$FORMAL`) og de tilsvarende etikettene i `lissom-2108.html`. De er der for
at en historisk betaling skal ha et navn, ikke for at drop-in skal kunne
selges.

Skal historikken også bort, er det en egen beslutning — den sletter en
kvittering kunden har fått og en linje i regnskapet, og bør tas med åpne øyne.

## 7. Slik sjekkes det at det er borte

```
node bin/dropinsjekk.mjs
```

Skriptet logger inn som admin, åpner 24 skjermer og de to nedlagte adressene i
en ekte nettleser, og leter etter ordet. Det måler to ting:

* **Ordet** «drop-in» skal ikke stå noe sted.
* **Tallene** skjermen viser. «Planlagte kurs» på Oversikt sto på 165 uten at
  ordet «Drop-in» sto noe sted på den sida — skaden var tallet. Første utgave
  av skriptet ga grønt på akkurat den skjermen med filteret slått av. Derfor
  leses tallene også.

Skriptet er prøvd mot den ekte feilen: med filtrene slått av går det rødt på
54 treff i kalenderen og 142 i «Alle kurs».

Krever at den lokale tjeneren og databasen kjører, og at
`DELETE FROM rate_limits` er kjørt i `lissom_test`.
