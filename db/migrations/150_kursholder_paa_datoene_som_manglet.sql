-- Datoene som ligger uten kursholder faar den de skulle hatt.
--
-- Eieren, 7. september 2026, om spalta «Uten kursholder»: «det er jo ingen
-- oekter uten kursholder». Spalta ble fjernet samme dag, og da staar en okt
-- uten holder ikke lenger i dagsvisningen — dagsoverskriften teller den, men
-- ingen spalte tegner den.
--
-- Fra 1. september faar alle nye datoer en holder av seg selv: den som staar
-- paa kurset, ellers verkstedets standard. Se app/lib/kursholder.php. Datoer
-- laget FOER den regelen kan fortsatt ligge med feltet tomt.
--
-- Denne fyller dem etter den samme regelen, i samme rekkefolge:
--   1. Den som staar paa kurset, om hen fortsatt er aktiv.
--   2. Ellers verkstedets standard.
-- Finnes ingen av delene, staar datoen tom som for.
--
-- Bare datoer fra og med i dag. En okt som alt er holdt skal ikke faa et navn
-- den ikke hadde — da ville basen paastaa hvem som sto der en kveld i august.
UPDATE course_sessions cs
   JOIN courses c ON c.id = cs.course_id
    SET cs.kursholder_id = COALESCE(
        (SELECT k.id FROM kursholdere k
          WHERE k.id = c.kursholder_id AND k.aktiv = 1),
        (SELECT k2.id FROM kursholdere k2
          WHERE k2.standard = 1 AND k2.aktiv = 1 LIMIT 1))
 WHERE cs.kursholder_id IS NULL
   AND cs.start_tid >= CURDATE();
