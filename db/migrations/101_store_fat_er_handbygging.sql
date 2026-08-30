-- «Store fat kurs» er håndbygging.
--
-- Kurset sto med temaet «Kurs» i basen — altså ingen ekte kategori. Kortet i
-- kursbasen falt tilbake på kurstypen og gjettet «Dreiing», og det er feil:
-- fatet formes av en leireplate med hendene. Kursmal har alltid visst det —
-- se «Store fat kurs» der, som arver plateteknikk-innledningen.
--
-- Eieren, 30. august: «store fat er håndbygging».
--
-- Med temaet satt trenger ingen å gjette. Kurset står under Håndbygging i
-- lista ute, i kursbasen og i nedtrekket for nye datoer, og det blir stående
-- der uansett hva kurstypen sier.
--
-- Bare når temaet er tomt eller det generiske «Kurs». Har noen alt gitt det
-- en kategori, er det den som gjelder — samme regel som migrasjon 099.

UPDATE courses
   SET tema = 'Håndbygging'
 WHERE tittel = 'Store fat kurs'
   AND (tema IS NULL OR tema = '' OR tema = 'Kurs');
