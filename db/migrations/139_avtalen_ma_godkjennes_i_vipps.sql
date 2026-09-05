-- Velkomstmalen sa at avtalen var opprettet. Den er ikke gyldig for kunden
-- har godkjent den i Vipps-appen — og det sto ingen steder.
--
-- Eieren, 5. september: «ved årsavatel en annen vippsløsning enn resten, men
-- kunden får ingen beskjed om å godkjenne så vi får ikke penger», og
-- «eposten de som bestiller årsmedlemskap får forteller ingenting om at de
-- må godkjenne», og «jeg får jo ikke inn pengene mine».
--
-- Fast trekk i Vipps er en FULLMAKT som kunden gir. Vipps::opprettAvtale()
-- lager den som «PENDING» og gir oss en «vippsConfirmationUrl»; foerst naar
-- kunden aapner den og godkjenner i appen, blir avtalen aktiv og kan
-- belastes. Gjor hun ikke det, staar raden vaar paa «venter» for alltid, og
-- det finnes ingenting aa trekke paa.
--
-- Den gamle teksten sa tre ting som ikke stemte for det har skjedd:
--
--   «Du har opprettet en fast betalingsavtale i Vipps»
--       — nei, den er foreslaatt. Kunden maa si ja.
--   «Den trekkes automatisk»
--       — bare etter godkjenning.
--   «Medlemskapet er aktivt med det samme»
--       — Medlemskap::oppdaterFraVipps() setter medlemmet aktivt naar
--         AVTALEN blir aktiv, ikke naar e-posten sendes.
--
-- Naa staar godkjenningen foerst, med lenka, og resten kommer etter. {lenke}
-- er adressen til Vipps; er den tom, faller teksten tilbake paa Min side.
--
-- Teksten kan skrives om videre under Beskjeder → E-post- og SMS-maler uten
-- at noen trenger aa roere koden.
UPDATE notification_templates
   SET emne  = 'Godkjenn medlemskapet i Vipps',
       tekst = 'Hei {navn},

Takk for at du melder deg inn hos Lissom.

Medlemskap: {type}

DU MÅ GODKJENNE AVTALEN I VIPPS
Medlemskapet starter ikke før du har gjort det. Åpne denne lenken på
telefonen og si ja i Vipps-appen:

{lenke}

Tar det bare et minutt. Har du lukket siden, finner du den samme lenken
på Min side.

Når du har godkjent, trekkes beløpet automatisk hver måned, og du kan si
opp avtalen selv fra Min side. Vipps krever at vi varsler deg før hvert
trekk, så du får en e-post fra oss før pengene går.

Vi går gjennom dørkode og ordensregler første gang du kommer.

Hilsen Lissom Keramikk & Håndverk
Nordre Løkkevei 15, 3120 Nøtterøy'
 WHERE navn = 'innmelding_fast_trekk';

-- Paaminnelsen: avtalen ligger og venter paa godkjenning.
--
-- Ingenting fantes for dette. Fullforte ikke kunden i Vipps med det samme,
-- laa lenka i basen og naadde ingen. Cron sender den paa nytt dagen etter og
-- én gang til etter tre dager — se bin/cron.php.
INSERT INTO notification_templates (navn, kanal, gruppe, emne, tekst, aktiv)
VALUES (
  'avtale_ikke_godkjent',
  'epost_sms',
  'ordre',
  'Medlemskapet ditt venter på deg',
  'Hei {navn},

Medlemskapet ditt hos Lissom er ikke i gang ennå. Avtalen i Vipps mangler
godkjenningen din.

Medlemskap: {type} — {belop} i måneden

Åpne denne lenken på telefonen og si ja i Vipps-appen:

{lenke}

Da er du i gang. Har du ombestemt deg, er det bare å svare på denne
e-posten, så tar vi den bort.

Hilsen Lissom Keramikk & Håndverk
Nordre Løkkevei 15, 3120 Nøtterøy',
  1
)
ON DUPLICATE KEY UPDATE navn = navn;

-- Naar vi sist minnet medlemmet paa at avtalen maa godkjennes.
--
-- Uten denne kolonnen ville paaminnelsen gaatt ut hver eneste natt saa lenge
-- raden sto paa «venter» — og en purring hver dag er ikke en purring, det er
-- mas. Cron sender den dagen etter, og én gang til etter tre dager. Se
-- bin/cron.php.
ALTER TABLE subscriptions
  ADD COLUMN paaminnet_at DATETIME NULL DEFAULT NULL AFTER vipps_url,
  ADD COLUMN paaminnet_antall TINYINT NOT NULL DEFAULT 0 AFTER paaminnet_at;
