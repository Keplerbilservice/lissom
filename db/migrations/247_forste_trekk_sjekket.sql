-- L-6: naar foerste trekk paa en avtale er avklart hos oss.
--
-- Trekkrunden foerer foerste trekk (det Vipps tok ved godkjenning) paa nytt
-- naar oppslaget feilet da avtalen ble aktiv. En avtale som er sjekket og
-- avklart — trekket er foert, eller staar alt paa en annen rad — merkes her,
-- saa den ikke slaas opp hos Vipps hver natt i 90 dager. Trygg aa kjoere to
-- ganger. Uten kolonnen virker koden som foer (sjekker hver natt).
ALTER TABLE subscriptions
    ADD COLUMN IF NOT EXISTS forste_trekk_sjekket DATETIME NULL DEFAULT NULL
        COMMENT 'Naar foerste trekk (initialCharge) ble avklart hos oss (UTC). NULL = ikke sjekket.';
