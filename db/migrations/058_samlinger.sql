-- Kurs som gaar over flere dager, med egen tekst per samling.
--
-- En kursdato kan i dag spenne over flere dager: start onsdag og slutt
-- torsdag gir «onsdag 9. – torsdag 10. september». Det er én sammenhengende
-- blokk. Tre samlinger med hver sin dato, hvert sitt klokkeslett, sin egen
-- overskrift og sin egen tekst finnes ikke, og det er det som er bestilt.
--
-- Hvorfor ikke flere rader i course_sessions:
--
--   course_sessions er den bookbare enheten. Én paamelding peker paa én rad.
--   Ble hver samling en egen rad, kunne noen meldt seg paa samling 2 uten 1,
--   plasstellingen ville talt samme person tre ganger, og betalingen maatte
--   deles i tre. Det er ikke et flerdagerskurs — det er tre kurs.
--
-- Samlingene henger under datoen i stedet. Paameldingen er fortsatt én.

CREATE TABLE IF NOT EXISTS okt_samlinger (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id  BIGINT UNSIGNED NOT NULL,
    -- Samling 1, 2, 3. Rekkefolgen deltakeren ser dem i.
    nummer      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    dato        DATE NOT NULL,
    fra         TIME NULL,
    til         TIME NULL,
    overskrift  VARCHAR(191) NULL,
    tekst       TEXT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_samling (session_id, nummer),
    KEY ix_samling_dato (dato),
    CONSTRAINT fk_samling_okt FOREIGN KEY (session_id)
        REFERENCES course_sessions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pris og informasjon som gjelder bare denne ene kursdatoen.
--
-- Bestilt sammen med «Ny kursdato»: en dato kan koste noe annet enn kurset
-- ellers, og ha noe aa si som ikke gjelder de andre datoene.
-- NULL betyr «som kurset», saa alle datoer som ligger der i dag oppforer seg
-- noyaktig som for.
ALTER TABLE course_sessions
  ADD COLUMN IF NOT EXISTS pris_ore INT UNSIGNED NULL COMMENT 'NULL = bruk kursets pris',
  ADD COLUMN IF NOT EXISTS info TEXT NULL COMMENT 'Gjelder bare denne datoen';
