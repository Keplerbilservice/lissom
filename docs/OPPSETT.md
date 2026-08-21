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
| Redirect-URI (Login) | `https://lissom.no/api/vipps-callback.php` |
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
