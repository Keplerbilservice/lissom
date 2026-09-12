-- «Ovn er tømt».
--
-- Eieren, 12. september 2026: «mine medlemmer ønsker på min side en knapp
-- som heter ovn er tømt, da må de andre medlemmene se at denne er tømt og
-- gjerne med en pulserende vinsing. admin må også ha denne knappen på
-- kalender oversikten.» Hvert medlem kvitterer selv med «Sett», og alt
-- forsvinner av seg selv etter 24 timer.
--
-- To tabeller: hvem som toemte naar, og hvem som har sett det. Den som
-- toemte staar som sett med det samme. Eldre rader enn et doegn leses aldri,
-- men slettes heller ikke — de er loggen over naar ovnen ble toemt.

CREATE TABLE IF NOT EXISTS ovn_tomt (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id   BIGINT UNSIGNED NULL,
  navn        VARCHAR(191) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ovn_tomt_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Naar ovnen ble toemt, og av hvem';

CREATE TABLE IF NOT EXISTS ovn_tomt_sett (
  tomt_id     BIGINT UNSIGNED NOT NULL,
  member_id   BIGINT UNSIGNED NOT NULL,
  sett_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (tomt_id, member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Hvem som har trykket «Sett» paa en toemming';
