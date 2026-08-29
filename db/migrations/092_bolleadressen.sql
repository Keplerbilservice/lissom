-- Adressen skal foelge navnet: /kurs/lag-din-egen-bolle.
--
-- Kurset het «Kurs boller» og fikk navnet «Lag din egen bolle» i migrasjon
-- 087. Adressen sto igjen som «kurs-boller» med vilje den gangen — gamle
-- lenker skulle virke. Eieren, 29. august: adressen skal folge navnet.
--
-- ── Hvorfor det maa gjores i to trekk ─────────────────────────────────
--
-- «courses.slug» er unik, og dubletten som migrasjon 091 satte til kladd
-- holder fortsatt paa «lag-din-egen-bolle». Settes den nye adressen paa det
-- publiserte kurset for den gamle raden har sluppet den, feiler hele
-- migrasjonen paa den unike noekkelen.
--
-- Kladden faar derfor en adresse med «-gammel» bak. Den er ikke publisert og
-- staar ikke noe sted; adressen er bare et navn i basen som ingen ser.
--
-- ── Betingelsene ─────────────────────────────────────────────────────
--
-- Ingenting skjer med mindre bildet er noeyaktig som ventet: ett publisert
-- kurs paa «kurs-boller», og eventuelt én kladd paa «lag-din-egen-bolle».
-- Er noen andre kommet til aa bruke adressen i mellomtiden, roerer
-- migrasjonen ingenting — da skal et menneske se paa det.
--
-- ── Den gamle adressen ───────────────────────────────────────────────
--
-- «kurs-boller» er den Google kjenner. Den sendes videre med et 301 i
-- .htaccess, men FORST naar denne migrasjonen er kjort — ellers ville
-- omdirigeringen pekt paa en adresse som ennaa ikke fantes.

-- 1) Kladden slipper adressen.
SET @kladd := (
  SELECT id FROM courses
   WHERE slug = 'lag-din-egen-bolle' AND status = 'kladd'
   LIMIT 1
);
SET @sql := IF(@kladd IS NULL, 'DO 0',
  'UPDATE courses SET slug = ''lag-din-egen-bolle-gammel'' WHERE id = @kladd');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) Det publiserte kurset tar den — hvis den er ledig naa.
SET @kurs := (
  SELECT id FROM courses WHERE slug = 'kurs-boller' LIMIT 1
);
SET @opptatt := (
  SELECT COUNT(*) FROM courses WHERE slug = 'lag-din-egen-bolle'
);
SET @sql := IF(@kurs IS NOT NULL AND @opptatt = 0,
  'UPDATE courses SET slug = ''lag-din-egen-bolle'' WHERE id = @kurs',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
