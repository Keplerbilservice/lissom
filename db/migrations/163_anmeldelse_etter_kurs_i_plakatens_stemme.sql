-- Oppfoelgingen etter kurset, i samme stemme som plakaten «Anmeld oss».
--
-- Eieren, 11. september 2026: «vi har også laget en plakat med anmeld oss,
-- kan du bruke den som et utgangspunkt og lage klart en mal som er ment for
-- kursdeltagere som har gjennomført et kurs, ikke aktivere, bare lage
-- klart». GO paa teksten, som e-post.
--
-- Malen «anmeldelse» (migrasjon 080) er den som gaar til betalende
-- deltakere noen timer etter kursdatoen. Teksten byttes, kanalen blir
-- e-post (teksten er for lang til SMS), og malen settes inaktiv. Jobben
-- staar av fra foer (anmeldelse_paa = 0, lenke tom); naa maa ogsaa malen
-- skrus paa under Tekst maler foer noe sendes — «ikke aktivere».
--
-- Bare naar teksten er den fra 080. Har eieren skrevet sin egen i admin,
-- staar den.

UPDATE notification_templates
   SET kanal = 'epost',
       emne  = 'Likte du deg hos oss, {navn}?',
       tekst = 'Hei {navn}!\n\nTakk for at du var med på {kurs}. Vi håper du hadde en fin stund – og at du fikk leire under neglene.\n\nEt par ord fra deg betyr mer enn du tror, både for oss og for neste person som leter etter et keramikkurs. Det tar under ett minutt:\n{lenke}\n\nTusen takk!\n\n«Leiren husker alt du gjør med den – og vi husker alle som tar seg tid.»\n\nHilsen Monica, Lissom Keramikk',
       aktiv = 0
 WHERE navn = 'anmeldelse'
   AND tekst = 'Hei {navn}! Takk for at du var hos oss på {kurs}. Hadde du en fin stund, betyr det mye for oss om du legger igjen noen ord: {lenke}\n\nHilsen Monica, Lissom Keramikk';
