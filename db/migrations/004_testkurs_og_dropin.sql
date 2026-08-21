-- To ting: datoer for drop-in, som manglet, og et testkurs til én krone.
--
-- Testkurset finnes for å kunne kjøre hele kjeden — booking, betaling,
-- kvittering — mot ekte Vipps uten å binde opp et reelt beløp. Det slettes
-- med migrasjon 005 når testen er gjennomført.

-- Drop-in: åpne dager framover
INSERT INTO course_sessions (course_id, start_tid, slutt_tid)
SELECT id, '2026-08-25 08:00:00', '2026-08-25 11:00:00' FROM courses WHERE slug = 'drop-in'
UNION ALL SELECT id, '2026-08-26 08:00:00', '2026-08-26 11:00:00' FROM courses WHERE slug = 'drop-in'
UNION ALL SELECT id, '2026-08-27 14:00:00', '2026-08-27 17:00:00' FROM courses WHERE slug = 'drop-in'
UNION ALL SELECT id, '2026-09-01 08:00:00', '2026-09-01 11:00:00' FROM courses WHERE slug = 'drop-in';

-- Testkurs, 1 krone = 100 øre
INSERT INTO courses (slug, tittel, type, tema, pris_ore, kapasitet, sms_paaminnelse, status, beskrivelse)
VALUES ('testkurs', 'TEST — ikke book denne', 'kurs', 'Test', 100, 99, 0, 'publisert',
        'Teknisk test av betalingsflyten. Denne fjernes igjen.');

INSERT INTO course_sessions (course_id, start_tid, slutt_tid)
SELECT id, '2026-12-31 10:00:00', '2026-12-31 11:00:00' FROM courses WHERE slug = 'testkurs';
