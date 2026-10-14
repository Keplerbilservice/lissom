# Lukket Lissom-test på eksisterende webhotell

Valgt av eieren: `https://test.lissom.no` på Lissoms eget Domene.no-webhotell. Kontoen har PHP 8.4 og to ledige databaseplasser, kontrollert 14.10.2026. Kepler-kontoens fem databaseplasser er brukt. Ingen serverendringer er utført.

## Konkret testoppsett

- Ny dokumentrot: `/home/rbvapxvz/public_html/lissom-mcp-test`. Del aldri dokumentrot med hoveddomenet.
- Ny privat kode og konfigurasjon: `/home/rbvapxvz/lissom-mcp-test-private`.
- Ny database: `rbvapxvz_lissom_mcp_test`, med egen bruker `rbvapxvz_mcp_test` som bare har tilgang til denne databasen.
- Tilkoblingens navn: `Lissom – TEST`. Issuer: `https://test.lissom.no`. Kun scope `lissom:courses:read`.
- Bare syntetisk administrator og syntetiske kurs. Ingen kopi av kunder, produksjonsnøkler eller produksjonsdatabase.

Testpakken inneholder bare innlogging og integrasjon, ingen kundeside, booking, betalingskode eller utsendingskode. Den bruker eksisterende passordinnlogging og sesjonsklasse. Testens API-oppstart peker bare til den nye private testmappen; den søker aldri opp produksjonens bootstrap eller secrets. Privat oppstart kontrollerer den eksplisitte testdatabasen før tilkobling.

## Rekkefølge ved godkjent oppsett

1. Opprett den nye dokumentroten med `closed.htaccess` som `.htaccess` før DNS eller filer eksponeres. Den avviser alle besøk og sender noindex-header. Ikke endre hoveddomenets oppsett.
2. Opprett den separate databasen og begrensede brukeren. Nye passord fylles inn av eieren i kontrollpanelet. Ikke bruk en eksisterende produksjonsbruker.
3. Last bare pakkens offentlige filer til den nye dokumentroten og private filer til den nye private mappen. Privat konfigurasjon legges utenfor webrot med filrettighet 600. `mcp_enabled` holdes `false` under oppsett.
4. Importer `schema.sql` i den nye, tomme testdatabasen. CREATE uten IF NOT EXISTS gjør at eksisterende tabeller stopper importen; ingen DROP eller produksjonsmigrasjoner kjøres.
5. Opprett en syntetisk administrator med nytt passordhash, og egne RSA-/krypteringsnøkler. Nøkler, passord og rå tokens skal aldri i GitHub, rapport eller chat.
6. Opprett testdomenet med egen rot, og kontroller HTTPS. Behold full adgangssperre frem til nettinnsyn er kontrollert både via testdomenet og via hoveddomenets mulige mappeadresse.
7. Konfigurer serverens passordsperre for nettsiden, innloggingen og samtykket. `restricted.htaccess.example` er et konkret utkast med privat passordfil, eksakt testvertsnavn, HTTPS-krav og begrensede unntak for OAuth discovery, klientregistrering, token og MCP. Bearer-token kreves fortsatt for kurslesing. Sett noindex på alle svar, også avvisninger, og ingen offentlige lenker eller sitemap. Apache/LiteSpeed-oppsettet må kontrolleres på denne verten før den helt lukkede oppstartssperren erstattes.
8. Kontroller faktisk administratorinnlogging, SQL-sesjoner, kurslesing og tilgangssperrer. Aktiver deretter bare denne testintegrasjonen og koble `Lissom – TEST` til ChatGPT. Dette krever eierens konkrete godkjenning før serverarbeidet.

`closed.htaccess` er en oppstartssperre, ikke den ferdige innloggingskonfigurasjonen. Hele testen er utilgjengelig mens den brukes. Webhotellets støtte for headere, SSL og mappebeskyttelse må verifiseres ved faktisk oppsett; lokal PHP-test er ikke en Apache-test.

Lag en pakke uten hemmeligheter med `python bin/pakk-mcp-test.py --output <ny-zipfil>`. Pakken er et oppsettutkast og aktiverer ingenting. Ikke bruk vanlig main-deploy til testmappen.
