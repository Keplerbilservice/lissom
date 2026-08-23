-- Kurs som gaar fast, samme ukedag og klokkeslett, uke etter uke.
--
-- Datoer ble lagt inn én og én. Et kurs som gaar hver torsdag maatte dermed
-- fylles paa for haand, og gikk det tomt, forsvant kurset fra nettsida uten
-- at noen sa fra.
--
-- En regel her sier «torsdager 10:00–13:00». Cron legger ut oktene framover,
-- og fyller paa etter hvert som tida gaar. Slettes regelen, staar oktene som
-- alt er lagt ut igjen — folk kan ha booket dem.

CREATE TABLE IF NOT EXISTS kurs_serier (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    course_id   BIGINT UNSIGNED NOT NULL,
    -- 1 = mandag, 7 = sondag. Samme tall som DATE_FORMAT %w gir med ISO.
    ukedag      TINYINT UNSIGNED NOT NULL,
    fra         TIME NOT NULL,
    til         TIME NOT NULL,
    -- Tomt betyr «bruk kursets egen kapasitet».
    kapasitet   SMALLINT UNSIGNED NULL,
    -- Hvor mange uker fram oktene skal ligge ute til enhver tid.
    uker_fram   TINYINT UNSIGNED NOT NULL DEFAULT 8,
    aktiv       TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_serie (course_id, ukedag, fra),
    CONSTRAINT fk_serie_kurs FOREIGN KEY (course_id) REFERENCES courses (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Et kurs kan vises paa nettsida uten datoer.
--
-- Date Night forsvant helt da datoene tok slutt. Kurset finnes fortsatt, det
-- settes bare opp naar noen sporr. Med dette staar det ute med «Kontakt oss»
-- i stedet for en bookingknapp.
ALTER TABLE courses
  ADD COLUMN vis_uten_dato TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

UPDATE courses SET vis_uten_dato = 1 WHERE slug = 'date-night';

-- Store fat gaar fast paa torsdager, formiddag og ettermiddag.
INSERT INTO kurs_serier (course_id, ukedag, fra, til, uker_fram, aktiv)
SELECT id, 4, '10:00:00', '13:00:00', 8, 1 FROM courses WHERE slug = 'store-fat-kurs'
ON DUPLICATE KEY UPDATE til = VALUES(til), uker_fram = VALUES(uker_fram), aktiv = 1;

INSERT INTO kurs_serier (course_id, ukedag, fra, til, uker_fram, aktiv)
SELECT id, 4, '17:00:00', '20:00:00', 8, 1 FROM courses WHERE slug = 'store-fat-kurs'
ON DUPLICATE KEY UPDATE til = VALUES(til), uker_fram = VALUES(uker_fram), aktiv = 1;
