-- Vilkaarene maa godtas for man kan kjope et medlemskap.
--
-- Eieren, 2. september: «er det mulig aa legge til godta vilkaar for man faar
-- kjopt et medlemskap?»
--
-- Innmeldingsskjemaet hadde ingen hake. Under knappen sto én graa linje om
-- bindingstid, uten bekreftelse og uten at noe ble skrevet ned — ingen kunne i
-- ettertid vise at medlemmet hadde sett den.
--
-- Her lagres samtykket: naar det ble gitt, og hvilken versjon av vilkaarene
-- som gjaldt da. Versjonen staar som en dato i Medlemskap::VILKAAR_VERSJON og
-- endres naar teksten endres, saa en rad fra i fjor peker paa teksten som
-- gjaldt i fjor.
ALTER TABLE membership_applications
  ADD COLUMN vilkaar_godtatt_at DATETIME    NULL AFTER melding,
  ADD COLUMN vilkaar_versjon    VARCHAR(32) NULL AFTER vilkaar_godtatt_at;

-- ── Bindingstida paa proveperioden ──────────────────────────────────────
--
-- Eierens vilkaar: «Provemedlemskapet har ingen bindingstid» og «kan avsluttes
-- uten oppsigelsestid». I basen sto den med to maaneders binding og én maaneds
-- oppsigelse — samme som de loepende medlemskapene.
--
-- Det var feil paa to maater. «Prov Lissom» er en engangsbetaling som loper
-- tretti dager og stopper av seg selv; en bindingstid paa to maaneder er
-- lengre enn medlemskapet selv. Og hvorforIkkeSiOpp() ville nektet noen aa si
-- opp et medlemskap de har full rett til aa avslutte naar de vil.
--
-- Eieren, 2. september, om motstriden: «vilkaarene gjelder — rett basen».
UPDATE membership_plans
   SET binding_mnd = 0, oppsigelse_mnd = 0
 WHERE navn = 'Prøv Lissom';
