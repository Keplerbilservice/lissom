-- Gavekort kunne kjopes, men ikke brukes.
--
-- Feltet «Gavekort eller rabattkode» i kassa var ikke koblet til noe. Det
-- fantes ikke noe endepunkt som tok imot en kode, og saldo_ore ble aldri
-- trukket ned — den ble satt til hele beloepet ved aktivering og stod der.
-- Tabellen gift_card_uses, som skulle vaere sporet, har staatt tom siden
-- 001_init. En kunde kunne altsaa betale for et gavekort og aldri faa brukt
-- det.
--
-- Beloepet som skal trekkes, legges paa betalingen naar ordren opprettes, og
-- trekkes forst naar betalingen er bekreftet. Ellers ville en handlekurv som
-- ble forlatt i Vipps spist opp saldoen.

ALTER TABLE payments
  ADD COLUMN gavekort_id BIGINT UNSIGNED NULL
      COMMENT 'Gavekortet som dekker deler av eller hele beloepet'
      AFTER member_id,
  ADD COLUMN gavekort_ore INT UNSIGNED NOT NULL DEFAULT 0
      COMMENT 'Hvor mye av kjopet gavekortet dekker'
      AFTER gavekort_id;

ALTER TABLE payments
  ADD KEY ix_payments_gavekort (gavekort_id);
