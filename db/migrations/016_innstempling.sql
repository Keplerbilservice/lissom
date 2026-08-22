-- Innstempling i verkstedet.
--
-- Medlemskapet gir et antall timer i maaneden. Uten et sted aa fore naar folk
-- er der, kan ingen vite hvor mange timer som er brukt — og Min side maatte
-- late som. Den lot som «11,5 av 30 timer» til hvert eneste medlem.
--
-- En rad per okt. inn_tid settes ved innstempling, ut_tid og minutter ved
-- utstempling. Er ut_tid NULL, staar medlemmet innstemplet naa.
--
-- Tidspunkt lagres i UTC, som resten av basen. Maanedsgrensene regnes om til
-- norsk tid der de brukes.

CREATE TABLE IF NOT EXISTS check_ins (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id  BIGINT UNSIGNED NOT NULL,
  inn_tid    DATETIME NOT NULL,
  ut_tid     DATETIME NULL COMMENT 'NULL = staar innstemplet naa',
  minutter   INT UNSIGNED NULL COMMENT 'Regnet ut ved utstempling',
  auto_lukket TINYINT(1) NOT NULL DEFAULT 0
             COMMENT 'Glemt utstempling, lukket av systemet',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_check_medlem (member_id, inn_tid),
  KEY ix_check_apen (ut_tid, inn_tid),
  CONSTRAINT fk_check_medlem FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Om navnet skal vises for de andre medlemmene mens man er der.
-- Innstemplinga registreres uansett — den trekker timer fra abonnementet.
ALTER TABLE members
  ADD COLUMN IF NOT EXISTS vis_innstempling TINYINT(1) NOT NULL DEFAULT 1
    COMMENT 'Vis navnet i «I verkstedet naa». Timene telles uansett.'
    AFTER timer_per_mnd;
