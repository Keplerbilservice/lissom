-- Frakt fra Pakke-Express paa handlelista og samlebestillingen.
-- godkjent av eieren: 2026-09-30
--
-- Eieren, 30. september 2026: fraktprisene fra Pakke-Express (tilbud datert
-- 24.09.2026) er «til bruk på handlelisten innkjøp». Frakten deles paa
-- medlemmene som har varer i bestillingen, etter vekt: «ta vekt pr vare».
-- Admin kan rette totalvekten foer bestillingen sendes.
--
-- Prisene per sending, i oere, per vektklasse og sone. Den siste klassen
-- staar som «101–400 kg» i tilbudet; den er lest som 201–400 kg.
-- Energitillegget (9,5 %) foelger drivstoffprisene og endres av admin.
-- «Bud og ekspress: 20 kr per km» lagres, men brukes ikke automatisk.
--
-- Ingenting av dette staar i koden. Admin endrer det under Nettbutikk ›
-- Handlelister › «Frakt (Pakke-Express)».

ALTER TABLE products
    ADD COLUMN IF NOT EXISTS vekt_g INT NULL DEFAULT NULL COMMENT 'Vekt per stk i gram, for frakten. NULL = ukjent';

ALTER TABLE handleliste_linjer
    ADD COLUMN IF NOT EXISTS vekt_g INT NULL DEFAULT NULL COMMENT 'Vekt per stk i gram satt paa linja (oensker uten vare). NULL = fra varen';

ALTER TABLE leverandorer
    ADD COLUMN IF NOT EXISTS frakt_sone_standard VARCHAR(32) NULL DEFAULT NULL COMMENT 'Standard sone hos Pakke-Express. NULL = ikke Pakke-Express',
    ADD COLUMN IF NOT EXISTS frakt_sone VARCHAR(32) NULL DEFAULT NULL COMMENT 'Sonen for bestillingen som ligger naa. NULL = standard',
    ADD COLUMN IF NOT EXISTS frakt_vekt_g INT NULL DEFAULT NULL COMMENT 'Totalvekt rettet av admin for bestillingen. NULL = summen av linjene';

INSERT INTO innstillinger (nokkel, verdi) VALUES
('frakt_pakke_express', '{"soner":[{"kode":"tonsberg","navn":"Tønsberg og omegn"},{"kode":"oslo","navn":"Oslo/Bærum"}],"klasser":[{"fraKg":0,"tilKg":3,"ore":{"tonsberg":13000,"oslo":27500}},{"fraKg":4,"tilKg":10,"ore":{"tonsberg":16000,"oslo":32500}},{"fraKg":11,"tilKg":30,"ore":{"tonsberg":22500,"oslo":42500}},{"fraKg":31,"tilKg":100,"ore":{"tonsberg":35000,"oslo":85000}},{"fraKg":101,"tilKg":200,"ore":{"tonsberg":50000,"oslo":100000}},{"fraKg":201,"tilKg":400,"ore":{"tonsberg":85000,"oslo":115000}}],"energiProsent":9.5,"budKrPerKm":20}')
ON DUPLICATE KEY UPDATE nokkel = nokkel;

-- Standard sone: Waldemar Ellefsen sender fra Oslo, Cerama og Scan-Form
-- lokalt (se migrasjon 210, der de samme leverandoerene fikk satser).
UPDATE leverandorer SET frakt_sone_standard = 'oslo'
 WHERE navn = 'Waldemar Ellefsen' AND frakt_sone_standard IS NULL;
UPDATE leverandorer SET frakt_sone_standard = 'tonsberg'
 WHERE (navn = 'Cerama' OR REPLACE(REPLACE(LOWER(navn), '-', ''), ' ', '') = 'scanform')
   AND frakt_sone_standard IS NULL;
