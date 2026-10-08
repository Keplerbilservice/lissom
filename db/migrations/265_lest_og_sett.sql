-- Lest i medlemschatten og «sett» på påmeldinger, på serveren (eieren,
-- 8. oktober 2026, «Ok, bygg det» på idé 1 og 2 i forbedringsvisningen).
--
-- 1. chat_lest: hvor langt hver admin har lest i medlemschatten (høyeste
--    meldings-id). Meldinger-flisen på I dag teller det som er nyere.
-- 2. pamelding_sett: «Sett som sett» på en ny påmelding. Lå før bare i
--    nettleseren, så den gjaldt ikke på mobil når den ble trykket på PC.
--
-- Bare nye tabeller. Kan kjøres flere ganger.

CREATE TABLE IF NOT EXISTS chat_lest (
    member_id     BIGINT UNSIGNED NOT NULL,
    sist_lest_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    oppdatert_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (member_id),
    CONSTRAINT fk_chat_lest_member FOREIGN KEY (member_id)
        REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pamelding_sett (
    booking_id  BIGINT UNSIGNED NOT NULL,
    sett_av     BIGINT UNSIGNED NULL,
    sett_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (booking_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
