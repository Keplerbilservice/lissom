-- Én standard kursholder for verkstedet.
--
-- Migrasjon 085 ga hvert kurs sin egen «vanlige kursholder», og datoraden sa
-- «Kurset holdes vanligvis av Monica.» Eieren: «jeg vil bare ha mulighet aa
-- holde et kurs» — og «Monica er default».
--
-- Altsaa ikke én standard per kurs, men én for verkstedet. Det er ogsaa slik
-- det faktisk er: Monica holder kursene, og av og til gjor noen andre det.
--
-- Da blir feltet ett valg, uten en forklarende linje under.

ALTER TABLE kursholdere
  ADD COLUMN IF NOT EXISTS standard TINYINT(1) NOT NULL DEFAULT 0
  COMMENT 'Foreslaas paa nye datoer. Bare én om gangen.'
  AFTER aktiv;

-- Hvem det er.
--
-- «courses.instruktor» er navnet paa kursbeviset, og kolonnen sier selv at
-- tomt betyr Monica. Staar det et navn der som ogsaa finnes i registeret, er
-- det den som holder kursene — og det er den som skal foreslaas.
UPDATE kursholdere
   SET standard = 1
 WHERE aktiv = 1
   AND id = (
     SELECT id FROM (
       SELECT k.id
         FROM kursholdere k
         JOIN courses c ON LOWER(TRIM(c.instruktor)) = LOWER(TRIM(k.navn))
        WHERE k.aktiv = 1
     GROUP BY k.id
     ORDER BY COUNT(*) DESC, k.id
        LIMIT 1
     ) x
   );

-- Finnes det ingen slik kobling, men bare én aktiv kursholder, er det hen.
-- Er det flere, velger eieren selv — vi skal ikke gjette paa hvem.
UPDATE kursholdere
   SET standard = 1
 WHERE aktiv = 1
   AND (SELECT antall FROM (SELECT COUNT(*) AS antall FROM kursholdere WHERE aktiv = 1) a) = 1
   AND (SELECT harNoen FROM (SELECT COUNT(*) AS harNoen FROM kursholdere WHERE standard = 1) b) = 0;

-- Kursets egen standard fra migrasjon 085 er ikke i bruk lenger.
--
-- Den ble lagt inn og tatt ut igjen samme dag, og ingenting leser den. Den
-- fjernes framfor aa bli staaende som en kolonne ingen vet hva er — det er
-- nettopp den slags gjeld vi rydder bort.
SET @finnes := (
  SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'courses'
     AND constraint_name = 'fk_kurs_standardholder'
);
SET @sql := IF(@finnes > 0,
  'ALTER TABLE courses DROP FOREIGN KEY fk_kurs_standardholder', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @finnes := (
  SELECT COUNT(*) FROM information_schema.statistics
   WHERE table_schema = DATABASE()
     AND table_name = 'courses'
     AND index_name = 'ix_kurs_standardholder'
);
SET @sql := IF(@finnes > 0,
  'ALTER TABLE courses DROP INDEX ix_kurs_standardholder', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

ALTER TABLE courses DROP COLUMN IF EXISTS standard_kursholder_id;
