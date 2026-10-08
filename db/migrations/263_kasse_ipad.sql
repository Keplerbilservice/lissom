-- Lissom Kasse på iPad (eieren, «ok, bygg det» 8. oktober 2026, skissen
-- Rm1Casf4CpVv8i5zwyfecX).
--
-- 1. Rollen «kasse»: en egen innlogging for iPaden i verkstedet. Den kommer
--    bare til kassa (api/kasse/), ikke til resten av admin og ikke til
--    medlemssidene. Innloggingen varer i 30 dager.
-- 2. En 4-sifret PIN per person som står i kassa. Lagres bare som hash
--    (password_hash), settes av admin under Brukere.
-- 3. Hvem som har låst opp kassa, og til når. Låser seg etter 5 minutter uten
--    bruk. Ligger på sesjonen, så det er serveren som avgjør, ikke iPaden.
--
-- Bryteren «Vis/kasse» (content_blocks) lages ikke: mangler raden, er kassa
-- av, til eieren slår den på under Synlighet.
--
-- Migrasjonen rører ingen medlemmer, sesjoner eller betalinger som finnes.

ALTER TABLE members
  MODIFY rolle ENUM('medlem', 'admin', 'regnskap', 'kasse') NOT NULL DEFAULT 'medlem';

ALTER TABLE members
  ADD COLUMN IF NOT EXISTS kasse_pin_hash VARCHAR(255) NULL
      COMMENT 'PIN-en personen låser opp kassa med (password_hash). NULL = ingen kassetilgang.';

ALTER TABLE sessions
  ADD COLUMN IF NOT EXISTS kasse_person_id BIGINT UNSIGNED NULL
      COMMENT 'Personen som låste opp kassa med PIN på denne sesjonen.',
  ADD COLUMN IF NOT EXISTS kasse_ulast_til DATETIME NULL
      COMMENT 'Kassa er låst opp til (UTC). Skyves ved bruk, 5 minutter.';
