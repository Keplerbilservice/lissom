-- godkjent av eieren: 2026-10-08
--
-- Paint on Pots: fire prisnivåer og 100 kr per person ved booking.
--
-- Eieren, «ok, bygg det» 8. oktober 2026, til to fremvisninger:
--   - «prisliste + 100 kr ved booking» (GFD76vZ7iEiFgDiaPFf6hu)
--   - «fire prisnivåer» (PU3RwTARY6oVYx6dbwaLwo)
--
-- 1. Prisnivåene står i en tabell og redigeres i admin (Paint on Pots ›
--    innstillinger). Siden viser nivåene med gjenstandene i stedet for
--    «fra 450 kr». Ingen «fra»-pris.
-- 2. Ved booking betales et beløp per person med Vipps (courses.pris_ore på
--    Paint on Pots-kurset, satt i admin). Det trekkes fra i verkstedet.
--    courses.depositum = 1 sier at kursprisen er dette beløpet, ikke prisen
--    på det kunden tar med hjem: ingen grupperabatt, ingen medlemsrabatt og
--    ikke «betal ved oppmøte».
-- 3. Avbestilling senest courses.avbestilling_timer før = full refusjon via
--    Vipps. Senere, eller ikke møtt: beløpet beholdes.
-- 4. I kassa slås gjenstandene inn på bookingen (pop_kasselinjer). Bookingens
--    beløp blir summen av gjenstandene, og det som alt er betalt trekkes fra.
--
-- Eksisterende bookinger røres ikke: de nye kolonnene er NULL på alle rader
-- som finnes, og bare bookinger gjort etter dette får depositum_ore.

CREATE TABLE IF NOT EXISTS pop_prisnivaer (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  navn        VARCHAR(60)  NOT NULL,
  pris_ore    INT UNSIGNED NOT NULL,
  gjenstander VARCHAR(500) NOT NULL DEFAULT '' COMMENT 'Kommadelt, slik siden viser dem',
  rekkefolge  SMALLINT     NOT NULL DEFAULT 0,
  aktiv       TINYINT(1)   NOT NULL DEFAULT 1,
  oppdatert   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Startverdiene (eieren, 8. oktober 2026). Bare når tabellen er tom, så en
-- liste som alt er redigert i admin ikke skrives over.
INSERT INTO pop_prisnivaer (navn, pris_ore, gjenstander, rekkefolge)
SELECT n.navn, n.pris_ore, n.gjenstander, n.rekkefolge FROM (
  SELECT 'Liten' AS navn, 50000 AS pris_ore, 'standard kopp, kopp uten hank, liten bolle, lysestake' AS gjenstander, 1 AS rekkefolge
  UNION ALL SELECT 'Mellom', 70000, 'stor kopp/krus, stor tekopp, tallerken', 2
  UNION ALL SELECT 'Stor', 85000, 'stor bolle, lite serveringsfat, mugge, smørklokke', 3
  UNION ALL SELECT 'Ekstra stor', 100000, 'mellomstort serveringsfat, stor vase, stort serveringsfat', 4
) AS n
WHERE NOT EXISTS (SELECT 1 FROM pop_prisnivaer);

-- Gjenstandene som er slått inn i kassa på en booking. Navn og pris kopieres
-- fra nivået, så en senere prisendring ikke endrer et oppgjør som er gjort.
CREATE TABLE IF NOT EXISTS pop_kasselinjer (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id    BIGINT UNSIGNED NOT NULL,
  nivaa_id      INT UNSIGNED NULL,
  navn          VARCHAR(60)  NOT NULL,
  pris_ore      INT UNSIGNED NOT NULL,
  antall        SMALLINT UNSIGNED NOT NULL,
  registrert_av BIGINT UNSIGNED NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY booking (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE courses
  ADD COLUMN IF NOT EXISTS depositum TINYINT(1) NOT NULL DEFAULT 0
      COMMENT '1 = pris_ore betales ved booking per person og trekkes fra i verkstedet',
  ADD COLUMN IF NOT EXISTS avbestilling_timer SMALLINT UNSIGNED NULL
      COMMENT 'Full refusjon ved avbestilling senest så mange timer før. NULL = vilkårenes 2 dager.';

ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS depositum_ore INT UNSIGNED NULL
      COMMENT 'Beløpet som skulle betales ved booking (Paint on Pots). NULL = vanlig booking.',
  ADD COLUMN IF NOT EXISTS gjenstander_ore INT UNSIGNED NULL
      COMMENT 'Summen av gjenstandene slått inn i kassa. NULL = ikke slått inn ennå.',
  ADD COLUMN IF NOT EXISTS avbestill_kode CHAR(32) NULL
      COMMENT 'Koden i avbestillingslenka i bekreftelsen (Paint on Pots).';

-- Paint on Pots-kurset: 100 kr per person ved booking, avbestilling senest
-- 24 timer før, og ingen «fra»-pris. Bare kurset; ingen økter eller
-- bookinger røres.
UPDATE courses
   SET pris_ore = 10000, depositum = 1, avbestilling_timer = 24, fra_pris = 0
 WHERE folger_apningstid = 1
   AND (slug = 'paint-on-pots' OR tittel = 'Paint on Pots');
