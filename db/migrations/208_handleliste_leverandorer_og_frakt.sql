-- Handlelista: leverandoer, artikkelnummer og varenavn i egne felt, og frakt.
--
-- Eieren, 24. september 2026: «handleliste må også være bedre, egne felt,
-- antall, artikkelnummer, varenavn. og gjerne fra hvem av de
-- forhåndsdefinerte butikkene […] gjerne med et søkefelt rett i nettbutikken
-- til de man velger. det må komme frem at det er et administrasjongebyr […]
-- pluss andel av frakt tilkommer». Og: «WE er Waldemar Ellefsen, og Cerama et
-- eget, så det er 3 steder», «admin kan velge hvem leverandør som skal vises,
-- nå kan du skru av cerama».
--
-- Frakten: eieren valgte «fraktregning delt» — admin skriver inn hva frakten
-- kostet for bestillingen hos én leverandoer, og den deles likt paa
-- medlemmene som har varer hos den leverandoeren i kravet.

-- Leverandoeren medlemmet valgte, og artikkelnummeret det skrev. En vare fra
-- lista (product_id) har begge deler fra products; da staar disse tomme.
ALTER TABLE handleliste_linjer
    ADD COLUMN IF NOT EXISTS leverandor_id BIGINT UNSIGNED NULL COMMENT 'Leverandoeren medlemmet valgte (linjer uten vare)' AFTER tekst,
    ADD COLUMN IF NOT EXISTS artikkelnr VARCHAR(64) NOT NULL DEFAULT '' COMMENT 'Artikkelnummeret medlemmet skrev (linjer uten vare)' AFTER leverandor_id;

-- Hvilke leverandoerer medlemmene ser, soeket i nettbutikken deres, og
-- frakten for bestillingen som samles naa. {q} byttes med soekeordet.
ALTER TABLE leverandorer
    ADD COLUMN IF NOT EXISTS vis_medlemmer TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Kan velges i handlelista paa Min side',
    ADD COLUMN IF NOT EXISTS sok_url VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Soek i nettbutikken; {q} = soekeordet',
    ADD COLUMN IF NOT EXISTS frakt_ore INT UNSIGNED NULL COMMENT 'Frakt for bestillingen som samles naa. Nullstilles naar den er bestilt.';

-- Scan Form. Eieren kan ha lagt den inn selv som «Scan-Form» (15. september),
-- saa den legges bare til naar ingen med det navnet finnes.
INSERT INTO leverandorer (navn, epost)
SELECT 'Scan Form', 'info@scan-form.no' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM leverandorer
                    WHERE REPLACE(REPLACE(LOWER(navn), '-', ''), ' ', '') = 'scanform');

-- Soekeadressene, maalt 24. september 2026 ved aa soeke paa sidene selv.
UPDATE leverandorer SET sok_url = 'https://www.we.no/search?action=search&q={q}', vis_medlemmer = 1, aktiv = 1
 WHERE navn = 'Waldemar Ellefsen';
UPDATE leverandorer SET sok_url = 'https://cerama.no/Default.aspx?ID=4069&q={q}', vis_medlemmer = 0
 WHERE navn = 'Cerama';
UPDATE leverandorer SET sok_url = 'https://www.scan-form.no/sok?q={q}', vis_medlemmer = 1, aktiv = 1
 WHERE REPLACE(REPLACE(LOWER(navn), '-', ''), ' ', '') = 'scanform';
