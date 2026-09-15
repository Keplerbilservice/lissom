-- Korttekstene (186) traff ingen rader i produksjon.
--
-- 186 roerte bare tomme felt. Men admin lagrer feltet slik det vises — og
-- det vises med malens tekst naar det er tomt. Saa etter én lagring av
-- kurset staar malens tekst i basen, og 186 saa et «utfylt» felt. Eieren,
-- 15. september, etter aa ha kjoert oppdateringene: kortene sto uendret.
--
-- Her byttes ogsaa felt som inneholder en av malenes tekster (app/lib/
-- kursmal.php) — de er ikke skrevet av verkstedet. Alt annet staar.

UPDATE courses SET kort_beskrivelse = CASE slug
    WHEN 'date-night'                  THEN 'Lyst å gjøre noe romantisk med kjæresten? Dette er en koselig og morsom opplevelse for begge.'
    WHEN 'paint-on-pots'               THEN 'Paint on Pots passer enten du kommer alene eller er en større gruppe.'
    WHEN 'sip-and-clay'                THEN 'Er dere en gjeng — utdrikningslag, venninnekveld eller et event på jobben? Da kan Sip & Clay være midt i blinken.'
    WHEN 'dreiekurs'                   THEN 'Har du lyst å prøve å dreie? Da er dette kurset perfekt for deg.'
    WHEN 'store-fat-kurs'              THEN 'Et stort fat med ditt eget uttrykk — til bordet hjemme, eller som gave til noen som fortjener det.'
    WHEN 'lag-din-egen-bolle'          THEN 'En kveld med leire mellom hendene. Du lager to boller du faktisk kommer til å bruke.'
    WHEN 'alle-barn-se-her-tre-pa-rad' THEN 'Barn og voksen lager et Tre på rad-spill sammen. Koselig, litt sølete, og dere har et spill etterpå.'
    WHEN 'vi-lager-fransk-smorklokke'  THEN 'Smøret holder seg ferskt uten kjøleskap. Du lager klokka selv, i to deler, på én kveld.'
    WHEN 'workshop'                    THEN 'Du bestemmer hva du vil lage, vi hjelper deg underveis. Alt av leire, glasur og brenning er med.'
    ELSE kort_beskrivelse END
WHERE slug IN ('date-night', 'paint-on-pots', 'sip-and-clay', 'dreiekurs', 'store-fat-kurs', 'lag-din-egen-bolle', 'alle-barn-se-her-tre-pa-rad', 'vi-lager-fransk-smorklokke', 'workshop')
  AND (kort_beskrivelse IS NULL OR TRIM(kort_beskrivelse) IN (
    '',
    'Prøv den gode følelsen av å forme leire på dreieskiven. Du lærer det grunnleggende og får hjelp hele veien, helt uten krav om erfaring.',
    'Lag noe fint og personlig i keramikk. Et hyggelig kurs for deg som vil prøve plateteknikk og skape noe du faktisk kan bruke hjemme.',
    'En hyggelig kveld med leire — for venner, par, kolleger eller familien. Ingen trenger erfaring.',
    'Bestill en hyggelig stund med keramikkmaling hos oss. Velg blant et stort utvalg kopper, skåler, fat og figurer når du kommer, og skap noe helt unikt.',
    'Et hyggelig kurs med leire mellom hendene. Ingen erfaring nødvendig.',
    'Lag to fine og personlige boller i keramikk. Et hyggelig kurs for deg som vil prøve plateteknikk og skape noe du faktisk kan bruke hjemme.',
    'Lag et stort og personlig keramikkfat med ditt eget uttrykk. Vi bruker plateteknikk og dekor for å skape et fat du kan glede deg over hjemme.'
  ));
