-- Tre e-poster hadde samme emne, og kurspaameldingen sa feil ting.
--
-- «Takk for bestillingen hos Lissom!» sto som emne paa tre forskjellige
-- meldinger:
--
--   ordrebekreftelse    naar noen melder seg paa et KURS
--   butikkordre         butikkvare som skal HENTES paa Teie
--   butikkordre_pakke   butikkvare som skal SENDES som pakke
--
-- Kunden fikk samme linje i innboksen enten hun hadde kjopt en kopp, fatt en
-- pakke i posten, eller meldt seg paa et dreiekurs. Hun kunne ikke se
-- forskjell for hun apnet dem.
--
-- Eieren, 1. september, om det som sto under Systemmeldinger: «denne staar jo
-- to ganger og eposten ser jo helt feil ut».
--
-- Kurspaameldingen var verst. Den sa:
--
--   «Hei {navn}! Vi har mottatt bestillingen din ({ordre}). Du finner
--    kvitteringen under Min side. Velkommen til verkstedet!»
--
-- Fire ting galt: «bestillingen din» om et kurs; datoen gjemt inne i en
-- parentes fordi kursnavn og dato var limt sammen i ett felt; alt paa én
-- linje uten avsnitt; og ingen hilsen, mens de to butikkmalene slutter med
-- «Hilsen Lissom Keramikk».
--
-- Malen far to nye felt, {kurs} og {naar}, som app/lib/booking.php fyller.
-- {ordre} finnes fortsatt og har den gamle verdien — en mal som er skrevet
-- om for haand skal ikke miste innholdet sitt.
--
-- Bare malene som staar med den gamle teksten roeres. Har eieren endret en av
-- dem selv, staar hennes ord.

-- ── Kurspaameldingen ──────────────────────────────────────────────────
UPDATE notification_templates
   SET emne  = 'Du er påmeldt {kurs}',
       tekst = CONCAT(
         'Hei {navn}!', CHAR(10), CHAR(10),
         'Du har plassen din på {kurs}.', CHAR(10), CHAR(10),
         'Når: {naar}', CHAR(10),
         'Hvor: Nordre Løkkevei 15, 3120 Nøtterøy', CHAR(10), CHAR(10),
         'Alt utstyr og brenning er inkludert. Kvitteringen ligger under Min side.', CHAR(10), CHAR(10),
         'Vi gleder oss til å se deg.', CHAR(10), CHAR(10),
         'Hilsen Lissom Keramikk'
       )
 WHERE navn = 'ordrebekreftelse'
   AND emne = 'Takk for bestillingen hos Lissom!'
   AND tekst LIKE '%Vi har mottatt bestillingen din%';

-- ── Butikkvare som hentes ─────────────────────────────────────────────
UPDATE notification_templates
   SET emne = 'Bestillingen din er klar til henting'
 WHERE navn = 'butikkordre'
   AND emne = 'Takk for bestillingen hos Lissom!';

-- ── Butikkvare som sendes ─────────────────────────────────────────────
UPDATE notification_templates
   SET emne = 'Takk for bestillingen — den sendes som pakke'
 WHERE navn = 'butikkordre_pakke'
   AND emne = 'Takk for bestillingen hos Lissom!';
