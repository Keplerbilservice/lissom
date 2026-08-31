-- Kursbeviset skal foelge kursholderen som faktisk holdt kurset.
--
-- Eieren, 31. august: «dersom noen andre holder kurset saa er vel dette
-- valgt i kursoppsettet? Saa da henter det her infra». Det burde det, og det
-- gjorde det ikke: api/kursbevis.php leste «courses.instruktor», et
-- fritekstfelt ingen annen del av systemet bruker, mens kursholderen man
-- velger staar i «course_sessions.kursholder_id» og «courses.kursholder_id»
-- (migrasjon 085) og peker til denne tabellen.
--
-- Nå leser beviset kursholderen. Da trenger tabellen en signatur — den hadde
-- navn, rolle, epost, telefon og timesats, men ingen. Uten dette feltet ville
-- et riktig navn faatt Monicas signatur under seg, og det er verre enn feil
-- navn: det ser ut som hun har skrevet under paa noe hun ikke var med paa.
--
-- ── Hvorfor Monica maa faa sin egen med det samme ─────────────────────
--
-- Migrasjon 093 satte henne som kursholder paa alle kurs og alle oekter.
-- Beviset falt tilbake paa henne naar fritekstfeltet var tomt, og det er det
-- overalt. Begynner beviset aa lese kursholderen uten at raden hennes har en
-- signatur, forsvinner signaturen fra hvert eneste kursbevis som skrives ut.
--
-- Samme forsiktighet som i 093: skjer bare naar det finnes noeyaktig én som
-- heter Monica, og bare der feltet staar tomt.

ALTER TABLE kursholdere
  ADD COLUMN IF NOT EXISTS signatur VARCHAR(255) NULL
      COMMENT 'Filnavn eller api/bilde.php-adresse til signaturen paa kursbeviset';

SET @monica := (
  SELECT id FROM kursholdere
   WHERE LOWER(TRIM(navn)) LIKE 'monica%'
   HAVING COUNT(*) = 1
);

UPDATE kursholdere
   SET signatur = 'signatur-monica.png'
 WHERE id = @monica
   AND (signatur IS NULL OR signatur = '');
