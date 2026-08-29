-- Kursholder paa den enkelte datoen.
--
-- Verkstedet har et kursholderregister — navn, rolle, timesats, timer — men
-- det er ikke koblet til noe. Ingen fil utenom tabell-lista i api/status.php
-- nevner det. Den eneste instruktoerkoblingen som finnes er fritekstfeltet
-- «courses.instruktor», og det brukes bare til navnet paa kursbeviset.
--
-- Foelgene: du kan ikke se hvem som holder hva, ikke summere timene til en
-- kursholder mot oektene hen faktisk holdt, og ikke oppdage at samme person
-- staar oppfoert paa to kurs samtidig.
--
-- Kursholderen hoerer til datoen, ikke til kurset: den som holder dreiekurset
-- i september er ikke noedvendigvis den som holder det i oktober. Kurset faar
-- en standard, saa en ny dato foreslaar riktig person.

-- Hvem som holder denne gangen. NULL = ikke tildelt.
--
-- «ON DELETE SET NULL»: slutter noen, skal ikke datoene deres forsvinne.
-- Oekta blir staaende uten kursholder, og det er riktig — den gikk.
ALTER TABLE course_sessions
  ADD COLUMN IF NOT EXISTS kursholder_id BIGINT UNSIGNED NULL
  COMMENT 'Hvem som holder denne datoen. NULL = ikke tildelt.'
  AFTER serie_id;

-- Den som vanligvis holder kurset. Forslaget naar en ny dato settes opp.
ALTER TABLE courses
  ADD COLUMN IF NOT EXISTS standard_kursholder_id BIGINT UNSIGNED NULL
  COMMENT 'Foreslaas naar en ny dato legges inn. NULL = ingen standard.'
  AFTER instruktor;

CREATE INDEX IF NOT EXISTS ix_okt_kursholder ON course_sessions (kursholder_id);
CREATE INDEX IF NOT EXISTS ix_kurs_standardholder ON courses (standard_kursholder_id);

-- Fremmednoeklene legges paa hver for seg, og bare naar de ikke finnes fra
-- for. MariaDB har ingen «ADD CONSTRAINT IF NOT EXISTS», saa det gjoeres med
-- en sjekk mot information_schema — migrasjonen skal kunne kjores om igjen.
SET @finnes := (
  SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'course_sessions'
     AND constraint_name = 'fk_okt_kursholder'
);
SET @sql := IF(@finnes = 0,
  'ALTER TABLE course_sessions
     ADD CONSTRAINT fk_okt_kursholder FOREIGN KEY (kursholder_id)
     REFERENCES kursholdere (id) ON DELETE SET NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @finnes := (
  SELECT COUNT(*) FROM information_schema.table_constraints
   WHERE constraint_schema = DATABASE()
     AND table_name = 'courses'
     AND constraint_name = 'fk_kurs_standardholder'
);
SET @sql := IF(@finnes = 0,
  'ALTER TABLE courses
     ADD CONSTRAINT fk_kurs_standardholder FOREIGN KEY (standard_kursholder_id)
     REFERENCES kursholdere (id) ON DELETE SET NULL',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Det som alt er skrevet, kobles opp naar navnet stemmer.
--
-- «courses.instruktor» er fritekst, men den er som regel fylt ut med navnet
-- paa en som staar i registeret. Der de er like, settes standarden — da
-- slipper eieren aa gjoere det for haand paa hvert kurs. Feltet blir
-- staaende: det er navnet paa kursbeviset, og det skal ikke endres av dette.
UPDATE courses c
   JOIN kursholdere k ON LOWER(TRIM(k.navn)) = LOWER(TRIM(c.instruktor))
    SET c.standard_kursholder_id = k.id
  WHERE c.standard_kursholder_id IS NULL
    AND c.instruktor IS NOT NULL AND TRIM(c.instruktor) <> '';

-- Og datoene som ligger framover, arver standarden fra kurset sitt.
-- Datoer som har vaert, roeres ikke — de er historikk, og vi vet ikke hvem
-- som faktisk sto der.
UPDATE course_sessions cs
   JOIN courses c ON c.id = cs.course_id
    SET cs.kursholder_id = c.standard_kursholder_id
  WHERE cs.kursholder_id IS NULL
    AND c.standard_kursholder_id IS NOT NULL
    AND cs.start_tid > UTC_TIMESTAMP();
