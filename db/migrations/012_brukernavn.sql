-- Innlogging med brukernavn og passord for verkstedet.
--
-- Vipps Login blir staaende for kundene. Men admin skal kunne gis til noen
-- som ikke skal ha en kundekonto — en ansatt, en vikar — og da er brukernavn
-- og passord riktigere enn aa knytte tilgangen til noens private Vipps.
--
-- Feltene ligger paa members framfor i en egen tabell. Da fungerer sesjoner,
-- portvakter og revisjonslogg som for, og den samme personen kan logge inn
-- begge veier uten aa bli to personer i systemet.
--
-- passord_hash er en hash fra PHPs password_hash(), aldri passordet selv.

ALTER TABLE members
  ADD COLUMN IF NOT EXISTS brukernavn VARCHAR(64) NULL COMMENT 'Smaa bokstaver. NULL = logger inn med Vipps.' AFTER vipps_sub,
  ADD COLUMN IF NOT EXISTS passord_hash VARCHAR(255) NULL COMMENT 'password_hash(), aldri passordet' AFTER brukernavn,
  ADD COLUMN IF NOT EXISTS siste_innlogging DATETIME NULL AFTER passord_hash;

ALTER TABLE members
  ADD UNIQUE KEY IF NOT EXISTS uq_members_brukernavn (brukernavn);
