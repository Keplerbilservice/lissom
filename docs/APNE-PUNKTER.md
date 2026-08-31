# Åpne punkter

Alt Lissom har bedt om som ikke er gjort, og alt som venter på et svar.

**Hvorfor denne fila finnes:** lista sto i hodet mitt, og samtaler komprimeres
når de blir lange. Da forsvinner det som er sagt tidlig, og jeg svarte
«gjenstår ingenting» på ting som gjensto. Fila her overlever det. Den skal
oppdateres i samme commit som arbeidet gjøres — ikke etterpå.

Sist gjennomgått: 29. august 2026, kveld — etter «Legg til deltaker» på økta
med vippskrav, drop-in og Paint on Pots samlet i kalenderen, og
flerdagerskurset i kalenderen og på telefonen.

---

## Bestilt, ikke gjort

### 1. ~~Punktlista på kursene ligger fast i koden~~ — gjort 26. august

Plasstallet regnes nå ut av kurset, og «Maks 8 deltakere» sto i seks ulike
tekster som ikke fulgte med når plassene ble endret. Se «Ferdig» nederst.

### 1b. ~~Resten av punktlista kan fortsatt ikke redigeres~~ — gjort 27. august

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

## Gjort 30. august

**Et planlagt kurs holder plassene sine.** Eieren: «det må ikke være mulig å
booke drop in eller dreieskive på forhånd for medlemmer når det er planlagt
kurs. Da er de ressursene booket og opptatt med kurs.»

Før holdt et kurs bare de plassene som var *solgt*. Et dreiekurs 17–20 med tre
påmeldte lot fem drop-in-plasser stå åpne — på skiver som var dekket til
kurset. Nå holder kurset **plasstallet sitt**, ikke bare de solgte. Spurt om et
kurs med færre plasser enn ressursen har: «kurset holder av sine plasser» — en
Date Night for tre par tar seks av åtte skiver, og de to siste kan bookes.
Samme regel per ressurs, også bordene.

Målt onsdag 9. september, dreiekurs 17–20 med åtte plasser:

| Drop-in | Ledige |
| --- | --- |
| 08:00–15:30 | 8 |
| 17:00 og 18:30 | **0** |
| 20:00 | 8 |

**Ett unntak, og det er avgjørende:** de åpne plassene (drop-in og Paint on
Pots) holder bare det som faktisk er booket. De er et tilbud, ikke en plan.
Holdt de plasstallet sitt også, ville en tom drop-in-plass på åtte sperret
dreiekurset ved siden av, og de to hadde tatt livet av hverandre.

**Og kunden får vite hvorfor.** En drop-in-time som er stengt av et kurs sier
nå «Kurs i verkstedet», ikke «Fullbooket». Det siste er en liten løgn når det
ikke finnes én booking på timen — og den gir en telefon fra en som ikke ser
noen i verkstedet.

**Et flerdagerskurs sperret natta.** Funnet på lissom.no rett etter at delte
ressurser ble lagt ut: drop-in torsdag klokka åtte sto med fem ledige uten at
noe skjedde i huset.

Et kurs over to kvelder ligger som **én rad**: «Nybegynner dreiekurs» står med
9. september 17:00 → 10. september 20:00. Det er ikke syvogtyve timer i
verkstedet, det er to kvelder á tre — akkurat den fella `Kursmal::varighetAv`
allerede kjenner. Regnet rett fram holdt kurset tre dreieskiver opptatt
gjennom natta og hele torsdag formiddag.

Nå to prøver, ikke én: datoene må møtes, **og** klokkeslettene må møtes. To
kvelder 17–20 er i veien for hverandre; en kveld 17–20 og en formiddag
08–09:30 er det ikke, selv om raden spenner over begge. Målt etter fiksen:

| | Onsdag 9.9 | Torsdag 10.9 |
| --- | --- | --- |
| 08:00–15:30 | 8 ledige | 8 ledige |
| 17:00 og 18:30 | 5 ledige | 5 ledige |
| 20:00 | 8 ledige | 8 ledige |

Kursets andre kveld sperrer fortsatt, som den skal.

### Ressursene i verkstedet, delt av alle (migrasjon 103)

Eieren: «må ta plasser fra de samme ressursene. Altså om det er kurs eller
andre medlemmer, vi må tenke at alle disse har tilgang til de samme 8
dreieskivene», «1 dreieskive = 1 ressurs = 1 plass, 1 kursplass = 1 ressurs =
1 plass», og «kurs / medlemsbooking / drop-in må alle hente fra tilgjengelige
ressurser».

**Slik det var:** hver dato hadde sitt eget plasstall, og ledige plasser ble
regnet bare mot den ene datoen. Gikk det et dreiekurs 17–20 med åtte påmeldte,
viste drop-in kl. 18 fortsatt åtte ledige — verkstedet kunne selge seksten
plasser på åtte skiver.

**Slik det er:** hvert kurs peker på en ressurs. Alt som skjer samtidig og
peker på den samme, deler taket. Målt i basen:

| | Drop-in 17:00–18:30 | Date Night 18:00–21:00 |
| --- | --- | --- |
| Ingen booket | 8 ledige | 8 ledige |
| Seks booket på Date Night | 2 ledige | 2 ledige |

Date Night står med tolv plasser i basen og viser åtte — den kan ikke selge
flere skiver enn verkstedet har.

**Hvem bruker hva.** Spurt: dreiekursene og Date Night bruker skivene. Resten
sitter ved bordene — håndbygging, Sip & Clay, Paint on Pots. Tolv bordplasser,
som kursene alt sto med. Drop-in tar en skive; det er nettopp den som skal
miste plasser når skivene er opptatt.

**Innstemplede medlemmer trekkes fra**, men bare på det som går akkurat nå —
en booking om tre dager kan ikke vite hvem som møter opp.

Først gjettet regnestykket at enhver innstemplet sto ved en skive, og et
medlem som håndbygget ved bordet holdt av en skive ingen brukte. Eieren så
det: «kunne det være løst om de booker inn og velger dreieskive, eller
verkstedplass» — medlemmene — «det skjer på min side». Det gjør det nå
(migrasjon 104). Medlemmet velger på Min side når det stempler inn, og tallet
er eksakt. Målt: et medlem på bordplass tar ingen skive, et medlem på
dreieskive tar én.

Økter som alt sto åpne da dette ble lagt ut har ikke noe valg. De teller mot
skivene som før, til de har stemplet ut.

**Ressursene ligger i en tabell**, ikke i koden: «må kunne endre, slette og
legge til for å møte endringer i verkstedet». Kortet «Ressurser» står på
Oversikt med summen, og skjermen bak lar deg legge til, endre, slå av og
slette. En ressurs kurs peker på kan ikke slettes — den sier hvilke kurs som
bruker den, og du flytter dem først. Det var eierens eget valg da han ble
spurt.

**Kursoppsettet** har fått «Hva bruker kurset?» i seksjon 04, rett under
plasstallet. Plasstallet sier hvor mange kurset tar imot; ressursen sier hva
de tar av.

### Drop-in står for seg (migrasjon 102)

Eieren: «1. det kan bookes hele døgnet 2. det skal ikke følge kurs eller
åpningstider 3. det skal derfor ikke vises på kursoversikten, men skal høre
hjemme under medlemskap», presisert til «det skal kunne bookes tid mellom kl
08:00 og 22:00».

**Slik det var:** drop-in sto med `folger_apningstid = 1`, og plassene ble
klippet ut av åpningstidene — som igjen regnes av kursene som går den dagen.
Gikk det ikke noe kurs, var det ingen åpningstid, og da fantes det ingen
drop-in å booke. Verkstedet var altså bare åpent for drop-in de dagene det
gikk et kurs, som er den motsatte logikken av hva drop-in er.

**Slik det er:** to klokkeslett på kurset selv (`fast_fra`, `fast_til`). Står
de, gjelder de hver dag, uavhengig av kurs, åpningstider og innstempling.
Målt: ni plasser om dagen, 08:00–09:30 til 20:00–21:30, femten dager fram.
Den siste plassen begynner 20:00 og varer til 21:30 — halvannen time får ikke
plass mellom 21:30 og 22:00.

Paint on Pots står fortsatt på åpningstidene; den har ikke noe fast vindu.

**Ett sted å bestemme fra.** Drop-in hadde fra før ukeregler i `dropin_tider`
som lager sine egne økter. Med det faste vinduet ville de to generatorene lagt
plasser oppi hverandre. Reglene settes inaktive og øktene deres ryddes bort —
bortsett fra dem noen har booket. Radene blir stående, så ingenting er tapt om
vinduet skal bort igjen.

**Vinduet kan endres fra admin**, under Kurs og deltakere → Drop-in: to
klokkeslett og «Lagre vinduet». Tømmer du begge, faller drop-in tilbake på
åpningstidene. Vakter: begge må være `tt:mm`, fra før til, og minst én plass
(90 minutter).

**Ute:** drop-in er borte fra kursoversikten — den sto ute av «Kursene», men
«Vis alle» ga hele lista uendret, og der sto den mellom dreiekursene.
Menypunktet «Drop-in» er borte fra toppen; inngangen står nå på
medlemskapssiden, med klokkeslettene hentet fra basen så siden ikke kan love
tider som ikke finnes. Ruta `/drop-in` står igjen, så delte lenker virker.

**Ikke gjort:** drop-in er fortsatt åpen for alle som har gått kurs hos Lissom
eller kommer med et medlem — den er ikke gjort til et rent medlemsgode. Spør
eieren om det var meningen.

**«Store fat kurs» er håndbygging (migrasjon 101).** Kurset sto med temaet
«Kurs» i basen — altså ingen ekte kategori — og kortet gjettet «Dreiing» av
kurstypen. Eieren: «store fat er håndbygging». Nå står temaet, og ingen
trenger å gjette.

**Følgefeil av migrasjon 099, funnet samtidig.** Malene i `Kursmal` heter
fortsatt «Plateteknikk», mens 099 skrev radene om til «Håndbygging». Uten en
kobling mellom de to falt hvert håndbyggingskurs på reservemalen `*`, og den
har ingen `beskrivelse`. Et håndbyggingskurs uten egen tekst ville stått helt
uten kursbeskrivelse på nettsiden. `'Håndbygging' => 'Plateteknikk'` er lagt
inn i oppslaget.

**Verdt å vite:** migrasjoner må kjøres fra ⚙ Vedlikehold, ikke fra en
kommandolinje uten `--default-character-set=utf8mb4`. Gjør man det siste, blir
«Håndbygging» til «HÃ¥ndbygging» i basen. `api/migrer.php` kjører over PDO med
utf8mb4 og har ikke problemet.

**Kategorien på kortet og plassen i lista leste hver sin regel.** «Store fat
kurs» står med temaet «Kurs» i basen — altså ingen ekte kategori. Kortet
faller tilbake på kurstypen og skrev «Dreiing»; sorteringa gjorde ikke det, og
la kurset nederst, under Drop-in. Jeg trodde først det var en gammel utgave i
telefonen hans. Det var det ikke: `kursRang()` kalte `kategoriFor()` med tom
type, og fikk et annet svar enn kortet rett ved siden av.

Nå er det én funksjon, `kategoriVist()`, som begge leser. Den tar temaet
først, så kurstypen, og til slutt navnet — Paint on Pots sto med tema NULL og
kjennes på det det heter, og det fikk med på kjøpet at kortet nå sier «Events»
der det før sa «Uten kategori».

**Migrasjonene 094–100 er kjørt.** Eieren: «ingen migrasjon å se». Verifisert
mot lissom.no/api/kurs.php: kursene står med `tema = 'Håndbygging'` i basen,
som er nettopp det migrasjon 099 gjør. Raden «⚙ Vedlikehold» nederst i menyen
sier «Alt er oppdatert» når det ikke er noe å kjøre.

**Standardtekst per kategori, redigerbar rett i feltet.** «Alt som er
inkludert», «Praktisk informasjon», «Når er den ferdig» og «Godt å vite» har
nå en standardtekst som verkstedet setter selv. Den følger kategorien —
Dreiing, Håndbygging, Events, Kun medlemmer, Drop-in — fordi det som gjelder
for et dreiekurs ikke er det som gjelder for Paint on Pots. Skriv i feltet, og
«Lagre som standard for Dreiing» dukker opp under. Lenka står bare når teksten
faktisk er noe annet enn standarden, og lagringa lukker ikke skjemaet.

Teksten ligger som JSON under én nøkkel i `innstillinger`, ikke i koden, så
den kan endres uten en ny utlegging. Ingen migrasjon å kjøre.

**Hvor hvert felt vises står nå i skjemaet.** Eieren: «jeg vet jo ikke hvor
alt vises». Hver seksjon har fått en linje som sier hvor teksten havner — på
kurssiden, i faktaboksen, eller ingen steder.

**Seksjon 12 lovte en e-post den ikke sender.** «Bekreftelse og påminnelse»
sto med «teksten de får på skjermen etter kjøp og i e-postkvitteringen».
Kolonnen `bekreftelse_tekst` skrives fra kursoppsettet og leses ingen steder i
hele kodebasen. Kvitteringen som faktisk går ut er malen `ordrebekreftelse`
under Beskjeder → E-post- og SMS-maler, med `{navn}`, `{ordre}` og `{belop}`.
Teksten i skjemaet sier nå dette rett ut. Selve koblingen er ikke laget —
eieren må si om han vil ha den.

### Dobbelt i kursoppsettet — ryddet

Eieren: «en praktisk informasjon og en dette får du med hjem, rydd resten».
Tre par sa det samme, og av hvert par står ett igjen.

- **«Godt å vite» er borte.** Den og «Praktisk informasjon» var to fritekstfelt
  som begge ble egne avsnitt på kurssiden, rett etter hverandre.
  Eksempelteksten var «møt opp ti minutter før, bruk klær som tåler leire» i
  det ene og «ta med forkle» i det andre.
- **«Dette lager du» er borte fra kurssiden.** Feltet forsvant fra oppsettet
  tidligere samme kveld; avsnittet sto igjen rett over «Dette får du med hjem»
  og sa det samme. Faktaboksen «Med hjem» leste `lagerDu` og leser nå
  `medHjem` — ellers ville boksen blitt stående på en tekst ingen kunne rette.
- **«Bekreftelse» er borte.** Den overlappet «Praktisk informasjon» i innhold,
  og kolonnen ble aldri lest. Seksjon 12 heter nå «Påminnelse» og har bare
  SMS-haken igjen, med en linje som sier hvor e-postteksten faktisk settes opp.

Kurssiden gikk fra sju avsnitt til fem. Feltene er borte fra skjemaet, ikke
fra kursene: `tillegg`, `lager_du` og `bekreftelse_tekst` sendes fortsatt
videre urørt ved lagring, så ingenting går tapt om noe skal fram igjen.

**«Neste» mistet deg.** Steg 1 i kursoppsettet er tolv seksjoner langt; steg
2 og 3 er korte. Trykte du «Neste» nederst i steg 1, sto rullingen stille
mens kortet krympet under føttene på deg — og du sto plutselig midt i
kurslista under, uten at noe hadde gått galt. Eieren: «når jeg trykker på
neste så kommer jeg hit, hva i helvete». Toppen av kortet følger nå med, både
framover og tilbake.

**Varighetsfeltet er borte.** Det var en overstyring: sto det noe der, gjaldt
den teksten uansett hva klokka på datoene sa, og de to kunne si hver sin ting.
Eieren: «fjern varighet og bruk tidene på datoen». Varigheten regnes nå av
start- og sluttida, alltid, og står i faktaboksen som før. Kolonnen
`varighet_tekst` blir liggende urørt i basen — den leses bare ikke lenger.
Unntaket som står igjen: kurs der gjenstanden betales i verkstedet ligger ute
på åpningstidene, og da er økta hele det åpne vinduet. Der sier malen hva som
gjelder, ellers ville Paint on Pots stått med «10 timer».

**Tre felt ut av kursoppsettet.** Eieren, med veiviseren åpen: «varighet,
dette regnes fra kursstart til slutt, så fjern disse pillene» og «kort
beskrivelse og dette lager du kan fjernes».

Varigheten sto to steder som kunne si hver sin ting: et felt som regnes av
start- og sluttida på datoene, og tre brikker — Under to timer / To til fire
timer / Over fire timer — satt for hånd. Brikkene er borte. Feltet står, og
regner som før.

«Kort beskrivelse» lå ved siden av «Om kurset» i seksjon 03, og «Dette lager
du» ved siden av «Dette får du med hjem» rett under seg selv. Begge er borte
fra oppsettet.

Feltene er borte fra skjemaet, ikke fra kursene: teksten som allerede står
lagret følger med videre og vises som før på kurssiden. Det var eierens eget
valg da han ble spurt. Konsekvensen er at den teksten ikke lenger kan rettes
derfra — skal noe av den endres på et kurs, må det gjøres i basen.

Kursveilederen vektet også på varighet når den matchet en besøkende mot et
kurs. Den vekten er tatt bort: nye kurs får aldri varighet satt, og en vekt
som bare treffer det som ble lagret før 30. august er en skjult skjevhet.
Veilederen matcher nå på nivå, hvem og metode.

**Kategoriene ryddet: Håndbygging og Events.** Ute sto seks piller — Dreiing,
Plateteknikk, Workshop, Sip & Clay, Date Night og Paint on pots. Tre av dem er
det samme slaget kveld, og to av dem er det samme håndverket under hvert sitt
navn. Nå er det tre: **Dreiing**, **Håndbygging** og **Events**.

Events er en gruppering i visningen — de tre arrangementene beholder temaet
sitt, så Paint on Pots fortsatt kjennes igjen på sitt eget, og hvert av dem kan
fortsatt velges for seg i admin. Håndbygging er et nytt navn på et tema som
fantes, så radene følger med (migrasjon 099): Workshop og Plateteknikk blir
Håndbygging, og «Lag din egen bolle» flyttes dit fra det generiske «Kurs».
«Håndbygging» kan velges når et kurs legges ut.

**Et kort som heter «Kurs».** Under Kurs og deltakere handlet kortene om
datoene — «Planlagte kurs» er de som har noe framfor seg, «Datoer som ligger
ute» er datoene selv. Selve kursene, med navn, pris, tekst og bilder, hadde
ingen inngang. Nå står katalogen som eget kort, med «Lag et nytt kurs» nederst
(kortet «Opprett kurs» er borte — det gjorde bare halve jobben).

**Refunder-knapp under Økonomi.** Avbestiller kunden selv fra Min side, går
refusjonen av seg selv etter vilkårene. Alt annet — en avlyst dato, en kunde
som ringer — sto uten vei: skjermen sa «må refunderes manuelt under Økonomi»,
og der fantes ingen knapp. Serverdelen var ferdig fra før; nå er den koblet.
Trykk «Refunder …» på en betaling, la feltet stå tomt for hele beløpet eller
skriv et delbeløp, og bekreft. Betalingen settes til refundert eller delvis
refundert, og beløpet står på raden.

Samtidig rettet: en delrefusjon satte plassen som refundert og ga stolen bort.
Nå skjer det bare når hele beløpet er sendt tilbake — det er hele poenget med
50 %-regelen at plassen ikke gis fra seg gratis.

**Nedtrekkene virket ikke på iPhone — alle sammen.** `<sc-for>` inne i
`<select>` er ikke gyldig HTML: standarden sier at en ukjent start-tag inne i
et nedtrekk skal kastes, og Safari gjør det. Løkka forsvinner, og med den
valgene. Chrome tolererer det, så feilen fantes bare på telefonen — der
verkstedet faktisk står. Monica fikk et tomt nedtrekk med en hake i da hun
skulle velge betalingsmåte. Det var ikke en gammel utgave hun satt på; alle
som brukte Safari så det samme, i alle 28 nedtrekkene i admin.

Alle er nå brikker — vanlige knapper i en div, samme form som betalingsvalget
i kassa og i kalenderen alt hadde. Reglene står ett sted (`Component.NEDTREKK`
og `utenNedtrekk()`), og setterne er urørt: brikka kaller den samme funksjonen
med den samme hendelsen, så bare visningen er byttet.

**Stemple inn og Ferie står på Oversikt.** Menyen er stedene man kan gå;
dette er to ting man gjør. Samme tekst, samme piller, samme farger — flyttet
til den mørke stripa rett under «Ofte brukt».

**«Lukk» øverst til høyre går ikke lenger utenfor skjermen,** og knapperaden
på en kursdato («Rediger · Legg til deltaker · Påmeldte · Slett dato») bryter
i stedet for å stikke utenfor kortet.

**Planlagte kurs har uke, måned og liste.** De samme tre visningene som
kalenderen, på de samme øktene: uka med alle sju dagene (også de tomme),
månedsrutenettet som ruller sidelengs på telefon, og lista fjorten dager
nedover. Et trykk på en dato åpner nå *kurset* — datolista legger seg øverst
med alle datoene og «Legg til deltaker» på hver av dem. Før sendte den deg til
Påmeldte-skjermen med et filter på, altså en annen skjerm og ikke kurset.

**Planlagte kurs står som listen i kalenderen.** Skjermen «Kurs og deltakere»
hadde uka i sju spalter ved siden av hverandre. På en telefon er det 55 piksler
per dag, og eieren så to og en halv dag om gangen med titlene klippet på
midten. Nå er det den samme lista som listevisningen i kalenderen: dag for dag
nedover, klokkeslettet først, en farget prikk for hva slags økt det er, og
belegget under tittelen. Dager uten noe hoppes over, og tidene som følger
åpningstida samles til én linje («6 tider · Vis tidene») slik kalenderen alt
gjorde. Begge listene ble samtidig strammet inn på telefon — klokkeslettet tok
110 piksler det ikke trengte, og de gikk til titlene.

**«Ikke publisert» på datoen.** Eieren så «Lag din egen bolle» to ganger samme
kveld, med samme klokkeslett og samme belegg. Den ene datoen hører til et kurs
som ligger som utkast: den finnes ikke ute på nettsiden, og ingen kan booke
den. Admin viste de to helt likt. Nå står det på linja, både i kalenderen og
under Planlagte kurs. Er det to like linjer og bare den ene er merket, er det
et dobbelt kurs — det andre kan slettes eller publiseres.

**E-post når en deltaker legges inn fra kalenderen.** De to fyldige skjemaene
hadde feltet fra før. Hurtigfeltet under en valgt økt hadde bare navn og
telefon, så deltakeren ble stående uten adresse — ingen bekreftelse, ingen
beskjed når noe endrer seg.

**Markedsføringen bygget om.** Bare innlegg til sosiale medier hadde en
forhåndsvisning; en artikkel og et nyhetsbrev viste rå tekst og ingen bilde,
selv om bildet fulgte med hele veien i basen og helt ut på nettsiden. Alle tre
tegnes nå slik de faktisk blir. «Publiser nå» står i selve utkastet og
godkjenner og legger ut i ett kall. Tre feil lå bak: sperren i `aiKall` stoppet
også «godkjenn» og «publiser» med en begrunnelse som ikke stemte;
`articles.tittel` er UNIQUE, så to utkast om det samme ga hele SQLSTATE-feilen
på skjermen og ingen publisering; og emneknaggene sto som «##keramikk» når
AI-en tok med tegnet selv.

**Meld inn feil.** To lag: automatisk fangst av unntak, ubehandlede løfter og
API-kall som svarer 500 — alltid på, usynlig — og en knapp der et menneske kan
skrive fra seg det maskinen ikke ser. Knappen står i bunnteksten, nederst på
Min side og i adminmenyen, bak en dato eieren setter selv («Slå på i en uke»).
Rapportene ligger under Nettsiden → Feilmeldinger med «Kopier alt».

Vakta fant en feil første gang den kjørte: to `<img>` i kassa sto med
`src="{{ utQrBilde }}"` i stedet for `data-src`. Nettleseren ba om selve
krøllparentesene som filadresse ved hver eneste sidelasting, hos alle.

**Datolista.** «Neste åtte uker» viste datoer som hadde vært.
`api/admin/pameldte.php` sender med de siste tretti dagene — deltakerlista
trenger dem — men datolista filtrerte bare oppover. Grensen manglet i den
andre enden.

**Oversikt.** Kortet «Programmet på telefonen» er borte; abonnementet står nå
på Kalender-skjermen. Kortet «Kurs går tomme for datoer» er fjernet. Nytt kort:
statistikk med de mest populære kursene, regnet av plasser solgt siste tolv
måneder. Snarveiene heter «Ofte brukt» og er like store på telefon.

**Kvitteringen på e-post.** Alle steder som lovet en e-postkvittering som aldri
kommer er strøket — bookingsiden, kassen, «slik virker det», vilkårene og
svaret når et gavekort dekker hele kjøpet. Kvitteringen ligger i Vipps.

**De tre pillene i bunnteksten** er like store, med teksten på én linje. Målt
på 360, 375, 390, 430 og 1440 piksler.

---

## Gjort 29. august, kveld

**«Legg til deltaker» på den planlagte økta.** Navn, e-post, telefon, antall,
betaling, beløp og internt notat, rett i «Rediger økten». Samme endepunkt som
skjemaet under Kurs. «Vippskrav» sender kravet til mobilnummeret; plassen står
som reservert til det er godtatt, og settes til betalt av webhooken.

Tre feil kom fram under testingen og er rettet: ryddingen etter et krav som
ikke gikk gjennom slettet betalingen før bookingen og feilet på
fremmednøkkelen; et klikk på en hendelse i kalenderen ga «h is not defined» i
alle fire visningene; og uke- og dagvisningen åpnet ikke økta på klikk.

**Drop-in og Paint on Pots samlet i kalenderen.** Én linje per kurs per dag i
måneds- og listevisningen, med «6 tider» og tidene bak et trykk. Tid mellom to
kurs deler ikke linja — da er verkstedet åpent — men et hull gjør det, for da
var døra lukket. Bookingen er urørt: 1,5 timer som før.

**Flerdagerskurset i kalenderen.** Dag to og tre står nå der, merket «Samling
2 av 2». De kan ikke dras, og de åpner økta på den dagen du trykket på.

**Flerdagerskurset på telefonen.** Én hendelse per kursdag i stedet for én
blokk på 27 timer. Første dag beholder id-en, så den gamle blokka rettes
framfor å bli liggende igjen.

**Migrasjon 093.** Alle kurs over på Monica, eieren står som sluttet. Timene
hans blir liggende.

**Vippskrav i den raske ruta.** Boksen nederst i deltakerlista kan sende krav
også, ikke bare notere Vipps eller kontant.

**Vipps-QR i kassa.** «Vis Vipps-QR» gir en kode kunden skanner. Skjermen
følger med til pengene er inne.

**Vipps sier hva som er galt.** Feilene sto bare i feilloggen på webhotellet;
på skjermen sto det «Sjekk at nummeret har Vipps, og prøv igjen» uansett hva
som var galt. Nå står Vipps' egen setning der — «Vipps svarte 403: The sale
unit is not allowed to use userFlow PUSH\_MESSAGE», «Vipps svarte 401: Access
denied due to invalid subscription key», «customer.phoneNumber: The phone
number is not registered with Vipps». Ingen av dem løses ved å prøve igjen, og
nå er det mulig å se hvilken det er.

---

## Gjort 28.–29. august

Kalenderen skriver nå (fase 6): tolv knapper som bare lukket seg er koblet,
kursholderkonflikt sjekkes, sju daglige oppgaver gjøres derfra, og økter
flyttes med dra-og-slipp — med en angreknapp som virker begge veier.
Kurskortene i kalenderen er klikkbare og redigerbare, og endringene slår
gjennom på alle planlagte datoer.

Menyen er ryddet (fase 7): elleve punkter ned til ti, «Deltakere» og «Kurs og
medlemskap» slått sammen til «Kurs og deltakere», Kalender opp som nummer to,
og Oppskrifter er blitt «Verkstedet».

Verkstedet (fase 8) har fått notater, påminnelser og brenninger i basen.
Notatet lå i `localStorage` — skrev eieren det på telefonen, fantes det ikke
på PC-en, og tømte hun nettleserdataene var det borte. Det ble flyttet inn én
gang, ikke kopiert.

Kursholderen velges på kurset. Tre trinn, én vei: datoens valg står over
kursets, kursets over verkstedets standard. Tomt betyr Monica, ikke «ingen».

Oppryddingen (fase 9) tok bestillingsboksen på Min side som aldri kunne åpne
seg, statusen «Flyttet» som aldri kunne settes, et internkjøp som aldri nådde
serveren, to attrapp-dialoger og seks props uten binding. De 61 adressene går
alle et sted, og alle 2 403 bindingene i skjermbildene har en verdi bak seg.

Beskjedkortet i kalenderen sier fra når køen er tom, viser to lesbare linjer
av meldingen, og «Åpne» går til de ubesvarte når det er noe ubesvart.

Varselkortene: to køer ingen sto vakt over har fått kort på Oversikt —
medlemsvarer som venter på godkjenning, og søknader om frys. Kassekortet
viser dagens salg.

---

## Må gjøres av Lissom

### 7d. Migrasjon 094 må kjøres — ny 30. august

094 lager tabellen feilrapportene havner i. Til den er kjørt, samler
«Meld inn feil» ingenting — den sier ikke fra som en feil, den bare står
tom. Etterpå: Nettsiden → Feilmeldinger → «Slå på i en uke», ellers vises
knappen ingen andre steder enn i adminmenyen.

### 7c. Migrasjon 091–093 må kjøres — nye 29. august, kveld

091 tar ned det andre bollekurset, hvis det er tomt.
092 flytter adressen til `/kurs/lag-din-egen-bolle`, med 301 fra den gamle.
093 setter alle kurs over på Monica og eieren som sluttet.

### 7b. Migrasjon 052–090 må kjøres

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
079–086 gir drop-in etter åpningstid, anmeldelse etter kurs, fast trekk eller
selv, binding og oppsigelse, spørsmålene som egen seksjon, manuell betaling
som ekte betaling, kursholder per økt og en standard kursholder.
087 døper «Kurs boller» om til «Lag din egen bolle», i katalogen og på alle
datoene. Adressen står urørt, så gamle lenker virker.
088 gir Verkstedet: notatene og påminnelsene ut av nettleseren og inn i basen,
og brenningene som står i kalenderen.
089 legger kursholderen på selve kurset — datoene arver den — og fjerner
vakttabellen fra 088 igjen. «Det er ingen andre vakter utenom kursholdere.»
090 fjerner `checkins` og `hour_usage`, som ingen kode leser. **Bare der de er
tomme.** Har de rader hos deg, blir de stående, og de vises fortsatt under
«ubrukte» i api/status.php — da er det historikk noen må se på først.

**Kjørt 29. august.** Lissom kjørte vedlikeholdet; hele rekka til og med 090
er ute.

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

### 9. ~~Dublettene i medlemslista~~ — verktøy bygget 29. august

Flere rader for samme person. Under Medlemmer finner skjermen dem selv — lik
e-post eller likt telefonnummer er sikkert, likt navn alene er et forslag — og
slår dem sammen i én transaksjon. Alle sytten kolonnene som peker på et medlem
flyttes over, den gamle raden anonymiseres framfor å slettes, og det som ikke
lot seg flytte blir rapportert.

Selve sammenslåingen må fortsatt startes av et menneske. Det er med vilje:
to personer kan hete det samme.

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

### 11b. Jeg kan nå se lissom.no selv — men ikke Vipps

Fra 29. august står `lissom.no` og `*.lissom.no` i domenelista til
skymiljøet. Det betyr at jeg kan hente den ekte siden, kontrollere at en
utlegging faktisk landet, og kjøre nettlesertester mot den — ikke bare mot
testserveren her. Det er brukt hver gang siden.

To grenser står igjen:

**Admin krever innlogging**, og passordet skal ikke i en chat. Alt jeg
kontrollerer live er derfor det utloggede: sidene, API-ene som er åpne, og at
en utlegging har landet. Admin testes fortsatt mot en kopi av basen her.

**`apitest.vipps.no` står ikke i lista.** Skal jeg kunne prøve en handel mot
Vipps' testmiljø herfra, må den legges til på samme sted — skyikonet over
meldingsfeltet på claude.ai/code, tannhjulet på `lissom`, Allowed domains.
Uten den svarer proxyen 403, og testene faller tilbake på stubben. De
passerer, men de rører ikke Vipps.

**Nettleseren gaar en omvei.** Chromium kommer ikke gjennom proxyen — den
aapner tunnelen, men ClientHello-en blir avvist, og det gjelder alle verter,
ikke bare lissom.no. Node henter sidene over TLS med full sertifikatsjekk og
serverer dem til nettleseren lokalt. Ingenting er slaatt av; det er bare
ClientHello-en som byttes ut.

### 11c. ~~Vippskrav: salgsenheten mangler tillatelse hos Vipps~~ — lagt bort 29. august

Eieren: «jeg gir beskjed om jeg ønsker denne funksjonen. Så stryk det fra
listen din.» Punktet er lukket. Alt som trengs er at Vipps skrur på
`PUSH_MESSAGE` for salgsenheten; koden er ferdig og virker den dagen det
skjer.

### 11d. Gammelt punkt (beholdt for historikken)

29. august, kveld. «Send Vipps-krav» i kassa svarer:

```
Vipps svarte 400: The sales unit with MSN 1143163 is not allowed to use
PUSH_MESSAGE flow. · ErrorCode: 5080
```

Vanlige Vipps-betalinger virker: kunden står foran skjermen og sendes til
Vipps. Et krav som dukker opp i appen til noen andre — `PUSH_MESSAGE` — er en
egen tillatelse på salgsenheten, og den må Vipps skru på. Ingen kode retter
det.

**Lissom må be Vipps om det**, med MSN-et som står i meldingen. Salgsenhetens
nummer, ikke en nøkkel.

Alt annet står klart: kravet lages, betalingsraden knyttes til bookingen eller
ordren begge veier, og webhooken setter den til betalt når pengene kommer. Den
dagen tillatelsen er på plass, virker knappen uten at noe endres.

**Prøvd med en ekte betaling 29. august.** Eieren gjennomførte et kjøp med
QR-en. Den virket. Han fikk lovet en kvittering på e-post som aldri kom — for
et disksalg finnes ingen e-postadresse å sende til, og kvitteringen ligger i
Vipps. Eieren: «ingen epost sendes med kvittering nødvendig». Teksten sier nå
at kvitteringen ligger i Vipps-appen; ingen e-post bygges.

**Bygget 29. august, kveld: Vipps-QR i kassa.** «Vis Vipps-QR» lager en helt
vanlig Vipps-betaling — den veien som virker — og viser adressen som en kode
kunden skanner med kameraet. Salget står som venter til pengene er inne, og
skjermen sier fra når de er det.

Forskjellen fra kravet: kunden må være til stede. Til gjengjeld slipper hun å
oppgi nummeret sitt. Det er en løsning for den som står i døra, ikke en
erstatning for kravet — be Vipps om `PUSH_MESSAGE` likevel.

Koden lages av `vendor/qrcode-2.0.4.js` (Kazuhiko Arase, MIT), lagt hos oss
som React-filene og lastet først når en kode skal vises.

**Og en fast kode til disken, 29. august.** Eieren ville ha én kode å henge
opp. Vipps-portalen kan lage en, men den vil ha en landingsside med
Hurtigkasse — Vipps Checkout, et annet produkt enn ePayment som nettsida
bruker. Vi har ingen slik side.

`/betal` gjør det samme med det vi har: kunden velger Kurs, Medlemskap eller
Butikk, skriver beløpet, og betaler med Vipps. Salget står som venter til
pengene er inne, og havner i kassa som alt annet. Koden hentes fram under
Kassa → «Fast kode til disken», med utskrift.

Beløpet kommer fra nettleseren her, og bare her. Overalt ellers regnes summen
av databasen — men her finnes ingen pris å jukse med: kunden bestemmer selv
hva hun skal betale. Mot søppel står en grense per IP, og beløpet må være
mellom én krone og 100 000.

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
- **Plassene er halvannen time** (endret fra to 27. august), og de ligger inne
  i dagens åpningstid — fra det første begynner til det siste slutter. Inntil
  åtte tidspunkt per dag; taket ligger over den lengste dagen verkstedet har,
  så det er åpningstiden som bestemmer, ikke tallet.
- **Ett kort, ikke ett per dato.** Paint on Pots står som ett kort på siden, med
  bildet fra kurslista og «N ledige tider». Datoen velger man inne i
  bestillingen. Fjorten kort med samme bilde og samme tekst, der bare datoen
  skilte dem, var en vegg.
- **Velg dato, så tidspunkt.** Bestillingen viser **tre dager** om gangen, med
  «Vis N datoer til» under. Trykker du en dag, kommer tidspunktene den dagen —
  som klokkeslett: 10:00, 11:30, 13:00. Du har plassen i **halvannen time**
  fra tidspunktet du velger, og det står under knappene. Lengden står ett sted
  — `Apent::PLASS_MINUTTER` — og `api/kurs.php` sender den som `plassVarighet`,
  så teksten under knappene ikke kan si noe annet enn det tidene er klippet i.

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

- **Hele halvannen time, eller ingenting.** Er det under halvannen time igjen
  av det åpne vinduet, settes det ikke opp et tidspunkt. En åpen periode 10–13
  gir to tidspunkt (10:00 og 11:30), ikke tre der det siste bare varer en
  halvtime.

- **Timene mellom to kurs er også bookbare** — bestemt av Lissom 27. august:
  «husk tiden som er mellom kurs også skal være tilgjengelig å booke». Går det
  et kurs 10–13 og et til 16–19, er hun der hele dagen, og da skal noen kunne
  sette seg ned klokka 14. 3. september i testdataene: Store fat 10–13,
  drop-in 16–19, Store fat 17–20, Date Night 18–21 — dagen er åpen 10–21, og
  tidspunktene blir 10:00, 11:30, 13:00, 14:30, 16:00, 17:30 og 19:00.

  Hullet var stengt en periode. Det var feil vei: det gjorde en dag hun
  uansett er i huset mindre bookbar enn en dag hun stikker innom en time.
  Innstemplingen slås fortsatt sammen med dagen bare når de henger sammen —
  kurs 10–13 og innstempling 18:00 er to perioder, for i mellomtiden var hun
  ikke der.

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

**Besøkende laster ikke lenger ned adminpanelet — 28. august.** Målt med
Lighthouse mot ekte tall fra lissom.no (LCP 5,0 s, FCP 3,8 s, CLS 0,248).

- Hver besøkende lastet ned alle 30 adminskjermene: **583 kB markup** de aldri
  fikk se. De lå bak en `sc-if` — skjult, men sendt.
- `bin/utenadmin.mjs` lager en utgave uten dem, `side.php` sender den til alle
  som ikke har en admin-sesjon. **2081 kB → 1499 kB**; komprimert 430 → 361 kB.
  Den største gevinsten er ikke nedlastingen, men at nettleseren slipper å
  lese 583 kB HTML.
- **De aller fleste har ingen sesjonscookie.** Da vet vi svaret uten å spørre
  basen. Er noe i veien — mangler fila, er basen nede — sendes hele siden;
  den tunge utgaven virker alltid.
- **Innlogging fra en side uten panel** henter siden på nytt. Uten det ville
  «adminoversikt» gitt en tom skjerm: skjermene fantes ikke i dokumentet.

**Beviset for at utseendet er uendret.** Skjermbilder duger ikke — 12 av 32
varierte mellom to kjøringer av *samme* kode, fordi bilder lastes i ulik
rekkefølge. I stedet sammenlignes et avtrykk av DOM-en: all synlig tekst,
antall synlige elementer, plasseringen og størrelsen på hvert av dem, og
sidens høyde. Det avtrykket er helt stabilt (0 av 32 varierer mellom to
kjøringer), og **0 av 32 sider skiller seg mellom lett og full utgave** — 16
sider, mobil og skjerm.

**Kurssiden åpner på kursene, ikke på alt — 28. august.** Lissom: «oppstart
default må være dreiing, plateteknikk og workshop».

- Filteret starter på `Kursene`. Det holder utenfor arrangementene (Sip &
  Clay, Date Night), drop-in, interne samlinger — og Paint on Pots.
- **Paint on Pots og drop-in kjennes på `folgerApningstid`**, ikke på navnet.
  Det er en egenskap ved dem: datoene lages av åpningstidene. Et nytt kurs
  Lissom lager uten å sette kategori faller derfor inn i standardvisningen,
  som det skal, framfor å bli usynlig.
- Alle sju knappene står som før, og «Vis alle» viser fortsatt alt. De tre
  arrangementene velges manuelt — valgt i samråd med Lissom.
- **Dreiekursene ligger først.** Det er dreieskiva folk har sett for seg når
  de kommer. Hva som *er* et dreiekurs står ett sted — `kursIKategori` — så
  knappen «Dreiing» og sorteringen ikke kan bli uenige om det samme kurset.
  Resten beholder rekkefølgen sin, og har man valgt én kategori skjer det
  ingenting.
- **Kategoriraden er borte fra /events og /drop-in.** De nås fra menyen og er
  hver sin side; der sto hele raden likevel, over en side som handler om én
  ting. Første trykk tok deg vekk fra siden du nettopp valgte.

**Hver vare i butikken har fått sin egen adresse — 28. august.** `/butikk`
var én side. Ingen kopp kunne deles med en lenke som viste nettopp den, ingen
kunne rangere på sitt eget navn, og varene kunne ikke ligge i Googles gratis
handletreff — de krever en adresse per vare.

- Adressen er `/butikk/8-kaffekopp-gronn`. **Tallet er det som gjelder**;
  navnet bak leses ikke. En vare kan derfor døpes om uten at gamle lenker
  ryker, og `side.php` setter canonical til adressen slik den heter nå.
  Derfor er det ingen slug-kolonne på `products`: en lagret slug ville blitt
  stående feil den dagen navnet ble endret.
- **Medlemsvarene får ingen adresse.** Leire og ekstra brenning er verkstedets
  interne hylle. Serveren gir dem ingen `sti`, og `/butikk/6-leire-10-kg`
  svarer `noindex` uten å åpne noe.
- **`Product` i strukturerte data**, med pris, valuta, lagerstatus og selger.
  Det er dette Google leser når den plasserer en vare i handletreffene.
- Sitemapet gikk fra 31 til 50 adresser.
- `Lenker::slug()` samler regelen som lå duplisert i artikler og kurs. De to
  var *nesten* like — én brukte `mb_strtolower`, den andre `strtolower` — og
  kunne gitt hver sin adresse for samme navn.

To feil ble funnet av testene underveis, begge verdt å notere:

- **`bootstrap.php` har en variabel som heter det samme som `side.php` sin.**
  `require` på toppnivå deler variabler, så adressen ble overskrevet med
  `/home/user/lissom/app/secrets.php` og alle varesidene falt tilbake til
  forsidens tittel. Innlastingen skjer nå inne i en lukking, der de variablene
  blir lukkingens.
- **Baseraden heter `tittel`, kortet heter `title`.** En vare åpnet fra sin
  egen adresse fikk raden rett inn, og ruta viste pris og tekst uten navn.
  `varekort()` gjør nå den samme oversettelsen begge steder.

**«Takk for sist» etter kurset — bygget 28. august, står av.** Lissom har
**null anmeldelser på Google**. For et lokalt verksted er det den største
enkeltfaktoren i «keramikkurs i nærheten»: kartet svarer før de organiske
treffene gjør det.

- Migrasjon 080 gir malen `anmeldelse`, kolonnen `course_sessions.anmeldelse_sendt_at`
  og tre innstillinger. `bin/cron.php anmeldelser` sender, én gang per
  kursdato, til dem som betalte.
- **Kanalen er SMS.** Malen står som `kanal = 'sms'`, og `Varsel::mal()` gjør
  da det den skal så lenge SMS ikke er satt opp: sender e-post i stedet. Den
  dagen SMS skrus på, går den samme meldingen som SMS — kanalen med høyest
  svarprosent — uten at noen rører koden.
- **Lenken står ikke i koden.** Den er verkstedets egen og limes inn under
  Markedsføring → E-post og SMS. Uten lenke sendes ingenting, uansett bryter.
- **Aldri lenger tilbake enn tre døgn.** Skrus den på i november, går det
  ingen melding til dem som var her i august. Det er den feilen som ville
  gjort mest skade, og den er den eneste som ikke lar seg angre.
- Skjermen sier hva som faktisk skjer, ikke bare hva bryteren står på: «Går 3
  timer etter kurset, som e-post. Den går som SMS av seg selv den dagen SMS er
  satt opp.»

**Sikkerhetsgjennomgang 28. august.** Hele koden gått gjennom: SQL, tilgang,
opplasting, CSRF, XSS, hemmeligheter, headere og hva som ligger åpent over
nett. To funn, begge rettet.

- **`api/sjekk-secrets.php` sto åpent for alle.** Den skrev ut hele oversikten
  over hva som er satt opp — hvilke tjenester vi bruker, om vi står i test
  eller produksjon, og hva som mangler. Verre: ved en skrivefeil i fila skrev
  den ut PHPs egen feilmelding, og den siterer symbolet den snublet i. Er
  feilen inne i en verdi, er det verdien den siterer — prøvd:
  `'passord med ' fnutt inni'` ga `unexpected identifier "fnutt"`. Et
  passordfragment til hvem som helst.

  Nå: er fila i stykker, er siden fortsatt åpen — det er da den trengs, og da
  virker ingen innlogging — men svaret er bare et linjenummer. Er fila i
  orden, kreves `?nokkel=` med `cron_nokkel`. Nøkkelen leses rett ut av fila,
  så siden virker også når databasen er nede.

- **Glasurlappen skrev oppskriftsnavnet rått inn i utskriftsvinduet.**
  `window.open('')` arver vårt opphav, så det som skrives inn der kjører som
  om det stod på lissom.no. Navnet er skrevet i et fritekstfelt. Escapes nå,
  som månedsrapporten alt gjorde.

- **E-postheadere tok imot linjeskift.** «Svar til» og avsenderadressen under
  Varsler → oppsett gikk rett inn i headerblokka, som settes sammen med CRLF.
  Et linjeskift midt i verdien ble en ekte headerlinje — `Bcc:` går like fint
  som noe annet. `trim()` i skjemaet tar bare endene, ikke midten. Bare en
  innlogget admin kan skrive feltet, så det var ingen vei inn utenfra, men et
  overtatt admin-passord skulle ikke også bli en postsentral. CR og LF klippes
  nå ett sted, for alle headere, og avsenderadressen må validere som e-post.

Resten holdt. Ingen SQL-injeksjon — alle strengene som settes sammen er
kodelitteraler, hvitlister eller heltall. Alle 37 admin-endepunkter krever
admin, kontrollert både i koden og med kall utenfra. Alle skrivende
endepunkter har opphavssjekk unntatt Vipps-webhooken, som er HMAC-signert i
stedet og aldri får endre en betaling uten gyldig signatur. Opplastede bilder
tegnes om gjennom GD og får tilfeldig navn, så en fil som utgir seg for å være
et bilde blir et bilde. Filnavn valideres mot `^[0-9a-f]{32}\.jpg$`, så
`../` ikke er et gyldig bildenavn. Ingen `eval`, `exec`, `unserialize` eller
`include $variabel`. Ingen nøkler i git, hverken nå eller i historikken.

**Katalogen spurte tre ganger per kursdato — rettet 28. august.** Målt, ikke
gjettet: `api/kurs.php` kjørte **279 spørringer** på én sidevisning, og
`api/admin/kurs.php` 254.

- Mønsteret var det samme tre steder: én spørring per kurs etter datoene, og
  så inne i visningen ett kall etter ledige plasser og ett etter samlinger —
  per dato. 83 datoer ble 249 spørringer.
- Det vokser av seg selv nå. Paint on Pots og drop-in lager datoene sine av
  åpningstidene, og fjorten dager framover blir fort et par hundre. Katalogen
  er det første nettsiden henter, så alle besøkende betaler den.
- `Booking::ledigePlasserFlere()` og `Samlinger::forOkter()` henter alle i én
  spørring. `ledigePlasser()` og `forOkt()` kaller dem med én id, så
  regnestykket står **ett** sted — sto det to steder, kunne det ene tallet
  vist «3 plasser igjen» og det andre solgt den siste stolen. Den låste
  lesningen (`FOR UPDATE`) er beholdt: økta låses først, så regnes det.
- Etter: **kurs.php 279 → 21 spørringer** (103 → 24 ms), **admin/kurs.php
  254 → 36** (100 → 29 ms), påmeldte 102 → 13, marked 76 → 26, venteliste
  63 → 11. Svaret er byte-identisk før og etter, kontrollert med `cmp`.
- Ingen endepunkter ligger over 36 spørringer nå.

**Drop-in følger det samme — gjort 27. august.** «Samme bestilling med datoer
og tider, og tilgjengeligheten skal følge kurs og når jeg er innstemplet.»

- Migrasjon 079 gir `courses.folger_apningstid`. Til nå gjorde
  `gjenstand_i_kassa` to jobber på én gang: «gjenstanden betales i verkstedet»
  **og** «datoene lages av åpningstidene». Drop-in trengte bare den andre
  halvdelen — den betales på nett som før — og de to er skilt her.
- **Drop-in-tidene under Kurs og medlemskap → Drop-in står urørt.** De
  definerer fortsatt når verkstedet er åpent. Det nye er at drop-in også blir
  bookbar de dagene et kurs går, eller Lissom er stemplet inn, uten at noen må
  sette opp en tid.
- **Ingen dubletter.** Utleggingen hopper over de tidene kurset alt står med
  (`fra_apningstid = 0`). Går drop-in 10–13 på tirsdag fra ukereglene, lages
  det ingen plass oppi den — ellers lå den samme timen to ganger i
  bestillingen, med hvert sitt plasstall. Resten av dagen får plasser som
  vanlig.
- **Admin ser forskjell på dem.** På Drop-in-skjermen står de genererte som
  «Lagt ut av seg selv — kurs eller innstempling», ikke rammet inn i rødt som
  en tid som «stemmer ikke med åpningstidene over». Sletter man en, sier
  bekreftelsen at den kommer tilbake så lenge kurset eller innstemplingen står
  — vil man ha den bort for godt, må dagen stenges under Åpningstider.
- **Prisen står som før.** Drop-in koster kr. 490,- og betales med Vipps i
  bestillingen; det er bare Paint on Pots som har gjenstanden i kassa.
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

### 20. Gamle søknader som står som «venter»

Innmeldingen går rett til Vipps — det finnes ingen søknad til behandling
lenger. Men et medlem som har en gammel rad stående som «venter» fra den
gangen det fantes, ser fortsatt «Søknaden din er til behandling» i stedet for
innmeldingsskjemaet, og kommer ikke videre.

Jeg vet ikke om det finnes slike rader på den ekte siden — jeg ser bare den
lokale basen. Er det noen, må de settes til «godkjent» eller «avslått» før de
slipper inn. Si fra, så skriver jeg en migrasjon som rydder dem.

## Venter på svar fra Lissom

### 16. ~~Den enkle «Legg til deltaker» i deltakervinduet~~ — avklart 29. august

Eieren: «vi vil ha vipps og betaling ja». Den raske ruta har nå tre brikker —
Vipps, Kontant og Vippskrav — og et beløpsfelt som kommer fram når kravet er
valgt. Nummeret bytter etikett til «Mobil — kravet går hit», og knappen til
«Send vippskrav». Begge veiene inn står, og begge skriver den samme
påmeldingen.

### 16b. Gammelt punkt (beholdt for historikken)

Det finnes to veier inn nå. Den fyldige ligger i «Rediger økten» (navn,
e-post, telefon, antall, betaling, beløp, notat, vippskrav). Den enkle ligger
nederst i «Deltakere og venteliste» og tar navn, telefon og Vipps/Kontant.

Begge går til det samme endepunktet — samme booking, samme deltakerliste — så
det er to skjemaer inn til én ting, ikke to systemer. Den enkle er raskere når
noen står i døra. Skal den stå, tas bort, eller få vippskrav den også?

### 17. ~~Uke- og dagvisningen samler ikke~~ — avklart 29. august

Eieren: «jeg trenger ikke det». Måneds- og listevisningen samler; uke og dag
står som de er, så hver økt kan dras til et nytt klokkeslett.

### 17b. Gammelt punkt (beholdt for historikken)

Drop-in og Paint on Pots står som én linje per dag i måneds- og listevisningen.
I uke- og dagvisningen ligger de fortsatt som egne blokker på tidsaksen — der
må hver enkelt kunne dras til et nytt klokkeslett, og en samlet linje kan ikke
dras. Skal de samles der også, mister du flyttingen.

### 18. ~~«Få plasser» eller «Få ledige plasser»?~~ — avklart 29. august

Eieren: «det er forskjellig og riktig». Teksten står som den er.

### 18b. Gammelt punkt (beholdt for historikken)

Teksten når det er få igjen står som «Få plasser» på Dreiing og «Få ledige
plasser» på de andre — slik det ble skrevet. Skal de være like?

### 19. De to interne samlingene fra seeden

«Store former, viderekomne» og «Medlemsfrokost» ble lagt inn av migrasjon 003,
den gangen katalogen var gjettet framfor hentet. De er ikke spurt om, og ikke
rørt. Skal de stå?

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
