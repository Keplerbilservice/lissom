-- Date Night koster 2990 kroner.
--
-- Sto med 1490 paa kortet. Eieren, 1. september: «Datenight, maa koste kr
-- 2990».
--
-- Prisen kan endres i admin etterpa, under Kurs og deltakere.

UPDATE courses
   SET pris_ore = 299000
 WHERE slug = 'date-night'
    OR tittel = 'Date Night';
