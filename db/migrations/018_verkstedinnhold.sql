-- Oppskrifter, artikler og lenker.
--
-- Tre skjermer i admin som saa ferdige ut, men bare holdt paa det som ble
-- skrevet til siden ble lastet paa nytt. Oppskriftene forsvant, artiklene
-- under Nyttig info kom aldri ut paa nettsiden, og lenkelista var én fast
-- lenke ingen kunne endre.
--
-- SEO trenger ingen tabell — de tekstene ligger i content_blocks, som resten
-- av det eieren kan redigere.

-- Glasur- og engobeoppskrifter. Bare for verkstedet, ikke offentlig.
CREATE TABLE IF NOT EXISTS recipes (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  navn       VARCHAR(191) NOT NULL,
  type       VARCHAR(64) NOT NULL DEFAULT 'Glasur' COMMENT 'Glasur eller Engobe',
  temperatur VARCHAR(64) NULL COMMENT 'F.eks. «Blank glasur · 1240 °C»',
  -- Raavarene som JSON: [["Kvarts", 25], ["Feltspat", 30], ...].
  -- Rekkefolgen betyr noe i en oppskrift, og en egen linjetabell ville
  -- kostet mer enn den ga her.
  raavarer   JSON NOT NULL,
  notat      TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recipes_navn (navn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Artiklene under Nyttig info.
CREATE TABLE IF NOT EXISTS articles (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tittel     VARCHAR(191) NOT NULL,
  dato       VARCHAR(64) NULL,
  ingress    TEXT NULL,
  bilde      VARCHAR(255) NULL,
  innhold    MEDIUMTEXT NULL,
  status     ENUM('kladd','publisert') NOT NULL DEFAULT 'publisert',
  sortering  INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_articles_tittel (tittel),
  KEY ix_articles_status (status, sortering)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nyttige lenker ut av nettsiden.
CREATE TABLE IF NOT EXISTS links (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  navn       VARCHAR(191) NOT NULL,
  url        VARCHAR(500) NOT NULL,
  om         VARCHAR(500) NULL,
  sortering  INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_links_url (url)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Lenka som laa fast i fila, saa den ikke forsvinner naar lista blir ekte.
INSERT IGNORE INTO links (navn, url, om) VALUES
('Mayco Glasurkombinasjoner', 'https://www.maycocolors.com/glaze-combinations/',
 'Ferdige glasurkombinasjoner med bilder og cone-angivelser.');
