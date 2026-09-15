-- Dugnad: medlemmer jobber i verkstedet og faar tida lagt til timene sine.
--
-- Eieren, 15. september 2026: «man kan stemple inn som dugnad f eks om man
-- vil vaske verkstedet ... denne tiden maa du legge til paa medlemmenes
-- timer, og de maa paa en maate se at det er lagt til tid». Og:
-- «Medlemmene maa foresporre og faa godkjenning av Monica for de faar
-- stemplet inn, de maa gjerne skrive hva de vil jobbe med i korte trekk.»
-- Tida godkjennes ogsaa etterpaa («skal godkjennes»), og rundes til
-- naermeste kvarter.
--
-- Én rad per dugnad, fra foresporsel til godkjent tid:
--   venter          medlemmet har spurt, verkstedet har ikke svart
--   avslatt         verkstedet sa nei
--   godkjent        medlemmet kan stemple inn dugnad
--   pagar           innstemplet naa (inn_tid satt, ut_tid tom)
--   til_godkjenning stemplet ut; verkstedet ser over jobben og tida
--   ferdig          tida er godkjent og lagt til (godkjent_minutter)
--   avvist          verkstedet godkjente ikke tida
--
-- Timene: Medlemskap::timerMedGaver() legger godkjente dugnadsminutter til
-- taket for maaneden — se Dugnad::minutterTilgode(). Ubrukte dugnadstimer
-- tas med til neste maaned naar bryteren Vis/dugnadoverforing staar paa.

CREATE TABLE IF NOT EXISTS dugnad (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  member_id          BIGINT UNSIGNED NOT NULL,
  tekst              VARCHAR(500) NOT NULL COMMENT 'Hva medlemmet vil gjoere',
  status             ENUM('venter','avslatt','godkjent','pagar','til_godkjenning','ferdig','avvist') NOT NULL DEFAULT 'venter',
  svar               VARCHAR(500) NULL COMMENT 'Verkstedets svar til medlemmet',
  svart_av           BIGINT UNSIGNED NULL,
  svart_at           DATETIME NULL,
  inn_tid            DATETIME NULL,
  ut_tid             DATETIME NULL,
  minutter           INT UNSIGNED NULL COMMENT 'Stemplet tid, regnet ved utstempling',
  godkjent_minutter  INT UNSIGNED NULL COMMENT 'Tida som ble lagt til, rundet til kvarter',
  godkjent_av        BIGINT UNSIGNED NULL,
  godkjent_at        DATETIME NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_dugnad_medlem (member_id, status),
  KEY ix_dugnad_status (status, created_at),
  KEY ix_dugnad_godkjent (member_id, godkjent_at),
  CONSTRAINT fk_dugnad_medlem FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bryteren staar AV til verkstedet slaar den paa (⊙ Synlighet → Paa Min
-- side → Dugnad). En bryter som mangler i basen staar paa — derfor raden.
INSERT INTO content_blocks (nokkel, verdi)
SELECT 'Vis/dugnad', 'nei' FROM (SELECT 1) AS d
 WHERE NOT EXISTS (SELECT 1 FROM content_blocks WHERE nokkel = 'Vis/dugnad');
