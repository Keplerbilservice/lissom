-- Foresporsler fra nettsiden.
--
-- Skjemaet «Send oss en foresporsel» la til naa bare en oppforing i
-- nettleserens minne. Sendte noen inn en foresporsel om utdrikningslag eller
-- en skoleklasse, forsvant den i det de lukket fana — og verkstedet fikk
-- aldri vite om den. Det er den dyreste formen for simulering.

CREATE TABLE IF NOT EXISTS enquiries (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  navn        VARCHAR(191) NOT NULL,
  epost       VARCHAR(191) NULL,
  telefon     VARCHAR(32)  NULL,
  type        VARCHAR(64)  NULL COMMENT 'Privat gruppe, utdrikningslag, bursdag ...',
  antall      VARCHAR(32)  NULL COMMENT 'Fritekst — folk skriver «ca 12»',
  melding     TEXT NULL,
  status      ENUM('ubesvart','besvart') NOT NULL DEFAULT 'ubesvart',
  besvart_at  DATETIME NULL,
  besvart_av  BIGINT UNSIGNED NULL,
  ip          VARBINARY(16) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_enquiries_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
