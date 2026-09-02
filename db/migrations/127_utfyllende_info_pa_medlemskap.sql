-- Utfyllende informasjon paa medlemskapene.
--
-- Eieren, 2. september: «jeg vil ha utvidet info paa medlemskapene» og «naar
-- du har lagt ut dette vil jeg at jeg maa ha mulighet aa legge ut utfyllende
-- info naar jeg skal legge ut et nytt medlemskap».
--
-- «beskrivelse» er én setning paa kortet og rommer 400 tegn. Teksten eieren
-- vil ha inn er seks avsnitt lang og hoerer hjemme paa sida man kommer til
-- naar man klikker seg inn paa medlemskapet — ikke paa kortet.
--
--   langtekst  avsnittene paa medlemskapssida. Tomme linjer skiller dem, og
--              nettsida setter hvert avsnitt i sitt eget avsnitt.
--   viktig     «Viktig aa vite» — én linje per punkt, som «punkter».
--
-- Begge staar tomme paa planer som ikke har faatt tekst. Da ser sida ut
-- noeyaktig som for.
ALTER TABLE membership_plans
  ADD COLUMN langtekst TEXT NULL AFTER beskrivelse,
  ADD COLUMN viktig    TEXT NULL AFTER punkter;
