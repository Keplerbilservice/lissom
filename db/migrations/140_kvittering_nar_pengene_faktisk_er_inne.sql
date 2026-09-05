-- Velkomstbrevet sa «Du har betalt» for kunden hadde betalt.
--
-- Eieren, 5. september: «Hvilken info får de som kjøper et av de andre
-- medlemskapene». Svaret var: et brev som paastod noe som ikke hadde skjedd
-- enda, og ingenting naar det faktisk skjedde.
--
-- De andre medlemskapene — Mini 15, 30 timer, Prøv Lissom, Fri tilgang —
-- gaar ikke paa fullmakt, men paa vanlig Vipps-betaling. startEngangs()
-- oppretter betalingen, e-posten legges i koen, og FORST DERETTER sendes
-- kunden til Vipps. Avbryter hun der, har hun likevel faatt et brev som sier
-- at hun har betalt.
--
-- Og gikk betalingen gjennom, kom det ingenting. markerBetalt() kaller
-- betaltEngangs(), medlemskapet slaas paa — og kunden fikk aldri et ord fra
-- oss om at det var i orden.
--
-- Aarsavtalen hadde det samme moensteret motsatt vei: den sa «avtalen er
-- opprettet» for den var godkjent. Se migrasjon 139. Naa sier begge brevene
-- hva som GJENSTAAR, og kvitteringen kommer naar pengene er inne.
UPDATE notification_templates
   SET emne  = 'Fullfør betalingen i Vipps',
       tekst = 'Hei {navn},

Takk for at du melder deg inn hos Lissom.

Medlemskap: {type} — {belop}

FULLFØR BETALINGEN I VIPPS
Medlemskapet starter når betalingen er i havn. Har du ikke betalt ennå,
åpner du denne lenken på telefonen:

{lenke}

Det kommer ingen automatiske trekk på dette medlemskapet. Vi tar kontakt
før neste periode.

Når betalingen er registrert, får du en kvittering fra oss. Vi går
gjennom dørkode og ordensregler første gang du kommer.

Hilsen Lissom Keramikk & Håndverk
Nordre Løkkevei 15, 3120 Nøtterøy'
 WHERE navn = 'innmelding_ordner_selv';

-- Kvitteringen: pengene er inne, og medlemskapet gjelder.
--
-- Denne fantes ikke. Betalingen gikk gjennom, medlemskapet ble slaatt paa i
-- basen, og kunden satt igjen med Vipps' egen kvittering som eneste tegn paa
-- at noe hadde skjedd hos oss.
INSERT INTO notification_templates (navn, kanal, gruppe, emne, tekst, aktiv)
VALUES (
  'medlemskap_betalt',
  'epost',
  'ordre',
  'Medlemskapet ditt er i gang',
  'Hei {navn},

Betalingen er registrert, og medlemskapet ditt er i gang.

Medlemskap: {type} — {belop}
{gyldig}

Dørkoden og timene dine står på Min side. Vi går gjennom ordensreglene
første gang du kommer.

Hilsen Lissom Keramikk & Håndverk
Nordre Løkkevei 15, 3120 Nøtterøy',
  1
)
ON DUPLICATE KEY UPDATE navn = navn;
