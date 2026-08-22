-- Tekstrettelser etter gjennomlesing i verkstedet.
--
-- To ting gikk igjen: kurs sto oppfort som om de krevde erfaring, og
-- beskrivelser som horer til dreiekurs (sentrere, dreie, trimme) var brukt
-- paa plateteknikk. Kursene passer alle, og paa plateteknikk bygger man sin
-- egen gjenstand.

-- «Store fat kurs» sa «passer for deg som har provd leire for». Kurset
-- passer alle, og teksten beskriver na selve teknikken i stedet.
UPDATE courses
   SET beskrivelse = 'Plateteknikk for store former. Du bygger din egen gjenstand med tradisjonell plateteknikk — vi kjevler ut leira, former fatet over form og jobber med kanter og dekor. Passer for alle, ingen forkunnskaper nødvendig.'
 WHERE slug = 'store-fat-kurs';

-- Kurs boller: samme presisering — det er plateteknikk, ikke dreiing.
UPDATE courses
   SET beskrivelse = 'En kveld med plateteknikk. Du bygger dine egne boller med tradisjonell plateteknikk: kjevler ut leira, former dem i flere størrelser og lærer å få jevne kanter og rene sammenføyninger. Du velger glasur til slutt.'
 WHERE slug = 'kurs-boller';
