-- Sporet etter et gavekorttrekk, ogsaa naar det ikke henger paa noe annet.
--
-- «gift_card_uses.ref_type» hadde tre verdier: booking, ordre, medlemskap.
-- De to forste peker paa en rad; den tredje peker paa en avtale i
-- «subscriptions».
--
-- Et medlemskap gjort opp for haand har ikke alltid en avtale. «Prov Lissom»
-- betales én gang og har ingen; en som staar «Ingen betaling registrert» i
-- Kassa har det heller ikke. Maalt 7. september 2026: gavekortet ble da
-- trukket — saldoen gikk ned — men ingen rad ble skrevet, fordi det ikke
-- fantes noe aa peke paa. Uten den raden er trekket usporbart, og sperren mot
-- aa trekke to ganger virker ikke: den slaar opp nettopp den raden.
--
-- «betaling» peker paa betalingsraden selv. Den finnes alltid.
ALTER TABLE gift_card_uses
  MODIFY ref_type ENUM('booking','ordre','medlemskap','betaling') NOT NULL;
