-- Et bilde til feilmeldingen.
--
-- Eieren, 31. august: «paa admin burde man kunne legge inn bilde naar man
-- melder feil, ellers er jeg redd du ikke forstaar hva vi mener».
--
-- Han har rett. «Listen var tom» og «knappen virker ikke» kan bety fem ting
-- hver; et skjermbilde betyr én. Kolonnen holder filnavnet — selve bildet
-- ligger utenfor det som publiseres, og serveres av api/bilde.php.
--
-- Bare meldinger fra mennesker faar bilde. De som fanges automatisk har
-- ingen som kunne tatt det.

SET @har := (
  SELECT COUNT(*) FROM information_schema.columns
   WHERE table_schema = DATABASE()
     AND table_name = 'feilrapporter'
     AND column_name = 'bilde'
);

SET @sql := IF(@har = 0,
  'ALTER TABLE feilrapporter ADD COLUMN bilde VARCHAR(64) NULL AFTER skjerm',
  'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
