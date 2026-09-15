-- Korttekstene (186 og 187) traff fortsatt ingen rader i produksjon.
--
-- 186 roerte bare tomme felt; 187 ogsaa felt som var lik malens tekst,
-- ord for ord. Etter oppdateringene sto kortene likevel uendret. Her
-- kjennes malens tekst paa begynnelsen — det taaler et annet strek-tegn
-- eller et ekstra mellomrom. Eierens egne tekster roeres ikke.

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
  AND (kort_beskrivelse IS NULL OR TRIM(kort_beskrivelse) = ''
    -- Malenes tekster, kjent paa begynnelsen: 187 sammenlignet hele
    -- teksten, og et annet tankestrek-tegn eller et dobbelt mellomrom var
    -- nok til at ingenting ble byttet.
    OR kort_beskrivelse LIKE 'Prøv den gode følelsen av å forme leire%'
    OR kort_beskrivelse LIKE 'Lag noe fint og personlig i keramikk%'
    OR kort_beskrivelse LIKE 'En hyggelig kveld med leire%'
    OR kort_beskrivelse LIKE 'Bestill en hyggelig stund med keramikkmaling%'
    OR kort_beskrivelse LIKE 'Et hyggelig kurs med leire mellom hendene%'
    OR kort_beskrivelse LIKE 'Lag to fine og personlige boller%'
    OR kort_beskrivelse LIKE 'Lag et stort og personlig keramikkfat%');
