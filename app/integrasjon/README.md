# PHP-integrasjon for Lissom og andre systemer

Serveren kjører PHP og MySQL på eksisterende webhotell. Railway og en Node-server er ikke nødvendig. Node brukes bare som lokal testklient. Integrasjonen er ikke aktivert på en server.

## Tilgang og gjenbruk

Den felles OAuth-/MCP-kjernen ligger i `src/`. `LissomTools.php` er Lissoms adapter og tilbyr bare `lissom_list_courses` og `lissom_get_course`. Resultatene inneholder `id`, `tittel`, `slug`, `status` og `bilde`; ingen deltakere eller betalinger. Bildeendring fra PR #245 er ikke eksponert som MCP-verktøy.

Hvert system skal ha separat HTTPS-adresse, issuer, ressursadresse, scope, nøkler og kontroll av egne administratorer. Kepler har foreløpig ingen ferdig adapter. Den obligatoriske prosjektkontrollen stoppet fordi lokal frontend-commit avvek fra GitHub; Kepler-kode er ikke endret.

OAuth bruker administratorens eksisterende innlogging, samtykke og PKCE S256. Access tokens varer maksimalt ti minutter. Roterende refresh tokens kan fornye tilkoblingen frem til den opprinnelige samtykkeperioden på maksimalt 24 timer utløper. Gjenbruk av et brukt refresh token sperrer hele tilkoblingen. Administratorrollen kontrolleres igjen ved bruk.

SQL lagrer klienter, samtykke, tokenstatus og MCP-sesjoner. Nøkler til tilstanden hashes; rå access tokens, refresh tokens og autorisasjonskoder lagres ikke. Lesing logges, og feil i revisjonsloggen stopper resultatet. MCP-forespørsler starter ikke applikasjonens bakgrunnskjøring for betaling og varsling.

## Forberedelse til testserver

Krever PHP 8.2 eller nyere, OpenSSL, PDO MySQL og eksisterende privat applikasjonsmappe. Installer låste avhengigheter i denne mappen med:

```sh
composer install --no-dev --no-interaction --no-plugins --no-scripts --prefer-dist --optimize-autoloader
```

Migrasjon `db/migrations/278_chatgpt_integrasjon.sql` oppretter én ny tabell i eksisterende database. Den er ikke kjørt på en server. Eierens godkjenning kreves før serverendringer og aktivering.

Sett disse verdiene i eksisterende privat Config-oppsett, aldri i repoet eller nettleseren:

| Innstilling | Verdi |
| --- | --- |
| `mcp_enabled` | `false` frem til godkjent aktivering; deretter boolsk `true` |
| `mcp_test_mode` | Boolsk `true` på testserver: samtykkesiden merkes «Lissom – TEST» |
| `mcp_issuer` | Eksakt HTTPS-rotadresse uten avsluttende skråstrek |
| `mcp_private_key` | Privat RSA-nøkkel i PEM-format, minst 2048 bits |
| `mcp_encryption_key` | Separat tilfeldig hemmelighet, minst 32 bytes |

Eksisterende `.htaccess` ruter `/integrasjon/mcp`, `/integrasjon/oauth/authorize`, `/integrasjon/oauth/token`, `/integrasjon/oauth/register` og de to OAuth discovery-adressene til PHP. Composer-avhengigheter legges i den private app-mappen ved ordinær bygging.

## Kontrollert og gjenstående

### Testen skal ikke bli et offentlig nettsted

Eieren krever at testen ikke blir synlig i søkemotorer eller forveksles med Lissom. Før aktivering skal testadressen ha egen dokumentrot, testdatabase med syntetiske data, egne servernøkler og tydelig «Lissom – TEST»-navn i ChatGPT-tilkoblingen og samtykket. Ikke bruk produksjonsdatabase, betalingsnøkler, utsending eller delte sesjonscookies. Ingen lenker fra offentlig nettside, sitemap eller Search Console.

Hele testnettstedet skal sperres med serverens tilgangskontroll før filer legges ut. Bare de nødvendige OAuth discovery-, registrerings-, token- og MCP-adressene kan ha et kontrollert unntak for ChatGPT. Disse skal ikke utlevere innhold uten gyldig OAuth. Administratorinnlogging og samtykke forblir beskyttet. En eventuell HTTP-passordsperre må ikke kreve Basic Authorization på MCP-ruten, fordi den bruker Bearer Authorization.

Alle integrasjonssvar har `X-Robots-Tag: noindex, nofollow, noarchive`, også feil. Hele testvertens øvrige svar må ha tilsvarende header. `robots.txt` eller noindex er ikke adgangskontroll; offentlig innsyn må sperres separat. Faktisk sperre, syntetiske data og headere må testes uten innlogging på testverten før aktivering godkjennes. Disse servertiltakene er foreløpig krav, ikke utførte handlinger.

Primærkilder: [Google om noindex](https://developers.google.com/search/docs/crawling-indexing/block-indexing) og [Apache om tilgangskontroll](https://httpd.apache.org/docs/2.4/mod/mod_authz_core.html#require).

`php tests/chatgpt-php-oauth.php` fra repo-roten tester OAuth og MCP med syntetiske administratorer og isolert SQLite. `php tests/chatgpt-kurser.php` tester kurslesing, replayvern og revisjonslogg. `npm test` i `bin/chatgpt-mcp` bruker en ekte MCP-klient mot en lokal PHP HTTP-fixture. Ingen av disse laster produksjonshemmeligheter.

CI har også en isolert MariaDB-test som bruker den faktiske migrasjonen. Bestått lokal SQLite-test bekrefter ikke at MariaDB-jobben eller webhotellet fungerer. Ekte HTTPS, Apache-ruting, administratorinnlogging, databaseoppsett og ChatGPT-tilkobling må kontrolleres på godkjent testserver før funksjonen erklæres serverklar. Bildeopplasting, bildebytte og andre skriveoperasjoner er neste fase og krever egne rettigheter og idempotens.
