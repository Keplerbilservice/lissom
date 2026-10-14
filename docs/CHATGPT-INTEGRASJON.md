# ChatGPT-integrasjon for Lissom – sikker implementeringsplan

Status: lokal PHP-lesefase implementert som videreføring av planen under, ikke aktivert. Ingen produksjonstilgang eller legitimasjon opprettet.

## Verifisert i eksisterende kode
- `api/admin/kurs.php` bruker `krev_admin()` og kan lese kurs med bildefelt.
- `api/admin/bilder.php` støtter bildeopplasting (multipart) og bruker admin-sesjon og opphavskontroll.
- `app/lib/auth.php` baserer administratorrettigheter på nettlesersesjonen.
- `api/admin/ai.php` lagrer AI-innhold som utkast før godkjenning.
- `revider()` skriver til `audit_log`, men er ikke i seg selv en ekstern OAuth-løsning.

## Arkitektur
Bygg en separat, versjonert MCP-tjeneste for ChatGPT (ikke eksponer eksisterende nettleserbaserte admin-endepunkter direkte). Bruk OAuth 2.1 med PKCE, ressursbundet tilgangstoken, korte levetider, scopes per verktøy og eksplisitt samtykke. Hemmeligheter lagres i serverens hemmelighetslager, aldri i repo eller ChatGPT-meldinger.

MCP-tjenesten autentiserer brukeren, autoriserer hver enkelt handling og kaller interne Lissom-tjenester med server-til-server-legitimasjon. Nettlesercookies må aldri videresendes. Bruk begrenset CORS, rate limiting, revisjonslogg og fail-closed ved feil.

## Faser
1. **Kun lesing**: liste kurs og hent ett kurs, uten sensitive persondata. Test autentisering, tilgangskontroll og logging.
2. **Bilder**: opplasting med type-, størrelse- og bildevalidering; lagre som ikke-aktivt medieobjekt. Forhåndsvis før bruk.
3. **Endre ett kursbilde**: krever eksakt kurs-ID, forventet tidligere bilde (optimistisk lås), eget endringsforslag og brukerbekreftelse. Oppdater bare `courses.bilde`; behold tidligere verdi for tilbakeføring.
4. **Bred administrasjon**: egne verktøy per domene (kurs, nettside, lager, ordre, rapporter) og minste nødvendige scopes.
5. **Høyrisiko**: betalinger, refusjoner, persondataeksport, sletting, tilgangsstyring og publisering krever separat bekreftelse og ekstra serverkontroll. Aldri generisk SQL-verktøy eller vilkårlig filskriving.

## Krav til sikkerhet og drift
- Alle skrivehandlinger bruker idempotency-key og revisjonslogg med aktør, ressurs, gammel/ny verdi og korrelasjons-ID.
- Ingen utlevering av booking- eller medlemsdata uten dokumentert behandlingsgrunnlag og tilgangsbegrensning (GDPR).
- Avvis oppgaver utenfor spesifisert ressurs, uventede felt og ukjent kurs-ID.
- Før utrulling: automatiserte tester for 401/403, scope-brudd, feil kurs-ID, samtidige endringer, CSRF/OAuth-state, tokenutløp, opplastingsfeil, rollback og logging.
- Staging først; verifiser PC/mobil, eksisterende booking og betaling. Produksjonsutrulling kun etter eierens godkjenning.
- ChatGPT-plugin må opprettes/tilkobles separat etter at MCP-tjenesten er driftssatt. GitHub-merge gir ikke ChatGPT automatisk tilgang.

## Første brukeroppgave
Finn «Lag din egen lyslykt», kontroller ID og gjeldende bilde, last opp valgt bilde som utkast, vis forhåndsvisning, få godkjenning, oppdater kun bilde, verifiser offentlig kursside og tilby tilbakeføring.

## Ikke gjør
Ikke endre dagens admin-API, sesjonshåndtering, kursdata, booking, database, deploy eller eksisterende bilder i denne PR-en.
# Videreføring 14.10.2026

PR #245 er videreført på `codex/lissom-integrasjon-20261014` med PHP, egen OAuth-server og SQL-lagring. Railway, ekstern OAuth-leverandør og Node-server er ikke nødvendig. Node brukes bare som testklient. Se [oppsett og faktisk testomfang](../app/integrasjon/README.md).

Lesefasen tilbyr to begrensede kursverktøy med eksisterende administratorinnlogging, samtykke, PKCE, ti minutters tilgangstoken og roterende fornyelse i maksimalt 24 timer fra opprinnelig samtykke. Hvert system får separat tilkobling og egne nøkler. Migrasjon 278 oppretter én tabell; den er ikke kjørt på serveren. Ingen serveraktivering eller ChatGPT-tilkobling er utført. Keplers adapter, bildeopplasting og bildebytte via MCP gjenstår. Begrensningene under «Ikke gjør» beskriver første PR #245; denne videreføringen forbereder database og deploy, men publiserer ikke.
