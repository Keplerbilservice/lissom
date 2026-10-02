-- «Gi tid»: verkstedet gir et medlem tid fra admin (eieren, 2. oktober 2026).
--
-- Tre valg: 1 time, tilgang én uke eller tilgang ut måneden. Én rad per
-- tildeling. En tildeling slettes aldri — den trekkes (trukket_at/trukket_av),
-- saa det staar igjen hvem som ga og hvem som trakk.
--
-- ON DELETE RESTRICT: et medlem som har faatt tid, slettes ikke helt. Basen
-- sier nei, og «Slett» i admin anonymiserer i stedet (api/admin/medlemmer.php),
-- saa tildelingene staar igjen.
--
--   type = time    timer = 1, gjelder ut maaneden (til = siste dag i maaneden)
--   type = uke     tilgang dag 1–7 (til = fra + 6 dager)
--   type = maaned  tilgang ut maaneden (til = siste dag i maaneden)
--
-- Datoene er norsk tid (Europe/Oslo) og regnes ut i app/lib/tildeling.php.
-- Bit T1 lager bare tabellen og regnestykket; ingenting leser den ennaa
-- (bryteren Vis/tildeling er av naar raden mangler).
--
-- Trygg aa kjoere to ganger. Legger bare til.

CREATE TABLE IF NOT EXISTS tildelinger (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id   BIGINT UNSIGNED NOT NULL,
    type        ENUM('time','uke','maaned') NOT NULL,
    timer       INT NULL COMMENT 'Antall timer naar type = time',
    fra         DATE NOT NULL COMMENT 'Foerste dag (norsk tid)',
    til         DATE NOT NULL COMMENT 'Siste dag den gjelder (norsk tid)',
    gitt_av     BIGINT UNSIGNED NULL,
    trukket_at  DATETIME NULL DEFAULT NULL COMMENT 'Naar den ble trukket (UTC). NULL = gjelder',
    trukket_av  BIGINT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_tildeling_medlem_til (member_id, til),
    CONSTRAINT fk_tildeling_medlem FOREIGN KEY (member_id)
        REFERENCES members (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
