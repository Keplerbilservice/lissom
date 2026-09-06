-- Aarskalenderen: aaret maaned for maaned.
--
-- Eieren, 6. september: «det er ogsaa oenske om en aarskalender, som viser
-- kun maaned for maaned, og et felt der jeg kan skrive inn hva som skjer
-- denne maaneden, vil ogsaa kunne redigere og bytte og slette, lag punktvis
-- visning inne paa maanedene. lag en egen meny som heter aarskalender, denne
-- kan ogsaa ligge paa kalender siden ledige felt oeverst paa siden.»
--
-- Han saa skissen og svarte «GO — bygg det».
--
-- Dette er ikke kurs, og det er ikke datoer. Det er notatene hans om aaret:
-- «Julekurs legges ut», «Bestille leire foer hoesten», «Stengt fra 23.».
-- Paa spoersmaal om kursene skulle dukke opp av seg selv i maanedene, valgte
-- han «Nei, bare det jeg skriver selv» — ellers fylles den av seg selv, og da
-- drukner det han ville huske.
--
-- Intern. Ingenting av dette vises paa nettsiden.

CREATE TABLE IF NOT EXISTS arskalender (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  aar       SMALLINT NOT NULL,
  mnd       TINYINT NOT NULL,
  tekst     VARCHAR(300) NOT NULL,
  -- Rekkefolgen innenfor maaneden. Punktene staar slik de ble skrevet, og
  -- et punkt som flyttes til en annen maaned legger seg bakerst der.
  sortering INT NOT NULL DEFAULT 0,
  opprettet DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  endret    DATETIME NULL DEFAULT NULL,
  KEY aar_mnd (aar, mnd, sortering)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
