-- «Fri tilgang» (merket «Proff») skal ikke lenger ligge ute.
--
-- Eieren, 2. september: «medlemskapet proff - fri tilgang skal avpubliseres».
--
-- Medlemskap::planer() henter bare rader med aktiv = 1, saa dette er alt som
-- skal til: kortet forsvinner fra nettsiden og fra innmeldingsskjemaet.
--
-- Planen blir staaende i basen, og staar i admin under Medlemskap merket
-- «Ikke i salg». De som alt staar paa den beholder prisen og timene sine —
-- abonnementet peker paa navnet, ikke paa at planen er i salg. Skal den ut
-- igjen, er det haken «I salg på nettsiden» i planskjemaet.
UPDATE membership_plans
   SET aktiv = 0
 WHERE navn = 'Fri tilgang';
