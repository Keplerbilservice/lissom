-- Salgsuka blir en generell salgskampanje.
--
-- Eieren, 13. september 2026: «Jeg vil ogsaa at salgsuke banneret skal vaere
-- et generelt salgs kampanje. Her vil jeg legge til og redigere bilde og
-- tekster og mulighet for aa vise pris / De kan godt lagres som maler saa har
-- vi». Han valgte «GO — bygg alt» etter aa ha sett forslaget.
--
-- Banneret hadde tre felt i content_blocks — tittel, periode og tekst — og
-- merkelappen sto fast som «Salgsuke». Naa er hver kampanje en rad her, med
-- bilde, fri merkelapp, valgfri pris og egen knappetekst. Den som staar paa
-- forsiden speiles inn i content_blocks under «Kampanje/», fordi det er den
-- eneste tabellen den besoekende faar lese — se api/innhold.php.
--
-- Prisen staar i oere, som alle andre beloep i basen. Ingen pris er NULL, og
-- da vises det ingen pris.

CREATE TABLE IF NOT EXISTS kampanjer (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  navn        VARCHAR(191) NOT NULL,
  merke       VARCHAR(191) NULL,
  tittel      VARCHAR(191) NOT NULL,
  tekst       TEXT NULL,
  pris_ore    INT UNSIGNED NULL,
  bilde       VARCHAR(255) NULL,
  knapp       VARCHAR(191) NULL,
  maal        VARCHAR(32) NOT NULL DEFAULT 'butikk',
  sist_brukt  DATETIME NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY kampanjer_brukt (sist_brukt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Salgskampanjene paa forsiden. Den aktive speiles til content_blocks.';

-- Salgsuka som staar der i dag flyttes inn som foerste kampanje, med de
-- tekstene eieren alt har skrevet. Uten dette ville banneret staatt tomt til
-- noen hadde skrevet alt paa nytt.
INSERT INTO kampanjer (id, navn, merke, tittel, tekst, knapp, maal, sist_brukt)
SELECT 1,
       'Medlemmenes salgsuke',
       CONCAT('Salgsuke',
              CASE WHEN COALESCE((SELECT verdi FROM content_blocks WHERE nokkel = 'Salgsuke/periode'), '') <> ''
                   THEN CONCAT(' · ', (SELECT verdi FROM content_blocks WHERE nokkel = 'Salgsuke/periode'))
                   ELSE '' END),
       COALESCE((SELECT verdi FROM content_blocks WHERE nokkel = 'Salgsuke/tittel'), 'Medlemmenes salgsuke'),
       COALESCE((SELECT verdi FROM content_blocks WHERE nokkel = 'Salgsuke/tekst'),
                'I én uke selger vi medlemmenes håndlagde keramikk sammen i nettbutikken. Alt er laget i verkstedet på Teie.'),
       'Se medlemmenes keramikk',
       'butikk',
       NOW()
WHERE NOT EXISTS (SELECT 1 FROM (SELECT id FROM kampanjer) k WHERE k.id = 1);

-- Og speiles ut dit forsiden leser fra, saa banneret ser likt ut foer og
-- etter oppdateringa.
INSERT INTO content_blocks (nokkel, verdi)
SELECT 'Kampanje/aktiv', '1' WHERE EXISTS (SELECT 1 FROM (SELECT id FROM kampanjer) k WHERE k.id = 1)
ON DUPLICATE KEY UPDATE verdi = verdi;

INSERT INTO content_blocks (nokkel, verdi)
SELECT 'Kampanje/merke', merke FROM kampanjer WHERE id = 1
ON DUPLICATE KEY UPDATE verdi = verdi;

INSERT INTO content_blocks (nokkel, verdi)
SELECT 'Kampanje/tittel', tittel FROM kampanjer WHERE id = 1
ON DUPLICATE KEY UPDATE verdi = verdi;

INSERT INTO content_blocks (nokkel, verdi)
SELECT 'Kampanje/tekst', COALESCE(tekst, '') FROM kampanjer WHERE id = 1
ON DUPLICATE KEY UPDATE verdi = verdi;

INSERT INTO content_blocks (nokkel, verdi)
SELECT 'Kampanje/knapp', COALESCE(knapp, '') FROM kampanjer WHERE id = 1
ON DUPLICATE KEY UPDATE verdi = verdi;
