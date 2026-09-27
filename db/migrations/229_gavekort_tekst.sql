-- godkjent av eieren: 2026-09-27
--
-- Gavekortet: nye tekster, uten «verkstedtid», med litt følelse av gave.
-- Eieren, 27. september 2026: «gavekort tekst, få gemini til å sjekke, men
-- stryk verkstedtid, går det an å lage noe som indikerer litt gave? men ikke
-- for mye og det må være i vår stil så klart». Tekstene er Geminis forslag,
-- godkjent av eieren samme dag (skissen «Gavekortsiden med Geminis tekster»).
--
-- Bare der teksten fortsatt er den gamle standarden: har eieren skrevet noe
-- eget under Nettsiden → Innhold, eller rettet e-posten i Tekst maler, står det.

UPDATE content_blocks
   SET verdi = 'Gi bort litt tid med leire'
 WHERE nokkel = 'Gavekort/0/Overskrift'
   AND verdi = 'Gavekort på keramikkurs';

UPDATE content_blocks
   SET verdi = 'Et gavekort hos oss er en invitasjon til å senke skuldrene og skape noe med hendene. Enten mottakeren vil delta på et kurs, eller velge seg håndlaget keramikk fra hyllene våre på Teie. Du kan velge et fritt beløp fra 100 til 20 000 kroner, og legge ved en liten hilsen.'
 WHERE nokkel = 'Gavekort/0/Brødtekst'
   AND verdi = 'En opplevelse å glede seg til — og noe håndlaget å ta med hjem. Kan brukes på alle våre tjenester og produkter, med en personlig hilsen fra deg.';

-- E-posten mottakeren får: bare hvis den står slik migrasjon 227 satte den.
UPDATE notification_templates
   SET overskrift = 'Et gavekort til deg',
       avsnitt = '["Du har fått et gavekort på {belop} til Lissom Keramikk.","{hilsen}","Gavekortet kan du bruke på kurs, events, medlemskap eller ferdig keramikk i butikken vår. Du velger selv hva som passer best.","For å delta på kurs finner du en dato på nettsiden vår og legger inn koden når du bestiller. Vil du heller bruke det på keramikk i butikken, viser du bare frem koden når du er innom oss på Teie."]'
 WHERE navn = 'gavekort_mottaker'
   AND overskrift = 'En kreativ gave til deg'
   AND avsnitt = '["Hei,","Du har fått et gavekort til Lissom Keramikk. {hilsen}","Gavekortet kan du bruke på kurs, events, medlemskap og verkstedtid. Oppgi koden når du bestiller, eller ta den med deg ned i verkstedet på Teie.","Vi gleder oss til å se deg."]';
