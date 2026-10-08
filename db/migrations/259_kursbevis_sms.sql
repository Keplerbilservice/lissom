-- Kursbevis på SMS (eieren, «ok, godkjent» 8. oktober 2026, skissen
-- 1JpA7jncCDah2exGGjJZYz).
-- godkjent av eieren: 2026-10-08
--
-- Kursbeviset går allerede på e-post neste dag kl. 10 sammen med spørsmålet
-- om anmeldelse (bin/cron.php anmeldelser). Samtidig går nå en SMS med
-- lenken til dem som har møtt, har betalt og har mobil. Teksten kan endres
-- under Tekstmaler («Kursbevis (SMS)»), og malen kan slås av der.
--
-- course_sessions.kursbevis_sms: bryteren «Send også på SMS» i Start kurset
-- › Etter kurset, for én økt. På som standard, slik skissen viste.
ALTER TABLE course_sessions
  ADD COLUMN IF NOT EXISTS kursbevis_sms TINYINT(1) NOT NULL DEFAULT 1;

INSERT INTO notification_templates (navn, kanal, emne, tekst, gruppe) VALUES
('kursbevis_sms', 'sms', NULL,
 'Hei {fornavn}! Takk for at du var med på {kurs} hos Lissom. Her er kursbeviset ditt: {lenke}\nHilsen oss i Lissom', 'kurs')
ON DUPLICATE KEY UPDATE navn = navn;
