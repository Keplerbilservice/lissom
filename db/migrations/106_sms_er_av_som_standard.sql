-- SMS-paaminnelse skal aldri staa paa av seg selv.
--
-- Eieren, 31. august: «Jeg skal ikke ha sms paaminnelse som default noe
-- jaevla sted! Dette har jeg sagt saa mange ganger naa». Han har rett, og
-- grunnen til at det kom tilbake er at det sto paa fire steder, ikke ett:
--
--   1. Kolonnen her, med DEFAULT 1 fra 001_init. Enhver INSERT som ikke
--      nevner kolonnen fikk SMS paa.
--   2. api/admin/kurs.php skrev «alt som ikke er 'nei' blir 1» — saa et
--      lagringskall uten feltet slo den paa.
--   3. og 4. To steder i fronten leste «!== false», som gjor undefined til
--      paa.
--
-- Tre av dem er rettet i koden. Denne migrasjonen tar kolonnen, og setter
-- alle kursene som ligger inne til av. Et kurs som skal ha SMS skrus paa
-- for haand — det er valget, ikke utgangspunktet.
--
-- Retningen er trygg: ingen faar en SMS de ikke skulle hatt. Motsatt vei er
-- det som koster.

ALTER TABLE courses
  MODIFY COLUMN sms_paaminnelse TINYINT(1) NOT NULL DEFAULT 0;

UPDATE courses SET sms_paaminnelse = 0 WHERE sms_paaminnelse <> 0;
