-- Juleverksted som lagret kampanje paa forsiden — klar, men ikke vist.
--
-- Eieren, 21. september 2026: «du kan også lage klart juleverksted for
-- banneret på forsiden, ikke vis men gjøre klart». Tekstene er hentet fra
-- kursteksten hans og vist ham foer dette ble lagt inn. Kampanjen ligger i
-- lista under Markedsføring → Kampanjer til han trykker «Vis»; Kampanje/aktiv
-- roeres ikke, saa forsiden er som foer.
--
-- Knappen gaar rett til kurssida: maal = «kurs/juleverksted» (nytt fra samme
-- dag, se api/admin/kampanjer.php). Kolonna var VARCHAR(32) — for kort til
-- «kurs/alle-barn-se-her-tre-pa-rad».

ALTER TABLE kampanjer MODIFY maal VARCHAR(191) NOT NULL DEFAULT 'butikk';

INSERT INTO kampanjer (navn, merke, tittel, tekst, pris_ore, bilde, knapp, maal)
SELECT 'Juleverksted',
       'Juleverksted',
       'Lag julepynten selv i år',
       'En hyggelig kveld med leire i verkstedet på Teie. Vi lager julepynt etter eget ønske med håndbyggingsteknikker alle får til – ingen forkunnskaper. Barn under 12 år kommer sammen med en voksen.',
       99000,
       NULL,
       'Se datoer og book',
       'kurs/juleverksted'
 WHERE NOT EXISTS (SELECT 1 FROM kampanjer WHERE navn = 'Juleverksted');
