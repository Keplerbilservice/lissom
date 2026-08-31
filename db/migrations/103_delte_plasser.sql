-- Ressursene i verkstedet, delt av alle.
--
-- Eieren, 30. august: «må ta plasser fra de samme ressursene. Altså om det er
-- kurs eller andre medlemmer, vi må tenke at alle disse har tilgang til de
-- samme 8 dreieskivene», og «1 dreieskive = 1 ressurs = 1 plass, 1 kursplass
-- = 1 ressurs = 1 plass».
--
-- Slik det var: hver dato hadde sitt eget plasstall, og ledige plasser ble
-- regnet bare mot den ene datoen. Gikk det et dreiekurs 17–20 med åtte
-- påmeldte, viste drop-in kl. 18 fortsatt åtte ledige — og verkstedet kunne
-- selge seksten plasser på åtte skiver.
--
-- Slik det er: hvert kurs peker på en ressurs. Alt som skjer samtidig og
-- peker på den samme, deler taket.
--
-- Ressursene står i en tabell og ikke i koden. Eieren: «må kunne endre,
-- slette og legge til for å møte endringer i verkstedet» — kommer det tre
-- skiver til, eller et malebord, skal ikke nettsiden legges ut på nytt.

CREATE TABLE IF NOT EXISTS ressurser (
  id       INT AUTO_INCREMENT PRIMARY KEY,
  navn     VARCHAR(64) NOT NULL,
  antall   INT NOT NULL,
  merknad  VARCHAR(255) NULL,
  aktiv    TINYINT(1) NOT NULL DEFAULT 1,
  opprettet DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY navn (navn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO ressurser (navn, antall, merknad) VALUES
  ('Dreieskive', 8,  'Dreiekursene og Date Night deler disse.'),
  ('Bordplass', 12, 'Håndbygging, maling og events.')
ON DUPLICATE KEY UPDATE navn = navn;

-- Kolonna fra første utkast av denne migrasjonen. Sto den igjen, ville to
-- steder sagt hva et kurs bruker.
ALTER TABLE courses DROP COLUMN IF EXISTS ressurs;

ALTER TABLE courses
  ADD COLUMN IF NOT EXISTS ressurs_id INT NULL
      COMMENT 'Hva kurset legger beslag på. NULL = ingen delt grense.';

-- Dreiekursene. Kategorien er fasit; «Dreiing» er temaet de står med etter
-- migrasjon 099. Date Night er en kveld ved skiva for to — «dere får hver
-- deres skive». Drop-in er nettopp den som skal miste plasser når skivene er
-- opptatt.
UPDATE courses c
   JOIN ressurser r ON r.navn = 'Dreieskive'
    SET c.ressurs_id = r.id
  WHERE c.tema = 'Dreiing'
     OR c.tittel LIKE '%dreie%'
     OR c.tittel = 'Date Night'
     OR c.type = 'dropin'
     OR c.tema = 'Drop-in';

-- Resten sitter ved bordene: håndbygging, Sip & Clay, Paint on Pots.
UPDATE courses c
   JOIN ressurser r ON r.navn = 'Bordplass'
    SET c.ressurs_id = r.id
  WHERE c.ressurs_id IS NULL;
