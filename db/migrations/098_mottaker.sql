-- Navnet paa pakken.
--
-- Adressefeltene kom med migrasjon 095, men uten et navn: en pakke med
-- gateadresse, postnummer og poststed og ingen mottaker er ikke en pakke
-- Posten kan levere. Eieren 30. august: «naar man velger aa sende pakken,
-- saa maa vi ogsaa ha navn».
--
-- Eget felt, ikke kunde_navn. Butikken har gaveinnpakning og gavehilsen fra
-- for (migrasjon 041) — den som betaler er ikke alltid den som skal ha
-- pakken, og da maa begge navnene staa. Er de den samme, fyller skjemaet det
-- inn av seg selv.
ALTER TABLE orders
  ADD COLUMN IF NOT EXISTS mottaker VARCHAR(191) NULL AFTER frakt_ore;
