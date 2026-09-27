-- Galleriet paa forsida: medlemmenes egne bilder fra verkstedet.
--
-- Eieren, 27. september 2026: «paa forsiden paa lissom.no saa finner man
-- butikken, kan denne gjoeres om til galleri? […] bildene er av medlemmenes
-- egne opplastninger de har gjort paa min side, admin maa godkjenne og jeg
-- vil admin kan bruke bildeforbereder for bakgrunn etc. kan denne kombineres
-- med dette med sosiale medier? slik at vi kan velge aa legge ut paa some og
-- paa vaart galleri». Skissen fikk GO samme dag. Se app/lib/galleri.php.
--
-- Bildene er medlemsforslagene (migrasjon 207). Et forslag kan naa godkjennes
-- til Instagram, til galleriet, eller begge. «galleri» er statusen til et
-- forslag som bare ble godkjent til galleriet: fila er da offentlig (se
-- api/bilde.php), men ingenting er lagt ut paa Instagram.

ALTER TABLE medlemsforslag
  MODIFY status ENUM('venter','godkjent','publisert','avvist','galleri') NOT NULL DEFAULT 'venter';

ALTER TABLE medlemsforslag
  ADD COLUMN IF NOT EXISTS galleri TINYINT(1) NOT NULL DEFAULT 0
    COMMENT '1 = skal vises i galleriet paa forsida',
  -- Naar bildet foerst ble vist. Etter en maaned viker det for et bilde som
  -- venter paa plass (eieren, 27. september 2026). NULL = venter paa plass.
  ADD COLUMN IF NOT EXISTS galleri_vist_fra DATETIME NULL,
  ADD COLUMN IF NOT EXISTS galleri_godkjent_at DATETIME NULL,
  -- «Forbedre bildet» bytter fila. Originalen til medlemmet blir staaende.
  ADD COLUMN IF NOT EXISTS fil_original VARCHAR(64) NULL,
  ADD KEY IF NOT EXISTS ix_medlemsforslag_galleri (galleri, galleri_vist_fra);
