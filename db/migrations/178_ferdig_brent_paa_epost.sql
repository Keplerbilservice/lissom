-- «Keramikken din er ferdig og klar til henting» flyttes fra SMS til e-post.
--
-- Eieren, 13. september 2026, spurt om SMS var riktig kanal for den:
-- «Bare e-post». Grunnen han ga: SMS koster penger per melding, e-post gjor
-- ikke det.
--
-- I praksis har den gaatt paa e-post hele tiden: naar SMS ikke er satt opp,
-- sendes SMS-meldinger som e-post i stedet (se Utsending::sendSms). Denne
-- migrasjonen gjor det til det den ER, ikke til noe som avhenger av at SMS
-- staar ubrukt — den dagen SMS settes opp, skal denne fortsatt gaa paa
-- e-post.
--
-- Teksten roeres ikke. Emnet roeres ikke. Bare kanalen.
--
-- Merk: en SMS-mal har ikke emne i bruk, men raden har det likevel, og det
-- er det som blir emnelinja naar den naa gaar som e-post. Det staar der fra
-- for: «Keramikken din er ferdig og klar til henting».

UPDATE notification_templates
   SET kanal = 'epost'
 WHERE navn = 'ferdig_brent'
   AND kanal = 'sms';
