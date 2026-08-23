-- Flytter innholdsnoklene som byttet plass.
--
-- Noklene i content_blocks er «Side/blokknummer/Felt». Blokknummeret er
-- posisjonen i lista over seksjoner. Da lista ble rettet opp mot sida slik
-- den faktisk ser ut, flyttet noen seksjoner seg — og da ville verdiene
-- eieren har skrevet blitt liggende igjen paa et nummer ingen leser.
--
-- Ingenting slettes. Finnes den nye noekkelen alt, staar den nye igjen.

-- «Praktisk info» paa butikksida: blokk 2 → 4.
UPDATE IGNORE content_blocks SET nokkel = REPLACE(nokkel, 'Butikk/2/', 'Butikk/4/')
 WHERE nokkel LIKE 'Butikk/2/Punkt %';

-- «Kommende datoer» paa forsiden: blokk 1 → 2. Blokk 1 er naa «Velg din
-- inngang», som ikke fantes i lista for.
UPDATE IGNORE content_blocks SET nokkel = REPLACE(nokkel, 'Forside/1/', 'Forside/2/')
 WHERE nokkel IN ('Forside/1/Kicker', 'Forside/1/Overskrift');

-- «100% haandlaget med kjaerlighet» sto under Forside. Teksten staar i
-- bunnfeltet, og feltet er flyttet dit.
UPDATE IGNORE content_blocks SET nokkel = 'Footer/3/Merkelinje'
 WHERE nokkel = 'Forside/4/Overskrift';

-- Noklene som ikke lenger har en seksjon paa sida blir staaende. De gjor
-- ingen skade, og skulle noe vaere lagret der, er det ikke borte.
