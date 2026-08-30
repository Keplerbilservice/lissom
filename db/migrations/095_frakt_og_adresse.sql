-- Frakt som faktisk kreves inn, og en adresse aa sende til.
--
-- Kassa viste «Inkludert frakt kr. 89,-» og la 89 kroner til i totalen paa
-- skjermen. Belopet gikk aldri til serveren: api/ordre.php regner summen av
-- varene alene, og valget «Send som pakke» fulgte ikke med i det hele tatt.
-- Bestilte noen med sending, betalte verkstedet portoen selv — og ingen fikk
-- vite hvor pakken skulle.
--
-- Tallet 89 sto dessuten skrevet inn fire steder i koden. Naa staar det ett
-- sted, i basen, og eieren kan endre det selv. Eieren 30. august: 140 kroner.
INSERT INTO innstillinger (nokkel, verdi)
     VALUES ('frakt_ore', '14000')
ON DUPLICATE KEY UPDATE nokkel = nokkel;

-- Hva kunden valgte, hva frakten kostet, og hvor den skal.
--
-- «hent» er utgangspunktet: de fleste henter i verkstedet, og en gammel ordre
-- uten kolonnene skal ikke plutselig se ut som en pakke ingen har sendt.
ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS levering VARCHAR(16) NOT NULL DEFAULT 'hent' AFTER status;

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS frakt_ore INT UNSIGNED NOT NULL DEFAULT 0 AFTER levering;

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS adresse VARCHAR(191) NULL AFTER frakt_ore;

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS postnr VARCHAR(10) NULL AFTER adresse;

ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS poststed VARCHAR(100) NULL AFTER postnr;
