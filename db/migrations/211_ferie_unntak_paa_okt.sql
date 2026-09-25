-- Kurs i ferien, likevel.
--
-- Eieren, 25. september 2026: «alle ferie er i utgangspunktet stengt, men
-- åpent for medlemmer» — og: «husk å gi meg mulighet å legge ut kurs likevel,
-- men jeg må få advarsel, om at det er ferie».
--
-- En stengt dag (apningstider.stengt = 1) skjuler kursdatoene den dagen fra
-- nettsiden og bookingen. Med ferie_ok = 1 er oekta et unntak: den vises og
-- kan bookes, mens dagen fortsatt er stengt for drop-in og aapningstider.
-- Settes naar eieren svarer «Legg ut likevel» paa advarselen i admin.

ALTER TABLE course_sessions
    ADD COLUMN IF NOT EXISTS ferie_ok TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Vises selv om dagen er stengt (ferie)';

-- De to oektene med paameldte som laa i hoestferien (uke 41) da feriene ble
-- stengt 25. september 2026: dreiekurset 7.–8. oktober kl. 17 og Paint on
-- Pots 8. oktober kl. 17. Kurs med paameldte skal ikke endres — de skal
-- vaere synlige og bookbare som foer.
UPDATE course_sessions SET ferie_ok = 1 WHERE id IN (56, 1738);
