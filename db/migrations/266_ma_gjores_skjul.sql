-- Må gjøres på I dag, idé 3, 4, 5 og 7 (eieren 08.10.2026, visningen
-- «ideer-visning»). Bare tillegg; tåler å kjøres to ganger.
--
-- 1. ma_gjores_skjul: «Vent en uke» (kurs som fylles tregt) og «Ikke nå»
--    (medlemmer som ikke har vært innom) skjuler saken i 7 dager. Lagres på
--    serveren, så det gjelder på mobil og PC. Nøkkel = «tregt:<øktId>» eller
--    «ikkeinnom».
-- 2. brenninger.ferdig_at: når brenningen ble merket ferdig.
-- 3. brenning_okter: hvilke kursdatoer som lå i ovnen. Da kan «Ovnen er
--    ferdig: N klar til henting» vise hvem som kan hente.
--
-- Koden (api/admin/ma-gjores-mer.php) tåler at denne ikke er kjørt.

CREATE TABLE IF NOT EXISTS ma_gjores_skjul (
  nokkel     VARCHAR(64) NOT NULL,
  skjult_til DATETIME NOT NULL COMMENT 'UTC',
  av         BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (nokkel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Saker på I dag som er skjult en stund (Vent en uke / Ikke nå).';

ALTER TABLE brenninger
  ADD COLUMN IF NOT EXISTS ferdig_at DATETIME NULL COMMENT 'Merket ferdig (UTC)';

CREATE TABLE IF NOT EXISTS brenning_okter (
  brenning_id       BIGINT UNSIGNED NOT NULL,
  course_session_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (brenning_id, course_session_id),
  KEY ix_brenning_okt (course_session_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Kursdatoene som lå i ovnen i en brenning.';
