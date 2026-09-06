-- «Fullfor betalingen i Vipps» ba folk betale mens de sto og betalte.
--
-- Eieren, 6. september: «de andre medlemskapene, skal vel ikke ha noen link,
-- de maa betale naar de booker!!»
--
-- Han har rett, og flyten er kontrollert: api/bli-medlem.php sender soekeren
-- rett til Vipps med betalingsadressen — «Du er paa vei til Vipps» — og
-- sender SAMTIDIG denne e-posten med en lenke og setningen «Har du ikke
-- betalt ennaa». Brevet ba altsaa om en betaling som nettopp var gjort, og
-- lenka var en betaling til paa det samme medlemskapet.
--
-- Paa spoersmaal om den samme malen ogsaa brukes av «Send Vipps-avtale» i
-- admin, der lenka er det eneste den som IKKE har betalt har, svarte eieren:
-- «Én tekst — fjern lenka helt». Det er et bevisst valg, og det staar her
-- saa det ikke leses som en forglemmelse senere: etter denne kan ikke
-- verkstedet lenger sende en betalingslenke for Mini 15, 30 timer, Proev
-- Lissom eller Fri tilgang. De maa betale ved innmelding, eller gjores opp i
-- Kassa.
--
-- Teksten sier ingenting om betalingen — verken at den er gjort eller at den
-- mangler. Brevet gaar naar innmeldingen skjer, og da vet vi ikke hvordan
-- det gikk i Vipps.
--
-- Fast trekk («innmelding_fast_trekk») er ikke roert. Der ER lenka poenget:
-- avtalen maa godkjennes i appen, og uten den godkjenningen finnes det
-- ingenting aa trekke paa.
UPDATE notification_templates
   SET emne  = 'Takk for at du melder deg inn',
       tekst = 'Hei {navn},

Takk for at du melder deg inn hos Lissom.

Medlemskap: {type} — {belop}

Dørkoden og timene dine står på Min side. Vi går gjennom ordensreglene
første gang du kommer.

Hilsen Lissom Keramikk & Håndverk
Nordre Løkkevei 15, 3120 Nøtterøy'
 WHERE navn = 'innmelding_ordner_selv';
