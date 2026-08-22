-- Grupperabatt.
--
-- Bookingsiden viste «Grupperabatt 10 %: −kr. 280,-» og en nedsatt sum, mens
-- serveren regnet ut pris_ore × antall og trakk kunden full pris. Nivaaene laa
-- bare i nettleseren til den som saa paa dem, saa serveren visste ingenting om
-- dem — den kunne ikke ha regnet riktig.
--
-- Naa ligger de her, og prisen regnes ett sted: paa serveren.

CREATE TABLE IF NOT EXISTS discount_tiers (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  -- Fra og med saa mange plasser i samme booking.
  min_antall SMALLINT UNSIGNED NOT NULL,
  prosent    DECIMAL(5,2) NOT NULL,
  -- 'alle' = alle kurs og workshops, 'dreiing' = alle dreiekurs, ellers
  -- slug-en til ett bestemt kurs.
  gjelder    VARCHAR(191) NOT NULL DEFAULT 'alle',
  aktiv      TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_tiers_aktiv (aktiv, gjelder, min_antall)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Booking husker hvilken rabatt som faktisk ble gitt. Uten det kan en gammel
-- kvittering ikke forklares naar nivaaene senere endres.
ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS rabatt_prosent DECIMAL(5,2) NOT NULL DEFAULT 0
    COMMENT 'Grupperabatten som ble gitt paa denne bookingen'
    AFTER belop_ore;

-- Nivaaene som sto i designfila, saa de ikke forsvinner naar lista blir ekte.
INSERT INTO discount_tiers (min_antall, prosent, gjelder, aktiv)
SELECT * FROM (
  SELECT 3 AS a, 10.00 AS b, 'alle' AS c, 1 AS d UNION ALL
  SELECT 5, 15.00, 'alle', 1 UNION ALL
  SELECT 3, 5.00, 'dreiing', 1 UNION ALL
  SELECT 6, 10.00, 'dreiing', 1
) AS nye
WHERE NOT EXISTS (SELECT 1 FROM discount_tiers);
