# Markedsføringsmodulen — overlevering

En AI-drevet markedsføringsmodul for adminen i et lite nettsted: skriver
utkast til artikler, nyhetsbrev og innlegg i sosiale medier, viser dem slik de
faktisk blir før de går ut, publiserer med ett klikk, holder styr på søkeord og
SEO-muligheter, og logger hva hvert AI-kall koster mot et tak eieren setter
selv.

Dokumentet er skrevet for å bygges fra, ikke for å leses ved siden av en
kodebase. Alt som trengs står her: datamodellen, endepunktene, promptene,
skjermene og reglene. Det er kjørt i produksjon på ett nettsted; alt som var
knyttet til det nettstedet er tatt bort og erstattet med et tydelig
tilpasningspunkt.

Koden det er hentet fra er PHP 8.4 mot MariaDB, uten rammeverk og uten
pakkebehandler. Ingenting i utformingen krever det. Datamodellen og
endepunktene er de samme uansett språk.

---

## 1. Tre regler modulen bygger på

Disse er ikke pynt. De er grunnen til at modulen kan stå på et nettsted som
drives av én person uten teknisk bakgrunn.

**Ingenting oppdiktes.** Mangler API-nøkkelen, sier skjermen det rett ut. Den
viser aldri en «eksempeltekst» som ser ut som noe modellen skrev. Er det ingen
data å bygge et nyhetsbrev på, sier den det i stedet for å finne på noe.

**Hvert kall logges med tokens og anslått kostnad.** Uten det vet ingen hva det
koster før fakturaen kommer. Det finnes et tak per måned som eieren setter
selv, og når taket er nådd, stopper kallene.

**Et utkast er et utkast til noen har trykket publiser.** Ingenting går ut av
seg selv. Ingen planlagt automatikk, ingen «post dette på fredag» uten at et
menneske har lest teksten.

---

## 2. De ti fanene

Modulen er én adminskjerm med et fanevalg. Fanen ligger i `state`, ikke i
adressen, bortsett fra SEO som er sin egen skjerm (den ble for stor).

| Fane | Hva den er |
| --- | --- |
| **Tavle** | Startsiden. Utkast som venter, hva som er godkjent, forbruk mot taket denne måneden, og autopilotens forslag til uka. |
| **Produktmarkedsføring** | Hele pakka for ett produkt: artikkel, Facebook-innlegg, Instagram-innlegg, emneknagger, e-post til kundelista, melding til eksisterende kunder. Ett kall, seks tekster. |
| **Artikler** | Lag en artikkel eller guide om et emne du skriver inn. |
| **Kunnskapsbank** | Artiklene som finnes: rediger, publiser, avpubliser, slett, planlegg. |
| **Nyhetsbrev** | Månedsbrev, sesongbrev, kampanje, kundebrev. Bygger på det som faktisk ligger i katalogen. |
| **Sosiale medier** | Innlegg, story, reel-manus eller karusell til Instagram, Facebook, TikTok eller LinkedIn. |
| **SEO** | Egen skjerm. Søkeord, målsider, score per side, muligheter som er regnet ut av databasen. |
| **Analyse** | Det nettstedet vet selv om salg og bookinger, pluss Google Analytics om det er koblet til. |
| **Assistent** | Fritekstspørsmål om egen drift. Svarer på tall den faktisk har. |
| **Innstillinger** | API-nøkkelstatus, tak for AI-bruk, Google Analytics-id, Google-kobling. |

«Produktmarkedsføring» gjaldt opprinnelig én vare med datoer og ledige
plasser. Den er generalisert her: den gjelder én ting i katalogen, med de
hendelsene og den kapasiteten som hører til den. Har prosjektet ditt produkter
uten datoer, faller datodelen bort av seg selv — prompten bygger bare på det
som finnes.

---

## 3. Datamodellen

Tre nye tabeller. Alt annet henger på artikkeltabellen prosjektet
sannsynligvis har fra før.

### 3.1 `ai_utkast` — alt modellen foreslår

Ett bord for alle typer. `type` sier hva det er, og `data` bærer det som er
særegent for hver type.

```sql
CREATE TABLE ai_utkast (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type        ENUM('artikkel','nyhetsbrev','sosialt','seo','produktpakke','kundebrev') NOT NULL,
  tittel      VARCHAR(191) NOT NULL,
  tekst       MEDIUMTEXT NULL,
  data        JSON NULL COMMENT 'Kanal, emneknagger, produkt-id — det som varierer med typen',
  kontekst    VARCHAR(191) NULL COMMENT 'Hva utkastet gjelder, så sammenhengen er synlig i lista',
  status      ENUM('utkast','godkjent','publisert','forkastet') NOT NULL DEFAULT 'utkast',
  resultat_id BIGINT UNSIGNED NULL COMMENT 'Artikkelen som ble laget av det',
  kostnad_ore INT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_ai_utkast_type (type, status),
  KEY ix_ai_utkast_tid (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Hva `data` inneholder, per type:

| Type | Nøkler i `data` |
| --- | --- |
| `artikkel` | `ingress`, `fokusord`, `metabeskrivelse`, `kategori`, `bilde` |
| `seo` | `ingress`, `metabeskrivelse`, `slug`, `sokeord` |
| `sosialt` | `kanal`, `form`, `hashtags[]`, `bildeforslag`, `bilde` |
| `nyhetsbrev` | `slag`, `bilde` |
| `kundebrev` | `bilde` |
| `produktpakke` | hele JSON-svaret: `artikkel{tittel,tekst}`, `facebook`, `instagram`, `hashtags[]`, `epost{emne,tekst}`, `eksisterende` |

### 3.2 `ai_logg` — hvert kall, med kostnad

```sql
CREATE TABLE ai_logg (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  formal      VARCHAR(64) NOT NULL,
  modell      VARCHAR(64) NOT NULL,
  tokens_inn  INT UNSIGNED NOT NULL DEFAULT 0,
  tokens_ut   INT UNSIGNED NOT NULL DEFAULT 0,
  kostnad_ore INT UNSIGNED NOT NULL DEFAULT 0,
  ok          TINYINT(1) NOT NULL DEFAULT 1,
  feil        VARCHAR(500) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY ix_ai_logg_tid (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Mislykkede kall logges også, med `ok = 0` og feilteksten. Det er de som
forteller hvorfor noe sluttet å virke.

### 3.3 `marked_sokeord` — søkeordene du vil bli funnet på

```sql
CREATE TABLE marked_sokeord (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  ord        VARCHAR(191) NOT NULL,
  maalside   VARCHAR(64) NULL COMMENT 'Hvilken side som skal svare på søket. Tom = ikke bestemt',
  notat      VARCHAR(500) NULL,
  sortering  INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sokeord (ord)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 3.4 Artikkeltabellen

Modulen forutsetter en artikkeltabell. Har du en, trenger den disse feltene:

```sql
ALTER TABLE articles
  ADD COLUMN kategori     VARCHAR(64)  NULL,
  ADD COLUMN slug         VARCHAR(191) NULL,
  ADD COLUMN fokus_ord    VARCHAR(191) NULL,
  ADD COLUMN kilde        VARCHAR(16)  NOT NULL DEFAULT 'manuell',  -- 'manuell' | 'ai'
  ADD COLUMN publisert_at DATETIME     NULL,
  ADD COLUMN publisert_av BIGINT UNSIGNED NULL,
  ADD COLUMN planlagt_til DATETIME     NULL,
  ADD COLUMN bilde_tekst  VARCHAR(255) NULL,
  ADD COLUMN bilde_alt    VARCHAR(255) NULL;

CREATE UNIQUE INDEX uq_articles_slug ON articles (slug);
```

`kilde` er verdt å ta med: den lar eieren se hva som er skrevet av hvem.

**En felle å kjenne til.** Hvis `articles.tittel` er UNIQUE, kolliderer to
utkast om det samme emnet, og hele databasefeilen kommer opp på skjermen i
stedet for publiseringen. Løsningen er en `ledigTittel()` som setter et tall
bak — og som *sier fra* at den gjorde det, så eieren kan gi artikkelen et
bedre navn framfor å lure på hva som skjedde:

```
Det fantes en artikkel med samme overskrift, så denne heter «Vedlikehold (2)».
Gi den gjerne et bedre navn under Kunnskapsbank.
```

Samme regel for `slug`: `grunnform`, `grunnform-2`, `grunnform-3`.

### 3.5 Innstillinger

Tre nøkler lagres som vanlige innstillinger (i kildeprosjektet: en
nøkkel/verdi-tabell). Har prosjektet ditt et annet innstillingslager, er det
disse tre som må flyttes:

| Nøkkel | Hva |
| --- | --- |
| `Marked/AI-tak` | Tak i kroner per måned. Tomt = standarden i koden. |
| `Marked/GA-id` | Google Analytics måle-id. Tomt = analysefanen sier at den ikke er koblet til. |
| `Marked/Google` | Kobling mot Google Search Console / Business. |

API-nøkkelen ligger **ikke** her. Den hører hjemme i en fil utenfor repoet.

---

## 4. AI-laget

Én klasse. Den kaller `https://api.anthropic.com/v1/messages` direkte over
HTTP — ingen SDK, fordi kildeprosjektet ikke hadde pakkebehandler, og fordi ett
endepunkt er alt som trengs.

### 4.1 Konstantene som må sjekkes ved oppsett

```php
const MODELL          = 'claude-opus-5';   // sjekk hva som er nyeste
const PRIS_INN_USD    = 5.00;              // dollar per million tokens inn
const PRIS_UT_USD     = 25.00;             // dollar per million tokens ut
const KRONER_PER_DOLLAR = 11.0;            // anslag, ikke et regnskapstall
const TAK_STANDARD_KR = 300;               // om eieren ikke har satt sitt eget
```

Prisene endrer seg. Alle steder kostnaden vises står det «ca.», nettopp fordi
kursen er et anslag og ikke hentes.

### 4.2 De to kallene

```php
AI::spor(string $system, string $bruker, string $formal, int $maksTokens = 8000): array
// → ['tekst' => ..., 'kostnadOre' => ..., 'tokensInn' => ..., 'tokensUt' => ...]

AI::sporJson(string $system, string $bruker, string $formal, int $maksTokens = 8000): array
// → det modellen svarte, som liste
```

`sporJson()` legger til én linje på systemprompten:

> Svar med gyldig JSON og ingenting annet. Ingen forklaring, ingen kodeblokk.

og klipper likevel bort ``` ```json … ``` `` hvis modellen rammer det inn. Det
er billigere enn å kalle en gang til.

`$formal` er en kort etikett som havner i loggen — `artikkel`, `nyhetsbrev`,
`sosialt`. Den er det som gjør forbruksoversikten lesbar.

**Tidsavbruddet må være langt.** Kildeprosjektet brukte 20 sekunder overalt
ellers; her er det 120. En lang artikkel tar tid.

### 4.3 Rekkefølgen i `spor()`

1. Ingen nøkkel → kast med en beskjed eieren kan gjøre noe med.
2. Over taket denne måneden → kast, og si hvor taket settes.
3. Kall modellen.
4. Ikke-200 → logg med `ok = 0`, oversett feilen, kast.
5. Plukk ut tekstblokkene fra `content[]`.
6. Regn ut kostnaden fra `usage.input_tokens` og `usage.output_tokens`, logg.
7. Tomt svar → kast.

### 4.4 Feiloversettelsen

Dette er det som skiller en modul en ikke-teknisk eier kan bruke fra en som
krever at noen ser i loggen. Fire tilfeller er verdt å oversette:

| Situasjon | Det eieren får se |
| --- | --- |
| 401 | «Nøkkelen ble ikke godtatt. Sjekk `api_key` i innstillingsfila.» |
| 429 | «For mange kall på kort tid. Vent et minutt og prøv igjen.» |
| 5xx | «Tjenesten svarer ikke akkurat nå. Prøv igjen om litt.» |
| Melding inneholder `credit` eller `billing` | «Kontoen har ikke dekning. Fyll på under Billing.» |

Alt annet: «AI-en svarte ikke: {meldingen}».

Endepunktet setter en unntakshåndterer som gjør `RuntimeException` til HTTP 400
med teksten som svar. Uten den blir en manglende nøkkel til en 500 med filsti
og linjenummer, og eieren sitter igjen med `lib/ai.php:123` i stedet for hva
hun skal gjøre.

### 4.5 Kostnadsregningen

```php
usd  = (inn / 1_000_000) * PRIS_INN + (ut / 1_000_000) * PRIS_UT
ore  = round(usd * KRONER_PER_DOLLAR * 100)
```

Forbruk denne måneden = `SUM(kostnad_ore)` fra månedens første dag, regnet i
lokal tidssone, ikke UTC. Ellers hopper taket rundt månedsskiftet.

Én ting som er lett å miste: `sporJson()` returnerer bare svaret, og da
forsvinner kostnaden. Den som lagrer utkastet trenger den. Løsningen er en
statisk `sisteKostnad()` som settes ved hvert vellykkede kall — ellers står
alle utkast med null, og forbruksoversikten stemmer ikke med loggen.

---

## 5. Konteksten — her ligger tilpasningsjobben

Hvert kall får to tekstblokker foran oppgaven: **fakta** og **stemme**. Det er
disse som gjør at modellen skriver om *din* virksomhet og ikke generisk prosa.

**Dette er punktet der mesteparten av tilpasningsarbeidet ligger.** Alt annet i
modulen er nøytralt.

### 5.1 Fakta

En funksjon som bygger en tekstblokk av det som faktisk står i databasen.
Mønsteret, generisk:

```
Om virksomheten du skriver for:
{navn}, {adresse}.
{telefon}, {e-post}, {sosiale kanaler}.
{én til tre setninger om hva stedet er}
{det som alltid er inkludert / alltid gjelder}

Det vi tilbyr:
- {produkt} ({kategori}, {pris})
- ...

{Eventuelle abonnement eller medlemskap:}
- {navn}: {pris}, {hva det gir}
```

Hent det fra basen, ikke skriv det i koden. Da følger det med når eieren legger
inn noe nytt.

### 5.2 Stemme

Skrivereglene. Samme stemme uansett hvilken knapp som ble trykket. Denne kan
brukes nesten som den står — bytt bare ut den bransjespesifikke linja:

```
Slik skal du skrive:
- Norsk bokmål. Naturlig, varmt og konkret — som et lite sted som kjenner
  kundene sine.
- Aldri salgsspråk eller floskler. Ikke «unik opplevelse», ikke «ta kontakt
  i dag!», ikke utropstegn på rekke.
- Skriv om det som faktisk skjer hos oss: {det konkrete i din bransje}.
- Ingen påstander du ikke har dekning for i fakta over. Finn aldri på
  priser, datoer eller antall.
- Er noe uklart, skriv rundt det framfor å gjette.
```

### 5.3 Katalogen — det modulen trenger fra prosjektet ditt

Modulen spør etter fem ting. Definer dem som ett lite grensesnitt, så er den
resten av veien uavhengig av hva slags nettsted den står på.

| Hva | Brukes av |
| --- | --- |
| `produkter()` — navn, kategori, pris, beskrivelse, status | fakta, produktpakke, produktbeskrivelse |
| `hendelserFramover(uker)` — produkt, tidspunkt, kapasitet, ledig | nyhetsbrev, autopilot, assistent |
| `kunderAntall()` | kundebrev, assistent |
| `salgSiste(dager)` | analyse, assistent |
| `sider()` — adresse, tittel, metabeskrivelse | SEO-muligheter |

Har prosjektet ditt ikke hendelser med kapasitet, returner tom liste. Da faller
nyhetsbrevet og autopiloten tilbake på en beskjed om at det ikke er noe å
fortelle om — som er riktig oppførsel, ikke en feil.

---

## 6. Promptene

Alle prompter får `fakta + stemme + oppgaven` som systemprompt. Det som står
under er oppgavedelen. `{krøllparenteser}` er det som settes inn.

### 6.1 Artikkel

**System:**
> Du skriver en artikkel til nettsidens kunnskapsbank. Den skal hjelpe leseren
> med noe konkret, ikke selge. En som søker på emnet skal finne svaret her.
>
> Svar med JSON: `{"tittel": "...", "ingress": "...", "innhold": "...", "fokusord": "...", "metabeskrivelse": "..."}`
> `innhold` er ren tekst med avsnitt skilt av doble linjeskift, 400–700 ord.
> Bruk mellomtitler på egne linjer der det hjelper lesingen.
> `metabeskrivelse` er 120–155 tegn.

**Bruker:** `Emne: {emne}` og eventuelt `Kategori: {kategori}`
**Maks tokens:** 8000

### 6.2 Produktpakke

**System:**
> Du lager markedsføringen for ett bestemt produkt. Alt skal bygge på
> opplysningene som står under — finn aldri på nye.
>
> Svar med JSON: `{"artikkel": {"tittel": "...", "tekst": "..."}, "facebook": "...", "instagram": "...", "hashtags": ["..."], "epost": {"emne": "...", "tekst": "..."}, "eksisterende": "..."}`
> `artikkel.tekst` er 250–400 ord til nettsida. `facebook` er 3–6 setninger.
> `instagram` er kortere, med linjeskift. `hashtags` er 5–8 uten
> emneknagg-tegnet. `epost.tekst` er en kort e-post til kundelista.
> `eksisterende` er 2–3 setninger til dem som allerede er kunder.

**Bruker:** produktet med pris, beskrivelse og eventuelle datoer med ledige plasser
**Maks tokens:** 10000

### 6.3 Nyhetsbrev

`{slag}` er én av: månedens nyhetsbrev, sesongbrev, kampanje, kundebrev.

**System:**
> Du skriver et {slag} til folk som har handlet hos oss eller står på lista.
> Bygg det på det som står under. Finn aldri på datoer, priser eller antall.
>
> Svar med JSON: `{"emne": "...", "tekst": "..."}`
> `emne` er e-postemnet, under 60 tegn, uten utropstegn.
> `tekst` er selve brevet, 200–350 ord, avsnitt skilt av doble linjeskift.
> Avslutt uten signatur — den legges på av systemet.

**Bruker:** liste over det som skjer de neste åtte ukene, med dato, ledig antall og pris
**Maks tokens:** 8000

Er lista tom, kall aldri modellen. Svar i stedet:

> Det er ingenting lagt ut de neste åtte ukene. Legg ut noe først, så har
> nyhetsbrevet noe å fortelle om.

### 6.4 Sosiale medier

`{kanal}` ∈ Instagram, Facebook, TikTok, LinkedIn.
`{form}` ∈ innlegg, story, reels, karusell — oversatt til:

| Form | Vink |
| --- | --- |
| innlegg | et vanlig innlegg |
| story | en kort story-tekst, maks to setninger |
| reels | manus til en reel: hva som skjer i bildet, og teksten som leses eller står |
| karusell | en karusell: 4–6 kort, hvert med en kort overskrift og en setning |

**System:**
> Du skriver {vink} til {kanal}.
>
> Svar med JSON: `{"tekst": "...", "hashtags": ["..."], "bildeforslag": "..."}`
> *(karusell: `tekst` har ett kort per avsnitt, nummerert.)*
> `hashtags` er 5–8 stykker uten emneknagg-tegnet, på norsk der det passer.
> `bildeforslag` er én setning om hva bildet bør vise — noe som faktisk finnes
> hos oss, og som kan knipses på mobilen der og da. Ikke et oppdiktet motiv.
> *(LinkedIn: saklig tone, ingen emojier. Ellers: høyst én emoji, og bare om
> den tilfører noe.)*

**Maks tokens:** 4000

**Bildeforslaget er verdt å ta med.** Teksten alene hjelper lite. Den som skal
legge ut innlegget må vite hva slags bilde hun skal ta med, og det er mye
lettere å hente fram når det står konkret hva som skal være i det.

### 6.5 Side som mangler (SEO)

**System:**
> Ingen av sidene våre svarer på dette søket. Skriv sida som gjør det.
>
> Svar med JSON: `{"tittel": "...", "ingress": "...", "innhold": "...", "metabeskrivelse": "...", "slug": "..."}`
> `innhold` er 350–600 ord. `slug` er små bokstaver med bindestrek, uten æ ø å.

### 6.6 Kundebrev

**System:**
> Du skriver til virksomhetens {antall} aktive kunder. De kjenner stedet fra
> før — ikke forklar hva vi driver med, og ikke selg dem noe de allerede har.
>
> Svar med JSON: `{"emne": "...", "tekst": "..."}`
> `tekst` er 150–250 ord.

### 6.7 Assistenten

Svarer med tekst, ikke JSON, og lagrer **ikke** noe utkast.

**System:** fakta + stemme, pluss:
> Du er markedsføringshjelpen til den som driver stedet. Svar kort og konkret,
> og bygg på tallene under. Vet du ikke noe, si det — ikke gjett. Foreslå
> gjerne hva hun bør gjøre, i prioritert rekkefølge.

etterfulgt av en faktablokk som hentes ved hvert kall:

```
Tall fra databasen akkurat nå:
- Aktive kunder: {n}
- Produkter publisert: {n}
- Bestillinger siste 30 dager: {n}

Framover, med ledig kapasitet:
- {navn}, {dato}: {ledig} av {kapasitet} ledig
```

**Maks tokens:** 4000

### 6.8 Produktbeskrivelse

Svarer med teksten selv, ikke som utkast — den skal rett inn i feltet der
eieren står, og hun leser og retter før hun lagrer. Utkastkøen er for det som
går ut av seg selv.

**System:** fakta + stemme, pluss:
> Skriv beskrivelsen som skal stå på produktsida. Tre til fem setninger, ett
> avsnitt, ingen overskrift og ingen punktliste. Fortell hva man får, hva som
> er inkludert, og hvem det passer for. Legg deg tett opp til måten
> beskrivelsene under er skrevet på. Finn aldri på datoer, klokkeslett eller
> antall som ikke står i opplysningene.

etterfulgt av inntil seks eksisterende beskrivelser, sortert med samme kategori
først:

```
Slik er beskrivelsene skrevet fra før:

### {tittel} ({kategori})
{beskrivelse}
```

**Maks tokens:** 1200

Forbildene er det som gjør denne god. Uten dem skriver modellen generisk prosa;
med dem treffer den måten stedet selv skriver på — lengde, tonefall, hva som
pleier å stå til slutt.

### 6.9 Autopiloten

**System:**
> Foreslå ukas markedsføring. Vær knapp — dette skal kunne leses på et halvt
> minutt.
>
> Svar med JSON: `{"artikler": [{"tittel": "...", "hvorfor": "..."}], "innlegg": [{"kanal": "...", "om": "..."}], "nyhetsbrev": {"emne": "...", "hvorfor": "..."}, "produkter": [{"tittel": "...", "hvorfor": "..."}]}`
> Høyst 3 artikler, 2 innlegg, 1 nyhetsbrev. Under produkter: de som trenger
> det mest.

**Bruker:** det som har ledig kapasitet de neste seks ukene
**Maks tokens:** 6000

Er alt fullt, kall ikke modellen: «Alt er utsolgt de neste seks ukene. Da er
det ingenting autopiloten trenger å foreslå.»

---

## 7. Endepunktene

To endepunkter. Skillet går på om kallet koster penger.

### 7.1 `POST /api/admin/ai` — alt som koster et AI-kall

| `handling` | Felter inn | Hva som skjer |
| --- | --- | --- |
| `artikkel` | `emne`, `kategori?`, `bilde?` | lagrer utkast `artikkel` |
| `produktpakke` | `produktId` | lagrer utkast `produktpakke` |
| `nyhetsbrev` | `slag`, `bilde?` | lagrer utkast `nyhetsbrev` |
| `sosialt` | `kanal`, `form`, `om`, `bilde?` | lagrer utkast `sosialt` |
| `seoside` | `sokeord` | lagrer utkast `seo` |
| `kundebrev` | `om?`, `bilde?` | lagrer utkast `kundebrev` |
| `assistent` | `sporsmal` | svarer med tekst, lagrer ingenting |
| `produktbeskrivelse` | `tittel`, `kategori?`, `pris?`, `antall?` | svarer med tekst, lagrer ingenting |
| `autopilot` | — | lagrer utkast `seo` med forslagene i `data` |
| `godkjenn` | `id`, `publiser?` | se 7.3 |
| `publiser` | `id` | legger den ferdige artikkelen ut |
| `forkast` | `id` | `status = 'forkastet'` — skjuler den |
| `slett` | `id` | sletter raden for godt |

Svaret på et lagret utkast:

```json
{
  "id": 41,
  "tittel": "...",
  "tekst": "...",
  "data": { },
  "kostnad": "ca. kr 1,20",
  "beskjed": "Utkastet er klart. Les gjennom før du tar det i bruk."
}
```

### 7.2 `GET|POST /api/admin/marked` — alt som er gratis

| Kall | Hva |
| --- | --- |
| `GET` | hele skjermen: tavle, SEO-muligheter, produkter, søkeord, analyse |
| `GET ?utkast={id}` | ett utkast med hele teksten og oppsettet |
| `POST handling=sokeord` | legg til, endre eller fjern et søkeord |
| `POST handling=innstilling` | lagre GA-id, tak for AI-bruk, Google-kobling |

**Hvorfor teksten hentes for seg:** lista over utkast bærer bare
overskriftene. Førti artikler på noen tusen tegn hver ville vært en megabyte
ved hver eneste lasting av skjermen, og alt sammen for å vise en tittel.

### 7.3 Godkjenning — livsløpet til et utkast

```
utkast ──godkjenn──> godkjent ──publiser──> publisert
   │                     │
   └──forkast──> forkastet
   └──slett──> borte
```

Ved `godkjenn`:

1. Er typen `artikkel` eller `seo`, og er det tekst i den, lages en **artikkel
   som kladd**. Godkjenning betyr «denne vil jeg ha», ikke «legg den ut nå» —
   eieren velger tidspunktet selv.
2. Slug lages av `data.slug` eller av tittelen, med tall bak ved kollisjon.
3. Tittelen kjøres gjennom `ledigTittel()`.
4. Bildet eieren valgte da utkastet ble laget følger med. **Uten dette måtte
   det velges på nytt inne i artikkelen etterpå — valget var gjort, og ble
   borte.**
5. `ai_utkast.status = 'godkjent'`, `resultat_id` peker på artikkelen.
6. Ba hun om at den skulle ut med det samme (`publiser: true`), går den ut nå.

Svaret sier hvor det ble av. Det er viktigere enn det høres ut:

| Type | Beskjed |
| --- | --- |
| Artikkel, publisert | «Publisert. Artikkelen ligger ute på nettsiden nå.» |
| Artikkel, kladd | «Lagt i kunnskapsbanken som kladd. Publiser den når du er klar.» |
| Nyhetsbrev/kundebrev | «Åpnet under Beskjeder, med teksten klar. Velg mottakere og send.» + `tilBeskjed: true` og hele teksten |
| Innlegg | «Godkjent. Den ligger under «Godkjent» på tavla til du har brukt den.» |

«Utkastet er godkjent» og ikke et ord om hvor det ble av er det som får en
eier til å slutte å bruke modulen. Et nyhetsbrev skal sendes fra
beskjedskjermen, og da må teksten *følge med dit* — ellers må den skrives opp
igjen for hånd.

**Publiseringsreglene** (samme sted som for manuell publisering): en artikkel
uten tekst skal ikke ut, og mangler den adresse, får den en.

---

## 8. «Slik blir det» — forhåndsvisningen

Dette er delen som gjør størst forskjell for den som bruker modulen, og den
som er lettest å hoppe over.

AI-en skriver teksten og eieren velger bildet. Det som mangler er det siste
leddet: at de to blir til noe ferdig. Uten det ser eieren rå tekst, velger et
bilde hun aldri får se plassert, og oppdager først etter publisering hvordan
det faktisk ble.

Serveren sender med et **oppsett** per utkasttype, og skjermen tegner
rammen etter det:

| Type | Ramme | Merknad |
| --- | --- | --- |
| `sosialt` | kanalens format i piksler | «Instagram-innlegg (4:5) · 1080 × 1350 px» |
| `artikkel`, `seo` | 16:9 toppbilde | «Under Nyheter og guider» |
| `nyhetsbrev`, `kundebrev` | brevbredde | «Slik ser e-posten ut» |

Formatene:

```php
'Instagram/innlegg'  => [1080, 1350, 'Instagram-innlegg (4:5)'],
'Instagram/story'    => [1080, 1920, 'Instagram story (9:16)'],
'Instagram/reels'    => [1080, 1920, 'Reel (9:16)'],
'Instagram/karusell' => [1080, 1080, 'Karusell (1:1)'],
'Facebook/innlegg'   => [1200,  630, 'Facebook-innlegg (1,91:1)'],
'Facebook/story'     => [1080, 1920, 'Facebook story (9:16)'],
'TikTok/innlegg'     => [1080, 1920, 'TikTok (9:16)'],
'LinkedIn/innlegg'   => [1200,  627, 'LinkedIn-innlegg (1,91:1)'],
// ukjent kombinasjon: [1080, 1080, '{kanal} (1:1)']
```

Serveren bygger også **limteksten** — brødteksten, blank linje, emneknaggene
til slutt — slik at den som kopierer får nøyaktig det som står i
forhåndsvisningen. Bygg den på serveren, ikke i skjermen: da kan de to ikke
komme i utakt.

**Emneknaggene må renskes ett sted.** Modellen blir bedt om å svare uten
`#` og gjør det som regel, men ikke alltid. Setter skjermen selv en `#` foran
hver, blir det `##vaaren`. Strip `#` ved lesing fra basen, ikke bare ved
lagring — gamle rader har dem alt med.

Toppbildet på en artikkelside er bredt, ikke kvadratisk. Rammen i
forhåndsvisningen må ha samme forhold, ellers viser den et annet utsnitt enn
det som blir publisert.

---

## 9. SEO-muligheter — regnet ut, ikke gjettet

Hver mulighet peker på noe som faktisk mangler i basen, og sier hva som skal
gjøres med det. Ingenting er skrevet inn for hånd, og er det ingenting å
melde, står lista tom framfor å vise et oppdiktet eksempel.

| Type | Utløses av | Alvor |
| --- | --- | --- |
| Mangler side | søkeord uten målside | høy |
| Dårlig tittel | side uten metabeskrivelse | høy |
| Dårlig tittel | metabeskrivelse under 70 tegn | lav |
| Foreldet innhold | artikkel ikke endret på et år | lav |
| Mangler FAQ | ingen spørsmål og svar lagt inn | høy |

Hver mulighet bærer fire felter: `hva` (hva som er galt), `hvorfor` (hvorfor
det betyr noe), `grep` (hva som skal gjøres), og en peker til det som skal
rettes. Det er `hvorfor` som gjør lista brukbar for noen som ikke kan SEO.

---

## 10. Bilder

Modulen tar imot to slags bildeverdier, og ingen andre:

```
api/bilde.php?artikkel=<navn>        opplastet bilde
<navn>.jpg|jpeg|png|webp             fil i prosjektet
```

Alt annet forkastes. Feltet kommer fra nettleseren, og det ender i en
`src`-attributt på nettsiden.

En felle jeg gikk i: bruker du `basename()` for å rense verdien, spiser den
`api/`-prefikset, og bildet blir borte. Sjekk mot mønsteret først, `basename()`
bare på det som ikke matchet.

For e-post må adressen gjøres absolutt. En e-post åpnes et helt annet sted enn
nettsida, og `uploads_kopp.jpg` peker da ingen steder.

Bildesøk mot en bildebank (Shutterstock i kildeprosjektet, ~690 linjer) er et
eget tillegg og kan droppes helt.

---

## 11. Dette må du bytte

1. **API-nøkkelen** — i en fil utenfor repoet, aldri i koden.
2. **Modellen og prisene** — de endrer seg; sjekk dem når du setter opp.
3. **Faktablokka** — den er full av kildeprosjektets bransje. Skriv den om.
   Det er her mesteparten av jobben ligger.
4. **Stemmen** — tonen du vil ha, og den ene bransjelinja.
5. **Katalog-grensesnittet** i 5.3 — fem funksjoner mot ditt datagrunnlag.
6. **Taket per måned** — sett det lavt først. Et løpsk skript koster ekte
   penger.
7. **Målsidene** i søkeordtabellen — de peker på sider som ikke finnes hos deg.

---

## 12. Dette er bevisst ikke med

- **Ingen planlagt utsending.** Ingenting går ut uten at et menneske har
  trykket. Modulen kan planlegge en artikkel til et tidspunkt, men aldri
  skrive og publisere i samme åndedrag.
- **Ingen direkte publisering til sosiale medier.** Teksten kopieres og limes
  inn. Det er et bevisst valg: API-ene krever vedlikehold av tilganger som en
  liten drift ikke greier, og et innlegg som går ut feil er verre enn et som
  må limes inn.
- **Ingen bildegenerering.** Modellen foreslår et motiv i ord; mennesket tar
  bildet. Et generert bilde av et sted som ikke finnes er verre enn ikke noe
  bilde.
- **Ingen automatisk oppfølging av SEO-muligheter.** Lista peker; eieren
  bestemmer.

---

## 13. Rekkefølge å bygge i

1. `ai_logg` og AI-klassen med kostnadslogg og tak. Prøv den mot ett kall.
   **Uten loggen først vet du ikke hva resten koster mens du bygger den.**
2. Faktablokka og stemmen, mot ditt eget datagrunnlag.
3. `ai_utkast` og én type — artikkel er den enkleste.
4. Godkjenning og publisering, med kollisjonshåndtering på tittel og slug.
5. Forhåndsvisningen. Den gjør mer for brukbarheten enn to typer til.
6. Resten av typene. De er varianter av den samme fire linjene.
7. `marked_sokeord` og SEO-mulighetene.
8. Analyse og assistent til slutt — de er de minst nødvendige.
