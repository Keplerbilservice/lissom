-- Fraktsatser og bestillingsrutine per leverandoer.
--
-- Eieren, 24. september 2026: «legg inn dette på handleliste opplegget vårt,
-- her er infoen med fraktpriser og bestillingsrutiner som styres fra admin»:
--   «fra WE Waldemar Ellefsen så er det inntil 100 kg kr 850, inntil 200 kg
--   kr 1000 og inntil 400 kg kr 1150 — lokalt fra Scan Form eller Cerama er
--   det inntil 100 kg kr 350, inntil 200 kg kr 500 og inntil 400 kg kr 700».
--
-- Satsene er en liste i JSON: [{"kg":100,"ore":85000}, ...], sortert paa kg.
-- Admin endrer dem under Nettbutikk → Handlelister → Leverandører, og velger
-- en sats naar frakten for bestillingen settes (frakt_ore fra migrasjon 208).
-- Medlemmene ser satsene og rutinen paa Min side.

ALTER TABLE leverandorer
    ADD COLUMN IF NOT EXISTS frakt_satser TEXT NULL COMMENT 'Fraktsatser i JSON: [{kg, ore}], sortert paa kg',
    ADD COLUMN IF NOT EXISTS bestillingsrutine TEXT NULL COMMENT 'Hvordan og naar det bestilles. Vises paa Min side.';

UPDATE leverandorer
   SET frakt_satser = '[{"kg":100,"ore":85000},{"kg":200,"ore":100000},{"kg":400,"ore":115000}]'
 WHERE navn = 'Waldemar Ellefsen' AND frakt_satser IS NULL;

UPDATE leverandorer
   SET frakt_satser = '[{"kg":100,"ore":35000},{"kg":200,"ore":50000},{"kg":400,"ore":70000}]'
 WHERE (navn = 'Cerama' OR REPLACE(REPLACE(LOWER(navn), '-', ''), ' ', '') = 'scanform')
   AND frakt_satser IS NULL;
