-- To artikler som kladd: Topp moderne utstyr (pugmill, platerulle, brennovn) og Helgekurs i Tønsberg.
--
-- Eieren, 25. september 2026: satsing på å bli Norges største keramikk-kurssenter.
-- Løfter frem maskinparken og det nasjonale tilbudet om intensive helgekurs.
--
-- Tekstene ble godkjent av eieren («GO – legg inn som kladd») før innlegging.
-- Ingenting vises for publikum før eieren publiserer fra admin (Nyheter → status).
--
-- Formatet er det nettsida tegner (parseInnhold): «# » er mellomtittel,
-- «## » undertittel, «- » punkt, tom linje skiller avsnitt. Bolken
-- «# Ofte stilte spørsmål» med «## spoersmaal» under blir FAQ-data for
-- Google — se Robottekst::artikkelFaq().
--
-- «INSERT IGNORE»: tittel og slug er unike. Kjøres migrasjonen en gang
-- til, eller har verkstedet alt en artikkel med samme navn, røres ingenting.

INSERT IGNORE INTO articles (tittel, kategori, slug, fokus_ord, dato, ingress, innhold, kilde, status, sortering) VALUES
(
  'Utstyret som utgjør forskjellen: pugmill, platerulle og profesjonell brennovn',
  'Verkstedet',
  'utstyret-som-utgjor-forskjellen-pugmill-platerulle-ovn',
  'keramikkutstyr pugmill platerulle keramikkovn',
  '25. september 2026',
  'Hva skiller et provisorisk hobbyrom fra et topp moderne keramikkverksted? Her er utstyret som sparer armene dine, eliminerer luftbobler og løfter kvaliteten på keramikken til et profesjonelt nivå.',
  '# Fra frustrasjon til ren skaperglede

Mange som driver med keramikk hjemme i kjelleren eller i trange fellesverksteder opplever de samme utfordringene: leire som må knas for hånd i evigheter, plater som blir ujevne og slår seg, og brenninger som må utsettes fordi ovnen er for liten eller upålitelig.

Når du trer inn i et profesjonelt tilrettelagt verksted, merker du forskjellen umiddelbart. Riktig utstyr handler ikke om jåleri – det handler om flyt, ergonomi og trygghet for at arbeidet du legger ned faktisk overlever brenningen.

# 1. Pugmill (leire-eltekvern): Perfekt leire uten luftbobler

Den største fienden til enhver keramiker er luftbobler i leiren. Én oversett luftlomme kan føre til at gjenstanden eksploderer i ovnen og ødelegger både ditt eget og andres arbeid.

- Slutt på tung håndelting: Å kna ti kilo stiv leire for hånd sliter hardt på håndledd og skuldre.
- Vakuum-pugmill: Kvernen suger ut all luft og elter leiren med enorme krefter. Ut kommer en kompakt, homogen leirpølse som er 100 % klar til skiva eller kjevlingen.
- Bærekraftig resirkulering: All tørr og avskåret leire kan gjenvinnes til ny, førsteklasses leire uten svinn.

# 2. Platerulle: Jevne leireplater med et enkelt drag

Skal du bygge store fat, vaser, krukker eller smørklokker, er jevn platetykkelse alfa og omega.

- Med en vanlig kjevle må du trykke hardt, snu leiren gjentatte ganger og kjempe mot ujevnheter som skaper indre spenninger.
- Med en mekanisk platerulle stiller du enkelt inn ønsket millimeterhøyde på valsene, sveiver leiren igjennom på under fem sekunder, og får en speilblank, jevn plate uten spenninger.

# 3. Stor, datastyrt brennovn

Hjertet i ethvert keramikkverksted er brennovnen:

- En stor ovn gir plass til høye vaser, vide fat og store kursproduksjoner uten at gjenstandene må stables for tett.
- Moderne, digitale styringsenheter følger millimeterpresise brennekurver – med kontrollerte tørketrinn, langsom oppvarming over kvartsspranget (573 °C), og en nøye kontrollert topptemperatur på over 1220 °C for full sintring og matsikkerhet.

# 4. God kaffe og et koselig arbeidsmiljø

Utstyr alene gjør ingen keramiker lykkelig. Verkstedet må ha sjel.

Hos Lissom på Teie har vi kombinert det beste av profesjonelt maskineri med lun trearkitektur, store vinduer med naturlig lys, behagelig musikk og alltid fersk kaffe på trakteren. Enten du sitter dypt konsentrert ved en av våre ti dreieskiver eller skravler med andre rundt langbordet, skal verkstedet være ditt kreative fristed.

Vil du oppleve verkstedet? Se kursene våre på lissom.no/kurs eller les om medlemskap på lissom.no/medlemskap.

# Ofte stilte spørsmål

## Kan medlemmer bruke pugmillen og platerullen selv?

Ja, alle medlemmer får opplæring i trygg bruk av maskinene under introduksjonen til verkstedet.

## Hvor mange dreieskiver har Lissom?

Vi har ti moderne Shimpo-dreieskiver som går stille og gir presis turtallskontroll.

## Hvilken temperatur brennes steingodset på?

Råbrann kjøres typisk til 950–1000 °C, mens glasurbrann brennes til 1220–1240 °C for maksimal slitestyrke og matsikkerhet.',
  'ai', 'kladd', 25
),
(
  'Keramikkhelg i Tønsberg: intensivt helgekurs og kreativ pause ved kysten',
  'Kurs',
  'keramikkhelg-tonsberg-helgekurs',
  'keramikkurs helg keramikkhelg norge',
  '25. september 2026',
  'Trenger du en pause fra hverdagens skjermer og mas? Bli med på en intensiv keramikkhelg hos Lissom på Teie. Perfekt for vennegjenger, par og alene-reisende fra hele Norge.',
  '# Unn deg en kreativ helg med leire mellom hendene

Keramikk er den ultimate måten å koble av på. Ved dreieskiva eller modelleringsbordet kan du ikke multitaske, sjekke mobilvarsler eller tenke på mandagens gjøremål: du må være 100 % til stede med hendene i leiren.

Stadig flere reiser fra Oslo, Drammen, Telemark og resten av landet til Teie ved Tønsberg for å delta på våre helgekurs i keramikk.

# Hvorfor velge et helgekurs?

Et kveldskurs i en travel hverdag kan av og til føles stressende etter en lang arbeidsdag. På et helgekurs har du god tid:

- Full fordypning: Du får timer i sammenheng til å øve på sentrering, forme leiren, prøve og feile uten tidspress.
- Rask progresjon: Når du jobber intensivt over en helg sitter grepene og muskelminnet mye raskere.
- Sosialt og uformelt: Du deler opplevelsen med andre leireglade mennesker i et varmt og inkluderende fellesskap.

# Hva lærer du på en keramikkhelg hos Lissom?

Vi tilbyr helgekurs innen både dreiing og håndbygging:

- Dreiehelg for nybegynnere: Grundig innføring i leirens egenskaper, sentrering på skiven, opptrekk av vegger, forming av kopper og skåler, og trimming av fot andre dag.
- Håndbygging og store prosjekter: Lær plateteknikk, pølseteknikk og formbygging for å lage store serveringsfat, krukker, saltkastere eller smørklokker.
- Alt inkludert: Leire, verktøy, veiledning, brenning og enkel servering med god kaffe er alltid inkludert i kursprisen.

# Kombiner kurset med en helgetur til Tønsberg og Nøtterøy

Verkstedet vårt ligger idyllisk til på Teie, rett over kanalen fra Tønsberg brygge – bare én time og ti minutter med tog eller bil fra Oslo.

Gjør helgen til en komplett opplevelse:
- Bo på et av hotellene ved brygga i Norges eldste by.
- Nyt god mat og frisk sjøluft langs kyststiene på Nøtterøy og Tjøme.
- Avslutt dagen med kreative timer i verkstedet vårt.

Når gjenstandene dine er ferdig tørket, råbrent, glasert og brent, kan de enten hentes i verkstedet eller sendes trygt hjem til deg i posten etter avtale.

Se datoer for neste kurs-slipp og sikre deg plass på lissom.no/kurs.

# Ofte stilte spørsmål

## Må jeg ha erfaring fra før for å delta på helgekurs?

Nei, helgekursene våre er spesielt tilrettelagt for nybegynnere, med grundig oppfølging hele veien.

## Hvor mange plasser er det på et helgekurs?

Vi holder gruppene små (maks 8–10 deltakere) slik at hver deltaker får tett, personlig veiledning.

## Hva skjer hvis jeg bor langt unna og ikke kan hente keramikken selv?

Ingen problem. Vi pakker og sender de ferdige arbeidene dine i posten mot et lite frakttillegg så snart brenningene er ferdige.',
  'ai', 'kladd', 26
);
