-- Velkomstmalen sa ikke naar pengene gaar — og lovte noe som ikke stemmer.
--
-- Medlemmet Eirin, 2. september: «Jeg betalte med vipps i gaar via siden her.
-- Saa ut til aa fungere greit. Men pengene er fremdeles paa min konto.»
--
-- To ting i den gamle teksten:
--
-- 1. Den sa ingenting om NAAR. Fast trekk i Vipps er en fullmakt, ikke en
--    betaling: trekket bes om av cron, og Vipps krever at kunden varsles for
--    det skjer — saa forfallet ligger tre dager fram. «Du faar beskjed for
--    hvert trekk» er sant, men en som nettopp har vaert gjennom Vipps leser
--    velkomsten som en kvittering.
--
-- 2. «Medlemskapet er aktivt saa snart betalingen er registrert» stemmer
--    ikke. Medlemskap::oppdaterFraVipps() setter medlemmet aktivt naar
--    AVTALEN blir aktiv i Vipps, for en eneste krone har flyttet seg. Malen
--    lovte altsaa noe annet enn det systemet gjor.
--
-- Teksten er eierens egen, med det som manglet lagt til. Den kan skrives om
-- videre under Beskjeder → E-post- og SMS-maler uten at noen trenger aa roere
-- koden.
UPDATE notification_templates
   SET tekst = 'Hei {navn},

Takk for at du melder deg inn hos Lissom.

Ønsket medlemskap: {type}

Du har opprettet en fast betalingsavtale i Vipps. Den trekkes automatisk, og du kan si den opp fra Min side.

Første trekk kommer om noen dager. Vipps krever at vi varsler deg først, så du får en e-post fra oss før pengene går.

Medlemskapet er aktivt med det samme. Vi går gjennom dørkode og ordensregler første gang du kommer.

Hilsen Lissom Keramikk & Håndverk
Nordre Løkkevei 15, 3120 Nøtterøy'
 WHERE navn = 'innmelding_fast_trekk';
