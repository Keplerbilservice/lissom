-- Paint on Pots: gjenstandene i prisnivåene kan kobles til butikkvarer, og
-- kassa trekker lageret for dem (eieren, ja 8. oktober 2026, etter
-- opus-kontrollen).
--
-- Koblingen er frivillig: hver gjenstand i et nivå (teksten i
-- pop_prisnivaer.gjenstander, skilt med komma) kan peke på én vare i
-- products. En gjenstand uten kobling trekker ingenting.
--
-- På linjene i kassa står hvilken gjenstand det var, hvilken vare den var
-- koblet til, og hvor mange som faktisk ble trukket fra lageret (trukket).
-- Det er det tallet som legges tilbake når gjenstander tas bort i kassa
-- eller plassen avbestilles i admin — aldri mer enn det som gikk ut.
--
-- Migrasjonen rører ingen varer, bookinger eller linjer som finnes.

CREATE TABLE IF NOT EXISTS pop_gjenstand_vare (
  nivaa_id   INT UNSIGNED    NOT NULL,
  gjenstand  VARCHAR(100)    NOT NULL,
  produkt_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (nivaa_id, gjenstand)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE pop_kasselinjer
  ADD COLUMN IF NOT EXISTS gjenstand VARCHAR(100) NULL
      COMMENT 'Gjenstanden i nivået. NULL = bare nivået (linjer fra før migrasjon 262).',
  ADD COLUMN IF NOT EXISTS produkt_id BIGINT UNSIGNED NULL
      COMMENT 'Butikkvaren gjenstanden var koblet til da den ble slått inn.',
  ADD COLUMN IF NOT EXISTS trukket SMALLINT UNSIGNED NOT NULL DEFAULT 0
      COMMENT 'Antall trukket fra products.lager for denne linja.';
