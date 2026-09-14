-- Seks artikler skrevet som svar paa det folk spoer Google og ChatGPT om,
-- og egne soekeord-titler paa tre kurs.
--
-- Eieren, 14. september 2026: «jeg vil også at du lager det du mener er
-- relevante nyhetsbrev eller artikler, dette kan være lurt i forhold til
-- synlighet på web og ai» — og GO paa hele del B, tekstene lest paa forhaand.
--
-- Artiklene ligger under Nyheter i admin og kan endres der som alt annet.
-- Fem gaar ut som publisert. Den sjette — «Keramikk til bedriften» — ligger
-- som kladd: den trenger to–tre setninger om hva som ble laget for Grenseløs
-- og Spire Regnskap, og det vet bare verkstedet. Stedene er merket
-- «[Fyll inn: …]» i teksten.
--
-- Formatet er det nettsida tegner (parseInnhold): «# » er mellomtittel,
-- «## » undertittel, «- » punkt, tom linje skiller avsnitt. Bolken
-- «# Ofte stilte spørsmål» med «## spoersmaal» under blir FAQ-data for
-- Google — se Robottekst::artikkelFaq().
--
-- «INSERT IGNORE»: tittel og slug er unike. Kjoeres migrasjonen en gang
-- til, eller har verkstedet alt en artikkel med samme navn, roeres ingenting.

INSERT IGNORE INTO articles (tittel, kategori, slug, fokus_ord, dato, ingress, innhold, kilde, status, publisert_at, sortering) VALUES
(
  'Dreiekurs i Tønsberg: hva skjer de to kveldene?',
  'Kurs',
  'dreiekurs-i-tonsberg-hva-skjer',
  'dreiekurs tønsberg',
  '14. september 2026',
  'Mange lurer på hva et dreiekurs egentlig går ut på, og om man klarer det uten å ha rørt leire før. Her er de to kveldene hos Lissom på Teie, fra første klump til ferdig kopp.',
  '# Første kveld: sentrere og dreie

Du får en dreieskive for deg selv og en klump leire. Det første du lærer er å sentrere — å få leira til å sitte helt stille midt på skiva mens den snurrer. Det tar de fleste en halvtimes tid, og det er den delen alle husker etterpå. Så åpner du klumpen, trekker opp veggene, og plutselig står det en kopp der. Du lager gjerne tre–fire ting første kveld; noen blir skjeve, og det er meningen.

# Andre kveld: trimme og dekorere

Leira har tørket til «lærhard» — fast nok til å snus opp ned. Da trimmer du foten, så koppen står stødig og får den rene kanten nederst. Etterpå dekorerer du: riss, stempel eller begitning. Vi glaserer og brenner alt for deg etter kurset.

# Hva som er inkludert

- Leire, verktøy og forkle
- Glasur og brenning
- Veiledning hele veien, i små grupper

Du henter det ferdige etter noen uker, når det er glasert og brent. Vi sender beskjed.

# Hvem passer det for

Alle uten forkunnskaper. Kurset går i små grupper, så du får hjelp når du sitter fast. Kurset er to kvelder, tre timer hver gang — se prisen og datoene på kurssiden.

# Hvor

Verkstedet ligger i Nordre Løkkevei 15 på Teie, fem minutter fra Tønsberg sentrum over Kanalbrua, med gratis parkering rett utenfor.

# Ofte stilte spørsmål

## Må jeg ha prøvd keramikk før?

Nei. Dreiekurset er laget for nybegynnere, og alle får hjelp med sentreringen.

## Hvor mange ting får jeg med meg hjem?

De fleste får med seg to til fire ferdige kopper eller skåler, glasert og brent.

## Når kan jeg hente det jeg laget?

Etter glasering og brenning, normalt to til fire uker etter siste kveld. Vi sender beskjed.',
  'ai', 'publisert', NOW(), 10
),
(
  'Plateteknikk eller håndbygging — hva er forskjellen, og hva passer deg?',
  'Kurs',
  'plateteknikk-eller-handbygging',
  'plateteknikk håndbygging keramikk',
  '14. september 2026',
  'Alt som ikke lages på dreieskiva, lages for hånd. Men «håndbygging» er flere ting. Her er forskjellen på plateteknikk, pølseteknikk og klypeteknikk — og hvilket kurs som bruker hva.',
  '# Plateteknikk

Plateteknikk er å kjevle leira ut til en jevn plate, som en deig, og så bygge med platene: legge dem over en form, skjøte kanter, reise vegger. Det gir rene, jevne flater og er den raskeste veien til noe som ser ferdig ut. Hos oss er det plateteknikk i «Lag din egen bolle» og i «Store fat kurs» — der former du fatet over en form og jobber med kanten og dekoren.

# Pølseteknikk

Pølseteknikk (coiling) er å rulle leira til pølser og legge dem lag på lag, som en spiral, og så glatte sammen. Det er den eldste måten å lage keramikk på, og den tåler høye, buede former godt.

# Klypeteknikk

Klypeteknikk er å klype en klump opp til en skål med tommelen — det første alle gjør, og fortsatt en teknikk vi bruker til små ting.

# Fransk smørklokke

Smørklokken bygges med håndbygging i to deler: en klokke som holder smøret, og en skål med vann som holder det ferskt uten kjøleskap. Kurset er én kveld, og det er en fin inngang til håndbygging fordi de to delene må passe sammen.

# Hva passer deg?

- Vil du ha noe ferdig på én kveld: plateteknikk — bolle eller fat.
- Vil du bygge noe med flere deler: smørklokken.
- Vil du ha den fulle opplevelsen med skiva: dreiekurset over to kvelder.

Leire, glasur og brenning er inkludert i alle kursene. Prisene står på kurssiden.

# Ofte stilte spørsmål

## Er håndbygging lettere enn dreiing?

Det er lettere å få et pent resultat første kveld. Dreiing krever mer trening, men gir en annen glede når det sitter.

## Kan barn være med på plateteknikk?

Ja. «Alle barn, se her! Tre på rad» er plateteknikk for barn, med voksen for de under 12.

## Får jeg velge glasur selv?

Ja, du velger glasur på slutten av kvelden, og vi brenner for deg.',
  'ai', 'publisert', NOW(), 11
),
(
  'Paint on Pots: slik fungerer det, og hva koster det',
  'Paint on Pots',
  'paint-on-pots-slik-fungerer-det',
  'paint on pots tønsberg',
  '14. september 2026',
  'Paint on Pots er den enkleste måten å ta med seg noe hjem fra keramikkverkstedet: du velger en ferdigbrent gjenstand, maler den slik du vil, og vi glaserer og brenner. Ingen forkunnskaper, ingen leire på fingrene.',
  '# Slik går det til

Du velger blant koppene, skålene og figurene på hylla — de er allerede brent én gang, så de er harde og hvite. Så maler du med keramikkfarger: striper, prikker, navn, et motiv. Fargene ser matte og bleke ut når du maler; det er etter brenningen de får glans og dybde. Når du er ferdig, setter vi på klar glasur og brenner. Etter to til fire uker henter du det, klart til bruk.

# Hvor lang tid tar det

Cirka halvannen time. Du kommer når det passer i åpningstiden og booker plass på nettsiden.

# Hva det koster

Du betaler for gjenstanden du velger. Farger, glasur og brenning er med i prisen. Prisene står på Paint on Pots-siden.

# Hvem det passer for

Barn fra 6 år sammen med en voksen, venninnegjengen, bursdagen, besteforeldre med barnebarn — og deg som vil prøve keramikk uten å begynne med leire. Paint on Pots er det mange starter med, og kommer tilbake til.

# Ofte stilte spørsmål

## Må jeg booke Paint on Pots på forhånd?

Ja, book plass på nettsiden så vi vet at det er ledig ved bordet. Det er drop-in innenfor åpningstidene.

## Kan jeg ta med det jeg malte hjem samme dag?

Nei — det må glaseres og brennes først. Det er klart til henting etter to til fire uker.

## Passer Paint on Pots for barn?

Ja, fra 6 år sammen med en voksen. Det er en av de mest populære tingene vi har for familier.',
  'ai', 'publisert', NOW(), 12
),
(
  'Hva koster et keramikkurs i Tønsberg og Vestfold?',
  'Kurs',
  'hva-koster-keramikkurs-i-tonsberg',
  'keramikkurs pris tønsberg',
  '14. september 2026',
  'Hva som er inkludert i prisen hos Lissom på Teie, hvordan kursene er bygget opp, og hvor du finner prisen som gjelder akkurat nå — så du slipper å regne selv.',
  '# Kurs på én kveld

Lag din egen bolle, Store fat kurs, Vi lager fransk smørklokke og Keramikk Workshop går over én kveld. Leire, verktøy, glasur og brenning er inkludert. Du henter det ferdige etter noen uker.

# Dreiekurs, to kvelder

Tre timer hver gang, to kvelder. Alt inkludert, også to brenninger.

# Barn

«Alle barn, se her! Tre på rad» er plateteknikk for barn. Barn under 12 tar med en voksen.

# Paint on Pots

Du betaler for gjenstanden du velger; farger, glasur og brenning er med.

# Events

Sip & Clay og Date Night er kvelder med leire og godt selskap — ta med det dere vil drikke, vi har glass.

# Medlemskap

Fire medlemskap, fra én prøvemåned uten binding til årsavtale med flest timer og lavest timepris. Alle gir egen hylle og dørkode døgnet rundt.

# Gavekort

Fra kr 100 til kr 20 000, gyldig i tre år, kan brukes på alt — kurs, events, medlemskap og varer i butikken.

# Hvor prisen står

Alle priser står på kurssiden, på Paint on Pots-siden og på medlemskapssiden, og betales med Vipps når du booker. Det som står der er alltid det som gjelder — prisene her på nettsiden oppdateres på ett sted.

# Ofte stilte spørsmål

## Er leire og brenning inkludert i prisen?

Ja, i alle kurs. Du betaler ikke noe ekstra for å få tingene brent.

## Kan jeg bruke gavekort på medlemskap?

Ja, gavekortet kan brukes på kurs, events, medlemskap og varer i butikken.

## Får vi egen pris hvis vi er flere?

For grupper på 6–16 setter vi opp et eget event — send en forespørsel, så får dere pris.',
  'ai', 'publisert', NOW(), 13
),
(
  'Medlemskap i keramikkverksted: hva får du, og hvem passer det for?',
  'Medlemskap',
  'medlemskap-i-keramikkverksted',
  'medlemskap keramikkverksted tønsberg',
  '14. september 2026',
  'Etter et kurs vil mange fortsette — men få har plass til dreieskive og ovn hjemme. Medlemskap i verkstedet på Teie er svaret: egen hylle, dørkode døgnet rundt, og timer i verkstedet hver måned.',
  '# Dette får du

- Tilgang til verkstedet 24/7 med personlig dørkode
- Egen hylleplass til prosjekter og utstyr
- Dreieskiver, verktøy og glasurer
- Et verksted der det alltid er noen å spørre

Du jobber med egne prosjekter i eget tempo.

# Fire medlemskap

Prøv Lissom er én måned med et fast antall timer og ingen binding — for deg som vil finne ut om dette er noe. Mini 15 gir 15 timer i måneden. Basis 30 gir 30 timer, og er det mest valgte. Årsmedlemskapet gir flest timer i måneden til lavest timepris, med årsavtale — og mulighet til å selge egne arbeider gjennom lissom.no. Prisene står på medlemskapssiden.

# Hvem passer det for

Deg som har tatt et kurs og vil videre. Deg som har dreid før og mangler et sted. Deg som vil ha et sted å gå til på kvelden som ikke er sofaen. Du trenger ikke være «flink» — du trenger lyst.

# Slik begynner du

Meld deg inn på nettsiden, godkjenn avtalen i Vipps, og du får dørkoden. Leire kjøper du i verkstedet.

# Ofte stilte spørsmål

## Må jeg ha tatt kurs for å bli medlem?

Nei, men du bør kunne jobbe selvstendig med leire. Er du usikker, start med Prøv Lissom eller et kurs.

## Kan jeg komme på kvelden og i helgen?

Ja, verkstedet er åpent for medlemmer døgnet rundt med dørkode.

## Kan jeg selge det jeg lager?

Ja, med årsmedlemskap kan du selge egne arbeider gjennom butikken på lissom.no.',
  'ai', 'publisert', NOW(), 14
),
(
  'Keramikk til bedriften: kopper med eget motiv, slik gjør vi det',
  'Bedrift',
  'keramikk-til-bedriften',
  'keramikk bedrift logo kopper',
  '14. september 2026',
  'Kepler Bilservice byttet ut pappkrusene med keramikkopper laget i verkstedet på Teie. Grenseløs, en av Tønsbergs flotteste restauranter, og Spire Regnskap har også keramikk fra Lissom. Slik foregår det.',
  '# Hvorfor

En kopp med bedriftens eget motiv sier noe pappkruset ikke kan: at gjestene er verdt det lille ekstra. Kepler sa det slik: «Vi ønsker å gi kundene det lille ekstra, og denne flotte keramikken bidrar til det.» Og det er bærekraftig — en kopp som vaskes erstatter tusen som kastes.

# Slik går det til

Dere sier hva dere trenger — kopper, skåler, fat, hvor mange, og til hva. Vi tegner motivet etter ønske og viser et prøveeksemplar før vi lager resten. Alt dreies og glaseres i verkstedet, så hvert stykke er litt sitt eget.

# Grenseløs

[Fyll inn: hva dere laget for Grenseløs, 2–3 setninger.]

# Spire Regnskap

[Fyll inn: hva dere laget for Spire Regnskap, 2–3 setninger.]

# Kepler Bilservice

Kepler ønsket å tenke mer bærekraftig og byttet fra pappkrus til keramikkopper. Motivet er tegnet etter ønske fra kunden.

# Pris og tid

[Fyll inn: fra-pris per kopp og typisk leveringstid — eller «send en forespørsel, så får dere pris».]

# Eller: lag dem selv

Mange bedrifter kombinerer det med et teambuilding-event ved dreieskiva. Da lager kollegene koppene selv, og vi glaserer og brenner. Se Bedrift og event.

# Ofte stilte spørsmål

## Kan vi få logoen vår på koppene?

Ja. Vi tegner motivet etter ønske og viser et prøveeksemplar før vi lager serien.

## Hvor mange må vi bestille?

[Fyll inn: minste antall, eller «ingen minstebestilling».]',
  'ai', 'kladd', NULL, 15
);

-- Tre kurs faar egen tittel og beskrivelse i soket, med teknikken i navnet.
-- Bare der verkstedet ikke alt har skrevet noe selv.
UPDATE courses SET
  seo_tittel = 'Store fat kurs — plateteknikk i Tønsberg | Lissom',
  seo_meta   = 'Lag ditt eget store fat med plateteknikk på én kveld. Vi kjevler, former over form og jobber med kanter og dekor. Leire, glasur og brenning inkludert.'
WHERE slug = 'store-fat-kurs' AND COALESCE(seo_tittel, '') = '';

UPDATE courses SET
  seo_tittel = 'Lag din egen bolle — plateteknikk | Lissom Keramikk',
  seo_meta   = 'En kveld med plateteknikk i Tønsberg: kjevle ut leira, form boller i flere størrelser, velg glasur. Ingen forkunnskaper. Leire og brenning inkludert.'
WHERE slug = 'lag-din-egen-bolle' AND COALESCE(seo_tittel, '') = '';

UPDATE courses SET
  seo_tittel = 'Fransk smørklokke — håndbyggingskurs | Lissom',
  seo_meta   = 'Bygg en fransk smørklokke i to deler på én kveld i keramikkverkstedet på Teie. Håndbygging, dekor og glasur — alt inkludert, ingen forkunnskaper.'
WHERE slug = 'vi-lager-fransk-smorklokke' AND COALESCE(seo_tittel, '') = '';
