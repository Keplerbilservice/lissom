-- Standardtekst for bekreftelsen etter kjøp.
--
-- Feltet «Bekreftelsestekst» i kursveiviseren sto tomt hver gang. Den som
-- legger ut et kurs måtte skrive den samme praktiske informasjonen på nytt —
-- møt opp litt før, ta med klær som tåler leire, hvor du parkerer — eller
-- droppe det, og da fikk kunden ingenting.
--
-- Teksten står her og ikke i koden, så den kan endres fra admin uten en ny
-- utlegging av nettsiden. Nye kurs får den ferdig utfylt; du retter eller
-- sletter den fritt på det enkelte kurset.

INSERT INTO innstillinger (nokkel, verdi) VALUES
('kurs_bekreftelse',
 'Velkommen! Møt gjerne opp ti minutter før, så rekker vi å hilse før vi setter i gang. Ta på klær som tåler litt leire — vi har forklær, men leira finner alltid veien. Du finner oss i Nordre Løkkevei 15 på Teie, og det er parkering rett utenfor. Alt du trenger av leire, verktøy, glasur og brenning er inkludert. Arbeidene er klare til henting etter to til tre uker, og vi sier fra når de er ferdige.')
ON DUPLICATE KEY UPDATE nokkel = nokkel;
