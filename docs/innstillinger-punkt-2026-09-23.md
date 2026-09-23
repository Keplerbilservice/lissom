# Innstillinger utenfor koden — 23. september 2026

Dette er de ID-ene og koblingene som **ligger i produksjonsdatabasen**
(tabellen `innstillinger` og `content_blocks`), ikke i koden. Gjenoppretter
du koden fra taggen `punkt/2026-09-23-meta-og-butikk`, følger ikke disse
med. Da fylles de inn igjen herfra.

Eieren, 23. september 2026: «lagre, slik at vi ikke mister meta, google
ads tags osv, sett et punkt nå, så det ikke kan påvirkes av noe annet».

## Ingen hemmeligheter står her

Tokener, API-nøkler og passord er **med vilje ikke skrevet ned i denne
fila**, og heller ikke i repoet. Serveren leverer dem aldri tilbake — den
sier bare OM de står inne.

**Eieren oppbevarer dem selv**, utenfor repoet, i
`C:\Users\info\Documents\Claude-nøkler`:

| Fil | Hva det er |
|---|---|
| `meta app token.txt` | System User-tokenet til Instagram/Facebook-publisering (lagt dit 23. september 2026) |
| `gemini.txt` | Gemini API-nøkkelen |

Mappa ligger på eierens egen maskin og er ikke koblet til nettstedet.
Claude åpner ikke filene — de står her bare så det er kjent hvor de er.
Mistes både basen og mappa, må nøklene lages på nytt hos leverandøren.
Det står under hver av dem hvordan.

## Meta — Instagram og Facebook

| Felt | Verdi | Hvor det legges inn |
|---|---|---|
| Instagram-konto-id | `17841400763092125` | Admin → Markedsføring → Oppsett |
| Facebook-side-id | `1343208898874649` | samme sted |
| Graph-versjon | `v21.0` | samme sted |
| System User-token | *(hemmelig)* | samme sted |

Tokenet lages på nytt slik: business.facebook.com → Innstillinger →
Brukere → **Systembrukere** → «Lissom publisering» (ID 61594298657545,
i porteføljen **kepler_bilservice**, 123445175513220) → **Generer
token** → app **Publisering** → utløper **Aldri** → tillatelsene
`instagram_basic`, `instagram_content_publish`, `pages_manage_posts`,
`business_management`.

To ting som tok dager å finne 23. september, og som må stå riktig:

1. **Riktig Facebook-konto må være innlogget** — den som administrerer
   kepler_bilservice. Er Monica innlogget, omdirigerer Meta bort fra de
   innstillingene, og det ser ut som en feil i Meta.
2. **Systembrukeren må ha «Administrere app»** på appen Publisering, ikke
   bare «Utvikle app». Uten den krasjer tillatelsessteget i veiviseren.

Instagram-id-en sto en periode med ett feil siffer (`…76305 2125` mot
riktig `…76309 2125`). Den ekte finnes i Business-innstillingene under
**Instagram-kontoer → @lissom_keramikk → Details**.

## Måling

| Felt | Verdi |
|---|---|
| GA4 måle-id | `G-GMJSTL5KP2` |
| Meta-piksel / datasett | `1094401586278954` |
| GTM-container | *(ikke i bruk — feltet står tomt)* |

GA4-egenskap `a405568299` / `p551127363`. Google Ads-konto
**565-533-5782** (`ocid=8491656218`). Ads henter konverteringene fra
GA4; de er ikke definert i koden.

Måle-API-nøklene (`maal_ga_api_secret`, `maal_meta_token`) er hemmelige
og står ikke her. GA4-hemmeligheten lages på nytt under GA4 → Admin →
Datastrømmer → Measurement Protocol API secrets.

## Hva koden inneholder selv

Alt annet — tagger, samtykkeporten, hendelsene, Conversions API-koden,
serversidene — ligger i repoet og kommer tilbake med taggen.

## Kopi av selve databasen

Claude har ingen tilgang til produksjonsbasen og kan ikke ta kopi av
den. Den kopien må tas der basen driftes (cPanel → phpMyAdmin →
Export, eller Databaser → Sikkerhetskopi). Denne fila erstatter ikke en
slik kopi — den er en huskeliste for det som ellers ville vært tapt.
