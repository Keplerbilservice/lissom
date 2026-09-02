-- Teksten eieren vil ha paa medlemskapssidene.
--
-- Eieren, 2. september, én melding per plan: «dette vil jeg skal staa paa
-- proev lissom», «dette vil jeg skal staa paa basis 30», «dette vil jeg skal
-- staa paa aarsmedlemskapet».
--
-- Avsnittene gaar i «langtekst» og staar paa sida man kommer til naar man
-- klikker seg inn paa medlemskapet. «Dette faar du» er punktlista som alt
-- fantes, og som ogsaa staar paa kortet. «Viktig aa vite» er den nye lista.
--
-- Kortene ute er urort: pris, merke og undertekst staar som for.
--
-- Rettet i teksten: «Oppsigeletid» → «Oppsigelsestid» paa Basis 30. Resten
-- staar ord for ord som eieren skrev den.

UPDATE membership_plans
   SET langtekst = 'Prøv Lissom – prøv keramikkverkstedet i ditt eget tempo

Er du nysgjerrig på keramikk og lurer på om verkstedmedlemskap er noe for deg? Da er Prøv Lissom den perfekte starten.

Dette medlemskapet gir deg muligheten til å bruke verkstedet vårt i en begrenset periode, slik at du kan kjenne på arbeidsformen, miljøet og gleden ved å skape med leire før du eventuelt velger et fast medlemskap.

Du får tilgang til verkstedet i totalt 10 timer, som kan brukes fritt innenfor en periode på 30 dager. Tiden kan fordeles slik det passer deg, enten du ønsker noen korte økter eller lengre arbeidsøkter.

Prøv Lissom passer like godt for deg som nettopp har fullført et kurs hos oss, som for deg som har erfaring med keramikk fra tidligere. For å kunne benytte verkstedet på egen hånd må du enten ha deltatt på kurs hos oss eller ha tilstrekkelig erfaring fra før.

Leire, glasur og brenning er inkludert i medlemskapet.

Dette er ikke et abonnement, og medlemskapet avsluttes automatisk når perioden er over. Du trenger derfor ikke å si opp noe eller være bekymret for videre trekk. Ordningen er ment som en introduksjon til verkstedet og kan kun benyttes én gang per person.

Prøv Lissom er for deg som ønsker å utforske keramikk i ro og mak, bli kjent med verkstedet og finne ut om dette er en hobby du vil ta videre.',
       punkter   = '10 verkstedtimer
Brukes fritt innen 30 dager
Tilgang til et inspirerende keramikkverksted
Ingen bindingstid eller automatisk fornyelse
Perfekt for deg som vil prøve før du bestemmer deg
Leire og glasur er inkludert',
       viktig    = 'Krever kurs hos oss eller tidligere erfaring
Kan kun benyttes én gang per person
Går ikke automatisk over til abonnement'
 WHERE navn = 'Prøv Lissom';

UPDATE membership_plans
   SET langtekst = 'Basis 30 – frihet til å skape, når det passer deg

Basis 30 er vårt mest populære medlemskap og passer perfekt for deg som ønsker å være en aktiv del av verkstedet. Her får du god tid til å utvikle prosjektene dine, prøve nye teknikker og jobbe i ditt eget tempo.

Med dette medlemskapet får du 30 verkstedtimer hver måned, som du kan bruke når det passer deg. Du har tilgang til verkstedet døgnet rundt med personlig dørkode, slik at du kan komme innom både tidlig om morgenen, på kveldstid eller i helger.

For å gjøre det enkelt å ha flere prosjekter gående samtidig får du også din egen hylleplass i verkstedet. Her kan du oppbevare arbeider som tørker, venter på glasering eller rett og slett trenger litt mer tid før de er ferdige.

Basis 30 passer både for deg som ønsker en fast kreativ hobby og for deg som vil ha muligheten til å komme og gå fritt gjennom måneden. Mange av våre medlemmer bruker dette medlemskapet som sitt faste ukentlige pusterom, hvor de kan koble av, være kreative og bli en del av det hyggelige miljøet på Lissom.

For å kunne arbeide selvstendig i verkstedet må du enten ha gjennomført kurs hos oss eller ha tilsvarende erfaring fra før.

Basis 30 er medlemskapet for deg som ønsker god tilgang til verkstedet, fast plass i miljøet og friheten til å skape på dine egne premisser.',
       punkter   = '30 verkstedtimer hver måned
Tilgang til verkstedet 24/7 med dørkode
Egen hylleplass til prosjekter og utstyr
Frihet til å komme og gå når det passer deg
Mulighet til å jobbe med egne prosjekter over tid',
       viktig    = 'Krever kurs hos oss eller tidligere erfaring
2 måneders bindingstid ved oppstart
Oppsigelsestid 1 måned
Leire, glassur og brenning kjøpes i tillegg'
 WHERE navn = 'Basis 30';

UPDATE membership_plans
   SET langtekst = 'Årsmedlemskap – mest verkstedtid og flere muligheter

For deg som vet at keramikk er mer enn bare en hobby, er Årsmedlemskap det beste valget. Dette medlemskapet gir deg mest verkstedtid til lavest månedspris, og er laget for deg som ønsker å bruke verkstedet aktivt gjennom hele året.

Med 35 verkstedtimer hver måned får du god tid til å utvikle ferdigheter, arbeide med større prosjekter og fordype deg i det kreative arbeidet. Du har tilgang til verkstedet døgnet rundt med personlig dørkode, slik at du kan jobbe når inspirasjonen kommer, enten det er tidlig om morgenen, sent på kvelden eller i helgene.

Som medlem får du din egen hylleplass i verkstedet hvor du kan oppbevare arbeider, verktøy og prosjekter som er under utvikling. Dette gir deg muligheten til å jobbe over tid uten å måtte ta med deg alt hjem mellom hver økt.

En ekstra fordel med Årsmedlemskap er muligheten til å selge egne arbeider gjennom lissom.no. Dersom du lager produkter du ønsker å tilby andre, kan du få vist frem arbeidene dine gjennom vår nettbutikk og bli en del av det kreative fellesskapet rundt Lissom.

Dette medlemskapet passer for deg som ønsker å være en fast del av verkstedmiljøet og som ser for deg å bruke keramikk aktivt gjennom året. For å kunne arbeide selvstendig må du enten ha gjennomført kurs hos oss eller ha tilsvarende erfaring fra tidligere.

Årsmedlemskapet har 12 måneders bindingstid og gir deg den beste timeprisen av alle våre medlemskap.',
       punkter   = '35 verkstedtimer hver måned
Tilgang til verkstedet 24/7 med personlig dørkode
Egen hylleplass i verkstedet
Beste timepris av våre medlemskap
Mulighet til å selge egne arbeider gjennom lissom.no
Fast plass i et kreativt og inkluderende verkstedmiljø',
       viktig    = 'Krever kurs hos oss eller tidligere erfaring
Leire, glassur og brenning kjøpes i tillegg
Årsavtale
Bindingstid på 12 måneder'
 WHERE navn = 'Årsmedlemskap';
