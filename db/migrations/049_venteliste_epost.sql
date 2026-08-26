-- Ventelista lover e-post, ikke SMS.
--
-- Bekreftelsen sa «vi gir beskjed på e-post og SMS». SMS er ikke satt opp, og
-- skal ikke loves. Bestilt 26. august: det skal staa at du faar en e-post om
-- det blir ledig, eller at vi forsoeker aa ringe deg.
--
-- Teksten i bekreftelsen ligger i api/venteliste.php og er rettet der. Her
-- staar malen som gaar ut naar plassen faktisk blir ledig.
--
-- Kanalen settes til epost. Migrasjon 025 ga malen et emne saa den falt
-- tilbake til e-post naar SMS ikke var mulig — men kanalen sto fortsatt som
-- sms, og ville gaatt tilbake til SMS av seg selv den dagen noen satte inn
-- SMS-noekler. Da ville kunden faatt noe annet enn det vi lovet.

UPDATE notification_templates
   SET kanal = 'epost',
       emne  = 'Det ble ledig plass på {kurs}'
 WHERE navn = 'venteliste_ledig';
