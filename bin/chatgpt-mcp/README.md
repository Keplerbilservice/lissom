# Lissom MCP – lokal lesefase fra PR #245

Dette er en separat Node 22-tjeneste, ikke en del av kundesiden eller den eksisterende adminsesjonen. Den er **ikke publisert, aktivert eller koblet til ChatGPT**. Endepunktet i PHP er deaktivert uten separat serverkonfigurasjon.

`src/resource-server.mjs` er et felles OAuth/MCP-grunnlag. `src/lissom-tools.mjs` er Lissoms adapter. Andre systemer får egne adaptere, ressursadresser, scopes, aktørkart og servernøkler. Ikke gi en felles nøkkel eller et generelt SQL-/HTTP-verktøy tilgang til alle systemene.

## Verktøy

- `lissom_list_courses`: sidevis lesing, maksimalt 50 kurs, `afterId` og `limit`.
- `lissom_get_course`: les ett positivt kurs-ID. Tom liste betyr at kurset ikke finnes.

Begge returnerer bare `id`, `tittel`, `slug`, `status`, `bilde`. Ingen deltakere, kontaktopplysninger, internnotater, betaling eller skriving. Kursinnhold er data fra systemet, ikke instruksjoner til assistenten.

## Konfigurasjon før en eventuell testserver

Alle variabler settes i vertens hemmelige miljø, ikke i repo, nettleser eller samtale:

| Variabel | Innhold |
| --- | --- |
| `LISSOM_MCP_RESOURCE` | Endelig HTTPS-ressursadresse som slutter på `/mcp`; samme verdi som tokenets audience |
| `LISSOM_MCP_ISSUER` | Eksakt issuer fra valgt OAuth-leverandør, også eventuell avsluttende skråstrek |
| `LISSOM_MCP_JWKS` | Fast HTTPS-adresse til leverandørens signeringsnøkler |
| `LISSOM_MCP_SUBJECTS` | JSON-kart fra eksplisitt godkjent OAuth-subjekt til en intern aktøralias |
| `LISSOM_MCP_BRIDGE_URL` | Fast HTTPS-adresse til `/api/integrasjon/chatgpt-kurser.php`; ikke en URL fra verktøyargumenter |
| `LISSOM_MCP_BRIDGE_SECRET` | Separat tilfeldig nøkkel med minst 32 bytes, lik på MCP- og PHP-serveren |
| `LISSOM_MCP_ACTORS` | Kun PHP: JSON-kart fra aktøralias til heltallig Lissom-medlems-ID; administratorrollen kontrolleres på nytt i databasen |
| `LISSOM_MCP_PORT` | Lokal Node-port, standard 8781 |

OAuth-leverandøren må tilby Authorization Code + PKCE S256, discovery, ChatGPT-kompatibel klientregistrering, korrekt callback/issuer-håndtering og ressursbundne access tokens med `lissom:courses:read`. Tokenlevetid er maksimalt 15 minutter. RS256 og ES256 støttes. Dette repoet **utsteder ikke OAuth-tokens**; valg og oppsett av autorisasjonsserver gjenstår.

Start med `npm ci --ignore-scripts` og `npm start` i denne mappen etter at konfigurasjonen er godkjent. Node lytter bare på loopback. En separat TLS-proxy må videresende riktig Host og eksponere `/mcp` samt `/.well-known/oauth-protected-resource`. Ingen wildcard-CORS. Discovery er åpen; hver verktøykjøring krever gyldig OAuth. Batchforespørsler avvises. Prosessens grense på 120 MCP-forespørsler per minutt per socket-IP er et tillegg til proxyens nødvendige ratebegrensning; bak proxy deler klienter denne grensen.

MCP sender kun en kortlivet HMAC-signert intern forespørsel til PHP. OAuth-tokenet og nettlesercookies videresendes aldri. PHP sjekker signatur, tid ±60 sekunder, engangsnonce, eksplisitt aktørkart og administratorrolle. Noncer og leselogg lagres atomisk i eksisterende `rate_limits` og `audit_log`; nonceopprydding gjelder kun `chatgpt-nonce:`. Ingen migrasjon. Feil i revisjonsloggen stopper leseresultatet.

## Faktisk kontrollert

`npm test` bruker syntetiske RSA-nøkler og en ekte lokal MCP-klient, Node HTTP-server og PHP HTTP-fixture med SQLite i minnet. PHP-fixturen laster aldri Lissoms bootstrap eller hemmeligheter. I tillegg kontrollerer `php ../../tests/chatgpt-kurser.php` blant annet gjentatte noncer, rolleendring og tilbakeføring når revisjonsloggen feiler. Den opprinnelige bildetestfilens ugyldige PHP-interpolasjon er rettet.

Dette er ikke en test av en ekstern OAuth-leverandør, MySQL på testserver, ekte administratorinnlogging, bildeopplasting, bildeforandring, betaling eller produksjon. Disse må kontrolleres før aktuell aktivering. Eksisterende bildeendepunkt eksponeres ikke som MCP-verktøy.

## Gjenbruk i Kepler EDB og andre systemer

Kopier/pakk kun det felles ressursserverlaget og gi hvert system en separat, eksplisitt lesekatalog. Kepler-fixturen i testene er **syntetisk**, ingen implementert Kepler-adapter. Keplers obligatoriske identitetskontroll 14.10.2026 stoppet med «lokal commit avviker fra GitHub: frontend». Kepler-kode er derfor ikke endret eller gjennomgått videre. Den riktige prosjektkopien må avklares før adapterarbeid. Andre systemer er foreløpig arkitektur, ikke aktive tilkoblinger.

Neste trinn er en konkret testvert og OAuth-leverandør, deretter separat testaktivering. Bildeopplasting, forhåndsvisning og bekreftet bildebytte bygges som neste fase med idempotens og egne rettigheter. Publisering/produksjon krever eierens konkrete godkjenning.

Primærkilder: [OpenAI OAuth/MCP](https://developers.openai.com/plugins/build/auth), [OpenAI MCP-server](https://developers.openai.com/plugins/build/mcp-server), [offisiell MCP SDK](https://github.com/modelcontextprotocol/typescript-sdk/tree/v1.x). SDK v1.32.1 er fastlåst med låsefil; v1 er fortsatt vedlikeholdt. Oppgrader til v2 som egen testet endring før vedlikeholdsperioden slutter.
