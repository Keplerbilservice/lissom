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
-- Godkjenningen står heller ikke lenger i teksten. Eieren, samme dag: «Må det
-- stå at avtalen må godkjennes i vipps, er det ikke nettopp dette man gjør når
-- man betaler med vipps da?» Det er det: å si ja til avtalen i appen ER
-- betalingen, ikke et steg til. For alle som fullførte var setningen støy.
-- Skjermen sier det fortsatt til den som blir avbrutt — ruta «Godkjenn i
-- Vipps» i lissom-2108.html.
--
-- Vipps tar første måned i det hun sier ja — se «initialCharge» i
-- app/lib/vipps.php. Derfor står det at trekket går med det samme.
UPDATE notification_templates
   SET emne  = 'Medlemskapet ditt hos Lissom',
       tekst = 'Hei {navn},

Takk for at du melder deg inn hos Lissom.

Medlemskap: {type} — {belop} i måneden

Første måned trekkes med det samme. Avtalen finner du
under Faste trekk i Vipps-appen — der ser du beløpet, når neste trekk går,
og du kan stoppe den når du vil.

Vi går gjennom dørkode og ordensregler første gang du kommer.

Hilsen Lissom Keramikk & Håndverk
Nordre Løkkevei 15, 3120 Nøtterøy'
 WHERE navn = 'innmelding_fast_trekk';
