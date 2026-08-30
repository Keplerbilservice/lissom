# Gjenbruk: markedsføringsmodulen og adminlayouten

Denne fila sier nøyaktig hvilke filer de to delene består av, hva de henger
sammen med, og hva som må byttes ut i et nytt prosjekt. Ingenting er flyttet
eller kopiert — dette er oppskriften du følger når du starter det neste.

Skrevet 30. august 2026. Linjenumre er omtrentlige og flytter seg; navnene
gjør det ikke, så søk etter dem.

---

## Før du begynner: tre ting som gjelder begge delene

**1. Motoren under.** `lissom-2108.html` er én fil: markup øverst, all logikk
i `class Component` nederst, og `renderVals()` som binder dem sammen. Skal du
gjenbruke skjermer derfra, må det nye prosjektet ha den samme motoren
(dc-runtime + designsystemet i `ds-bundle.js`). Bruker du noe annet — React,
Vue, ren PHP — er det backend-filene og reglene som er verdt å ta med, ikke
markupen.

**2. Fire feller i den motoren.** De kostet dager her, og de er ikke synlige
før noe er galt:

- `renderVals()` returnerer ett flatt objekt. To felter med samme navn er
  ingen feilmelding — det siste vinner, og det første forsvinner i stillhet.
- `<sc-for>` inne i `<select>` er ikke gyldig HTML. Safari kaster løkka og
  valgene med den; Chrome tolererer det. Bruk brikker (vanlige knapper i en
  div). Se `Component.NEDTREKK` og `utenNedtrekk()`.
- `<img src="{{ ... }}">` binder ikke. Bruk `data-src`.
- Et `data-*`-attributt på samme element som en bundet handler gjør at
  handleren skrives ut som ren tekst.

**3. Vaktskriptene er halve verdien.** `bin/listesjekk.mjs`,
`knappesjekk.mjs`, `metodesjekk.mjs`, `skjemasjekk.mjs` og
`innholdssjekk.mjs` leser malen og sier fra om en knapp uten funksjon, et
felt uten kobling eller en løkke som peker på noe som ikke settes. De er
skrevet mot denne malen, men reglene er generelle — ta dem med, de finner
feilene ingen tester fanger.

---

## Del 1 — Markedsføringsmodulen

Det den gjør: skriver utkast til innlegg, nyhetsbrev og artikler med AI,
viser dem slik de faktisk blir, publiserer med ett klikk, holder styr på
søkeord og SEO-muligheter, og logger hva hvert AI-kall koster mot et tak
eieren setter selv.

### Filer

| Fil | Hva den gjør |
| --- | --- |
| `app/lib/ai.php` | Kaller Claude-API-et med curl. Modell, priser, kostnadslogg og månedstak. Ingen SDK — webhotellet har ingen pakkebehandler. |
| `api/admin/ai.php` | Lager utkast, godkjenner, forkaster, publiserer, sletter. Bygger konteksten (kurs, datoer, påmeldte) som AI-en får se. |
| `api/admin/marked.php` | Alt som ikke koster et AI-kall: tavla, SEO-muligheter, søkeord, analyse, innstillinger. Sender også oppsettet for hver utkasttype (bildeformat 16:9 / 3:2). |
| `api/admin/artikler.php` | Artiklene som publiseres: opprett, rediger, publiser, slett. |
| `app/lib/artikler.php` | Reglene rundt en artikkel: `ledigTittel()`, `tittelTatt()`, oppslag. |
| `app/lib/lenker.php` | `Lenker::slug()` — adressen en artikkel får. Deles med kurs og butikk. |
| `api/admin/bilder.php` | Opplasting og skalering av bildene som følger et utkast. |
| `api/admin/shutterstock.php`, `shutterstock-kobling.php` | Bildesøk mot Shutterstock. Valgfritt — kan droppes helt. |

### Databasen

| Migrasjon | Tabeller |
| --- | --- |
| `db/migrations/018_verkstedinnhold.sql` | `articles` (og `recipes`, `links` — de to hører til verkstedet, ikke markedsføringen) |
| `db/migrations/024_markedsforing.sql` | `ai_utkast`, `ai_logg`, `marked_sokeord` |
| `db/migrations/063_artikkel_publisering.sql` | publiseringsfeltene på `articles` |
| `db/migrations/064_artikkel_bilder.sql` | `artikkel_bilder` |
| `db/migrations/070_artikkel_bildetekst.sql` | bildetekst |

`content_blocks` (fra `018`) brukes som innstillingslager for GA-id, AI-tak
og Google-kobling. Har det nye prosjektet et annet sted for innstillinger,
er det de tre nøklene som må flyttes.

### Skjermen

I `lissom-2108.html`:

- Markup: `<sc-if value="{{ erAdminMarked }}">` → `data-screen-label="Admin –
  markedsføring"` (rundt linje 12466 og ~700 linjer ned).
- Logikk: `hentMarked()`, `hentMarkedArtikler()`, `aiKall()`, `mkLes()` og
  verdiene som begynner på `mk` og `ai`.
- Forhåndsvisningen: `mkLesErArtikkel` / `mkLesErBrev` / `mkLesErSosialt` —
  tre oppsett, ett per kanal, med bildet plassert som det faktisk blir.
  `mkLesPubliser` er ettklikks-publiseringen.

### Dette må byttes i et nytt prosjekt

1. **API-nøkkelen** ligger i `app/secrets.php` på serveren, aldri i repoet.
2. **Modellen og prisene** står i `AI::MODELL` og prislista rett under. De
   endrer seg; sjekk dem når du setter opp.
3. **Konteksten AI-en får** er full av keramikk: kurs, økter, påmeldte,
   glasur. Den bygges i `api/admin/ai.php` og må skrives om for et annet
   fag. Det er her mesteparten av tilpasningsjobben ligger.
4. **Tonen og malene** — teksten AI-en bes om å skrive — står i de samme
   promptene.
5. **Taket per måned** står som en innstilling, ikke i koden. Sett det lavt
   først; et løpsk skript koster ekte penger.

### Tre regler modulen bygger på

- Ingenting oppdiktes. Mangler nøkkelen, sier skjermen det — den viser ikke
  en «eksempeltekst» som ser ut som noe AI-en skrev.
- Hvert kall logges med tokens og anslått kostnad. Uten det vet ingen hva
  det koster før fakturaen kommer.
- Et utkast er et utkast til noen har trykket publiser. Ingenting går ut av
  seg selv.

---

## Del 2 — Adminlayouten

Det den gjør: én ramme rundt alle adminskjermene. Sidemeny på skjerm,
«hvor du er» + Meny-pille på telefon, kort med tall på forsiden, én
detaljdialog, én kvitteringsstripe, og brikker i stedet for nedtrekk.

### Delene, i den rekkefølgen du trenger dem

**1. Rammen.** `adminGridStil`, `adminAsideStil` og `adminNavStil` i
`renderVals()` (rundt linje 39221). Under 980 px blir sidemenyen til en
stripe på toppen; over blir den en spalte på 248 px. Hver adminskjerm er
bygget likt:

```html
<div data-screen-label="Admin – …" style="{{ adminGridStil }}">
  <aside class="lx-adminaside" style="{{ adminAsideStil }}">…</aside>
  <main style="padding: …">…</main>
</div>
```

**2. CSS-en som hører til rammen.** `@media (max-width: 979px)` rundt linje
1204: `.lx-adminaside`, `.lx-admmob`, `.lx-admmobpanel`, `.lx-admstatus`.
Det er her mobilmenyen slås på og sidemenyen av.

**3. Menyen.** `Component.ADMIN_MENY` (linje ~23715) er hele kartet: navn,
rute og forvalg. `adminMeny()` bygger radene, `adminMobilMeny()` bygger
telefonutgaven med underpunkter, `adminSted()` vet hvor du er, og
`gaaAdmin(rute, forvalg)` går dit. Legger du til en rad i `ADMIN_MENY`,
dukker den opp begge steder — det er hele poenget med at lista står ett sted.

**4. Kortene på forsiden.** `const kort = (navn, hva, verdi, rute, forvalg,
knapp, haster)` (linje ~29767). Hvert kort er klikkbart, tallet avgjør om du
må dit, og rekkefølgen kan dras og lagres per bruker.

**5. Detaljdialogen.** `apne(tittel, undertittel, felter, handling, mål,
skjema, gjør)` lager en åpner; `klikkbar(liste, spec)` gjør en hel liste
klikkbar; `utforDetalj()` utfører handlingen. Markupen står ett sted (linje
~14042) og brukes av alle skjermene. Én dialog, ikke tjue.

**6. Brikker i stedet for nedtrekk.** `Component.NEDTREKK`, `brikkeliste()`,
`velgUt()` og `utenNedtrekk()`. Tabellen er `[lista, valgt verdi, setteren]`,
og setteren er den samme funksjonen nedtrekket hadde — brikka kaller den med
`{ target: { value } }`. Ta med hele mekanismen; den er grunnen til at
skjemaene virker på iPhone.

**7. Kvitteringen.** `kvittering` + `kvitteringDetalj` i state, og
`varselAutolukk()` som lukker den etter tre sekunder. Én stripe for hele
admin.

**8. Todelingen offentlig/admin.** `bin/adminsjekk.mjs` bygger
`…-uten-admin.html`: samme fil uten adminskjermene. `side.php` serverer den
lette utgaven til alle som ikke er innlogget som admin — her 1,2 MB mot 2,5.
**Husk:** redigerer du admin, må skriptet kjøres, ellers ser du ikke
endringen som ikke-admin.

### Dette må byttes i et nytt prosjekt

1. **Fargene og typografien** ligger i `ds-colors.css`, `ds-typography.css`
   og `ds-spacing.css` som CSS-variabler (`--lissom-brown`, `--clay-100`,
   `--terracotta-600` …). Bytt verdiene, ikke navnene, så følger hele admin
   med.
2. **Logoen** i `<aside>` (`logo-lockup-yellow.svg`).
3. **`ADMIN_MENY`** — punktene er Lissoms.
4. **`stemplingPiller()`** og statuslinja «Verkstedet står som stengt» er
   verkstedspesifikt. Fjern eller bytt.
5. **`adminHilsen()` og `norskDagsdato()`** — hilsenen på forsiden.
6. **`erAdminSkjerm(side)` og `STIER`** — adressene.

---

## Det som ikke kan følge med

- `app/secrets.php` — nøkler til Vipps, e-post og AI.
- Databasen — persondata.
- De 63 Shutterstock-bildene. Lisensen gjelder kjøperen og bruken den ble
  kjøpt til.
- Bilder av Monica, deltakere og verkstedet — personer må samtykke til ny
  bruk.
- Logo, ordmerke og navnet Lissom.
- Tekstene om kurs, «Om oss», vilkår og personvern.

Fri å ta med, med sine egne vilkår: `vendor/react*.js` og
`vendor/qrcode-2.0.4.js` (MIT — behold copyright-linjene), og fontene i
`fonts/` (Alegreya Sans og Bitter, Open Font License).

---

## Rekkefølgen jeg ville gjort det i

1. Sett opp det nye prosjektet med `app/db.php`, `app/lib/oppsett.php`,
   migrasjonssystemet og `bin/migrate.php`. Det er fundamentet, og det er
   lite.
2. Ta adminlayouten før innholdet. En skjerm uten ramme blir aldri ryddig
   i etterkant.
3. Ta med vaktskriptene med det samme, ikke til slutt.
4. Så markedsføringsmodulen, med et lavt kostnadstak fra første dag.
