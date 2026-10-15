# Codex / AI-agenter — Lissom

Les og følg hele `CLAUDE.md` før du gjør noe. Den inneholder eierens bindende arbeidsregler, blant annet at kundesiden ikke skal endres uten spesifikk bestilling, at ny kundevendt tekst må godkjennes og at godkjente endringer skal registreres i `tests/godkjent/vedtak.json`.


## Endringskontroll for alle AI-agenter (Codex, Claude og andre)

- Les denne filen og prosjektets CLAUDE.md før arbeid. Ved konflikt: følg strengeste sikkerhetsregel og spør eieren.
- Endre bare det som er eksplisitt bestilt og teknisk nødvendig. Ikke refaktorer, formater nabofiler, oppgrader pakker eller forbedre annet på eget initiativ.
- Før endring: beskriv oppgaven, forventede filer, berørte funksjoner og testplan. Hvis en ekstra fil eller funksjon må endres, be om godkjenning først.
- Respekter eksisterende arbeid og ucommittede endringer. Aldri reset, revert, force-push eller slett andres endringer uten uttrykkelig godkjenning.
- Ikke endre kundevendt tekst, UI, databaser, migrasjoner, autentisering, betaling eller deploy uten uttrykkelig godkjenning for akkurat den endringen.
- Før levering: kontroller git diff mot oppgavens godkjente omfang, kjør relevante tester og oppgi nøyaktig hvilke tester som faktisk er kjørt.
- Lever på egen gren som pull request med filoversikt, begrunnelse, risiko og testresultater. Ikke merge, deploy eller publiser uten eierens godkjenning.
- Instruksjoner alene er ikke en teknisk sperre. Bruk GitHub branch protection/rulesets og obligatoriske statuskontroller der det er tilgjengelig.

