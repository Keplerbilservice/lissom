-- Leirebestillingen: status per linje (Bestilt -> Kommet -> Hentet).
--
-- Eieren, 8. oktober 2026: én skjerm «Neste leirebestilling» i admin, og
-- medlemmet ser paa Min side om leira er bestilt, kommet («Leira di er
-- kommet») eller hentet. Bestilt = bestilt_at (finnes fra foer). Kommet og
-- Hentet settes av admin med ett trykk. bestilling_nr er nummeret i
-- e-posten til leverandoeren (B-ÅÅMM-NNN), saa tidligere bestillinger kan
-- vises samlet.
--
-- Bare nye kolonner. Trygg aa kjoere to ganger.

ALTER TABLE handleliste_linjer
    ADD COLUMN IF NOT EXISTS bestilling_nr VARCHAR(20) NULL COMMENT 'Nummeret i bestillingen til leverandoeren',
    ADD COLUMN IF NOT EXISTS kommet_at DATETIME NULL COMMENT 'Varene er kommet til verkstedet',
    ADD COLUMN IF NOT EXISTS hentet_at DATETIME NULL COMMENT 'Medlemmet har hentet varene';
