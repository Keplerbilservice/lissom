-- Alle utsendelser blir maler Monica kan endre selv.
--
-- Eieren, 1. september: «hvorfor kan ikke alle vaere redigerbare? og ligge i
-- et eget kort paa oversikt som heter maler».
--
-- Det var ingen god grunn. Ni av utsendelsene gikk gjennom Varsel::mal() og
-- laa i denne tabellen; tjue sto skrevet rett inn i PHP-en med Varsel::epost()
-- og kunne bare endres av meg. Forskjellen var at de ble skrevet paa to ulike
-- tidspunkt, ikke at noen hadde bestemt det.
--
-- Her flyttes de tjue inn. Teksten er ord for ord den samme som sto i koden,
-- med to unntak eieren har bedt om — se «hentetiden» nederst.
--
-- ── Delt der teksten hadde «hvis — ellers» ────────────────────────────
--
-- Fire av dem hadde to utfall i samme tekst. En mal kan ikke velge, saa de er
-- delt i to. Eieren, paa spoersmaal om hvordan: «del i to maler». Da ser han
-- hele teksten kunden faar, og kan skrive de to ulikt.
--
--   innmelding_fast_trekk / innmelding_ordner_selv
--   soknad_godkjent / soknad_avslatt
--   medlemsvare_godkjent / medlemsvare_avvist
--   butikkordre / butikkordre_pakke
--
-- ── To tekster der SMS-en er en annen enn e-posten ────────────────────
--
-- «Varsel::mal» sender den samme teksten i begge kanaler, og tilSmsTekst()
-- korter den bare av paa 900 tegn. En e-post paa fem avsnitt sendt som SMS
-- ville kostet penger og vaert uleselig. Der teksten faktisk er ulik, staar
-- de som hver sin mal.
--
-- ── Gruppa ───────────────────────────────────────────────────────────
--
-- Alle staar i «system», som er den gruppa Varsel::epost() brukte som
-- standard. Da oppfoerer signaturen seg noeyaktig som for. Skal en av dem
-- foelge signaturen for ordre eller kurs, kan gruppa endres senere — det er
-- et valg, ikke noe som skjer av seg selv naar de flyttes.

INSERT IGNORE INTO notification_templates (navn, kanal, emne, tekst, gruppe) VALUES

-- ── Butikken ─────────────────────────────────────────────────────────
--
-- Eieren, 1. september, om e-posten Monica fikk: «Trenger ikke staa at varene
-- er klare innen 2 dager». Den lovet to virkedager; kassa paa nettsiden lovet
-- to uker. Begge kunne ikke stemme, og ingen av dem var noe verkstedet ville
-- staa for. Teksten er hans egen, uten et antall dager.
--
-- Og: butikken selger frakt. Den som valgte «Send som pakke» skal ikke faa
-- beskjed om aa hente paa Teie.
('butikkordre', 'epost', 'Takk for bestillingen hos Lissom!',
 'Hei og takk for din bestilling.\n\nBestillingsnummer: {ordre}\n\n{varelinjer}\n\nTil sammen: {sum}\n\nDu kan hente varen hos oss i Nordre Løkkevei 15 på Teie i vår åpningstid.\n\nHilsen Lissom Keramikk', 'system'),

('butikkordre_pakke', 'epost', 'Takk for bestillingen hos Lissom!',
 'Hei og takk for din bestilling.\n\nBestillingsnummer: {ordre}\n\n{varelinjer}\n\nTil sammen: {sum}\n\nVi pakker og sender den til deg. Du får beskjed når pakken er på vei.\n\n{adresse}\n\nHilsen Lissom Keramikk', 'system'),

-- ── Gavekort ─────────────────────────────────────────────────────────
('gavekort_mottaker', 'epost', 'Gavekort til Lissom Keramikk',
 'Hei!\n\nDu har fått et gavekort på {belop} til Lissom Keramikk.{hilsen}\n\nKoden er: {kode}\nGyldig til {gyldig}.\n\nGavekortet kan brukes på kurs, events, medlemskap og verkstedtid. Oppgi koden når du bestiller, eller ta den med i verkstedet.\n\nHilsen Lissom Keramikk', 'system'),

('gavekort_kjoper', 'epost', 'Gavekortet er sendt',
 'Hei {navn}!\n\nGavekortet på {belop} er sendt til {mottaker}.\nKoden er {kode}, gyldig til {gyldig}.\n\nHilsen Lissom Keramikk', 'system'),

-- ── Medlemskap ───────────────────────────────────────────────────────
('medlemstrekk_varsel', 'epost', 'Trekk for medlemskapet ditt',
 'Hei {navn}!\n\n{belop} for medlemskapet «{plan}» trekkes i Vipps {dag}.\n\nDu kan si opp når som helst fra Min side, eller i Vipps-appen.', 'system'),

('innmelding_fast_trekk', 'epost', 'Velkommen som medlem hos Lissom',
 'Hei {navn},\n\nTakk for at du melder deg inn hos Lissom.\n\nØnsket medlemskap: {type}\n\nDu har opprettet en fast betalingsavtale i Vipps. Den trekkes automatisk, og du får beskjed før hvert trekk. Du kan si den opp fra Min side.\n\nMedlemskapet er aktivt så snart betalingen er registrert. Vi går gjennom dørkode og ordensregler første gang du kommer.\n\nHilsen Lissom Keramikk & Håndverk\nNordre Løkkevei 15, 3120 Nøtterøy', 'system'),

('innmelding_ordner_selv', 'epost', 'Velkommen som medlem hos Lissom',
 'Hei {navn},\n\nTakk for at du melder deg inn hos Lissom.\n\nØnsket medlemskap: {type}\n\nDu har betalt for denne perioden. Det kommer ingen automatiske trekk — vi tar kontakt før neste periode.\n\nMedlemskapet er aktivt så snart betalingen er registrert. Vi går gjennom dørkode og ordensregler første gang du kommer.\n\nHilsen Lissom Keramikk & Håndverk\nNordre Løkkevei 15, 3120 Nøtterøy', 'system'),

('soknad_godkjent', 'epost', 'Velkommen som medlem hos Lissom',
 'Hei {navn},\n\nSøknaden din er godkjent. Logg inn på lissom.no, så finner du medlemsdelen på Min side — timer, interne kurs og samlinger, og muligheten til å legge ut egne arbeider for salg.\n\nVi går gjennom dørkode og ordensregler første gang du kommer.\n\nHilsen Lissom Keramikk & Håndverk\nNordre Løkkevei 15, 3120 Nøtterøy', 'system'),

-- Kort med vilje: dette er en SMS, og den skal ikke vaere e-posten over.
('soknad_godkjent_sms', 'sms', 'Medlemskapet er godkjent',
 'Hei {navn}! Medlemskapet ditt hos Lissom er godkjent. Logg inn på lissom.no for å se Min side.', 'system'),

('soknad_avslatt', 'epost', 'Om søknaden din til Lissom',
 'Hei {navn},\n\nTakk for søknaden. Vi har dessverre ikke anledning til å ta deg opp som medlem nå.{begrunnelse}\n\nDu er hjertelig velkommen på kurs og arrangementer hos oss, og du kan søke igjen senere.\n\nHilsen Lissom Keramikk & Håndverk', 'system'),

-- ── Venteliste ───────────────────────────────────────────────────────
('venteliste_satt', 'epost', 'Du står på ventelisten hos Lissom',
 'Hei {navn}!\n\nDu er satt på ventelisten for {kurs}{dato}, som plass nummer {posisjon}.\n\nBlir det ledig, får du en e-post fra oss — eller vi forsøker å ringe deg. Du betaler ingenting før plassen er bekreftet.\n\nHilsen Lissom Keramikk', 'system'),

-- ── Foresporsler ─────────────────────────────────────────────────────
('foresporsel_mottatt', 'epost', 'Vi har fått forespørselen din',
 'Hei {navn},\n\nTakk for at du tok kontakt. Vi ser på forespørselen og svarer så snart vi kan — som regel samme dag.\n\nDette er det du sendte oss:\n\n{melding}\n\nHilsen Lissom Keramikk & Håndverk\nNordre Løkkevei 15, 3120 Nøtterøy\n+47 94 13 46 01', 'system'),

('foresporsel_svar', 'epost', 'Svar fra Lissom Keramikk', '{svar}', 'system'),

-- SMS bare naar kunden ikke har oppgitt e-post. Prefikset sier hvem det er
-- fra — en SMS uten avsender leses som spam.
('foresporsel_svar_sms', 'sms', 'Svar fra Lissom', 'Svar fra Lissom: {svar}', 'system'),

-- ── Medlemmenes egne varer ───────────────────────────────────────────
('medlemsvare_godkjent', 'epost', '«{tittel}» er ute i butikken',
 'Hei {navn}!\n\n«{tittel}» er godkjent og ligger nå i butikken på lissom.no.\n\nKjøperen betaler direkte til Vippsnummeret ditt, og tar kontakt for å avtale overlevering.', 'system'),

('medlemsvare_avvist', 'epost', '«{tittel}» ble ikke lagt ut',
 'Hei {navn}!\n\nVi har sett på «{tittel}», og legger den ikke ut slik den er nå.{grunn}\n\nDu kan legge den ut på nytt fra Min side når du vil.', 'system'),

-- ── Til verkstedet, ikke til kunden ──────────────────────────────────
--
-- Eieren ville ha disse med ogsaa: «ja, alle 29». De sendes til Monica selv,
-- og staar her saa det er ett sted aa lete framfor to.
('intern_ny_foresporsel', 'epost', 'Ny forespørsel fra {navn}', '{oppsummering}', 'system'),

('intern_nytt_medlem', 'epost', 'Nytt medlem: {navn}',
 'Det har meldt seg inn et nytt medlem.\n\nNavn: {navn}\nE-post: {epost}\nTelefon: {telefon}\nØnsket medlemskap: {type}\nBetaling: {betaling}\n{erfaring}{melding}', 'system'),

('intern_gave_pakkes', 'epost', 'Gave skal pakkes inn — {ordre}',
 'Bestilling {ordre} fra {navn} er merket som gave.\n\n{varelinjer}\n\nHilsen på kortet: {hilsen}', 'system'),

('intern_ny_vare', 'epost', 'Ny vare til godkjenning',
 '{produsent} har lagt ut «{tittel}» til {pris}.\n\nGodkjenn eller avvis under Admin → Butikk.', 'system'),

('intern_gave_lost_inn', 'epost', 'Gave løst inn: {tittel}',
 '{navn} ({kontakt}) har løst inn «{tittel}».\n\n{beskjed}', 'system'),

-- Kort med vilje: dette er en SMS til verkstedet, ikke e-posten over.
('intern_nytt_medlem_sms', 'sms', 'Nytt medlem',
 '{navn} har meldt seg inn ({type}, {betaling}). Se Admin → Medlemmer.', 'system');

-- ── Hentetiden ──────────────────────────────────────────────────────
--
-- Eieren, 1. september: «all hentetid av keramikk som er laget her er to til
-- fire uker, de faar beskjed eller kan se paa min side eller
-- https://lissom.no/ferdigbrent».
--
-- Denne malen sendes naar keramikken ER ferdig, saa den sier ikke hvor lang
-- tid det tar — den sier hvor lenge verkstedet tar vare paa den. Det tallet
-- roeres ikke. Det som legges til er naar den kan hentes: i aapningstida.
--
-- Ventetida paa to til fire uker staar paa nettsiden, og rettes der.
UPDATE notification_templates
   SET tekst = 'Hei {navn}! Keramikken din fra {kurs} er ferdig og klar til henting i åpningstiden vår. Vi oppbevarer den hos oss i tre uker.'
 WHERE navn = 'ferdig_brent';
