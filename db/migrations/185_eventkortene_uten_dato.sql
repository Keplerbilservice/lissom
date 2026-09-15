-- Kortteksten paa Date Night og Paint on Pots.
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
