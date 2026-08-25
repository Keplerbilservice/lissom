# Oppsett på Domene.no

Engangsjobb. Regn med en times tid første gang.

Webhotellet er **Web15 med cPanel**, PHP 8.4. Alt vi trenger er med i pakken.

---

## 1. Lag databasen

cPanel → **MySQL Databases**.

1. Opprett databasen. cPanel setter på kontoens prefiks selv, så den ender opp
   med et navn som `abcdefgh_lissom`. Skriv ned hele navnet.
2. Opprett en bruker under **Add New User**, med passordgeneratoren.
3. Under **Add User To Database**: legg brukeren til med **ALL PRIVILEGES**.
   Uten det får ikke migrasjonene laget tabeller.

Navn, bruker og passord skal inn i `secrets.php` i steg 3 — ikke i dette
repoet.

Passordet bør byttes når oppsettet er ferdig, siden det har vært sendt i
klartekst under utviklingen. Det gjøres samme sted, og så oppdaterer du
`db_passord` i `secrets.php`.

---

## 2. Lag mappene utenfor webroten

cPanel → **File Manager**, stå i hjemmemappa (`~`), altså ett nivå
**over** `public_html`.

Opprett to mapper:

```
lissom-app/        ← backend-koden. Legges ut automatisk ved hver publisering.
lissom-secrets/    ← nøkler og passord. Røres aldri av deploy.
```

Grunnen til at de er delt: deploy-jobben synkroniserer `lissom-app/` og sletter
det som ikke finnes i repoet. Lå nøklene der, ville de blitt borte ved første
publisering.

---

## 3. Legg inn nøklene

Kopier [`app/secrets.example.php`](../app/secrets.example.php), fyll den ut, og
last den opp som:

```
~/lissom-secrets/secrets.php
```

Sett rettighetene til **600** (høyreklikk → Change Permissions, huk av kun for
«Read» og «Write» under User). Da kan ingen andre kontoer på serveren lese den.

Start med Vipps sitt **testmiljø**:

```php
'miljo'      => 'test',
'vipps_base' => 'https://apitest.vipps.no',
```

---

## 3b. Opprett underdomenet den nye siden skal ligge på

`public_html` inneholder en WordPress-installasjon fra før. Den nye siden skal
ikke blandes inn der — to nettsteder i samme mappe gir uforutsigbar oppførsel,
og WordPress' `.htaccess` ville kollidert med vår.

cPanel → **Domains** → **Create A New Domain**:

cPanel → **Domains** → **Create A New Domain** → **Registered Domain**:

| Felt | Verdi |
|---|---|
| Domain | `ny.lissom.no` |
| Share document root | **ikke** huket av |

Dokumentroten ble `~/public_html/ny.lissom.no`. cPanel tillot ikke en sti
utenfor `public_html` for underdomener.

Det fungerer: Apache leser bare `.htaccess` fra dokumentroten og nedover, aldri
over, så WordPress' omskrivingsregler i `public_html` treffer ikke underdomenet.

Men det gir én felle å huske på, se neste avsnitt.

### Når WordPress skal fjernes

**Dette er gjort for filene sin del.** Da siden ble flyttet opp til
`public_html` i august 2026, ble WordPress flyttet til `~/gammel-wordpress` —
utenfor det som serveres. PHP-en der kan ikke lenger kjøres fra nettet.

Avsnittet under sto igjen med den gamle teksten, og advarte mot å slette
`public_html/ny.lissom.no`. Den mappa finnes ikke lenger.

Det som gjenstår er opprydding, ikke sikkerhet:

1. **Sjekk at den ikke serveres.** Åpne `lissom.no/wp-login.php`. «Siden
   finnes ikke» er svaret du vil ha. Kommer det en innloggingsside, ligger
   den fortsatt ute — da må den ut før noe annet.
2. **Ta sikkerhetskopi** av `~/gammel-wordpress` og av databasen, i cPanel.
3. **Slett mappa** `~/gammel-wordpress`.
4. **Tabellene.** WordPress-tabellene heter `wp_`. Se hva som finnes og hvor
   mye plass de tar før du rører dem:

   ```sql
   SELECT table_name, ROUND(data_length/1024/1024, 1) AS mb
     FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name LIKE 'wp\_%'
    ORDER BY data_length DESC;
   ```

   Våre egne tabeller heter noe annet — `bookings`, `courses`, `members`,
   `payments` og resten. Ingen av dem begynner med `wp_`. Slett bare når
   sikkerhetskopien er tatt og lastet ned.

Når siden er ferdig testet, peker vi `lissom.no` hit. Det er en DNS-endring, og
den kan reverseres umiddelbart hvis noe skulle vise seg å mangle.

---

## 4. Koble GitHub til webhotellet

Publiseringen skjer over FTPS fra GitHub. Du trenger en FTP-konto — den finnes
allerede, se **FTP konto** i kundeområdet, eller lag en egen til formålet i
cPanel → **FTP Accounts**.

I GitHub: **Settings → Secrets and variables → Actions → New repository secret**.
Legg inn tre stykker:

| Navn | Verdi |
|---|---|
| `FTP_SERVER` | `ftp.lissom.no` |
| `FTP_BRUKER` | FTP-brukernavnet |
| `FTP_PASSORD` | FTP-passordet |

Push til `main`, og se at jobben går grønt under **Actions**-fanen.

Første publisering tar noen minutter fordi alle bildene lastes opp. Etterpå
sendes bare det som faktisk er endret.

---

## 5. Opprett tabellene

Databasen er tom til nå. Kjør migrasjonene.

**Med SSH** (cPanel → **Terminal**, eller SSH-klient):

```bash
php ~/lissom-app/bin/migrate.php
```

**Uten SSH:** åpne cPanel → **phpMyAdmin**, velg databasen, gå til
**Import**, og kjør filene i `db/migrations/` i rekkefølge — `001` først.

Sjekk hva som er kjørt:

```bash
php ~/lissom-app/bin/migrate.php --status
```

---

## 6. Gi deg selv admin

Sett ditt eget mobilnummer i `secrets.php`:

```php
'admin_telefoner' => ['+4791234567'],
```

Nummeret må være det samme som du bruker i Vipps. Første gang du logger inn,
kjenner systemet igjen nummeret og gir deg admin automatisk.

Dette er en nødluke, ikke den vanlige måten. Senere administratorer settes opp
fra admin-panelet. Poenget er at du aldri kan låse deg selv ute.

---

## 7. Sett opp de planlagte jobbene

cPanel → **Cron Jobs**. Legg inn fire:

| Når | Kommando |
|---|---|
| Hvert 5. minutt | `php ~/lissom-app/bin/cron.php varsler` |
| Hvert 5. minutt | `php ~/lissom-app/bin/cron.php betalinger` |
| Daglig 07:00 | `php ~/lissom-app/bin/cron.php paaminnelser` |
| Daglig 01:00 | `php ~/lissom-app/bin/cron.php vedlikehold` |

Klokkeslettene i cPanel er som regel servertid. Sjekk hva serveren står i, og
juster hvis påminnelsene skal gå ut om morgenen norsk tid.

Sett e-postadressen din øverst på Cron Jobs-siden, så får du beskjed hvis en
jobb feiler.

---

## 8. Registrer adressene i Vipps-portalen

portal.vipps.no → **Utvikler** → testsalgsenheten.

| Hva | Adresse |
|---|---|
| Redirect-URI (Login) | `https://ny.lissom.no/api/vipps-callback.php` |

Bruk `ny.lissom.no` mens vi tester. Adressen byttes til `lissom.no` samtidig
med DNS-omleggingen, og må da også oppdateres i portalen.
| Vilkår | `https://lissom.no/vilkar.html` |
| Personvern | `https://lissom.no/personvern.html` |

Adressen må stemme **nøyaktig**, tegn for tegn. Vipps avviser innloggingen ved
minste avvik, uten å si hvorfor.

---

## 9. Prøv det

Gå til lissom.no, trykk **Logg inn**, velg Vipps.

Kom du tilbake til Min side, virker det. Sjekk `members`-tabellen i phpMyAdmin —
det skal ligge en rad der med navnet ditt og `rolle = admin`.

---

## Når noe ikke virker

**«Serveren er ikke ferdig satt opp»**
`secrets.php` finnes ikke der koden leter. Sjekk at den ligger i
`~/lissom-secrets/secrets.php` og ikke et annet sted.

**Hvit side, ingen feilmelding**
PHP-feil vises ikke til publikum, med vilje. De ligger i cPanel → **Errors**,
eller i `~/logs/`. Søk etter `[lissom]`.

**Innloggingen sender deg tilbake med `?innlogging=feilet`**
Nesten alltid redirect-URI-en. Den i `secrets.php` og den i Vipps-portalen må
være identiske.

**E-post kommer ikke fram**
Se i `notifications`-tabellen. Står det `feilet`, forteller `feilmelding`-feltet
hvorfor. Står det `ko` og blir liggende, kjører ikke cron-jobben.

---

## Fra test til produksjon

Når alt er prøvd ferdig i testmiljøet:

1. Bytt de fem Vipps-verdiene i `secrets.php` til produksjonsnøklene, og sett
   `'vipps_base' => 'https://api.vipps.no'` og `'miljo' => 'produksjon'`.
2. Legg inn redirect-URI-en på **produksjons**salgsenheten i portalen.
3. Ingen kodeendringer. Ingen ny publisering.

## E-post og SMS

E-post gaar som standard gjennom serverens egen `mail()`. Det virker ofte,
men du faar aldri vite om meldingen ble avvist — `mail()` svarer bare at den
er levert til koen paa serveren.

Sett heller opp SMTP. Da vet vi om det gikk galt, og hvorfor.

**Enkleste vei: Admin → Markedsføring → E-post og SMS.** Der fylles kontoen inn i
nettleseren, og en knapp sender en testmelding og sier hva som skjedde.
Passordet lagres paa serveren og vises aldri igjen. Da trenger ingen aa ha
FTP-tilgang for aa faa kvitteringer ut — som var grunnen til at dette sto
uvirksomt.

Skal det heller staa i fil, gaar det fortsatt: **`secrets.php` gjelder foran
det som skrives i admin.** Staar noekkelen der, sier skjermen fra at feltet er
laast av fila i stedet for aa la noen skrive i noe uten virkning. Legg da til i
`~/lissom-secrets/secrets.php`:

Utgaaende server staar under **kontodetaljer** for e-postkontoen i
kontrollpanelet. Paa cPanel-webhotell heter den som regel `mail.dittdomene.no`
— ikke leverandorens egen smtp-adresse. Bruker du feil server, svarer den
`535 Incorrect authentication data` selv om passordet er riktig.

```php
'smtp_vert'      => 'mail.lissom.no',
'smtp_port'      => 587,
'smtp_bruker'    => 'post@lissom.no',
'smtp_passord'   => 'passordet til e-postkontoen',
'smtp_sikkerhet' => 'starttls',

'epost_fra'      => 'post@lissom.no',
'epost_fra_navn' => 'Lissom Keramikk',
'epost_svar_til' => 'monica@lissom.no',
'varsel_epost'   => 'monica@lissom.no',
```

### Naar innloggingen blir avvist

`smtp_bruker` og `smtp_passord` er innloggingen til selve e-postkontoen, ikke
til cPanel og ikke til kundeweben hos Domene.no. Prov aa logge inn i webmail
med de samme opplysningene — gaar ikke det, er det passordet som er feil, og
det settes paa nytt under e-postkontoene i kontrollpanelet.

Virker det ikke med en gang, kan du ta ut `smtp_vert`-linja. Da gaar e-posten
gjennom serverens egen `mail()` i mellomtida. Nettsiden ligger hos Domene.no,
saa SPF stemmer uansett — det som mangler er bare beskjed nar noe blir avvist.

Avsenderadressen maa ligge paa lissom.no. Sender du fra en gmail-adresse,
blir meldingene avvist eller havner i sokkelen — SPF sier at Domene.no ikke
har lov til aa sende paa vegne av Gmail.

### SMS

To leverandorer er stottet. Begge tar betaling per melding uten
maanedsavgift, og begge er enkle HTTP-kall — aa bytte er en linje i
oppsettet, ikke en jobb.

Det samme gjelder her: **Admin → Markedsføring → E-post og SMS** har feltene for
Sveve, og da slipper du fila. Skal det i fil likevel:

**Sveve** (norsk):

```php
'sms_leverandor' => 'sveve',
'sveve_bruker'   => 'brukernavnet hos Sveve',
'sveve_passord'  => 'passordet',
'sms_avsender'   => 'Lissom',
```

**GatewayAPI** (dansk, ofte rimeligere til norske numre):

```php
'sms_leverandor'   => 'gatewayapi',
'gatewayapi_token' => 'API-token fra gatewayapi.com',
'sms_avsender'     => 'Lissom',
```

Avsendernavnet kan vaere inntil 11 tegn. Meldinger med navn som avsender
kan ikke besvares — det er greit her, for vi ber aldri om svar paa SMS.

### Sjekk at det virker

```
/api/test-varsel.php?epost=meg
/api/test-varsel.php?sms=+4790000000
```

`epost=meg` sender til adressen som staar paa din egen bruker.

Den sender én melding med det oppsettet som gjelder, forteller hvilken vei
den gikk, og viser de siste feilene fra koen. Krever noekkelen eller at du
er innlogget som admin.

---

## 8. Flytte siden til lissom.no

Til nå ligger den nye siden på `ny.lissom.no`, og en gammel WordPress på
`lissom.no`. Dette er byttet.

Rekkefølgen er ikke tilfeldig. Vipps-innlogging, betalingsreturer og
lagringen i admin henger alle på hvilken adresse serveren mener er sin egen,
og bytter man den før resten er på plass, slutter de å virke.

### Før du begynner

**Ta sikkerhetskopi av `public_html`.** cPanel → File Manager → merk
`public_html` → Compress → last ned zip-fila. Den gamle siden finnes bare
der.

**Finn ut hva Google har indeksert på lissom.no.** Search Console → Sider.
Adressene som står der, er de folk kan komme fra. De som ikke har en ny
motsvarighet, bør sendes videre til forsida framfor å bli en feilside.

### 1. Slipp begge adressene inn i admin, før byttet

I `~/lissom-secrets/secrets.php`:

```php
'tillatte_opphav' => ['https://ny.lissom.no', 'https://lissom.no', 'https://www.lissom.no'],
```

Denne alene endrer ingenting utad. Den gjør bare at admin kan lagre fra begge
adressene mens byttet står på — uten den får du «Forespørselen kom fra et
ukjent nettsted» i det sekundet adressen endrer seg.

### 2. Registrer den nye returadressen hos Vipps

Vipps sender kunden tilbake til en adresse som må være registrert på forhånd.
Står bare den gamle der, feiler innlogging med det samme.

I Vipps' portal, under salgsenheten: legg til

```
https://lissom.no/api/vipps-callback.php
```

**La den gamle stå** til flyttingen er ferdig og verifisert.

### 3. Pek lissom.no på den nye siden

**Slik ble det gjort, august 2026.** cPanel hos Domene.no låser dokumentroten
på hovedområdet — det finnes ingen blyant ved «Dokumentrot» på `lissom.no`.
Filene ble derfor flyttet i stedet:

1. Filbehandler → Innstillinger → **Vis skjulte filer**. Uten dette blir
   `.htaccess` liggende igjen, og ingen adresser på siden virker.
2. Alt i `public_html` unntatt `ny.lissom.no` og `.well-known` ble flyttet til
   `~/gammel-wordpress`. Ingenting slettet.
3. Alt i `public_html/ny.lissom.no` ble flyttet opp til `public_html`.
4. `server-dir` i `.github/workflows/deploy.yml` ble endret fra
   `public_html/ny.lissom.no/` til `public_html/`.

Punkt 4 må gjøres **etter** at filene er flyttet. Gjøres den før, legger neste
publisering filene i public_html ved siden av WordPress.

Har hosten senere låst opp dokumentroten, er det enklere å be support endre
den enn å flytte filene tilbake.

### 4. Bytt adressen serveren mener er sin egen

I `~/lissom-secrets/secrets.php`:

```php
'nettsted' => 'https://lissom.no',
```

Dette styrer Vipps-returer, lenker i e-post, canonical-adresser og
opphavssjekken. Gjør den **etter** steg 3, ikke før.

### 5. Sjekk at det virker, før noe slettes

- Åpne `https://lissom.no` — ny side, hengelås i adressefeltet.
- Logg inn med Vipps. Dette er det som ryker først hvis steg 2 ble glemt.
- Legg en vare i kurven og gå til Vipps. Avbryt før du betaler — poenget er
  at du kommer tilbake til riktig sted.
- Lagre noe i admin. Da vet du at opphavssjekken er i orden.
- `https://www.lissom.no` skal havne på `https://lissom.no`.

### 6. Send ny.lissom.no videre

Nå peker begge adressene på det samme, og det er to nettsteder med samme
innhold i Googles øyne. Legg dette øverst i `.htaccess`, rett etter
`RewriteEngine On`:

```apache
RewriteCond %{HTTP_HOST} ^ny\.lissom\.no$ [NC]
RewriteRule ^(.*)$ https://lissom.no/$1 [R=301,L,NE]
```

### 7. Fortell Google

- Search Console → Sitemaps → send inn `https://lissom.no/sitemap.xml`.
- Google Analytics → Datastrømmer → rett nettadressen til `lissom.no`.
- Google Bedriftsprofil → nettsted → `https://lissom.no`.

### 8. Rydd bort WordPress

Vent noen dager. Er alt stabilt, slett WordPress-filene i `public_html` —
**bortsett fra mappa `ny.lissom.no`**, som inneholder den levende siden.

En WordPress som står uten oppdateringer er en vanlig vei inn for angripere,
og her ville den delt konto med betalingsdata. Derfor skal den bort — men
ikke før det nye har stått en stund.
