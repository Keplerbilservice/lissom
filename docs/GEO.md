# GEO — å bli sitert av en AI

*Skrevet 1. september 2026, etter at eieren spurte:*

> «Markedsføring menyen, jeg er blitt fortalt at noe heter GEO som er med ai
> chat gpt, jeg vil optimalisere siden for dette også. Og jeg vil ha samme
> knapp som seo, optimaliser for geo kan jeg det? Forklar før du bygger.»

---

## Hva GEO er, og hvorfor det ikke er SEO

SEO handler om å komme høyt i en trefflste. GEO — *generative engine
optimization* — handler om noe annet: når noen spør ChatGPT eller Perplexity
«hvor kan jeg ta keramikkurs i Tønsberg?», er det ingen trefflste. Det
kommer ett svar, med én eller to kilder. Enten er Lissom i det svaret, eller
så finnes stedet ikke for den som spurte.

Det gjør at reglene er andre:

| | SEO | GEO |
|---|---|---|
| Målet | rangere blant ti treff | være setningen som siteres |
| Tittellengde | teller (50–60 tegn) | likegyldig |
| Meta description | vises i treffet | leses knapt |
| Tall i teksten | pynt | avgjørende |
| «Vi» og «hos oss» | greit | ødelegger |

Den siste linja er kjernen. En AI klipper ut én setning og limer den inn i
et svar. Står det «vi holder kurs hver onsdag», vet ikke leseren hvem «vi»
er — setningen er verdiløs løsrevet fra sida. Står det «Lissom Keramikk i
Nordre Løkkevei 15 på Teie i Tønsberg holder kurs i dreiing», kan den limes
rett inn og fortsatt være sann og forståelig.

## Hva scoren er — og hva den ikke er

Google har Search Console: du kan se hva du rangerer på. OpenAI har ingenting
tilsvarende. **Ingen kan måle om ChatGPT faktisk nevner Lissom.**

Scoren på GEO-skjermen er derfor vår egen tommelfingerregel. Den måler om
sida er *formet* slik at den lar seg sitere. Den kan ikke måle om noen gjør
det. Det står også på skjermen, i ruta øverst — et tall som later som det vet
mer enn det gjør, er verre enn ingen tall.

Trekkene, fra `geoScore(d)` i `lissom-2108.html`:

| Trekk | Hvorfor |
|---|---|
| −30 mangler kort svar | det er dette som siteres |
| −22 mangler spørsmålet | uten det vet ingen hva svaret svarer på |
| −18 mangler fakta | et svar uten opplysninger er en påstand |
| −12 svaret har ingen tall | pris, varighet eller antall gjør det konkret |
| −10 svaret sier ikke hvor det er | «keramikkurs» finnes overalt |
| −10 svaret er for kort (< 60 tegn) | står ikke alene |
| −8 svaret sier «vi» eller «oss» | meningsløst løsrevet |
| −8 står ikke hvem det passer for | |
| −8 færre enn tre fakta | |
| −6 svaret er for langt (> 220 tegn) | siteres ikke helt |
| −6 ingen av faktaene har et tall | |
| −6 spørsmålet mangler spørsmålstegn | |

## Hvor det ligger

**Skjermen:** Admin → Markedsføring → GEO, eller Nettsiden → GEO.
Adressen er `/admin/geo`. Den speiler SEO-skjermen: snittscore, liste over de
tjue sidene med hver sin score, felteditor og «Optimaliser for GEO».

**Fire felt per side**, lagret som `GEO/<id>` i `content_blocks` — samme sted
som SEO-tekstene:

| Felt | Maks | Hva |
|---|---|---|
| `sporsmal` | 90 | slik en kunde ville spurt |
| `kortSvar` | 220 | setningen som skal siteres |
| `fakta` | 400 | én per linje: pris, varighet, sted |
| `hvemFor` | 120 | kort |

**Koden**, alt i `lissom-2108.html`:

- `geoFeltDef()` — feltene
- `geoLagret(id)` / `geoData(id)` — lagret tekst, og lagret + utkast
- `geoScore(d)` — tabellen over
- `Component.GEO_FERDIG` — ferdigskrevne setninger for alle tjue sidene
- `geoForslag(s)` — den ferdige teksten med tallene fra katalogen lagt på
- `geoStrukturert(d, sd)` — FAQPage-markeringen til `<head>`
- verdiblokka bak `if (side !== 'admingeo') return {};`

## Prisene står aldri i teksten

Dette er den viktigste regelen i `GEO_FERDIG`. Ingen av de tjue ferdigskrevne
setningene har et kronebeløp i seg. Prisen, varigheten og antall datoer
hentes fra katalogen i `geoForslag()` og legges på som en egen setning:

    kortSvar: pris ? f.svar.replace(/\.$/, '') + '. Prisen er ' + pris + '.' : f.svar

Grunnen: en pris skrevet inn i teksten blir stående igjen som feil den dagen
Monica endrer den — og en AI siterer et gammelt tall like villig som et nytt.
Finner vi ikke kurset i katalogen, står setningen uten pris. Et forslag som
gjetter prisen er verre enn ingen forslag.

`tests/backend.php` sjekker at ingen av de tjue setningene har `kr.` eller
`kroner` i seg, og at alle er 60–220 tegn, har et tall, sier hvor det er, og
ikke sier «vi» eller «oss». Legger noen inn en tekst som bryter reglene sine
egne, blir prøvene røde.

## Hva GEO legger igjen utenfor admin

Tre steder, og bare det ene av dem er i selve sida:

**1. `<head>` på hver offentlig side** — en `FAQPage` i den strukturerte
dataen, ved siden av `LocalBusiness`, `Course` og `Product` som lå der fra
før. Bygget av `sporsmal` + `kortSvar` + `fakta`. Kommer bare når begge de
to første finnes: halv markering lover et svar som ikke står der. Det andre
spørsmålet — «Hvem passer X for?» — settes bare på kurs og events; «Hvem
passer kontakt for?» er ikke noe noen har spurt om.

**2. `lissom.no/llms.txt`** (`api/llms.php`) — et avsnitt «Spørsmål og svar»
med alle sidene som har både spørsmål og svar, hver med faktalinjene og en
`Kilde:`-lenke. Kursadressene slås opp på tittelen mot `courses.slug`, så en
`Kilde:` aldri peker feil når et kurs døper om seg. Se filhodet i
`api/llms.php` for hvorfor fila er generert og ikke skrevet for hånd.

**3. `robots.txt`** — søkerobotene til ChatGPT (OAI-SearchBot),
Perplexity (PerplexityBot) og Claude (Claude-SearchBot) slippes inn på de
åpne sidene, og treningsrobotene (GPTBot, ClaudeBot, Google-Extended,
Applebot-Extended) likeså. Alle åtte gruppene gjentar de samme fem
`Disallow`-linjene, for admin, Min side, kassa og API-et.

> **Merk:** en navngitt `User-agent`-gruppe i robots.txt leser *bare* sin egen
> gruppe og ser bort fra `User-agent: *`. Legger noen til en ny robot uten å
> gjenta `Disallow`-linjene, har den full tilgang til admin.

Nederst på GEO-skjermen står disse fire som en liste, med hva hver av dem
gjør. Ellers ville eieren ikke hatt noen måte å se at de finnes — det er
filer og hodemarkering ingen åpner i en nettleser.

## Slik brukes den

1. Admin → Markedsføring → GEO
2. **«Fyll inn og lagre»** fyller alle tjue sidene med de ferdigskrevne
   setningene og tallene fra basen. Felt som allerede er utfylt røres ikke.
3. Velg en side i lista til venstre for å endre den. **«Foreslå GEO»** fyller
   bare de tomme feltene; **«Erstatt alt med forslag»** bytter ut alt.
4. Ruta «Slik kan det bli sitert» viser setningen slik den står alene i et
   AI-svar, uten sida rundt. Det er der «vi holder kurs hver onsdag» faller
   fra hverandre.
5. Ingenting går ut på nettsiden før **Lagre**.

## Prøver

- `tests/backend.php` — ruta, menyene, lagringsnøkkelen, hvert enkelt trekk i
  scoren, alle tjue ferdigtekstene mot sine egne regler, FAQPage-reglene og
  de tre stedene i `api/llms.php`
- `bin/dropinsjekk.mjs` — åpner `/admin/geo` sammen med alle de andre
  adressene i `STIER`, på 390 px og 1400 px
- `bin/adminsjekk.mjs` — skjermen ligger bak admin, så den lette utgaven av
  fila må bygges på nytt etter hver endring

## Det som ikke er gjort

- **Kurssidene har ingen egne GEO-felt.** De tjue sidene i `seoSider()` er
  faste. Et nytt kurs får ikke sin egen `sporsmal`/`kortSvar` — det arver
  det som står på `/kurs`. Skal hvert kurs ha sitt eget, må feltene inn i
  kursoppsettet, ikke i denne lista.
- **Ingen måling.** Se «Hva scoren er» over. Skulle det bli mulig å måle
  sitering, er det her det hører hjemme.
