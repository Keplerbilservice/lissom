-- Trekkdagen ligger fast, ogsaa i februar.
--
-- Eieren, 7. september 2026: «er det mulig at jeg kan redigere naar trekkene
-- skal vaere? noen vil for eksempel ha 15 og andre 1.» Feltet kom samme dag.
--
-- Men datoen ble flyttet fram med «+1 month», og den regner ikke slik en
-- kalender gjor. Maalt: 31. januar + 1 maaned = 3. mars, ikke 28. februar.
-- Deretter 3. april, 3. mai — datoen vandrer nedover aaret, og et medlem satt
-- til den 31. ender paa en helt annen dag.
--
-- Han valgte «Siste dag i maaneden: 31. januar → 28. februar → 31. mars.
-- Ligger fast.» Det krever at dagen huskes: klipper vi bare til 28., er den
-- 31. tapt for godt, og neste maaned blir 28. mars.
--
-- Kolonna er dagen i maaneden trekket hoerer hjemme paa, 1-31. Staar den tom,
-- brukes dagen i «neste_trekk» som for — gamle avtaler endrer seg ikke.
ALTER TABLE subscriptions
  ADD COLUMN trekk_dag TINYINT NULL DEFAULT NULL
  COMMENT 'Dagen i maaneden trekket skal gaa. Klippes til siste dag i korte maaneder.'
  AFTER neste_trekk;
