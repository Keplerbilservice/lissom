-- Kursholder paa datoene som staar tomme.
--
-- Eieren, 1. september: «lag din egen bolle dukker opp i kalenderen naa, uten
-- kursholder, hvordan er det mulig naar det kun er monica som er kursholder
-- og default?» — og: «det gjelder saa klart ogsaa paa alle paint on pots».
--
-- To ting hadde gaatt galt.
--
-- 1) Ingen var standard. Migrasjon 096 skulle sette Monica, men den satte
--    bare naar ingen andre alt sto som standard — og paa det tidspunktet sto
--    eieren der, fra for han ble satt inaktiv. Betingelsen slo feil, og
--    ryddingen som satte ham til 0 kom etterpaa i samme fil. Resultatet:
--    ingen standard i det hele tatt. Her ryddes det forst, og settes etterpaa.
--
-- 2) Datoene arvet ingenting. Tre av de fire stedene som lager kursdatoer —
--    faste ukedager, aapent verksted og drop-in — la dem ut med tomt felt.
--    Det er rettet i koden (app/lib/kursholder.php); denne migrasjonen tar
--    dem som alt ligger der.
--
-- Bare framtidige datoer roeres. Et kurs som er holdt, er historie: staar det
-- ingen paa det, er det fordi ingen ble satt den gangen, og det skal ikke
-- denne filen finne paa et navn til.

-- ── 1. Rydd forst ────────────────────────────────────────────────────
-- En som har sluttet kan ikke vaere verkstedets standard.
UPDATE kursholdere SET standard = 0 WHERE standard = 1 AND aktiv = 0;

-- ── 2. Sett standarden, om ingen har den ─────────────────────────────
-- Monica ved navn, saa lenge hun finnes én gang og er aktiv.
UPDATE kursholdere
   SET standard = 1
 WHERE aktiv = 1
   AND LOWER(TRIM(navn)) = 'monica'
   AND (SELECT antall FROM (SELECT COUNT(*) AS antall FROM kursholdere
         WHERE aktiv = 1 AND LOWER(TRIM(navn)) = 'monica') AS m) = 1
   AND (SELECT n FROM (SELECT COUNT(*) AS n FROM kursholdere WHERE standard = 1) AS s) = 0;

-- Heter hun noe annet i en annen base: er det bare én aktiv kursholder
-- igjen, er det hen. Er det flere, roeres ingenting — da skal et menneske
-- velge, og «Uten kursholder» er aerligere enn en tilfeldig person.
UPDATE kursholdere
   SET standard = 1
 WHERE aktiv = 1
   AND (SELECT antall FROM (SELECT COUNT(*) AS antall FROM kursholdere WHERE aktiv = 1) AS a) = 1
   AND (SELECT n FROM (SELECT COUNT(*) AS n FROM kursholdere WHERE standard = 1) AS s) = 0;

SET @standard := (SELECT id FROM kursholdere WHERE standard = 1 AND aktiv = 1 LIMIT 1);

-- ── 3. Fyll de tomme datoene framover ────────────────────────────────
-- Samme regel som koden bruker naar den lager en ny dato: den som staar paa
-- kurset, ellers standarden. Er begge tomme, blir datoen staaende tom.
UPDATE course_sessions cs
  JOIN courses c ON c.id = cs.course_id
   SET cs.kursholder_id = COALESCE(c.kursholder_id, @standard)
 WHERE cs.kursholder_id IS NULL
   AND cs.start_tid > UTC_TIMESTAMP()
   AND cs.status <> 'avlyst'
   AND COALESCE(c.kursholder_id, @standard) IS NOT NULL;
