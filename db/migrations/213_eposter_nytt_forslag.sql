-- E-postene til kundene, etter eierens gjennomgang 25. september 2026.
--
-- Eieren: «Fjern eposten takk for at du spurte, bestilling, dropp
-- bestillingsnummer, de som har meldt seg på dreiekurs, må få mer info,
-- kursets lengde, veibeskrivelse, at vi serverer enkel snacks, kaffe eller
-- te, dere får låne forkle, og litt om hva vi går igjennom dag 1 og dag 2»
-- — og «det er mange eposter som sier det samme, så gå igjennom og lag et
-- helt nytt forslag». GO på forslaget samme dag.
--
-- - Påmeldingen: lengden, dagene og det praktiske kommer fra kurset
--   ({kursinfo}, se Booking::kursinfo), veibeskrivelsen fra Om oss.
-- - Butikken: uten bestillingsnummer.
-- - Påminnelsen og «Vil du fortsette» sier ikke det samme igjen.
-- - Dørkoden og ordensreglene står bare i «Medlemskapet er i gang».
-- - Adresse og telefon står i signaturen under hver e-post, ikke i teksten.
-- - Én avslutning: «Hilsen Lissom Keramikk».
-- - Dreiekurset får «Praktisk informasjon» (vises også på kurssiden —
--   eieren valgte det). Bare hvis feltet er tomt.

UPDATE notification_templates
   SET emne  = 'Du er påmeldt {kurs}',
       tekst = 'Hei {navn}!\n\nSå hyggelig at du blir med. Plassen din er klar.\n\n{naar}\n{kursinfo}\n\nFinn fram\nVi holder til i Nordre Løkkevei 15 på Teie, fem minutter fra Tønsberg sentrum over Kanalbrua. Gratis parkering rett utenfor.\n\nKvitteringen ligger på Min side.\n\n{betaling}\n\nVi gleder oss til å se deg!\n\nHilsen Lissom Keramikk'
 WHERE navn = 'ordrebekreftelse';

UPDATE notification_templates
   SET tekst = 'Hei {navn}, og takk for bestillingen!\n\n{varelinjer}\nTil sammen: {sum}\n{betaling}\n\nDu kan hente den hos oss på Teie i åpningstiden.\n\nHilsen Lissom Keramikk'
 WHERE navn = 'butikkordre';

UPDATE notification_templates
   SET tekst = 'Hei {navn}, og takk for bestillingen!\n\n{varelinjer}\nTil sammen: {sum}\n\nVi pakker og sender den til deg. Du får beskjed når pakken er på vei.\n\n{adresse}\n\nHilsen Lissom Keramikk'
 WHERE navn = 'butikkordre_pakke';

UPDATE notification_templates
   SET tekst = 'Hei {fornavn}! Vi gleder oss til å se deg {naar}.\n\nGratis parkering rett utenfor. Alt om kurset står i påmeldingen du fikk fra oss.\n\nHilsen Lissom Keramikk'
 WHERE navn = 'kurspaaminnelse';

UPDATE notification_templates
   SET tekst = 'Hei {navn}!\n\nDet du laget på kurset, var bare begynnelsen. Som tidligere kursdeltaker kan du bli medlem i verkstedet på Teie og fortsette der kurset slapp.\n\n- Fullt utstyrt verksted med dreieskiver\n- Leire, glasurer og brenning på ett sted\n- Et fellesskap som deler tips og skaperglede\n- Kom og gå når det passer deg\n\n{visste}Se medlemskapene: https://lissom.no/medlemskap\n\nEller stikk innom, så viser vi deg rundt. Vi håper vi ses igjen!\n\nDu får denne e-posten fordi du har deltatt på kurs hos oss. Meld deg av: {avmelding}'
 WHERE navn = 'fortsett';

-- Dørkoden og ordensreglene: bare i «Medlemskapet er i gang».
UPDATE notification_templates
   SET tekst = REPLACE(REPLACE(tekst,
         '\n\nVi går gjennom dørkode og ordensregler første gang du kommer.', ''),
         ' Vi går gjennom ordensreglene\nførste gang du kommer.', '')
 WHERE navn IN ('innmelding_fast_trekk', 'innmelding_ordner_selv', 'soknad_godkjent');

-- Adressen og telefonen står i signaturen.
UPDATE notification_templates
   SET tekst = REPLACE(REPLACE(tekst,
         '\nNordre Løkkevei 15, 3120 Nøtterøy\n+47 94 13 46 01', ''),
         '\nNordre Løkkevei 15, 3120 Nøtterøy', '')
 WHERE navn IN ('innmelding_fast_trekk', 'innmelding_ordner_selv', 'medlemskap_betalt',
                'soknad_godkjent', 'foresporsel_mottatt');

-- Én avslutning.
UPDATE notification_templates
   SET tekst = REPLACE(tekst, 'Hilsen Lissom Keramikk & Håndverk', 'Hilsen Lissom Keramikk')
 WHERE navn NOT LIKE 'intern\_%';

-- Dreiekurset: det praktiske, som står i påmeldingen og på kurssiden.
UPDATE courses
   SET praktisk = 'Vi serverer enkel snacks, og kaffe eller te.\nDere får låne forkle, men regn med å bli litt skitten.\nLeire, verktøy, glasur og brenning er inkludert.'
 WHERE slug = 'dreiekurs'
   AND (praktisk IS NULL OR TRIM(praktisk) = '');
