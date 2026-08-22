-- Aapningstidene for drop-in.
--
-- Tidene sto i nettleseren og forsvant ved omlasting. Da kunne ingen sette
-- naar verkstedet er aapent for drop-in, og oktene folk booker maatte legges
-- inn for haand én og én.
--
-- En rad per aapningstid. Samme ukedag kan ha flere — formiddag og ettermiddag
-- er to rader. kapasitet NULL betyr «arv fra drop-in-kurset».
--
-- Klokkeslettene er norsk tid, ikke UTC. De beskriver naar verkstedet er
-- aapent, ikke et tidspunkt paa en bestemt dato — «tirsdag 10.00» er tirsdag
-- 10.00 hele aaret, ogsaa naar sommertida slaar om.

CREATE TABLE IF NOT EXISTS dropin_tider (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ukedag     TINYINT UNSIGNED NOT NULL COMMENT '1 = mandag, 7 = sondag (ISO)',
  fra        TIME NOT NULL COMMENT 'Norsk tid',
  til        TIME NOT NULL COMMENT 'Norsk tid',
  kapasitet  SMALLINT UNSIGNED NULL COMMENT 'NULL = arv fra drop-in-kurset',
  aktiv      TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_dropin_tid (ukedag, fra),
  KEY ix_dropin_aktiv (aktiv, ukedag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Merke paa oktene som er laget av en aapningstid, saa de kan ryddes og
-- lages om igjen uten aa roere okter som er lagt inn for haand.
ALTER TABLE course_sessions
  ADD COLUMN IF NOT EXISTS fra_dropin_tid BIGINT UNSIGNED NULL
    COMMENT 'Laget av denne aapningstida. NULL = lagt inn manuelt.'
    AFTER manuelt_opptatt;
