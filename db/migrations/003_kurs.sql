-- Kurskatalogen, hentet fra designet.
--
-- Prisene her er fasiten. Nettleseren sender aldri beløp — den sender hvilket
-- kurs og hvilken dato, og serveren slår opp prisen selv. Ellers kunne hvem som
-- helst endret prisen i nettleseren før betaling.
--
-- Tidspunkt lagres i UTC. Norsk sommertid er UTC+2, vintertid UTC+1.

INSERT INTO courses (slug, tittel, type, tema, pris_ore, kapasitet, sms_paaminnelse, status, beskrivelse) VALUES
('nybegynner-dreiekurs', 'Nybegynner dreiekurs', 'kurs', 'Dreiing', 280000, 8, 1, 'publisert',
 'To økter over to dager. Første kveld lærer du å sentrere og dreie, andre kveld dreier du videre og lærer å trimme foten. Vi glaserer og brenner arbeidene for deg.'),

('kurs-boller', 'Kurs boller', 'kurs', 'Håndbygging', 149000, 8, 1, 'publisert',
 'En kveld med håndbygging. Du lager dine egne boller, vi tar oss av glasering og brenning.'),

('store-fat-kurs', 'Store fat kurs', 'kurs', 'Plateteknikk', 149000, 8, 1, 'publisert',
 'Plateteknikk for store former. Passer for deg som har prøvd leire før, men det er ingen krav.'),

('date-night', 'Date Night', 'event', 'Events', 119000, 12, 1, 'publisert',
 'En kveld for dere to. Leire, god stemning og noe å ta med hjem.'),

('paint-on-pots', 'Paint on Pots', 'event', 'Events', 69000, 14, 1, 'publisert',
 'Mal din egen keramikk. Ingen forkunnskaper, bare møt opp. Du betaler for keramikken du velger.'),

('drop-in', 'Drop-in i verkstedet', 'dropin', 'Drop-in', 49000, 6, 0, 'publisert',
 'To timer i verkstedet. Krever at du har gått kurs hos oss, eller kommer sammen med et aktivt medlem. Leire, materialer, brenning og ett ferdig arbeid er inkludert.');

-- Medlemsarrangementer. Gratis, og kun for aktive medlemmer.
INSERT INTO courses (slug, tittel, type, tema, pris_ore, kapasitet, sms_paaminnelse, status, beskrivelse) VALUES
('glasurkveld-medlemmer', 'Glasurkveld for medlemmer', 'event', 'Kun for medlemmer', 0, 10, 1, 'publisert',
 'Vi går gjennom glasurene i verkstedet, hva de tåler og hvordan de oppfører seg sammen.'),

('store-former-viderekomne', 'Store former, viderekomne', 'event', 'Kun for medlemmer', 0, 8, 1, 'publisert',
 'Dagskurs for deg som allerede dreier stødig og vil opp i størrelse.'),

('medlemsfrokost', 'Medlemsfrokost', 'event', 'Kun for medlemmer', 0, 20, 0, 'publisert',
 'Frokost i verkstedet. Ingen agenda, bare hyggelig selskap.');

-- Datoer. Klokkeslett i UTC — 15:30 UTC er 17:30 norsk sommertid.
INSERT INTO course_sessions (course_id, start_tid, slutt_tid)
SELECT id, '2026-09-02 15:30:00', '2026-09-02 18:30:00' FROM courses WHERE slug = 'nybegynner-dreiekurs'
UNION ALL SELECT id, '2026-09-03 15:30:00', '2026-09-03 18:30:00' FROM courses WHERE slug = 'nybegynner-dreiekurs'
UNION ALL SELECT id, '2026-09-16 15:30:00', '2026-09-16 18:30:00' FROM courses WHERE slug = 'nybegynner-dreiekurs'
UNION ALL SELECT id, '2026-09-17 15:30:00', '2026-09-17 18:30:00' FROM courses WHERE slug = 'nybegynner-dreiekurs'

UNION ALL SELECT id, '2026-08-24 15:00:00', '2026-08-24 18:00:00' FROM courses WHERE slug = 'kurs-boller'
UNION ALL SELECT id, '2026-09-21 15:00:00', '2026-09-21 18:00:00' FROM courses WHERE slug = 'kurs-boller'

UNION ALL SELECT id, '2026-09-08 08:00:00', '2026-09-08 11:00:00' FROM courses WHERE slug = 'store-fat-kurs'

UNION ALL SELECT id, '2026-08-28 16:00:00', '2026-08-28 19:00:00' FROM courses WHERE slug = 'date-night'
UNION ALL SELECT id, '2026-09-11 16:00:00', '2026-09-11 19:00:00' FROM courses WHERE slug = 'date-night'

UNION ALL SELECT id, '2026-09-06 10:00:00', '2026-09-06 13:00:00' FROM courses WHERE slug = 'paint-on-pots'
UNION ALL SELECT id, '2026-10-04 10:00:00', '2026-10-04 13:00:00' FROM courses WHERE slug = 'paint-on-pots'

UNION ALL SELECT id, '2026-08-27 16:00:00', '2026-08-27 19:00:00' FROM courses WHERE slug = 'glasurkveld-medlemmer'
UNION ALL SELECT id, '2026-09-13 08:00:00', '2026-09-13 13:00:00' FROM courses WHERE slug = 'store-former-viderekomne'
UNION ALL SELECT id, '2026-09-21 07:00:00', '2026-09-21 09:00:00' FROM courses WHERE slug = 'medlemsfrokost';
