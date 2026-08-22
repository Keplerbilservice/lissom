-- Hostprogrammet, slik verkstedet faktisk har satt det opp.
--
-- Datoene som laa her fra for var mine, hentet fra designet. De byttes ut med
-- de ekte. Okter uten paameldte fjernes; en okt noen har booket blir staaende
-- uansett — ingen skal miste plassen sin fordi et program ble oppdatert.
--
-- Tidspunkt lagres i UTC. September er sommertid, altsaa UTC+2: 17:00 norsk
-- tid er 15:00 UTC.
--
-- Fila kan kjores om igjen. Kursene settes inn med INSERT IGNORE paa slug,
-- oktene med ON DUPLICATE KEY paa (kurs, starttid).

-- --------------------------------------------------------------- kursene
INSERT IGNORE INTO courses (slug, tittel, type, tema, pris_ore, kapasitet, sms_paaminnelse, status, beskrivelse) VALUES
('workshop', 'Keramikk Workshop', 'workshop', 'Workshop', 149000, 12, 1, 'publisert',
 'Du lager det du vil, og faar veiledning underveis. Leire, verktoy, glasur og brenning er inkludert.'),

('sip-and-clay', 'Sip & Clay', 'event', 'Sip & Clay', 149000, 12, 1, 'publisert',
 'En kveld med leire og godt selskap. Ta med det du vil drikke, vi har glass. Ingen forkunnskaper.');

-- Beskrivelsen paa workshop settes ogsaa naar kurset fantes fra for.
UPDATE courses
   SET beskrivelse = 'Du lager det du vil, og faar veiledning underveis. Leire, verktoy, glasur og brenning er inkludert.',
       pris_ore = 149000, kapasitet = 12, tema = 'Workshop', type = 'workshop', status = 'publisert'
 WHERE slug = 'workshop';

UPDATE courses
   SET pris_ore = 149000, tema = 'Sip & Clay', type = 'event', status = 'publisert'
 WHERE slug = 'sip-and-clay';

-- ------------------------------------------------------- rydd gamle datoer
--
-- Bare oktene til kursene programmet gjelder, og bare de uten paameldte.
-- Drop-in og medlemsarrangementene rores ikke.
DELETE cs FROM course_sessions cs
  JOIN courses c ON c.id = cs.course_id
 WHERE c.slug IN ('nybegynner-dreiekurs', 'kurs-boller', 'store-fat-kurs',
                  'date-night', 'paint-on-pots', 'workshop', 'sip-and-clay')
   AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.course_session_id = cs.id);

-- ------------------------------------------------------------- programmet
--
-- Dreiekurset gaar over to kvelder og er én paamelding: okten starter forste
-- kveld og slutter andre. Laa det som to okter, kunne noen booke seg paa bare
-- den ene av dem.
INSERT INTO course_sessions (course_id, start_tid, slutt_tid, kapasitet, manuelt_opptatt)
SELECT id, '2026-08-24 15:00:00', '2026-08-24 18:00:00', 8, 2 FROM courses WHERE slug = 'kurs-boller'
UNION ALL SELECT id, '2026-09-03 15:00:00', '2026-09-03 18:00:00', 8, 2 FROM courses WHERE slug = 'kurs-boller'

UNION ALL SELECT id, '2026-09-02 08:00:00', '2026-09-02 11:00:00', 12,  2 FROM courses WHERE slug = 'workshop'
UNION ALL SELECT id, '2026-09-05 08:00:00', '2026-09-05 11:00:00', 12, 12 FROM courses WHERE slug = 'workshop'

UNION ALL SELECT id, '2026-09-09 15:00:00', '2026-09-10 18:00:00', 8, 3 FROM courses WHERE slug = 'nybegynner-dreiekurs'
UNION ALL SELECT id, '2026-09-16 15:00:00', '2026-09-17 18:00:00', 8, 1 FROM courses WHERE slug = 'nybegynner-dreiekurs'

UNION ALL SELECT id, '2026-09-24 16:00:00', '2026-09-24 19:00:00', 12, 12 FROM courses WHERE slug = 'sip-and-clay'

ON DUPLICATE KEY UPDATE
  slutt_tid       = VALUES(slutt_tid),
  kapasitet       = VALUES(kapasitet),
  manuelt_opptatt = VALUES(manuelt_opptatt),
  status          = 'planlagt';
