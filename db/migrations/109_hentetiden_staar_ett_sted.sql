-- Hentetiden sto to steder, med to forskjellige tall.
--
-- «Dette faar du med hjem» endte paa en fast setning om henting — den var
-- skrevet for feltet «Naar er den ferdig» fantes. Da det kom, skrev eieren
-- sin egen tekst der, og paa kurssida sto de to rett under hverandre:
--
--   Dette faar du med hjem   ... Den er normalt klar til henting etter 2–3 uker.
--   Naar er den ferdig       Klart til henting etter 2-4 uker. Vi gir beskjed.
--
-- To og tre uker, og fire uker, paa samme side. Eieren, 31. august: «2-4 uker
-- er riktig», og han ville ha med hvor man kan se det selv.
--
-- Malen er rettet: hentesetningen er ute av «Dette faar du med hjem», og
-- «Naar er den ferdig» har den som standard, med adressa til Ferdig brent og
-- Min side.
--
-- Denne toemmer den innlimte teksten paa de fire kursene som har den, saa de
-- foelger malen. Bare den noeyaktige teksten — har noen skrevet noe eget, blir
-- det staaende. Feltet er tomt etterpaa, og tomt betyr «bruk malen»; ingen
-- tekst gaar tapt som ikke sto ordrett slik.

UPDATE courses
   SET ferdig_tid = NULL
 WHERE TRIM(ferdig_tid) = 'Klart til henting etter 2-4 uker. Vi gir beskjed.';
