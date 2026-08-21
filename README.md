# lissom.no

Nettsiden og backenden til Lissom Keramikk & Håndverk AS.

Frontenden er ferdig designet og skal ikke bygges om — den ligger som én
HTML-fil (`Lissom nettside.dc.html`) med all logikk i `class Component` nederst.
Dette repoet legger den ekte backenden under: database, innlogging, betaling
med Vipps, og varsling på e-post og SMS.

## Hvordan du oppdaterer nettsiden

Akkurat som før: last opp filene til dette repoet på github.com. Hver endring på
`main` publiseres automatisk til lissom.no i løpet av et par minutter. Du skal
aldri måtte kopiere filer til webhotellet for hånd.

## Slik henger det sammen

```
Nettleser
   │
   ├─→  lissom.no                    Nettsiden (statiske filer)
   │
   └─→  lissom.no/api/*.php          Backend
                │
                ├─→  MySQL           Medlemmer, bookinger, betalinger
                ├─→  Vipps           Login, ePayment, Recurring
                ├─→  SMTP            Kvitteringer og påminnelser
                └─→  Sveve           SMS
```

Alt kjører på webhotellet hos Domene.no. Ingen andre abonnementer, bortsett fra
SMS, som betales per melding.

## Mappene

| Mappe | Hva det er | Ligger på nettet? |
|---|---|---|
| `/` | Nettsiden: HTML, bilder, designsystem | Ja |
| `api/` | Endepunktene frontenden kaller | Ja |
| `app/` | All logikk: database, Vipps, varsling | **Nei** — utenfor webroten |
| `db/migrations/` | Databasestrukturen, som SQL | Nei |
| `bin/` | Planlagte jobber (cron) | Nei |
| `docs/` | Oppsett og referanse | Nei |

Skillet er med vilje: koden som håndterer betaling og persondata skal ikke kunne
lastes ned av noen som gjetter en adresse.

## Hemmeligheter

Vipps-nøkler, databasepassord og SMS-innlogging ligger i `secrets.php`, som
lastes opp manuelt til `~/lissom-secrets/` på webhotellet én gang. Den er aldri
i git, og deploy-jobben kan ikke overskrive den.

Malen ligger i [`app/secrets.example.php`](app/secrets.example.php).

## Kom i gang

Førstegangsoppsett er beskrevet steg for steg i
[`docs/OPPSETT.md`](docs/OPPSETT.md).

## Status

| Fase | Hva | Status |
|---|---|---|
| 0 | Database, sesjoner, innlogging med Vipps | Bygget, ikke satt i drift |
| 1 | Booking av kurs med ePayment | Ikke startet |
| 2 | Gavekort, butikk, drop-in | Ikke startet |
| 3 | Medlemskap med månedstrekk | Ikke startet |
| 4 | Admin koblet til ekte data | Ikke startet |

## Teste backend lokalt

Krever MariaDB eller MySQL. Opprett en tom database, legg inn tilgangen i
`app/secrets.php`, og kjør:

```
php bin/migrate.php      # oppretter tabellene
php tests/backend.php    # 26 sjekker mot ekte database
tests/flyt.sh            # hele betalingskjeden, ende til ende
```

Testene dekker kapasitetsberegning, at siste plass ikke kan bookes to ganger,
at en betaling bare kvitteres én gang uansett hvor mange ganger Vipps melder
fra, at utløpte reservasjoner frigir plassen, tidssonehåndtering og
ratebegrensning.

`tests/flyt.sh` bygger opp samme mappestruktur som webhotellet, starter en
webserver og en stubbet Vipps, og kjører gjennom booking, webhook og
signaturkontroll. Betalingskjeden er den delen som ellers krever ekte penger,
en godkjent salgsenhet og en telefon for å prøve.
