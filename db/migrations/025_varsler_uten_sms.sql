-- SMS er ikke satt opp, og skal ikke vaere en forutsetning for at kunden
-- faar beskjed.
--
-- To maler fantes bare som SMS: «det ble ledig plass» og «keramikken er
-- ferdig brent». Uten leverandor stanset de i koen, prevde fem ganger, og
-- endte som feilede varsler ingen leste. Kunden ventet paa en beskjed som
-- aldri kom.
--
-- Naa gaar de ut som e-post naar SMS ikke er mulig. Da trenger de et emne —
-- en e-post uten emnefelt ser ut som soppelpost.
--
-- Kanalen staar med vilje urort: settes SMS-noklene inn senere, gaar de
-- tilbake til aa vaere SMS av seg selv, uten at noe maa endres her.

UPDATE notification_templates
   SET emne = 'Det ble ledig plass på {kurs}'
 WHERE navn = 'venteliste_ledig'
   AND (emne IS NULL OR emne = '');

UPDATE notification_templates
   SET emne = 'Keramikken din er ferdig og klar til henting'
 WHERE navn = 'ferdig_brent'
   AND (emne IS NULL OR emne = '');
