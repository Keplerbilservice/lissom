-- Adminpanelet skal kreve brukernavn og passord.
--
-- Vipps beviser hvem du er, men telefonen ligger gjerne ulaast paa et bord.
-- Et adminpanel med kundedata, betalinger og medlemsopplysninger skal ikke
-- staa aapent bak en app som allerede er logget inn.
--
-- Sesjonen maa derfor huske hvilken vei man kom inn. Eldre sesjoner staar
-- som «vipps»; de mister adminrettighetene ved neste sidevisning, og maa
-- logge inn paa nytt med passord. Det er meningen.
--
-- Kundene logger inn med Vipps som for. Dette gjelder bare adminpanelet.
--
-- En konto uten passord slipper fortsatt inn med Vipps. Uten det ville den
-- forste administratoren aldri kommet inn for aa sette et passord, og en
-- eier som mister passordet vaert laast ute for godt.

ALTER TABLE sessions
  ADD COLUMN maate ENUM('vipps', 'passord') NOT NULL DEFAULT 'vipps' AFTER member_id;
