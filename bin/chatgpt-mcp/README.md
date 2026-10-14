# MCP-testklient

Denne mappen inneholder bare en lokal/CI-testklient. Serveren kjører PHP og SQL; det skal ikke startes en Node-tjeneste på webhotellet.

Kjør `npm ci --ignore-scripts` og deretter `npm test` fra denne mappen. Testen starter en lokal PHP-fixture med syntetisk innlogging, midlertidig SQLite-database og testnøkler. Den laster ikke Lissoms bootstrap eller produksjonshemmeligheter.

Testen kontrollerer OAuth med PKCE og samtykke, MCP-tilkobling, kurslesing og avvisning uten tilgang. Den bekrefter ikke ekte HTTPS, Apache-ruting eller innlogging på webhotellet.

Se [PHP-integrasjonen](../../app/integrasjon/README.md) for serveroppsett og gjenstående kontroller.
