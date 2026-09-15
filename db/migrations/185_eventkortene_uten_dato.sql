-- Kortteksten paa kortene: foerst Date Night og Paint on Pots, saa alle.
--
-- Begge settes opp naar noen sporr, og ligger uten datoer. Da sto feltet
-- der datoene ellers staar tomt, og kortet sa ingenting om hva det er.
-- Eieren, 15. september 2026: «events viser ingen info i kortene ...
-- dersom det ikke er planlagt noe vil jeg at det skal staa ...» — med
-- teksten han ville ha. Nettsida viser kort_beskrivelse paa kortet naar
-- kurset ikke har datoer; feltet redigeres i admin under kurset.
--
-- Bare rader der feltet staar tomt roeres — har verkstedet skrevet noe
-- eget, gjelder det.

UPDATE courses SET kort_beskrivelse = 'Lyst å gjøre noe romantisk med kjæresten? Dette er en koselig og morsom opplevelse for begge.'
WHERE slug = 'date-night' AND (kort_beskrivelse IS NULL OR kort_beskrivelse = '');

UPDATE courses SET kort_beskrivelse = 'Paint on Pots passer enten du kommer alene eller er en større gruppe.'
WHERE slug = 'paint-on-pots' AND (kort_beskrivelse IS NULL OR kort_beskrivelse = '');

-- Sip & Clay fikk sin 15. september ogsaa («jeg vil ha lignende tekst paa
-- sip & clay»). Har kurset datoer, staar datoene paa kortet; teksten staar
-- der naar datoene tar slutt — og paa kurssida som reserve.
UPDATE courses SET kort_beskrivelse = 'Er dere en gjeng — utdrikningslag, venninnekveld eller et event på jobben? Da kan Sip & Clay være midt i blinken.'
WHERE slug = 'sip-and-clay' AND (kort_beskrivelse IS NULL OR kort_beskrivelse = '');

-- Kurskortene ogsaa (eieren 15. september: «litt tekst paa alle kortene»).
-- Tekstene er godkjent av eieren; dreiekursets er hans egne ord.
UPDATE courses SET kort_beskrivelse = 'Har du lyst å prøve å dreie? Da er dette kurset perfekt for deg.'
WHERE slug = 'dreiekurs' AND (kort_beskrivelse IS NULL OR kort_beskrivelse = '');
UPDATE courses SET kort_beskrivelse = 'Et stort fat med ditt eget uttrykk — til bordet hjemme, eller som gave til noen som fortjener det.'
WHERE slug = 'store-fat-kurs' AND (kort_beskrivelse IS NULL OR kort_beskrivelse = '');
UPDATE courses SET kort_beskrivelse = 'En kveld med leire mellom hendene. Du lager to boller du faktisk kommer til å bruke.'
WHERE slug = 'lag-din-egen-bolle' AND (kort_beskrivelse IS NULL OR kort_beskrivelse = '');
UPDATE courses SET kort_beskrivelse = 'Barn og voksen lager et Tre på rad-spill sammen. Koselig, litt sølete, og dere har et spill etterpå.'
WHERE slug = 'alle-barn-se-her-tre-pa-rad' AND (kort_beskrivelse IS NULL OR kort_beskrivelse = '');
UPDATE courses SET kort_beskrivelse = 'Smøret holder seg ferskt uten kjøleskap. Du lager klokka selv, i to deler, på én kveld.'
WHERE slug = 'vi-lager-fransk-smorklokke' AND (kort_beskrivelse IS NULL OR kort_beskrivelse = '');
UPDATE courses SET kort_beskrivelse = 'Du bestemmer hva du vil lage, vi hjelper deg underveis. Alt av leire, glasur og brenning er med.'
WHERE slug = 'workshop' AND (kort_beskrivelse IS NULL OR kort_beskrivelse = '');
