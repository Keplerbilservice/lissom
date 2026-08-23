-- Proveperioden gir ti timer, ikke aatte.
--
-- Tallet sto som 8 i planen og som 30 i lista nettsida tegnet, mens teksten
-- paa medlemskapssida lovte noe tredje. Tre steder, tre tall, og ingen av
-- dem var det verkstedet faktisk gir.
--
-- Ti er det riktige. Timeoversikten paa Min side leser dette tallet, saa den
-- viste feil grense for alle som har vaert paa proveperioden.

UPDATE membership_plans
   SET timer = 10
 WHERE navn = 'Prøv Lissom';

-- Medlemmer som staar med proveperioden og har faatt timetallet kopiert ned
-- paa seg selv, skal ogsaa opp. NULL betyr «foelg planen» og roeres ikke.
UPDATE members
   SET timer_per_mnd = 10
 WHERE medlemskap_type = 'Prøv Lissom'
   AND timer_per_mnd IS NOT NULL
   AND timer_per_mnd = 8;
