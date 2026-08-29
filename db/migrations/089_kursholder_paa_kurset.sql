-- Kursholderen velges paa kurset. Og vaktene gaar ut.
--
-- ── Vaktene ───────────────────────────────────────────────────────────
--
-- Migrasjon 088 ga verkstedet en vakttabell. Eieren: «vakt er ikke noe vi
-- trenger naa» — «det er ingen andre vakter utenom kursholdere». Den som er i
-- verkstedet, er der fordi hun holder et kurs, og det staar alt paa oekta.
--
-- Tabellen er ny og tom, og ingenting annet peker paa den. Da fjernes den,
-- framfor aa bli staaende som noe ingen vet hva er.
--
-- ── Kursholderen paa kurset ───────────────────────────────────────────
--
-- Kursholderen har hoert til den enkelte datoen siden migrasjon 085, og nye
-- datoer har arvet verkstedets standard. Men det fantes ingen vei til aa si
-- «dette kurset holdes av Joakim» — man matte sette det paa hver eneste dato.
--
-- Feltet kom og gikk samme dag i 085/086, den gangen som en «vanligvis»-linje
-- under datoen. Det var stoy. Dette er noe annet: et valg paa kurset, der
-- eieren setter opp kurset.
--
-- Regelen har tre trinn, og de gaar én vei:
--   1. Er det valgt en kursholder paa datoen, er det hen. Alltid.
--   2. Ellers: den som staar paa kurset.
--   3. Ellers: verkstedets standard — Monica.
-- Tomt paa kurset betyr altsaa ikke «ingen», det betyr «Monica».

DROP TABLE IF EXISTS vakter;

ALTER TABLE courses
  ADD COLUMN IF NOT EXISTS kursholder_id BIGINT UNSIGNED NULL
  COMMENT 'Hvem som holder dette kurset. NULL = verkstedets standard.'
  AFTER instruktor;

CREATE INDEX IF NOT EXISTS ix_kurs_kursholder ON courses (kursholder_id);

-- «ON DELETE SET NULL»: slutter noen, faller kurset tilbake paa standarden.
SET @finnes := (
  SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'courses'
     AND constraint_name = 'fk_kurs_holder'
);
SET @sql := IF(@finnes = 0,
  'ALTER TABLE courses
     ADD CONSTRAINT fk_kurs_holder FOREIGN KEY (kursholder_id)
     REFERENCES kursholdere (id) ON DELETE SET NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Det som alt er skrevet, kobles opp naar navnet stemmer.
--
-- «courses.instruktor» er fritekst og brukes til navnet paa kursbeviset. Der
-- den er fylt ut med navnet paa en som staar i registeret, er det den som
-- holder kurset. Feltet blir staaende — det er kursbeviset, og det skal ikke
-- endres av dette.
UPDATE courses c
   JOIN kursholdere k ON LOWER(TRIM(k.navn)) = LOWER(TRIM(c.instruktor))
    SET c.kursholder_id = k.id
  WHERE c.kursholder_id IS NULL
    AND c.instruktor IS NOT NULL AND TRIM(c.instruktor) <> '';
