-- Utsendingsnøkler fra ny admin (okt-varsel.php) og cron (Paaminnelse).
-- Codex runde 2 og kontrolløren runde 3, 9. oktober 2026: et nytt trykk, en
-- annen fane, cron eller et nytt forsøk etter en feil skal ikke gi samme
-- beskjed to ganger.
--
-- Én rad per beskjed som er gitt. Raden legges inn i samme transaksjon som
-- meldingen legges i køen; finnes den, er beskjeden alt gitt.
--
--   «Ny dato på kurset»:  flyttet:<booking>:<fra>:<til>:<kanal>
--                         Uten denne tabellen svarer «Ny dato» 503
--                         («Kjør oppdateringene først») og sender ingenting.
--   Påminnelsen:          paaminnelse:<booking>:<økt>
--                         Per påmelding og økt: den som meldte seg på etter
--                         en påminnelse for hånd, får cron sin, og en
--                         påmelding som flyttes til en ny økt, får den for
--                         den nye datoen. Uten tabellen tas hele økta med
--                         paaminnelse_sendt_at, som før.
--
-- Utfylling: påminnelser sendt før denne migrasjonen står bare som
-- paaminnelse_sendt_at på økta. Hver påmelding som fantes da (opprettet før
-- sendingen), får sin nøkkel her, så cron ikke sender dem én til. Økter som
-- alt har påminnelsesnøkler, røres ikke. Koden har samme sikkerhetsnett
-- (Paaminnelse::mottakere og fyllEldre) i tilfelle noe kommer til etterpå.
--
-- Bare tillegg; tåler å kjøres to ganger (IF NOT EXISTS, INSERT IGNORE).

CREATE TABLE IF NOT EXISTS varsel_utsendinger (
  nokkel     VARCHAR(191) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (nokkel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO varsel_utsendinger (nokkel, created_at)
SELECT CONCAT('paaminnelse:', b.id, ':', cs.id), cs.paaminnelse_sendt_at
  FROM bookings b
  JOIN course_sessions cs ON cs.id = b.course_session_id
 WHERE cs.paaminnelse_sendt_at IS NOT NULL
   AND b.created_at <= cs.paaminnelse_sendt_at
   AND NOT EXISTS (SELECT 1 FROM varsel_utsendinger v
                    WHERE v.nokkel LIKE CONCAT('paaminnelse:%:', cs.id));
