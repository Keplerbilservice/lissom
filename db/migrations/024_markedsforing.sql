-- Markedsforing: kunnskapsbank, AI-utkast, sokeord og kostnadslogg.
--
-- SEO-skjermen blir Markedsforing, og vokser fra ett sokeordskjema til en
-- modul som ogsaa dekker artikler, nyhetsbrev, sosiale medier og
-- kursmarkedsforing. Alt AI-en lager havner som utkast her — ingenting gaar
-- ut for eieren har lest det og trykket publiser.

-- Artiklene finnes fra for (Nyttig info). De trenger fire felter til for aa
-- kunne staa som nyheter og guider med egen adresse og kategori.
ALTER TABLE articles
  ADD COLUMN IF NOT EXISTS kategori   VARCHAR(64)  NULL AFTER tittel,
  ADD COLUMN IF NOT EXISTS slug       VARCHAR(191) NULL AFTER kategori,
  ADD COLUMN IF NOT EXISTS fokus_ord  VARCHAR(191) NULL AFTER slug,
  -- «manuell» eller «ai» — saa eieren ser hva som er skrevet av hvem.
  ADD COLUMN IF NOT EXISTS kilde      VARCHAR(16)  NOT NULL DEFAULT 'manuell' AFTER innhold;

-- To artikler kan ikke dele adresse. NULL er unntatt fra UNIQUE i MariaDB,
-- saa gamle artikler uten slug staar fint til de faar en.
CREATE UNIQUE INDEX IF NOT EXISTS uq_articles_slug ON articles (slug);

-- Alt AI-en foreslaar. Ett bord for alle typer; «type» sier hva det er, og
-- «data» baerer det som er saeregent for hver type (kanal, hashtags, kurs-id).
CREATE TABLE IF NOT EXISTS ai_utkast (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type        ENUM('artikkel','nyhetsbrev','sosialt','seo','kursboost','medlemsbrev') NOT NULL,
  tittel      VARCHAR(191) NOT NULL,
  tekst       MEDIUMTEXT NULL,
  data        JSON NULL COMMENT 'Kanal, hashtags, kurs-id — det som varierer med typen',
  -- Hva utkastet gjelder, saa eieren ser sammenhengen: «Dreiekurs september».
  kontekst    VARCHAR(191) NULL,
  status      ENUM('utkast','godkjent','publisert','forkastet') NOT NULL DEFAULT 'utkast',
  -- Peker til artikkelen, nyhetsbrevet eller innlegget som ble laget av det.
  resultat_id BIGINT UNSIGNED NULL,
  kostnad_ore INT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_ai_utkast_type (type, status),
  KEY ix_ai_utkast_tid (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hvert kall til modellen, med tokens og anslaatt kostnad.
--
-- Uten dette er AI-bruk en regning som kommer i posten uten forklaring. Med
-- det ser eieren hva som ble brukt, paa hva, og kan sette et tak.
CREATE TABLE IF NOT EXISTS ai_logg (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  formal      VARCHAR(64) NOT NULL,
  modell      VARCHAR(64) NOT NULL,
  tokens_inn  INT UNSIGNED NOT NULL DEFAULT 0,
  tokens_ut   INT UNSIGNED NOT NULL DEFAULT 0,
  kostnad_ore INT UNSIGNED NOT NULL DEFAULT 0,
  ok          TINYINT(1) NOT NULL DEFAULT 1,
  feil        VARCHAR(500) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_ai_logg_tid (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sokeordene verkstedet vil bli funnet paa.
CREATE TABLE IF NOT EXISTS marked_sokeord (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ord         VARCHAR(191) NOT NULL,
  -- Hvilken side som skal svare paa soket. Tom betyr «ikke bestemt ennaa».
  maalside    VARCHAR(64) NULL,
  notat       VARCHAR(500) NULL,
  sortering   INT NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sokeord (ord)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sokeordene fra SEO-arbeidet, som utgangspunkt.
INSERT IGNORE INTO marked_sokeord (ord, maalside, sortering) VALUES
  ('dreiekurs Tønsberg',            'kurs',       10),
  ('keramikkurs Vestfold',          'kurs',       20),
  ('male keramikk Tønsberg',        'paintonpots', 30),
  ('kreative aktiviteter Vestfold', 'forside',    40),
  ('keramikkverksted Nøtterøy',     'omoss',      50),
  ('drop-in keramikk Tønsberg',     'dropin',     60),
  ('medlemskap keramikkverksted',   'medlemskap', 70),
  ('gavekort keramikkurs',          'gavekort',   80);

-- Artikler som laa der fra for har ingen adresse. Uten en kan de ikke lenkes
-- til fra Nyheter. Lager en av tittelen: smaa bokstaver, bindestrek, uten
-- ae/oe/aa. Kjores bare paa rader som mangler den.
UPDATE articles
   SET slug = TRIM(BOTH '-' FROM
         REGEXP_REPLACE(
           LOWER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(tittel,
             'æ','ae'),'ø','o'),'å','a'),'Æ','ae'),'Ø','o'),'Å','a')),
           '[^a-z0-9]+', '-'))
 WHERE slug IS NULL OR slug = '';
