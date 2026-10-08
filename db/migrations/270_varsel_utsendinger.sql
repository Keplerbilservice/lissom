-- Utsendingsnøkler for «Ny dato på kurset» fra ny admin (okt-varsel.php
-- handling=flyttet). Codex runde 2, 9. oktober 2026: et nytt trykk, en annen
-- fane eller et nytt forsøk etter en feil skal ikke gi samme beskjed to ganger.
--
-- Én rad per påmelding + ny dato + kanal («flyttet:<booking>:<start_tid>:epost»).
-- Raden legges inn i samme transaksjon som meldingen legges i køen; finnes
-- den, er beskjeden om akkurat den datoen alt gitt.
--
-- Bare tillegg; tåler å kjøres to ganger. Koden tåler at den ikke er kjørt
-- (da sendes som før, men atomisk og låst per dato).

CREATE TABLE IF NOT EXISTS varsel_utsendinger (
  nokkel     VARCHAR(191) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (nokkel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
