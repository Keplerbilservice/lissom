-- Ett salgsprodukt kan vise flere bilder med egen bildetekst.
-- Det opprinnelige bilde-feltet beholdes som forsidebilde.
ALTER TABLE member_sales
  ADD COLUMN IF NOT EXISTS bilder_json LONGTEXT NULL
    COMMENT 'Ordnet liste med bildefil og bildetekst';
