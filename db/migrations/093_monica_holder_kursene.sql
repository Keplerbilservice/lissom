-- Alle kurs holdes av Monica. Joakim staar som sluttet.
--
-- Eieren, 29. august: «ikke vis meg som kursholder, for jeg er ikke det naa»,
-- og paa spoersmaalet om hvem oektene hans skal over paa: «ja alle kurs er
-- Monica».
--
-- ── Hva som flyttes ───────────────────────────────────────────────────
--
-- Kursholderen staar tre steder, og alle tre maa med — ellers ville navnet
-- hans blitt staaende paa oekter som alt er planlagt, mens han var borte fra
-- valget. Da kunne ingen rettet det.
--
--   course_sessions.kursholder_id   den enkelte datoen
--   courses.kursholder_id           kurset, som nye datoer arver
--   courses.instruktor              navnet paa kursbeviset
--
-- Kursbeviset er fritekst, og «tomt» betyr Monica fra for — api/kursbevis.php
-- faller tilbake paa henne naar feltet staar tomt. Derfor toemmes det framfor
-- aa skrive navnet hennes inn en gang til. Signaturen foelger med: navnet
-- hennes over hans signatur ville vaert verre enn begge deler.
--
-- ── Hva som IKKE flyttes ──────────────────────────────────────────────
--
-- «kursholder_timer» roeres ikke. De timene er foert arbeid — noen var i
-- verkstedet de timene, og det staar ikke til aa endre. De blir liggende paa
-- ham, og de blir lesbare: «Sluttet» skjuler ham fra listene, det sletter
-- ingenting.
--
-- ── Betingelsene ──────────────────────────────────────────────────────
--
-- Ingenting skjer med mindre begge navnene finnes, én gang hver. Er det to
-- som heter Joakim, eller ingen som heter Monica, roerer migrasjonen
-- ingenting — da skal et menneske se paa det. Kjores den to ganger, gjor den
-- ingenting andre gang: da staar det ikke lenger noe paa ham.

SET @joakim := (
  SELECT id FROM kursholdere
   WHERE LOWER(TRIM(navn)) = 'joakim'
   HAVING COUNT(*) = 1
);
SET @monica := (
  SELECT id FROM kursholdere
   WHERE LOWER(TRIM(navn)) = 'monica'
   HAVING COUNT(*) = 1
);
SET @gjor := (@joakim IS NOT NULL AND @monica IS NOT NULL AND @joakim <> @monica);

-- 1) Datoene som alt er planlagt.
SET @sql := IF(@gjor,
  'UPDATE course_sessions SET kursholder_id = @monica WHERE kursholder_id = @joakim',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) Kursene, som nye datoer arver fra.
SET @sql := IF(@gjor,
  'UPDATE courses SET kursholder_id = @monica WHERE kursholder_id = @joakim',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3) Navnet paa kursbeviset. Tomt = Monica.
SET @sql := IF(@gjor,
  'UPDATE courses SET instruktor = NULL, instruktor_signatur = NULL
    WHERE LOWER(TRIM(COALESCE(instruktor, ""))) = "joakim"',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4) Er han satt som verkstedets standard, maa den over foer han gaar ut —
--    ellers staar kalenderen uten en standard kursholder, og dagsvisningen
--    faar ingen spalte naar ingenting er planlagt.
SET @sql := IF(@gjor,
  'UPDATE kursholdere SET standard = 1 WHERE id = @monica AND (SELECT * FROM (SELECT standard FROM kursholdere WHERE id = @joakim) t) = 1',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 5) Og saa staar han som sluttet. Raden blir liggende med timene sine.
SET @sql := IF(@gjor,
  'UPDATE kursholdere SET aktiv = 0, standard = 0 WHERE id = @joakim',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
