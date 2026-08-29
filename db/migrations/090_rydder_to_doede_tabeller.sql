-- To tabeller ingen kode leser, og som bare fjernes hvis de er tomme.
--
-- ── «checkins» ────────────────────────────────────────────────────────
--
-- Laget i 001_init. Innstemplingen ligger i «check_ins» — med understrek —
-- laget paa nytt i migrasjon 016. De to har staatt side om side siden, og
-- den uten understrek har aldri hatt en eneste rad skrevet til seg.
--
-- Det var ikke bare stygt: helsesjekken i api/status.php sjekket lenge feil
-- av de to, og sa «alt i orden» selv naar den ekte tabellen manglet.
--
-- ── «hour_usage» ──────────────────────────────────────────────────────
--
-- Ogsaa fra 001_init. Tanken var aa foere timeforbruket til medlemmene som
-- egne rader. Det ble aldri bygget — timene regnes ut av «check_ins», som er
-- det eneste stedet som vet naar noen faktisk var i verkstedet. Ingen SQL i
-- kodebasen naevner tabellen.
--
-- ── Hvorfor de telles foerst ──────────────────────────────────────────
--
-- Jeg ser bare den lokale basen. Staar det rader i dem paa den ekte
-- tjeneren, er det historikk noen en gang skrev, og da skal de bli staaende
-- til eieren har sett paa dem. Derfor teller migrasjonen radene foerst og
-- dropper bare det som er tomt. Kjores den paa en base der de har innhold,
-- gjor den ingenting — og api/status.php fortsetter aa liste dem under
-- «ubrukte», saa det er synlig at de er der.

SET @n := (
  SELECT COUNT(*) FROM information_schema.tables
   WHERE table_schema = DATABASE() AND table_name = 'checkins'
);
SET @sql := IF(@n = 0, 'DO 0', 'SELECT COUNT(*) INTO @rader FROM checkins');
SET @rader := 1;
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@n = 1 AND @rader = 0, 'DROP TABLE checkins', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @n := (
  SELECT COUNT(*) FROM information_schema.tables
   WHERE table_schema = DATABASE() AND table_name = 'hour_usage'
);
SET @sql := IF(@n = 0, 'DO 0', 'SELECT COUNT(*) INTO @rader FROM hour_usage');
SET @rader := 1;
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF(@n = 1 AND @rader = 0, 'DROP TABLE hour_usage', 'DO 0');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
