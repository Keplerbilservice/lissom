# Avtaletrekk i Vipps — hva løsningen skal kunne

Skrevet 7. september 2026, da eieren ba om at avtaledelen skulle rives ut og
bygges på nytt: «du skal rive ut og bygge avtale vipssen på nytt», og «du får
lagre hvilke funksjoner vi skal ha, med valg av dato for trekk etc når du skal
lage ny».

Dette er kravlista. Alt som bygges skal kunne krysses av her.

## Hva det gjelder

Bare medlemskap som krever fast trekk. I dag er det **Årsmedlemskap** alene
(`membership_plans.krever_fast_trekk = 1`, migrasjon 146). Alt annet betales
med vanlig Vipps-betaling og er ikke berørt.

## Én vei inn

1. Medlemmet trykker på medlemskapet — på nettsida, eller på en lenke
   verkstedet har sendt.
2. Avtalen opprettes hos Vipps i det trykket, og hun sendes rett dit.
   Adressen Vipps gir lever i ti minutter; den skal aldri lagres og sendes
   videre senere.
3. Hun godkjenner i appen og kommer tilbake.
4. Vi spør Vipps om status. Sier Vipps `ACTIVE`, er medlemskapet aktivt.

Det finnes **én** lenke verkstedet kan sende: innmeldingsordren,
`/meld-inn/<nøkkel>`. Den lever i fjorten dager når verkstedet lager den, ett
døgn når medlemmet står i innmeldingen selv. Planen ligger i raden, ikke i
nettleseren.

## Krav

### K1 · Status kommer fra Vipps, ikke fra oss
Medlemmet blir aktivt når og bare når Vipps sier `ACTIVE`. Ingen sperre i vår
kode skal holde igjen en avtale Vipps har godkjent.

### K2 · Ingen død lenke skal kunne sendes
Vipps sin egen adresse lagres ikke og gjenbrukes ikke. Sendes noe ut, er det
vår egen lenke, og avtalen lages først i det den trykkes.

### K3 · Hun kommer ikke alltid tilbake
Godkjenner hun i appen og lukker den, skal medlemskapet bli aktivt likevel.
Cron spør Vipps om status på avtaler som står «venter».

### K4 · Én avtale per medlem
To aktive avtaler betyr to trekk. Gamle forsøk avlyses når et nytt starter.

### K5 · Trekkdato kan velges per medlem
Verkstedet kan sette hvilken dag i måneden trekket skal gå — noen vil ha den
15., andre den 1. Dagen huskes, så den 31. blir 28. i februar og 31. igjen i
mars. (`subscriptions.trekk_dag`, migrasjon 149.)

### K6 · Første trekk
Trekkes fra dagen etter godkjenning, ikke fra den 1. Ingen skal betale full
pris for en halv måned.

### K7 · Et bestilt trekk kan stoppes
Står et trekk som bestilt og ikke gjennomført, skal verkstedet kunne slette
det hos Vipps uten at penger flyttes.

### K8 · Avtalen kan stoppes
Sies medlemskapet opp, stoppes avtalen hos Vipps. En stoppet avtale kan ikke
startes igjen — da lages en ny.

### K9 · Alt skal kunne ses i admin
Hver avtale medlemmet har, med status fra Vipps, beløp, neste trekk og siste
trekk. Også de gamle radene — det er de som fører til dobbelt trekk.

### K10 · Ingenting skjer stille
Feiler et kall mot Vipps, skal det stå i feilloggen med avtale-ID.

## Det som er revet ut

- `avtale_lenker` og `/godkjenn/<nøkkel>` — vår andre lenke. Én er nok.
- Purringene på den lenka, og varselmalen `avtale_ikke_godkjent`.
- Sperra som holdt medlemmet inaktivt til søknaden var godkjent i admin.
- Gjenbruk av lagret Vipps-adresse i avtalegreina.

## Det som ikke kan bevises herfra

Hele kjeden kan kjøres mot en falsk Vipps som viser nøyaktig hva vi ber om og
hva vi gjør med svaret. Det den **ikke** kan si noe om, er om den ekte Vipps
godtar avtalen. Eieren fikk «Vi kjenner ikke denne QR-koden» i appen både på
en lenke som var over et døgn gammel (Eirin, 6. september) og på en som var
sekunder gammel (ham selv, 7. september). Da er lenkealderen utelukket, og det
som står igjen er oppsettet av Recurring på salgsenheten hos Vipps. Det ligger
utenfor koden.
