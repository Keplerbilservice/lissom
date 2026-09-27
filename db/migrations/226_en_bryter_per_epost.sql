-- Én bryter per e-post (eieren, 27. september 2026): «Vil du fortsette med
-- leire? — etter kurset, denne staar av, jeg mistenker at denne finnes paa
-- flere steder? jeg burde jo ha alle eposter som er lagret samlet paa et sted
-- med bryter send av og paa».
--
-- To utsendinger hadde to brytere hver: malens «aktiv» under Tekst maler, og
-- en egen innstilling (fortsett_paa, anmeldelse_paa). Begge maatte staa paa.
-- Naa er malen den ene. For at ingenting skal begynne aa sendes av seg selv,
-- slaas malen av der den egne bryteren staar av i dag — da stemmer det eieren
-- ser med det som faktisk gikk foer.

UPDATE notification_templates
   SET aktiv = 0
 WHERE navn = 'fortsett'
   AND COALESCE((SELECT verdi FROM innstillinger WHERE nokkel = 'fortsett_paa'), '0') <> '1';

UPDATE notification_templates
   SET aktiv = 0
 WHERE navn = 'anmeldelse'
   AND COALESCE((SELECT verdi FROM innstillinger WHERE nokkel = 'anmeldelse_paa'), '0') <> '1';

-- De gamle bryterne leses ikke lenger. De fjernes, saa ingen kan tro de gjor noe.
DELETE FROM innstillinger WHERE nokkel IN ('fortsett_paa', 'anmeldelse_paa');
