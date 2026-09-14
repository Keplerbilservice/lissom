-- Paaminnelsen sa «i morgen» til folk som hadde kurs samme dag.
--
-- Eieren, 14. september 2026, med skjermbildet av e-posten: «vi gleder oss
-- til aa se deg i morgen, det er feil. Kurset var i dag😱 Det holder med aa
-- skrive vi gleder oss til aa se deg. Ogsaa info om kurset de meldte seg paa?
-- Dato og klokkeslett» — og «husk faa med tid dag to etc dersom aktuelt».
--
-- Hvorfor det sto feil: bin/cron.php («paaminnelser») henter alt som starter
-- innen 30 TIMER. Et kurs klokka 17 i dag treffer kjoringa klokka 07 samme
-- morgen, og da er «i morgen» loegn. Teksten sa det uansett naar den gikk ut.
--
-- Loesningen er teksten, ikke klokka: staar det ikke naar det er, kan det
-- ikke staa feil. I stedet staar kurset og tidspunktet svart paa hvitt.
--
-- «{naar}» er dagen og klokkeslettet, ferdig skrevet. Gaar kurset over flere
-- dager, staar hver dag paa sin egen linje — «Dag 1: …», «Dag 2: …» — hentet
-- fra samlingene paa kursdatoen (migrasjon 155). «{fornavn}» er forste ord i
-- navnet: «Mia Soerensen» blir «Mia».
--
-- WHERE-en verner mot aa skrive over en tekst eieren har endret selv. Staar
-- det fortsatt den opprinnelige teksten fra migrasjon 002, rettes den. Har
-- han skrevet sin egen, faar den staa — da er det hans ord, ikke vaare.

UPDATE notification_templates
   SET emne  = 'Påminnelse: {kurs} kl. {tid}',
       tekst = 'Hei {fornavn}! Vi gleder oss til å se deg.

{kurs}
{naar}

Du får låne forkle av oss, men regn med å bli litt skitten.
Adresse: Nordre Løkkevei 15, Teie.'
 WHERE navn = 'kurspaaminnelse'
   AND tekst LIKE 'Hei {navn}! Vi gleder oss til å se deg i morgen.%';
