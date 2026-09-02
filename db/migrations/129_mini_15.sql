-- Nytt medlemskap: «Mini 15».
--
-- Eieren, 2. september: «legg til et nytt medlemskap, her er teksten, kort paa
-- forsiden Mini 15 / 15 timer, passer for deg som vil starte med keramikk, men
-- ikke har saa mye tid ... Pris kr 1790».
--
-- Ligger mellom «Proev Lissom» og «Basis 30» i pris og timer, og settes derfor
-- inn der. De andre flyttes ett hakk ned foerst, saa sorteringa ikke faar to
-- planer paa samme plass.
--
-- Bindingstid og oppsigelse er de samme som Basis 30: to maaneder og én.
-- Fast trekk kreves ikke — medlemmet kan velge «Jeg ordner selv», som paa de
-- andre loepende planene.
--
-- Bildet staar tomt med vilje. Da faller nettsida tilbake paa et av bildene i
-- biblioteket, og eieren velger sitt eget under Medlemskap → Bilde.
--
-- Rettet i teksten: «Basis 15 er et godt valg» → «Mini 15 er et godt valg» og
-- «Oppsigeletid» → «Oppsigelsestid». Resten staar ord for ord.

UPDATE membership_plans SET sortering = sortering + 1 WHERE sortering >= 2;

INSERT INTO membership_plans
  (navn, merke, undertekst, beskrivelse, langtekst, punkter, viktig, passer_for,
   bilde, fremhevet, pris_ore, intervall, timer, binding_mnd, oppsigelse_mnd,
   engangs, krever_fast_trekk, sortering, aktiv)
VALUES
  ('Mini 15', 'Medlemskap', '15 timer i måneden',
   'Mini 15 er medlemskapet for deg som ønsker regelmessig tilgang til verkstedet, god fleksibilitet og tid til å utvikle keramikkgleden i ditt eget tempo.',
   'Mini 15 – fleksibelt medlemskap for deg som vil skape jevnlig

Mini 15 passer perfekt for deg som ønsker fast tilgang til verkstedet, men ikke har behov for like mange timer som våre mest aktive medlemmer. Dette er et fleksibelt medlemskap for deg som vil ha keramikk som en fast del av hverdagen, uten å føle at du må være på verkstedet hele tiden.

Med 15 verkstedtimer per måned får du god tid til egne prosjekter, øving og kreativ utfoldelse. Du kan bruke timene når det passer deg, og med tilgang til verkstedet døgnet rundt har du frihet til å arbeide både på dagtid, kveldstid og i helger.

Som medlem får du også din egen hylleplass i verkstedet, slik at du enkelt kan oppbevare arbeider, verktøy og prosjekter mellom besøkene.

Mini 15 er et godt valg for deg som ønsker å bygge erfaring over tid, videreutvikle det du har lært på kurs eller ha et fast kreativt fristed i en travel hverdag.

For å kunne arbeide selvstendig i verkstedet må du enten ha gjennomført kurs hos oss eller ha tilsvarende erfaring fra tidligere.

Mini 15 er medlemskapet for deg som ønsker regelmessig tilgang til verkstedet, god fleksibilitet og tid til å utvikle keramikkgleden i ditt eget tempo.',
   '15 verkstedtimer per måned
Egen hylle i verkstedet
Tilgang 24/7 med personlig dørkode
Mulighet til å jobbe med egne prosjekter i eget tempo
Del av et kreativt og inkluderende verkstedmiljø',
   'Krever kurs hos oss eller tidligere erfaring
Leire, glassur og brenning kjøpes i tillegg
2 måneders bindingstid ved oppstart
Oppsigelsestid 1 måned',
   'vil starte med keramikk, men ikke har så mye tid',
   NULL, 0, 179000, 'maaned', 15, 2, 1, 0, 0, 2, 1);
