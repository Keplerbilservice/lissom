-- Drop-in rives ut.
--
-- Eieren, 2. september: «Det skal heller ikke vaere noe som heter drop inn.»
--
-- Migrasjon 110 skrudde det AV og lot alt staa, fordi han 31. august ba om
-- nettopp det: «lagre hvordan drop inn virker slik at jeg kan be deg hente det
-- frem senere». Naa vil han det motsatte, og har bekreftet det etter aa ha
-- faatt vite at det er gjenopprettingen som ryker.
--
-- Etter denne kan drop-in ikke hentes fram igjen. Det maa bygges paa nytt.

-- ---------------------------------------------------------------------------
-- Kurset
-- ---------------------------------------------------------------------------
--
-- Oektene forst: de peker paa kurset. Ingen av dem har en booking — det er
-- sjekket for denne ble skrevet — men betingelsen staar her likevel, fordi en
-- base i drift ikke er den samme som en base man har sett paa.
DELETE cs
  FROM course_sessions cs
  JOIN courses c ON c.id = cs.course_id
 WHERE (c.type = 'dropin' OR c.slug = 'drop-in' OR c.tema = 'Drop-in')
   AND NOT EXISTS (
         SELECT 1 FROM bookings b WHERE b.course_session_id = cs.id
       );

-- Selve kurset. Har noen likevel en booking paa det, staar kurset igjen —
-- en rad et bilag peker paa, slettes ikke.
DELETE c
  FROM courses c
 WHERE (c.type = 'dropin' OR c.slug = 'drop-in' OR c.tema = 'Drop-in')
   AND NOT EXISTS (SELECT 1 FROM bookings b  WHERE b.course_id = c.id)
   AND NOT EXISTS (SELECT 1 FROM course_sessions cs WHERE cs.course_id = c.id);

-- ---------------------------------------------------------------------------
-- Ukereglene
-- ---------------------------------------------------------------------------
--
-- «Drop-in gaar tirsdag 10-13» og de seks andre. De styrte ingenting lenger
-- etter at skjermen som leste dem ble slettet.
DROP TABLE IF EXISTS dropin_tider;

-- ---------------------------------------------------------------------------
-- Kolonnene
-- ---------------------------------------------------------------------------
--
-- «fast_fra»/«fast_til» var drop-ins eget vindu: kurset sto hver dag mellom
-- to klokkeslett, uavhengig av naar verkstedet var aapent. Ingen andre kurs
-- har brukt dem, og koden som leste dem er borte.
ALTER TABLE courses
  DROP COLUMN IF EXISTS fast_fra,
  DROP COLUMN IF EXISTS fast_til;

-- «fra_dropin_tid» pekte fra en oekt tilbake til ukeregelen som laget den.
-- Regelen finnes ikke lenger, saa pekeren peker ingen steder.
ALTER TABLE course_sessions
  DROP COLUMN IF EXISTS fra_dropin_tid;

-- ---------------------------------------------------------------------------
-- Verdiene i enum-ene
-- ---------------------------------------------------------------------------
--
-- Saa lenge 'dropin' staar som et lovlig valg, kan et nytt drop-in-kurs lages
-- ved et uhell — og da ville alt vi nettopp fjernet trengtes igjen. Uten
-- verdien er det ikke mulig.
--
-- Ingen rader har den. Er det likevel én i produksjon, feiler denne
-- setningen, migrasjonen stopper her, og ingenting halvveis er gjort — det er
-- riktig oppfoersel: da maa raden ses paa av et menneske forst.
ALTER TABLE courses
  MODIFY COLUMN type ENUM('kurs','event','workshop') NOT NULL DEFAULT 'kurs';

-- «formal» paa en betaling er et bilag. Verdien fjernes bare fordi ingen
-- betaling noen gang har brukt den; hadde én gjort det, ville denne stoppet.
ALTER TABLE payments
  MODIFY COLUMN formal ENUM('booking','gavekort','ordre','medlemskap') NOT NULL;
