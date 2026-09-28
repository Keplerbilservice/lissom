-- godkjent av eieren: 2026-09-28
--
-- Timepakken, og svarene etter «Ikke nå».
--
-- Eieren, 28. september 2026: en timepakke gir 6 timer for kr 800 (pris og
-- timer settes i admin, under Medlemskap). Den kan bare kjoepes naar
-- maanedens timer er brukt opp, og bare paa et vanlig medlemskap — ikke Prøv
-- Lissom. Timene gaar aldri ut, foelger med til neste maaned og brukes etter
-- maanedens timer. Timer alt stemplet over, trekkes fra pakken naar den
-- kjoepes. Betales med Vipps som et medlemskap. Se app/lib/timepakke.php.

INSERT INTO innstillinger (nokkel, verdi) VALUES
  ('timepakke_timer', '6'),
  ('timepakke_pris_ore', '80000')
ON DUPLICATE KEY UPDATE nokkel = nokkel;

-- Én rad per kjoep. «venter» til Vipps har svart, «betalt» naar pengene er i
-- havn. Timetall og pris staar paa raden slik de var da pakken ble kjoept.
CREATE TABLE IF NOT EXISTS timepakker (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id   BIGINT UNSIGNED NOT NULL,
  timer       INT UNSIGNED NOT NULL,
  pris_ore    INT UNSIGNED NOT NULL,
  status      ENUM('venter','betalt','avbrutt') NOT NULL DEFAULT 'venter',
  payment_id  BIGINT UNSIGNED NULL,
  betalt_at   DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_timepakke_betaling (payment_id),
  KEY ix_timepakke_medlem (member_id, status),
  CONSTRAINT fk_timepakke_medlem FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Pakketimene brukt i en maaned som er over. Skrives av cron én gang per
-- medlem og maaned (Timepakke::lukkMaaneder). Denne maanedens bruk regnes av
-- det som er stemplet, og staar ikke her.
CREATE TABLE IF NOT EXISTS timepakke_bruk (
  member_id   BIGINT UNSIGNED NOT NULL,
  maaned      CHAR(7) NOT NULL COMMENT 'AAAA-MM, norsk kalender',
  minutter    INT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (member_id, maaned),
  CONSTRAINT fk_timepakke_bruk_medlem FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Svarene i vindu 4 («Hva gjør at du venter?»), etter «Ikke nå».
CREATE TABLE IF NOT EXISTS timer_svar (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id   BIGINT UNSIGNED NOT NULL,
  grunn       VARCHAR(40) NULL,
  fritekst    TEXT NULL,
  vindu       ENUM('vanlig','prove') NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_timer_svar_medlem (member_id),
  CONSTRAINT fk_timer_svar_medlem FOREIGN KEY (member_id) REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
