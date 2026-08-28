-- Bindingstid og oppsigelsestid.
--
-- Eieren: «vi har 2 maaneders bindingstid ved inngaaelse av medlemskap og en
-- maaneds oppsigelsestid», og «de som har aarsavtale kan ikke si opp for det
-- har gaatt et aar».
--
-- Bindingen laa der fra for som «binding_mnd», men bare aarsmedlemskapet
-- hadde en verdi. De andre sto paa 0 — altsaa ingen binding.
--
-- Oppsigelsestida fantes ikke. En oppsigelse stoppet avtalen med det samme,
-- og medlemmet mistet tilgangen samme sekund.

-- To maaneder paa alt som ikke har en lengre binding fra for.
UPDATE membership_plans
   SET binding_mnd = 2
 WHERE binding_mnd < 2;

-- Hvor lenge en oppsigelse loper for den virker. Staar per medlemskap, saa
-- verkstedet kan endre den — men én maaned er regelen i dag.
ALTER TABLE membership_plans
  ADD COLUMN IF NOT EXISTS oppsigelse_mnd TINYINT UNSIGNED NOT NULL DEFAULT 1
  AFTER binding_mnd;

-- Siste dag medlemskapet gjelder. Settes naar noen sier opp: da loper det
-- oppsigelsestida ut, og forst der stoppes avtalen og tilgangen.
--
-- «sagt_opp_at» sier NAAR noen sa opp, «slutter» sier NAAR det tar slutt. De
-- to er ikke det samme naar det er en maaned mellom dem.
ALTER TABLE subscriptions
  ADD COLUMN IF NOT EXISTS slutter DATE NULL
  AFTER sagt_opp_at;
