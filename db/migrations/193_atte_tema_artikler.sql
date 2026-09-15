-- Åtte tema-artikler: fire nye, tre omskrevne, én retting.
--
-- Eieren, 15. september 2026: «kan du lage seo indexserte artikler som
-- omfavner våre temaer, også dette med at medlemmer kan ha med barn, kurs
-- osv.» — «alle 8, rydd gjerne i de som er der om de er av feil format
-- eller innhold» — og «artiklene er fine» etter å ha lest dem ord for ord.
--
-- Nye: Ta med barn, Date Night, Sip & Clay, Første gang på keramikkurs.
-- Omskrevne (samme tittel og adresse som før — Google kjenner dem alt):
-- de tre fra 30. august / 10. september, som manglet mellomtitler og FAQ
-- og hadde priser i teksten. Prisene er ute; de vises til kurssiden,
-- medlemskapssiden og Min side. Drop-in er ute — finnes ikke som kurs.
-- Paint on Pots (nr. 8 på lista) er dekket av artikkelen fra 14. september.
--
-- Formatet er det nettsida tegner (parseInnhold): «# » mellomtittel,
-- «## » undertittel, «- » punkt, tom linje skiller avsnitt. Bolken
-- «# Ofte stilte spørsmål» blir FAQ-data for Google (Robottekst::artikkelFaq).
--
-- INSERT IGNORE: tittel og slug er unike, så en artikkel som alt finnes
-- røres ikke. UPDATE-ene treffer på adressen.

INSERT IGNORE INTO articles (tittel, kategori, slug, fokus_ord, dato, ingress, innhold, kilde, status, publisert_at, sortering) VALUES
(
  'Ta med barn i keramikkverkstedet: kurs, Paint on Pots og medlemskap med barn',
  'Keramikk',
  'keramikk-med-barn-tonsberg',
  'keramikk med barn tønsberg',
  '15. september 2026',
  'Barn og leire er en god kombinasjon. Her er de tre måtene barn kan være med hos Lissom på Teie: barnekurset, Paint on Pots — og for medlemmer, tillegget «Ta med barn» som gjør at barnet kan bli med på timene dine i verkstedet.',
  '# Barnekurset: Tre på rad i leire

«Alle barn, se her! Tre på rad» er kurset vårt for barn. Sammen lager vi et lite Tre på rad-spill med plateteknikk: leira kjevles ut til plater, brettet og brikkene skjæres ut og dekoreres. Det blir en gave eller et spill barnet kan bruke selv sammen med en venn. Barn under 12 år tar med en voksen. Datoene står på kurssiden.

# Paint on Pots: mal, så brenner vi

Paint on Pots passer fra 6 år sammen med en voksen. Barnet velger en ferdigbrent kopp, skål eller figur på hylla, maler den med keramikkfarger, og vi glaserer og brenner. Ingen leire på fingrene, og det er ferdig etter noen uker. Det er en av de mest populære tingene vi har for familier — og det går like fint med besteforeldre som med foreldre.

# Medlem? Ta med barnet på timene dine

Er du medlem, kan du kjøpe tillegget «Ta med barn» på Min side. Da kan ett barn på opptil 12 år være med deg i verkstedet gjennom en hel kalendermåned, på timene du allerede har i medlemskapet. Tillegget gjelder én måned om gangen, betales med Vipps og er aktivt så snart betalingen er gjennomført. Du kan kjøpe det for inneværende måned, og fra den 25. også for neste.

Slik virker det:

- Ett barn per tillegg, med navn og alder
- Barnet bruker timene dine — det trekkes ikke ekstra tid
- Leire og glasur til barnet er ikke inkludert
- Prisen står på Min side

# Vilkårene, kort fortalt

Verkstedet er et sted med dreieskiver, glasurer og en ovn. Derfor gjelder noen enkle regler når barn er med:

- Barnet er alltid sammen med deg, og er aldri alene i verkstedet
- Du som foresatt sørger for at barnet følger ordensreglene og HMS-reglene
- Du godtar dette som vilkår når du kjøper tillegget

Reglene er de samme som gjelder for alle i verkstedet — bare med deg som ansvarlig for to.

# Hvor gamle bør barna være?

Paint on Pots fungerer fra 6 år. På barnekurset og i verkstedet er det mer et spørsmål om tålmodighet enn alder: klarer barnet å sitte ved et bord i en times tid og like at hendene blir skitne, går det som regel fint. Voksne som er med, får ofte like mye ut av det som barna.

# Ofte stilte spørsmål

## Kan barn være med på vanlige kurs?

Barnekurset «Tre på rad» og Paint on Pots er laget for barn. De andre kursene er for voksne — men på Paint on Pots kan hele familien være med.

## Hva må jeg gjøre for å ta med barnet mitt som medlem?

Kjøp tillegget «Ta med barn» på Min side, betal med Vipps, og barnet kan være med deg ut måneden. Barnet må alltid være sammen med deg i verkstedet.

## Er leire til barnet inkludert i tillegget?

Nei. Tillegget gir barnet plass ved siden av deg; leire og glasur kommer i tillegg.

## Kan jeg ta med to barn?

Ett barn per tillegg. Vil du ha med to, kjøper du to tillegg.',
  'ai', 'publisert', NOW(), 15
),
(
  'Date Night i keramikkverkstedet: en annerledes kveld for to',
  'Events',
  'date-night-keramikk-tonsberg',
  'date night tønsberg',
  '15. september 2026',
  'Lei av kino og middag? På Date Night hos Lissom på Teie lager dere noe i leire sammen — med skitne hender, mye latter og en kveld dere faktisk husker. Her er hva som skjer, hva dere trenger, og hvordan dere booker.',
  '# En date med hendene i leire

Date Night er en kveld for to i keramikkverkstedet. Dere får hvert deres arbeidsbord, leire og verktøy, og vi viser dere teknikken fra start. Mens leira formes, får praten flyte. Ingen av dere trenger å ha gjort dette før — og det er litt av poenget: dere er nybegynnere sammen, og det er lov å le av det som blir skjevt.

Det handler ikke om å prestere, men om å være sammen om noe. Mange forteller at tiden gikk fortere enn de trodde, og at de snakket om andre ting enn de pleier.

# Slik foregår kvelden

- Dere kommer til verkstedet i Nordre Løkkevei 15 på Teie
- Vi viser hva dere skal lage og hvordan, og hjelper underveis
- Dere former hver deres ting — eller én sammen
- Ta gjerne med noe å drikke, vi har glass

Det dere lager, glaserer og brenner vi etterpå. Etter noen uker sender vi beskjed, og dere henter det ferdig — en kopp, en skål eller noe helt annet, med en historie.

# Hvorfor leire fungerer som date

Leira krever at du er til stede. Den reagerer på alt du gjør — for hardt trykk, og formen gir etter; for lite vann, og fingrene drar. Du kan ikke sjekke mobilen samtidig. Det gjør noe med samtalen: dere snakker om det som skjer mellom hendene, og så om alt mulig annet. Og når det går galt — for det gjør det — er det bare å klemme leira sammen og begynne på nytt. Det er en ganske fin ting å gjøre sammen.

# Hvem passer det for

Par som vil gjøre noe annet enn det vanlige. Nye par som vil ha noe å snakke om. Par som har vært sammen i tjue år og trenger en kveld uten skjermer. Og de som ikke er par ennå — det fungerer overraskende bra som første date.

# Når og hvordan

Date Night settes opp som event på eventsiden. Der ser dere neste dato og booker plass; betalingen går med Vipps. Ser dere ingen dato, er neste ikke satt ennå — følg med, eller send oss en melding, så sier vi fra.

Gavekort kan brukes til Date Night, og det er en fin gave til noen dere vet trenger en kveld sammen.

# Ofte stilte spørsmål

## Må vi kunne noe om keramikk?

Nei. Date Night er for nybegynnere, og vi viser alt fra start.

## Får vi med oss det vi lager hjem samme kveld?

Nei — det må tørke, glaseres og brennes først. Vi sender beskjed når det er klart til henting, normalt etter noen uker.

## Kan vi ta med vin?

Ja, ta med det dere vil drikke. Vi har glass.

## Er det bare for par?

Nei. Det er laget for to, men to venner eller søsken har det like fint.',
  'ai', 'publisert', NOW(), 16
),
(
  'Sip & Clay: utdrikningslag, venninnekveld og jobbkveld med leire',
  'Events',
  'sip-and-clay-tonsberg',
  'sip and clay tønsberg',
  '15. september 2026',
  'Er dere en gjeng som skal finne på noe? Sip & Clay hos Lissom på Teie er en kveld med leire, noe godt i glasset og hendene i arbeid. Det fungerer for utdrikningslag, venninnekvelder, bursdager og jobbgjengen — og ingen trenger å ha gjort det før.',
  '# Hva Sip & Clay er

Sip & Clay er en kveld i keramikkverkstedet der dere former noe i leire mens dere drikker det dere selv har tatt med. Vi har glassene, leira, verktøyet og en som viser dere hvordan. Dere har praten og latteren. Det er ingen forkunnskaper som skal til, og det som blir skjevt blir gjerne kveldens høydepunkt.

# Utdrikningslag

Et utdrikningslag trenger noe som samler gjengen, som ikke er enda en middag, og som passer for både den som elsker å lage ting og den som helst vil holde et glass. Sip & Clay gjør begge deler. Bruden eller brudgommen får med seg noe hjem som ble laget den kvelden — og bildene blir bedre enn fra baren.

# Jobbkveld og teambuilding

Kollegaer som sitter ved samme bord med leire, snakker om andre ting enn de gjør på kontoret. Det er lav terskel, ingen vinner, og alle får noe med seg hjem. Sip & Clay passer for avdelingen som skal ha en sosial kveld, for den lille bedriften som vil gjøre noe sammen, og som avslutning på en fagdag.

# Venninnekveld og bursdag

Fire–fem venninner som vil ha en kveld sammen, eller en 40-årsdag som skal feires med noe annet enn kake: dere booker plassene deres på eventsiden og møter opp. Vi tar oss av resten.

# Slik foregår kvelden

- Dere kommer til verkstedet i Nordre Løkkevei 15 på Teie
- Ta med det dere vil drikke — vi har glass
- Vi viser teknikken, og dere former hver deres ting
- Det dere lager, glaserer og brenner vi etterpå, og dere henter det etter noen uker

# Dette er inkludert

- Leire, verktøy og forkle
- Glasur og brenning
- Veiledning hele kvelden
- Glass til det dere tar med

Verkstedet ligger i Nordre Løkkevei 15 på Teie, fem minutter fra Tønsberg sentrum, med gratis parkering rett utenfor — og det er lett å bestille taxi hjem derfra.

# Egen kveld for gruppa

Er dere 6–16, setter vi opp en egen kveld bare for dere. Send en forespørsel via nettsiden med hvor mange dere er og når det passer, så får dere dato og pris. Mindre grupper booker plasser på en av Sip & Clay-kveldene som står på eventsiden.

# Ofte stilte spørsmål

## Hvor mange kan vi være?

Enkeltplasser bookes på eventsiden. Grupper på 6–16 får en egen kveld — send en forespørsel.

## Må vi ta med drikke selv?

Ja, ta med det dere vil ha. Vi har glass.

## Kan vi få med oss det vi lager samme kveld?

Nei — det må glaseres og brennes først. Vi sender beskjed når det er klart, normalt etter noen uker.

## Kan bedriften få faktura?

Send en forespørsel via nettsiden, så avtaler vi betaling for gruppekvelder direkte.',
  'ai', 'publisert', NOW(), 17
),
(
  'Første gang på keramikkurs? Dette trenger du å vite',
  'Kurs',
  'forste-gang-pa-keramikkurs',
  'keramikkurs nybegynner',
  '15. september 2026',
  'Skal du på ditt første keramikkurs og lurer på hva du bør ha på deg, hva du må ta med, og hva som skjer etterpå? Her er alt det praktiske hos Lissom på Teie — så du kan bruke kvelden på leira, ikke på å lure.',
  '# Du trenger ikke ta med noe

Leire, verktøy, forkle, glasur og brenning er inkludert i alle kursene våre. Du trenger ingen forkunnskaper og ikke noe utstyr. Kom med to tomme hender og litt tid.

# Kle deg for leire

- Ha på noe du ikke er redd for. Leirvann sprer seg lenger enn du tror; det går av i vask, men buksa blir våt over lårene.
- Korte negler gjør en reell forskjell — lange negler graver spor i leira uten at du merker det.
- Ta av ringer, klokke og armbånd.
- Ha håret ut av ansiktet. Du kommer ikke til å ha rene hender når det faller ned.

# Velg riktig kurs

Vil du ha noe ferdig på én kveld, velg et håndbyggingskurs: «Lag din egen bolle», «Store fat kurs» eller «Vi lager fransk smørklokke». Vil du lære å dreie, er «Nybegynner dreiekurs» to kvelder — første kveld dreier du, andre kveld trimmer og dekorerer du. Vil du prøve keramikk uten leire, er Paint on Pots stedet å begynne: du maler en ferdig gjenstand, og vi brenner.

# Slik booker du

Kursene og datoene står på kurssiden. Du velger dato, betaler med Vipps, og får bekreftelse på e-post. Har du gavekort, bruker du det i samme steg.

# Kvelden

Kom noen minutter før. Kursene går i små grupper, så du får hjelp når du sitter fast — og alle sitter fast et sted. Forvent at de første forsøkene blir skjeve. Det er ikke et tegn på at du mangler talent; det er slik alle begynner.

# Det du lager er ikke ferdig når du går

Dette er det som overrasker flest. Leira må tørke sakte, brennes én gang, glaseres og brennes igjen. Ovnen har sitt eget tempo. Regn med noen uker fra kurskvelden til du har koppen i hånda. Vi sender beskjed når det er klart, og du henter det i verkstedet.

Skal det være en gave, planlegg med den tiden — ikke uka før.

# Finne fram

Verkstedet ligger i Nordre Løkkevei 15 på Teie, fem minutter fra Tønsberg sentrum over Kanalbrua, med gratis parkering rett utenfor.

# Ofte stilte spørsmål

## Må jeg ha prøvd keramikk før?

Nei. Alle kursene er for nybegynnere.

## Hva skal jeg ha på meg?

Klær som tåler leire, korte negler, ingen ringer. Forkle får du hos oss.

## Når kan jeg hente det jeg laget?

Etter tørking, glasering og brenning — normalt to til fire uker etter kurset. Vi sender beskjed.

## Kan jeg kjøpe kurs som gave?

Ja. Gavekort kjøpes på nettsiden og kan brukes på kurs, events, medlemskap og varer i butikken.',
  'ai', 'publisert', NOW(), 18
);

-- Omskrevet: Dreiekurs for nybegynnere: slik føles det å sitte ved dreieskiva første gang
UPDATE articles SET
  fokus_ord = 'dreiekurs nybegynner',
  ingress   = 'Mange lurer på om de har det som skal til for å dreie. Her er hva som faktisk skjer ved skiva, hvorfor det roer ned, og hva du bør vite før du setter deg ned med en klump leire foran deg.',
  innhold   = '# Det ser enkelt ut på film

En klump leire går rundt, to hender legger seg rundt den, og opp vokser en krukke. I virkeligheten er det litt mer motstridende enn som så — og det er nettopp derfor folk blir hektet.

# Det første som skjer: sentrering

Før du kan lage noe som helst, må leira sentreres. Det betyr at klumpen skal gå rundt uten å vippe eller vandre. Du legger håndbaken mot leira, låser armene mot kroppen og lar skiva gjøre jobben mens du holder imot.

Dette er den delen de fleste bruker lengst tid på, og den som overrasker mest. Du merker på fingertuppene når leira slutter å dytte tilbake mot deg. Plutselig står den bare stille i hendene selv om skiva går rundt. Den følelsen er vanskelig å forklare på forhånd, men umulig å ta feil av når den kommer.

# Hvorfor det roer ned

Dreiing krever at du er ett sted av gangen. Leira reagerer umiddelbart på alt du gjør — for hardt trykk, og veggen blir skjev. For lite vann, og fingrene drar. Du kan ikke tenke på e-post samtidig.

Det er også repeterende på en god måte: skiva går i jevnt tempo, hendene gjør små justeringer, og du følger med. Mange beskriver det som at hodet blir stille uten at de har prøvd å gjøre det stille. Lyden i verkstedet hjelper også — lav summing, vann, og folk som ler litt av sine egne skjeve kopper.

# Du trenger ikke være kreativ på forhånd

En vanlig bekymring er at man ikke er «flink med hendene» eller ikke vet hva man vil lage. Det er helt greit. På et nybegynnerkurs handler det ikke om å realisere en visjon, men om å bli kjent med materialet. Formen kommer ofte av seg selv når du merker hva leira vil.

De fleste lager sylindre og små skåler de første gangene. Det er ikke et nederlag — det er grunnformene alt annet bygger på.

# Fra våt leire til ferdig kopp

Det du dreier er ikke ferdig når du reiser deg fra skiva. Tingen må tørke til lærhard tilstand, så trimmes foten. Deretter skal den tørke helt, brennes én gang, glaseres og brennes igjen. Det er derfor kurset går over to kvelder: første kveld dreier du, andre kveld trimmer og dekorerer du, og vi glaserer og brenner for deg etterpå.

Det går altså en del tid fra kurskvelden til du har koppen i hånda. Ovnen har sitt eget tempo, og det er ingenting å gjøre med det.

# Hva du sitter igjen med

De fleste går hjem litt overrasket over hvor fort tiden gikk. Noen kommer tilbake for å dreie mer, andre er fornøyd med å ha prøvd. Begge deler er helt fine utfall. Vil du fortsette, er medlemskap i verkstedet veien videre — da har du skiva tilgjengelig når det passer deg.

Datoer og pris for Nybegynner dreiekurs står på kurssiden.

# Ofte stilte spørsmål

## Er dreiing vanskelig?

Sentreringen tar tid å lære, og de første forsøkene kollapser gjerne. Det er normalt. Kurset går i små grupper, så du får hjelp når det stopper opp.

## Hvor mange ting får jeg med meg hjem?

De fleste får med seg to til fire ferdige kopper eller skåler, glasert og brent.

## Hva skjer med det jeg dreier?

Det tørker, trimmes andre kveld, og glaseres og brennes av oss etterpå. Du henter det etter noen uker, når vi sender beskjed.'
WHERE slug = 'dreiekurs-for-nybegynnere-slik-foles-det-a-sitte-ved-dreieskiva-forste-gang';

-- Omskrevet: Dreiing eller håndbygging? Slik velger du riktig start med leire
UPDATE articles SET
  fokus_ord = 'håndbygging keramikk',
  ingress   = 'De to vanligste måtene å forme leire på gir helt ulike opplevelser — og passer ulike folk. Her er forskjellene, slik at du vet hva du går til før du melder deg på ditt første kurs.',
  innhold   = '# Spørsmålet alle stiller

Folk som ringer oss stiller ofte det samme spørsmålet: skal jeg begynne på dreieskiva, eller skal jeg bygge for hånd? Det finnes ikke ett riktig svar, men det finnes en forskjell som er verdt å kjenne til før du bestemmer deg.

# Hva skjer på dreieskiva

Dreiing handler om å sentrere en klump leire på en skive som går rundt, og så åpne og trekke den opp med hendene mens den roterer. Det gir de jevne, symmetriske formene de fleste tenker på når de tenker keramikk: kopper, sylindre, skåler med rene vegger.

Dreiing er kroppslig og krever tålmodighet. Sentreringen — å få leira til å ligge helt rolig i midten — er den delen som tar lengst tid å lære, og den kan ikke jukses bort. Du bruker armene, skuldrene og pusten mer enn du tror. Til gjengjeld går det fort å lage noe når det først løsner, og mange beskriver det som nesten meditativt når rytmen sitter.

De første forsøkene kollapser gjerne. Det er helt normalt, og ikke et tegn på at du mangler talent. Nybegynner dreiekurs går over to kvelder nettopp fordi hendene trenger gjentakelse for å forstå hva de holder på med.

# Hva skjer når du håndbygger

Håndbygging er alt du kan gjøre uten skive: klype ut en form fra en kule, rulle pølser og bygge opp lag på lag, eller kjevle ut plater og sette dem sammen. Teknikkene er eldgamle og krever ingen maskin.

Håndbygging er langsommere, men mer tilgivende. Du kan stoppe, se på formen, trykke den litt inn, bygge videre. Du er ikke bundet av at alt må være rundt, og du bestemmer selv om kanten skal være ujevn. Skal du lage et stort fat, en firkantet skål eller noe med en form du har i hodet, er håndbygging som regel veien dit.

«Lag din egen bolle», «Store fat kurs» og «Vi lager fransk smørklokke» er håndbygging, og du kommer i gang første gang du setter deg ned.

# Hvordan velge

- Vil du lære en teknikk: velg dreiing.
- Vil du lage en bestemt ting: velg håndbygging.
- Vil du ha noe ferdig på én kveld: håndbygging.
- Vil du ha den fulle opplevelsen med skiva: dreiekurset over to kvelder.

Er du usikker, er det ingenting i veien for å prøve begge. Mange begynner med et håndbyggingskurs fordi terskelen er lav, og går videre til dreieskiva når nysgjerrigheten melder seg. Andre gjør det motsatt. Leire, verktøy og brenning er inkludert i alle kursene, så du trenger ikke skaffe noe på forhånd.

# Det ingen forteller deg om tidsbruken

Uansett teknikk: det du lager er ikke ferdig når du går hjem. Leira må tørke sakte, gjennom en første brenning, glaseres og brennes igjen. Det tar tid, og du henter tingene dine ved en senere anledning. Vi sier fra når de er klare.

Dette overrasker en del, særlig hvis man har tenkt på keramikk som en gave man kan gi bort samme uke. Regn med at det går noen uker fra du former noe til du kan drikke kaffe av det.

# Vil du vite mer

Har du spørsmål om hva som passer deg, ring oss på 94 13 46 01 eller send en e-post til post@lissom.no. Vi svarer ærlig, også hvis svaret er at du bør vente til noe annet passer bedre.

# Ofte stilte spørsmål

## Er håndbygging lettere enn dreiing?

Det er lettere å få et pent resultat første kveld. Dreiing krever mer trening, men gir en annen glede når det sitter.

## Kan jeg lage kopper uten dreieskive?

Ja. Med plateteknikk eller pølseteknikk kan du bygge kopper, skåler og fat for hånd — de blir bare ikke like symmetriske som dreide.

## Hvilket kurs bør jeg begynne med?

Vil du ha noe ferdig på én kveld: «Lag din egen bolle». Vil du lære å dreie: Nybegynner dreiekurs.'
WHERE slug = 'dreiing-eller-handbygging-slik-velger-du-riktig-start-med-leire';

-- Omskrevet: Høsten er en fin tid å begynne med keramikk
UPDATE articles SET
  fokus_ord = 'keramikkverksted medlemskap',
  ingress   = 'Når mørket kommer tidlig og regnet slår mot vinduet, er det godt å ha et sted å gå. Her er hva et verkstedmedlemskap faktisk innebærer, og hvordan du finner ut hvor mange timer du trenger.',
  innhold   = '# Noe med september

Kveldene blir lengre, terrassen står ubrukt, og mange kjenner på et behov for å gjøre noe med hendene. Keramikk er et godt svar på det behovet, ikke fordi det er spesielt lett, men fordi det tar tid. Leira lar seg ikke stresse. Den tørker i sitt eget tempo, og du må komme tilbake for å jobbe videre.

# Hva et medlemskap er

Et medlemskap gir deg tilgang til verkstedet et visst antall timer i måneden. Du jobber selvstendig med det du vil lage, i motsetning til et kurs der du følger en oppgave fra start til slutt. Du disponerer arbeidsplass, dreieskiver, verktøy, glasurer og ovn, har egen hylle til prosjektene dine, og kommer inn med egen dørkode når det passer deg.

De fleste som blir medlemmer har tatt et kurs først. Det er ikke et krav, men det hjelper å ha vært gjennom en hel runde med leire minst én gang: fra vått og formbart, gjennom tørking og glødebrenning, til glasur og siste brenning. Da vet du hva du går til når du står alene ved bordet.

# Hvor mange timer trenger du?

Dette er det vanligste spørsmålet vi får, og svaret henger sammen med hvor ofte du realistisk kommer til å møte opp.

- Prøv Lissom er én måned uten binding, med et fast antall timer — omtrent en kveldsøkt i uka. Fint hvis du vil teste om dette er noe for deg gjennom vinteren.
- Mini 15 gir 15 timer i måneden. Plass til litt lengre økter, eller en ekstra gang når du er midt i noe.
- Basis 30 gir 30 timer, og er det mest valgte. For deg som vil ha en fast rytme med flere økter i uka.
- Årsmedlemskapet gir flest timer i måneden til lavest timepris, med årsavtale.

Prisene står på medlemskapssiden. Et praktisk råd: velg heller for lite enn for mye i starten. Det er lettere å oppleve at man har lyst på mer, enn å sitte med timer man ikke fikk brukt.

# Hva tiden faktisk går med til

Nybegynnere undervurderer ofte hvor lang tid selve arbeidet tar, og overvurderer hvor mye de rekker på en økt. En bolle på dreieskiva kan gå raskt hvis alt sitter, men skal den dreies opp, tørkes til lærhard tilstand og deretter trimmes i bunnen, snakker vi minst to besøk med noen dager imellom.

Regn også med tid til opprydding. Leirstøv skal ikke bli liggende, og bordet skal være klart for neste person. Det tar et kvarter, og det er en del av arbeidet.

# Om vinterrytmen

Det fine med å starte nå er at høsten og vinteren gir deg en lang, sammenhengende periode. Du rekker å komme forbi det første stadiet der alt kollapser eller sprekker, og videre til det punktet hvor du begynner å kjenne igjen hvordan leira oppfører seg. Mange beskriver det som at hendene lærer før hodet gjør det.

Og så er det folkene. Et verksted en tirsdag kveld i november er ikke stille. Det snakkes over bordene, noen viser fram noe som gikk bra, noen ler av noe som gikk dårlig. For en del er det den delen som blir viktigst.

# Lurer du på hvilket nivå som passer?

Si fra hva slags uke du har, så kan vi tenke høyt sammen. Nordre Løkkevei 15 på Teie, eller post@lissom.no.

# Ofte stilte spørsmål

## Må jeg ha tatt kurs for å bli medlem?

Nei, men du bør kunne jobbe selvstendig med leire. Er du usikker, start med Prøv Lissom eller et kurs.

## Kan jeg bytte til flere timer senere?

Ja. Begynn gjerne lavt, og gå opp når du merker at du bruker timene.

## Når kan jeg komme?

Når det passer deg — medlemmer har egen dørkode og kommer inn døgnet rundt.'
WHERE slug = 'hosten-er-en-fin-tid-a-begynne-med-keramikk';

-- Gavekortets beløpsgrenser sto i teksten. Beløpet velger man selv.
UPDATE articles SET innhold = REPLACE(innhold, 'Fra kr 100 til kr 20 000, gyldig i tre år, kan brukes på alt — kurs, events, medlemskap og varer i butikken.', 'Du velger beløpet selv. Gavekortet er gyldig i tre år og kan brukes på alt — kurs, events, medlemskap og varer i butikken.')
WHERE slug = 'hva-koster-keramikkurs-i-tonsberg';
