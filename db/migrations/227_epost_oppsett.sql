-- godkjent av eieren: 2026-09-27
-- Nytt oppsett for e-postene: overskrift, avsnitt, faktakort, knapp og en
-- sekundaer lenke. Eieren, 27. september 2026: «ikke bra nok, jeg vil ha med
-- knapper og kort», og «la gemini vurdere tekstene også». Tekstene er
-- Geminis forslag, godkjent av eieren mal for mal samme dag.
--
-- Dagens emne og tekst tas vare paa i emne_for_oppsett og tekst_for_oppsett,
-- saa det kan rulles tilbake. «tekst» selv staar urort: SMS-en bruker den.
-- aktiv roeres ikke — det som sendes i dag, sendes fortsatt, og det som
-- staar av, staar av.
--
-- intern_ny_vare og intern_ny_vare_ute blir én mal (begge sto av). Den
-- gamle raden slettes; koden sender statusen som eget felt.

ALTER TABLE notification_templates
  ADD COLUMN IF NOT EXISTS overskrift VARCHAR(191) NULL,
  ADD COLUMN IF NOT EXISTS avsnitt TEXT NULL,
  ADD COLUMN IF NOT EXISTS kort TEXT NULL,
  ADD COLUMN IF NOT EXISTS knapp TEXT NULL,
  ADD COLUMN IF NOT EXISTS lenke2 TEXT NULL,
  ADD COLUMN IF NOT EXISTS emne_for_oppsett VARCHAR(191) NULL,
  ADD COLUMN IF NOT EXISTS tekst_for_oppsett TEXT NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Takk for at du var hos oss, {fornavn}',
    overskrift = 'Håper du hadde det koselig',
    avsnitt = '["Hei {fornavn}.","Tusen takk for at du var med. Det var veldig hyggelig å ha deg på besøk i verkstedet.","Vi setter stor pris på om du vil ta deg tid til å skrive noen ord om oss på Google. Det hjelper oss mye.","Håper vi sees igjen!","Hilsen Monica"]',
    kort = '[["Kursbevis","{kursbevis}"]]',
    knapp = '["Skriv en anmeldelse","{lenke}"]',
    lenke2 = '["Gå til Min side","https://lissom.no/min-side"]'
  WHERE navn = 'anmeldelse' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Avbestillingen din er registrert',
    overskrift = 'Bekreftelse på avbestilling',
    avsnitt = '["Hei {navn}.","Vi har nå avbestilt plassen din.","{refusjon}","Håper vi får muligheten til å se deg i verkstedet en annen gang.","Hilsen Monica"]',
    kort = '[["Kurs","{kurs}"],["Refusjon","{belop}"],["Behandlingstid","{refusjonstid}"]]',
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'avbestilling' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Vi fikk ikke trukket betalingen',
    overskrift = 'Problemer med månedstrekket',
    avsnitt = '["Hei {navn}.","Månedstrekket ditt feilet dessverre. For å beholde tilgangen din må du åpne Vipps og godkjenne betalingen.","Ta gjerne kontakt med oss om du lurer på noe.","Hilsen Monica"]',
    kort = '[["Abonnement","{abonnement}"],["Frist","Innen 5 dager"]]',
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'betaling_feilet' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Bestillingen din er klar til henting',
    overskrift = 'Varene dine venter på deg',
    avsnitt = '["Hei {navn}, og tusen takk for bestillingen.","Nå er alt pakket klart. Du kan stikke innom oss på Teie for å hente varene når vi har åpent.","Hilsen Monica"]',
    kort = '[["Varer","{varelinjer}"],["Totalt","{sum}"],["Betaling","{betaling}"]]',
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'butikkordre' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Takk for bestillingen — den sendes som pakke',
    overskrift = 'Vi gjør klar pakken din',
    avsnitt = '["Hei {navn}, og tusen takk for bestillingen.","Vi pakker varene trygt inn og gjør dem klare for sending. Du får en ny beskjed fra oss så snart pakken er på vei.","Hilsen Monica"]',
    kort = '[["Varer","{varelinjer}"],["Totalt","{sum}"],["Leveres til","{adresse}"]]',
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'butikkordre_pakke' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Dugnad — ikke denne gangen',
    overskrift = 'Angående dugnadsøknaden din',
    avsnitt = '["Hei {fornavn}.","Akkurat nå har vi dessverre ikke mulighet til å ta imot denne dugnaden i verkstedet.","{svar}","Tusen takk for at du tilbød deg, du må gjerne spørre igjen en annen gang.","Hilsen Monica"]',
    kort = '[["Dugnad","{tekst}"]]',
    knapp = '["Gå til Min side","https://lissom.no/min-side"]',
    lenke2 = NULL
  WHERE navn = 'dugnad_avslatt' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Dugnaden din er godkjent',
    overskrift = 'Klart for dugnad',
    avsnitt = '["Hei {fornavn}.","Vi har godkjent forslaget ditt til dugnad. Tusen takk for at du vil bidra i verkstedet.","{svar}","Husk å stemple inn på Min side når du begynner, og ut når du er ferdig. Tiden blir lagt til timene dine etter at jobben er godkjent.","Hilsen Monica"]',
    kort = '[["Dugnad","{tekst}"]]',
    knapp = '["Stemple inn her","https://lissom.no/min-side"]',
    lenke2 = NULL
  WHERE navn = 'dugnad_godkjent' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Dugnaden ble ikke godkjent',
    overskrift = 'Angående registrert dugnadstid',
    avsnitt = '["Hei {fornavn}.","Tiden du førte for dugnaden ble dessverre ikke godkjent denne gangen, og den er derfor ikke lagt til på kontoen din.","{svar}","Si gjerne ifra til oss om du har spørsmål eller lurer på noe rundt dette.","Hilsen Monica"]',
    kort = '[["Dugnad","{tekst}"]]',
    knapp = '["Gå til Min side","https://lissom.no/min-side"]',
    lenke2 = NULL
  WHERE navn = 'dugnad_tid_avvist' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Takk for dugnaden — timer lagt til',
    overskrift = 'Dugnadstimene er registrert',
    avsnitt = '["Hei {fornavn}.","Tusen takk for den fine innsatsen din. Vi har nå godkjent timene, og de ligger klare inne på profilen din.","{svar}","Hilsen Monica"]',
    kort = '[["Dugnad","{tekst}"],["Godkjent tid","{timer} timer"]]',
    knapp = '["Se timene dine","https://lissom.no/min-side"]',
    lenke2 = NULL
  WHERE navn = 'dugnad_tid_godkjent' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Keramikken din er ferdig og klar til henting',
    overskrift = 'Klar til henting',
    avsnitt = '["Hei {navn}.","Nå har vi tatt keramikken din ut av ovnen, og den er brent og ferdig. Du kan stikke innom oss i åpningstiden for å hente den.","Vi gleder oss til å vise deg resultatet.","Hilsen Monica"]',
    kort = '[["Kurs","{kurs}"],["Hentefrist","Innen to uker"]]',
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'ferdig_brent' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Vi har mottatt forespørselen din',
    overskrift = 'Takk for at du tar kontakt',
    avsnitt = '["Hei {navn},","Vi ser på forespørselen din og svarer så snart vi kan, som regel samme dag.","Her er en kopi av meldingen du sendte oss:","{melding}"]',
    kort = NULL,
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'foresporsel_mottatt' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Svar på forespørselen din',
    overskrift = 'Svar fra verkstedet',
    avsnitt = '["{svar}"]',
    kort = NULL,
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'foresporsel_svar' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Vil du fortsette med leire?',
    overskrift = 'Fortsett der kurset slapp',
    avsnitt = '["Hei {navn},","Det du laget på kurset, var bare begynnelsen. Som tidligere kursdeltaker kan du bli medlem i verkstedet på Teie og fortsette med leiren når det passer deg.","Hos oss finner du alt på ett sted: fullt utstyrte dreieskiver, leire, glasurer og brenning. Du blir også en del av et fellesskap som deler tips og skaperglede.","{visste}","Stikk gjerne innom, så viser vi deg rundt. Vi håper vi ses igjen.","Du får denne e-posten fordi du har deltatt på kurs hos oss."]',
    kort = NULL,
    knapp = '["Se medlemskap","https://lissom.no/medlemskap"]',
    lenke2 = '["Meld deg av","{avmelding}"]'
  WHERE navn = 'fortsett' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Gavekortet ditt er sendt',
    overskrift = 'Takk for kjøpet',
    avsnitt = '["Hei {navn},","Gavekortet ditt er nå sendt til mottakeren. Vi håper det faller i smak, og gleder oss til å se dem i verkstedet."]',
    kort = '[["Beløp","{belop}"],["Mottaker","{mottaker}"],["Kode","{kode}"],["Gyldig til","{gyldig}"]]',
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'gavekort_kjoper' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Du har fått et gavekort',
    overskrift = 'En kreativ gave til deg',
    avsnitt = '["Hei,","Du har fått et gavekort til Lissom Keramikk. {hilsen}","Gavekortet kan du bruke på kurs, events, medlemskap og verkstedtid. Oppgi koden når du bestiller, eller ta den med deg ned i verkstedet på Teie.","Vi gleder oss til å se deg."]',
    kort = '[["Beløp","{belop}"],["Kode","{kode}"],["Gyldig til","{gyldig}"]]',
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'gavekort_mottaker' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Medlemskapet ditt hos Lissom',
    overskrift = 'Velkommen som medlem',
    avsnitt = '["Hei {navn},","Takk for at du melder deg inn i verkstedet vårt.","Den første måneden trekkes med det samme. Avtalen finner du under faste trekk i Vipps-appen. Der ser du når neste trekk går, og du kan stoppe den akkurat når du vil."]',
    kort = '[["Medlemskap","{type}"],["Pris i måneden","{belop}"]]',
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'innmelding_fast_trekk' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Takk for at du melder deg inn',
    overskrift = 'Velkommen til verkstedet',
    avsnitt = '["Hei {navn},","Takk for at du melder deg inn hos oss.","Du finner dørkoden og en oversikt over timene dine inne på Min side."]',
    kort = '[["Medlemskap","{type}"],["Beløp","{belop}"]]',
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'innmelding_ordner_selv' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Dugnad fullført: {navn}',
    overskrift = 'Utført dugnad',
    avsnitt = '["{navn} har stemplet ut fra dugnad.","«{tekst}»","Se over jobben og godkjenn tidsbruken i adminpanelet."]',
    kort = '[["Medlem","{navn}"],["Tid brukt","{varighet}"],["Foreslått godkjenning","{forslag} timer"]]',
    knapp = '["Godkjenn tid","https://lissom.no/admin/ubesvarte"]',
    lenke2 = NULL
  WHERE navn = 'intern_dugnad_ferdig' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Forespørsel om dugnad: {navn}',
    overskrift = 'Ny dugnadsforespørsel',
    avsnitt = '["{navn} spør om å jobbe dugnad i verkstedet:","«{tekst}»","Du kan godkjenne eller avslå forespørselen direkte i adminpanelet."]',
    kort = '[["Medlem","{navn}"]]',
    knapp = '["Behandle forespørsel","https://lissom.no/admin/ubesvarte"]',
    lenke2 = NULL
  WHERE navn = 'intern_dugnad_sporsmal' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Gave løst inn: {tittel}',
    overskrift = 'Innløst gave',
    avsnitt = '["En gave har blitt løst inn.","{beskjed}"]',
    kort = '[["Gave","{tittel}"],["Navn","{navn}"],["Kontakt","{kontakt}"]]',
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'intern_gave_lost_inn' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Ny forespørsel fra {navn}',
    overskrift = 'Ny forespørsel',
    avsnitt = '["En ny forespørsel har kommet inn:","{oppsummering}"]',
    kort = '[["Avsender","{navn}"]]',
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'intern_ny_foresporsel' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Ny påmelding til {kurs}',
    overskrift = 'Ny kursdeltaker',
    avsnitt = '["{navn} har meldt seg på kurs.","Du finner påmeldingen under Admin og deretter Påmeldte."]',
    kort = '[["Kurs","{kurs}"],["Når","{naar}"],["Beløp","{belop}"],["Betaling","{betaling}"],["E-post","{epost}"],["Telefon","{telefon}"]]',
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'intern_ny_pamelding' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Ny vare i butikken: {tittel}',
    overskrift = 'Ny vare fra et medlem',
    avsnitt = '["{produsent} har lagt ut en ny vare i butikken.","Du finner den under Admin og deretter Butikk."]',
    kort = '[["Vare","{tittel}"],["Produsent","{produsent}"],["Pris","{pris}"],["Status","{status}"]]',
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'intern_ny_vare' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Nytt medlem: {navn}',
    overskrift = 'Ny innmelding',
    avsnitt = '["Det har meldt seg inn et nytt medlem i verkstedet.","{erfaring}{melding}"]',
    kort = '[["Navn","{navn}"],["E-post","{epost}"],["Telefon","{telefon}"],["Ønsket medlemskap","{type}"],["Betaling","{betaling}"]]',
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'intern_nytt_medlem' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Påminnelse om kurs: {kurs}',
    overskrift = 'Snart klart for kurs',
    avsnitt = '["Hei {fornavn}.","Vi gleder oss til å se deg på verkstedet snart. Husk at du kan parkere helt gratis rett utenfor.","Alt du trenger å vite om selve kurset finner du i bekreftelsen du fikk da du meldte deg på.","Hilsen Monica hos Lissom Keramikk"]',
    kort = '[["Kurs","{kurs}"],["Dato","{naar}"],["Tid","kl. {tid}"]]',
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'kurspaaminnelse' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Bestilling {nummer} fra Lissom Keramikk & Håndverk',
    overskrift = 'Bestilling {nummer}',
    avsnitt = '["Hei, vi vil gjerne bestille varene spesifisert under.","Siden bestillingen er delt opp per person, ber vi om at hver bestilling pakkes for seg og merkes med navn.","Gi oss beskjed dersom noe ikke er på lager, så fjerner vi det fra ordren.","{varer}"]',
    kort = '[["Leveringsadresse","Nordre Løkkevei 15, 3120 Nøtterøy"]]',
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'leverandorbestilling' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Medlemskapet ditt er i gang',
    overskrift = 'Velkommen som medlem',
    avsnitt = '["Hei {navn}.","Nå er betalingen registrert, og medlemskapet ditt er i gang. Første gang du kommer innom verkstedet, tar vi en liten gjennomgang av ordensreglene sammen.","Dørkoden din og hvor mange timer du har til gode, finner du alltid oppdatert på Min side.","Hilsen Monica hos Lissom Keramikk"]',
    kort = '[["Medlemskap","{type}"],["Beløp","{belop}"],["Gyldighet","{gyldig}"]]',
    knapp = '["Gå til Min side","https://lissom.no/min-side"]',
    lenke2 = NULL
  WHERE navn = 'medlemskap_betalt' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Medlemskapet ditt er fornyet',
    overskrift = 'Klar for en ny måned',
    avsnitt = '["Hei {navn}.","Da er medlemskapet ditt fornyet for en ny måned, og timene dine er fylt opp igjen.","Jeg ønsker deg riktig god skapelyst i verkstedet!","Hilsen Monica"]',
    kort = '[["Abonnement","{abonnement}"]]',
    knapp = '["Gå til Min side","https://lissom.no/min-side"]',
    lenke2 = NULL
  WHERE navn = 'medlemskap_fornyet' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Trekk for medlemskapet ditt',
    overskrift = 'Snart tid for fornyelse',
    avsnitt = '["Hei {navn}.","Dette er bare en liten beskjed for å minne om at medlemskapet ditt snart fornyes. Beløpet trekkes automatisk i Vipps.","Husk at du kan si opp avtalen når som helst fra Min side, eller direkte inne i Vipps-appen.","Hilsen Monica"]',
    kort = '[["Medlemskap","{plan}"],["Beløp","{belop}"],["Trekkdato","{dag}"]]',
    knapp = '["Gå til Min side","https://lissom.no/min-side"]',
    lenke2 = NULL
  WHERE navn = 'medlemstrekk_varsel' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Vare ikke godkjent for salg',
    overskrift = 'Vi trenger en liten justering av varen din',
    avsnitt = '["Hei {navn}.","Vi har sett på varen din, og vi kan dessverre ikke legge den ut i butikken helt slik den er nå.","{grunn}","Når du har rettet opp dette, kan du enkelt legge den ut på nytt fra Min side når som helst."]',
    kort = '[["Vare","{tittel}"]]',
    knapp = '["Gå til Min side","https://lissom.no/min-side"]',
    lenke2 = NULL
  WHERE navn = 'medlemsvare_avvist' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = '«{tittel}» er nå ute i butikken',
    overskrift = 'Varen din ligger klar for salg',
    avsnitt = '["Hei {navn}.","Varen din er godkjent og ligger nå synlig i nettbutikken vår på lissom.no.","Kjøperen betaler direkte til Vippsnummeret ditt, og tar deretter kontakt med deg for å avtale overlevering."]',
    kort = '[["Vare","{tittel}"]]',
    knapp = '["Se nettbutikken","https://lissom.no"]',
    lenke2 = NULL
  WHERE navn = 'medlemsvare_godkjent' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Du er påmeldt {kurs}',
    overskrift = 'Plassen din er klar',
    avsnitt = '["Hei {navn}.","Så hyggelig at du blir med på kurs. Vi ser fram til å treffes i verkstedet.","{kursinfo}","Kvitteringen din ligger på Min side. Vi holder til fem minutter fra Tønsberg sentrum over Kanalbrua.","{betaling}","Vi gleder oss til å se deg! Hilsen Lissom Keramikk."]',
    kort = '[["Kurs","{kurs}"],["Tid","{naar}"],["Adresse","Nordre Løkkevei 15, Teie"],["Parkering","Gratis rett utenfor"]]',
    knapp = '["Se Min side","https://lissom.no/min-side"]',
    lenke2 = NULL
  WHERE navn = 'ordrebekreftelse' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Ny dato for {kurs}',
    overskrift = 'Kurset ditt er flyttet',
    avsnitt = '["Hei {navn}.","Vi har måttet flytte kurset ditt til en ny dato. Du trenger ikke å gjøre noe, og betalingen din følger automatisk med."]',
    kort = '[["Kurs","{kurs}"],["Tidligere dato","{fra}"],["Ny dato","{til}"]]',
    knapp = '["Se endringen på Min side","{lenke}"]',
    lenke2 = NULL
  WHERE navn = 'pamelding_flyttet' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Svar på medlemssøknad hos Lissom',
    overskrift = 'Vedrørende din søknad',
    avsnitt = '["Hei {navn}.","Takk for at du søkte om å bli medlem hos oss. Vi har dessverre ikke anledning til å ta deg opp som medlem akkurat nå.","{begrunnelse}","Du er hjertelig velkommen til å delta på kurs og arrangementer hos oss, og du må gjerne søke igjen ved en senere anledning.","Hilsen Lissom Keramikk."]',
    kort = NULL,
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'soknad_avslatt' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Velkommen som medlem hos Lissom',
    overskrift = 'Søknaden din er godkjent',
    avsnitt = '["Hei {navn}.","Vi er glade for å fortelle at søknaden din er godkjent, og ønsker deg varmt velkommen som medlem hos oss.","Når du logger inn på nettsiden, finner du medlemsdelen på Min side. Der kan du booke timer, melde deg på interne kurs og samlinger, og legge ut dine egne arbeider for salg.","Hilsen Lissom Keramikk."]',
    kort = NULL,
    knapp = '["Gå til Min side","https://lissom.no/min-side"]',
    lenke2 = NULL
  WHERE navn = 'soknad_godkjent' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Ledig plass på {kurs}',
    overskrift = 'Det har åpnet seg en plass',
    avsnitt = '["Hei {navn}.","Det har nettopp blitt en ledig plass på kurset du står på venteliste for. Her gjelder førstemann til mølla, så vær rask om du vil være med."]',
    kort = '[["Kurs","{kurs}"],["Dato","{dato}"]]',
    knapp = '["Book plassen din","{lenke}"]',
    lenke2 = NULL
  WHERE navn = 'venteliste_ledig' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Du står på venteliste for {kurs}',
    overskrift = 'Vi har notert deg på ventelisten',
    avsnitt = '["Hei {navn}.","Skulle det bli en ledig plass, sender vi deg en e-post eller forsøker å ringe deg. Du betaler ingenting før en plass eventuelt er bekreftet.","Hilsen Lissom Keramikk."]',
    kort = '[["Kurs","{kurs}"],["Dato","{dato}"],["Kønummer","{posisjon}"]]',
    knapp = NULL,
    lenke2 = NULL
  WHERE navn = 'venteliste_satt' AND overskrift IS NULL;

UPDATE notification_templates SET
    emne_for_oppsett = emne, tekst_for_oppsett = tekst,
    emne = 'Du har fått plass på {kurs}',
    overskrift = 'Plassen er din',
    avsnitt = '["Hei {navn}.","Gode nyheter! Du har fått plass på kurset. Vi har satt av plassen til deg, og den står som reservert fram til betalingen er gjort opp."]',
    kort = '[["Kurs","{kurs}"],["Dato","{dato}"]]',
    knapp = '["Se plassen på Min side","{lenke}"]',
    lenke2 = NULL
  WHERE navn = 'venteliste_tildelt' AND overskrift IS NULL;

DELETE FROM notification_templates WHERE navn = 'intern_ny_vare_ute';
