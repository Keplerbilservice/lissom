-- E-posten ved innmelding sier hvor avtalen står, og har ingen lenke.
--
-- Eieren, 7. september 2026: «Mailen som kommer kan da endres, fjern linken.
-- Fortell at man kan se faste trekk i vipps appen». Spurt om lenka skulle bli
-- igjen der verkstedet sender avtalen selv: «Fjern lenka overalt».
--
-- Lenka var Vipps sin egen adresse ved selvbetjent innmelding. Den lever i ti
-- minutter, og e-posten leses sjelden innen da — den var altså død når hun
-- åpnet den. Nå er den borte.
--
-- Teksten må være sann to steder: ved selvbetjent innmelding, der hun sendes
-- rett til Vipps i det hun trykker, og når verkstedet trykker «Send
-- Vipps-avtale». Derfor peker den på Min side framfor å love en omdirigering
-- som ikke skjer i det ene tilfellet.
--
-- Vipps tar første måned i det hun sier ja — se «initialCharge» i
-- app/lib/vipps.php. Derfor står det at trekket går med det samme.
UPDATE notification_templates
   SET emne  = 'Medlemskapet ditt hos Lissom',
       tekst = 'Hei {navn},

Takk for at du melder deg inn hos Lissom.

Medlemskap: {type} — {belop} i måneden

Medlemskapet starter når du har sagt ja til avtalen i Vipps. Har du ikke
gjort det ennå, finner du den under Medlemskap på Min side.

Når du har sagt ja, trekkes første måned med det samme. Avtalen finner du
under Faste trekk i Vipps-appen — der ser du beløpet, når neste trekk går,
og du kan stoppe den når du vil.

Vi går gjennom dørkode og ordensregler første gang du kommer.

Hilsen Lissom Keramikk & Håndverk
Nordre Løkkevei 15, 3120 Nøtterøy'
 WHERE navn = 'innmelding_fast_trekk';
