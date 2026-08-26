# Kursveilederen — kartlegging før den bygges om

Bestilt 26. august: gjennomgå og videreutvikle Kursveilederen, og gjør
spørsmål, svar og logikk redigerbare fra admin uten kodeendringer.

Bestillingen ber om at dagens løsning kartlegges først. Dette er den
kartleggingen. **Ingenting er bygget om ennå.**

---

## 1. Hva den heter, og hvor den er

Den heter tre ting i koden, og det er verdt å vite før man leter:

| Sted | Navn |
|---|---|
| Knappen på forsiden | «Finn kurset mitt» |
| Overskriften i vinduet | «Finn kurset som passer deg» |
| Redigeringen i admin | «Kursvelgeren — svar og anbefalinger» |
| Variablene i koden | `kv…` |

| Del | Fil og sted |
|---|---|
| Knappen som åpner den | `lissom-2108.html` linje ~2333, «Usikker på hvilket kurs som passer?» |
| Selve vinduet | `lissom-2108.html` linje ~8835, et modalvindu over sida |
| Logikken | `lissom-2108.html` linje ~17578, ett stort `renderVals`-uttrykk |
| Svarene | `lissom-2108.html` linje ~10285, `kvRegler()` |
| Redigeringen i admin | `lissom-2108.html` linje ~5365 og ~7030 — **to steder** |

---

## 2. Spørsmålene som finnes i dag

Tre, og bare det andre er betinget:

**1. «Hvor mange er dere?»**
En teller med − og +, fra 1 til 12 og så «Flere enn 12». Ikke knapper med
alternativer, men et tall man klikker seg til.

**2. «Hvem er dere to?»** — *vises bare når svaret over er nøyaktig 2*
Venner · Kjæreste eller date · Familie

**3. «Hva vil du helst?»** (eller «Hva vil dere helst?» når det er flere enn
én) — alternativene er `kvRegler()`, som i dag er:

| Svar | Anbefaler |
|---|---|
| Lære å dreie | Nybegynner dreiekurs |
| Lage boller og fat | Store fat kurs |
| Male ferdig keramikk | Paint on Pots-siden |

Det er hele veilederen. Tre spørsmål, hvorav ett hoppes over i de fleste
tilfeller.

---

## 3. Hvordan anbefalingen regnes ut

Rekkefølgen betyr alt — første treff vinner:

```
1.  Flere enn 12          → Privat event
2.  Nøyaktig 2 og «Kjæreste eller date» → Date Night
3.  Svaret peker på en side (SIDE:…)    → den sida
4.  Flere enn det dreiekursene tar      → Privat event
5.  Svaret peker på et kurs             → det kurset
6.  Ingen av delene                     → Nybegynner dreiekurs
```

Punkt 4 leser plassene fra kursene i basen framfor å ha tallet skrevet inn:
endrer verkstedet plassene på dreiekursene, følger setningen med.

Resultatsida viser navn, en kort tekst, og en metalinje med pris, neste dato
og status — hentet fra kurslista. Så «Les mer», som går rett til booking, og
«Start på nytt».

---

## 4. Hvor spørsmål og svar lagres i dag

**Ingen steder.**

```js
kvRegler() {
  return this.state.kvRegler || [ … tre faste svar … ];
}
```

`this.state` er nettleserens minne. Redigerer eieren et svar i admin, endres
`state.kvRegler` — og er borte ved neste sidelasting. Det finnes ingen
tabell, ingen kolonne, og ingen API-kall som lagrer noe av dette.

**Dette er den viktigste funnet i kartleggingen.** Redigeringen i admin ser
ut til å virke: feltene tar imot tekst, «+ Legg til svar» legger til en rad,
og krysset sletter. Ingenting av det overlever en oppfriskning av sida.

De to første spørsmålene — antallet og «hvem er dere to» — er dessuten
skrevet rett inn i logikken og finnes ikke i redigeringen i det hele tatt.

---

## 5. Hva som mangler mot bestillingen

| Bestilt | I dag |
|---|---|
| Opprette nytt spørsmål | Nei — bare svarene på spørsmål 3 kan endres |
| Redigere spørsmål | Nei |
| Endre rekkefølge | Nei |
| Deaktivere spørsmål | Nei |
| Slette spørsmål | Nei — bare svar |
| Velge spørsmålstype | Nei — typen følger av hvilket spørsmål det er |
| Forhåndsvisning | Nei |
| Ja/nei, envalg, flervalg, tallfelt, fritekst, avhukning | Bare envalg og et tallfelt, begge fastlåst |
| Lagring | **Nei — alt forsvinner ved oppfriskning** |
| Kurs merket «passer for nybegynnere» osv. | Nei — ingen slike felter finnes på kurset |
| Begrunnelse for hvorfor kurset passer | Delvis — én fast tekst per svar |
| Nærmeste alternativer når ingenting matcher | Nei — den faller tilbake på ett kurs |
| Ett spørsmål av gangen, progresjon, tilbake | **Ja** — dette finnes og virker |
| Mobilvennlig | Ja |

---

## 6. Hva som kan gjenbrukes

| Behov | Finnes allerede |
|---|---|
| Ett spørsmål av gangen, prikker, tilbake-knapp | Vinduet på linje ~8835 — hele flyten er bygget |
| Anbefalingskortet med pris og neste dato | `kvMeta`, leser kurslista |
| «Les mer» rett til booking | `kvGaTil` |
| Redigering med rader, legg til og slett | Admin-blokka på ~5365 |
| Kurs, temaer, plasser, varighet | `courses` |
| Kort, knapper, skjemafelt, farger | `LissomDesignSystem` |
| Innlogging og rettigheter | `krev_admin()` |
| Lagring av oppsett i basen | Mønsteret finnes: `innstillinger`, `content_blocks`, `kurs_serier` |

Flyten og designet trenger ikke bygges på nytt. Det som mangler er at
spørsmålene finnes som data framfor som kode, og at de lagres.

---

## 7. Hva som må endres i datamodellen, og hvorfor

Tre spørsmål. To krever nye tabeller, ett krever kolonner på `courses`.

### 7a. Spørsmålene og svarene — to nye tabeller

Det finnes ingen struktur i basen for «et spørsmål med alternativer i en
rekkefølge». `content_blocks` er nøkkel → tekst, én verdi per nøkkel; den kan
ikke holde en liste med rekkefølge, type og aktiv-flagg. `innstillinger` er
det samme. Å presse spørsmålene inn som JSON i én tekstverdi ville gjort det
umulig å flytte ett spørsmål, deaktivere ett, eller spørre «hvilke svar peker
på dette kurset» uten å lese og tolke hele klumpen.

```sql
veileder_sporsmal
  id          BIGINT UNSIGNED PK
  tekst       VARCHAR(255)   -- «Hvor mange er dere?»
  type        ENUM('envalg','flervalg','janei','tall','fritekst','avhuking')
  sortering   SMALLINT       -- rekkefølgen, slik den flyttes i admin
  aktiv       TINYINT(1)     -- deaktivert uten å slettes
  hjelpetekst VARCHAR(255)   -- valgfri forklaring under spørsmålet
  created_at  DATETIME

veileder_svar
  id            BIGINT UNSIGNED PK
  sporsmal_id   BIGINT UNSIGNED
  tekst         VARCHAR(255)   -- «Lære å dreie»
  sortering     SMALLINT
  aktiv         TINYINT(1)
  -- Hva svaret betyr for anbefalingen. Se 7b.
  passer_nivaa  VARCHAR(40) NULL
  passer_hvem   VARCHAR(40) NULL
  metode        VARCHAR(20) NULL
  varighet      VARCHAR(20) NULL
  -- Peker rett på ett kurs eller én side, slik i dag.
  mal           VARCHAR(80) NULL
  begrunnelse   VARCHAR(255) NULL
```

### 7b. Kursene må kunne merkes — kolonner på `courses`

Bestillingen ber om at hvert kurs merkes med hvem det passer for. Det finnes
ingen slike felter i dag. `courses.tema` er kategorien som styrer filteret
ute på nettsiden — «Dreiing», «Plateteknikk», «Events» — og kan ikke også
bety «passer for nybegynnere» uten at filteret ute går i stykker.

```sql
ALTER TABLE courses
  ADD COLUMN passer_nivaa  VARCHAR(60) NULL,   -- nybegynner,litt,erfaren
  ADD COLUMN passer_hvem   VARCHAR(80) NULL,   -- alene,par,venner,familie,firma
  ADD COLUMN metode        VARCHAR(20) NULL,   -- dreiing,handbygging,begge
  ADD COLUMN varighet      VARCHAR(20) NULL;   -- kort,medium,lang
```

Kommaseparerte lister framfor koblingstabeller: et kurs har to–tre av hver,
listene er korte og faste, og de skal bare leses samlet. En koblingstabell
per felt ville gitt fire tabeller til uten å svare på noe mer.

**Ingen eksisterende kolonne endres, og ingen data røres.** Et kurs uten
merking oppfører seg som i dag.

### 7c. Svarene som er lagret underveis — ingen endring

Bestillingen ber om «lagring av svar underveis». Det betyr i nettleseren,
mens man svarer, ikke i basen. `this.state` gjør allerede dette. Ingen ny
lagring, og ingen personopplysninger som blir liggende.

---

## 8. Implementeringsplan

Rekkefølgen er valgt slik at veilederen virker som i dag hele veien, og
aldri står halvferdig.

| Steg | Hva | Hvorfor først |
|---|---|---|
| 1 | Migrasjon: to tabeller og fire kolonner, fylt med dagens tre spørsmål | Da finnes dagens veileder som data, og ingenting ser annerledes ut |
| 2 | `api/veileder.php` — spørsmålene ut, åpent som `api/innhold.php` | Vinduet må kunne lese dem |
| 3 | Vinduet leser fra API-et framfor `kvRegler()`, med dagens tre som reserve om basen ikke er oppdatert | Ingen endring for den som bruker den |
| 4 | `api/admin/veileder.php` — opprett, endre, flytt, deaktiver, slett | Bak `krev_admin()` |
| 5 | Admin-skjerm under **Nettsiden**, med forhåndsvisning av selve vinduet | Der de andre innholdsredigeringene ligger. Den ene av de to dublettene fjernes |
| 6 | Merkefeltene i kursveiviseren, som en seksjon i steg 1 | Kursene må merkes før anbefalingen kan bruke merkingen |
| 7 | Anbefalingen regner poeng mot merkingen, med begrunnelse og nærmeste alternativer | Siste, fordi den trenger alt over |

Merk steg 5: redigeringen finnes i dag **to steder** i malen med samme
innhold. Det er en dublett fra før, og skal bli ett sted — ikke to som kan
si hver sitt.

---

## 9. Det som ikke skal gjøres

- Ingen ny modal, ingen ny stegvisning, ingen nye knappestiler. Vinduet som
  finnes er bygget etter malen og skal brukes.
- Ingen endring av `courses.tema`. Den styrer filteret ute.
- Ingen sletting av dagens tre spørsmål. De blir de tre første radene.
- Ingen anbefaling som lagrer hvem som svarte hva. Det er ikke bestilt, og
  det ville vært personopplysninger uten grunn.
