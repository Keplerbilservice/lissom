-- Vår andre godkjenningslenke er revet ut.
--
-- Eieren, 7. september 2026: «du skal rive ut og bygge avtale vipssen paa
-- nytt». Se docs/AVTALETREKK.md.
--
-- Det fantes to lenker som gjorde det samme: innmeldingsordren
-- (/meld-inn/<noekkel>, tabellen «medlemsordrer») og denne
-- (/godkjenn/<noekkel>, tabellen «avtale_lenker»). Begge laget Vipps-avtalen
-- i det mottakeren trykket. To veier inn til det samme er to steder en feil
-- kan gjemme seg, og eieren fikk «Vi kjenner ikke denne QR-koden» paa begge.
--
-- Innmeldingsordren staar igjen: den er den som brukes fra medlemskapssida
-- ogsaa, og planen ligger i raden.
--
-- Tabellen slettes til slutt. Radene i den er noekler som ikke kan brukes til
-- noe lenger — /godkjenn/ finnes ikke.
DELETE FROM notification_templates WHERE navn = 'avtale_ikke_godkjent';
DROP TABLE IF EXISTS avtale_lenker;
