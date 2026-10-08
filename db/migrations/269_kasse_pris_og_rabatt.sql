-- Den enklere kassa på iPad (eieren, «ja» 8. oktober 2026, oppsett A):
-- endret pris på en linje og rabatt på hele kjøpet.
--
-- 1. kasse_justeringer: én rad per del av et kjøp (påmeldingen eller ordren)
--    som fikk endret pris eller rabatt i kassa. Opprinnelig beløp, nytt
--    beløp, rabatten, hvem som sto i kassa og hvorfor. Vises på kvitteringen
--    og i Dagens oppgjør. «nokkel» er delens idempotensnøkkel fra iPaden, så
--    et nytt forsøk aldri lager raden to ganger.
-- 2. bookings.kasse_rabatt_ore: det kassa har trukket fra på påmeldingen
--    (prisendring og rabatt). Paint on Pots regner beløpet på nytt når
--    gjenstandene slås inn; da trekkes dette fra igjen, så rabatten ikke
--    forsvinner.
--
-- Migrasjonen rører ingen påmeldinger, ordrer eller betalinger som finnes.

CREATE TABLE IF NOT EXISTS kasse_justeringer (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nokkel            CHAR(36) NOT NULL COMMENT 'Delens idempotensnøkkel fra iPaden',
  del_type          VARCHAR(16) NOT NULL COMMENT 'booking | ordre',
  booking_id        BIGINT UNSIGNED NULL,
  order_id          BIGINT UNSIGNED NULL,
  opprinnelig_ore   INT NOT NULL COMMENT 'Delen før endringen',
  ny_ore            INT NOT NULL COMMENT 'Delen etter endringen',
  pris_ore          INT NOT NULL DEFAULT 0 COMMENT 'Trukket fra med endret pris (negativ = pris satt opp)',
  rabatt_ore        INT NOT NULL DEFAULT 0 COMMENT 'Delens andel av rabatten',
  rabatt_tekst      VARCHAR(32) NULL COMMENT '«10 %» eller «200 kr»',
  hvorfor           VARCHAR(191) NULL,
  linjer            TEXT NULL COMMENT 'JSON: linjene med endret pris (tekst, antall, fraOre, tilOre)',
  registrert_av     BIGINT UNSIGNED NULL COMMENT 'Personen som sto i kassa',
  siste_betaling_id BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Største payments.id da raden ble laget',
  erstattet_at      DATETIME NULL COMMENT 'Erstattet av en ny endring før det ble betalt',
  created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_kasse_justeringer_nokkel (nokkel),
  KEY ix_kasse_justeringer_booking (booking_id),
  KEY ix_kasse_justeringer_order (order_id),
  KEY ix_kasse_justeringer_tid (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS kasse_rabatt_ore INT NOT NULL DEFAULT 0
      COMMENT 'Trukket fra i kassa (endret pris og rabatt), i øre';
