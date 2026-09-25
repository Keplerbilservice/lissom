-- Kursbeviset sendes med e-posten «Be om en anmeldelse».
--
-- Eieren, 25. september 2026: «dette må vi sende ut sammen med google
-- anmeldelsen tenker jeg, kan du endre denne mailen til å inneholde begge
-- deler?» — GO på teksten samme dag. Før lå beviset bare på Min side, og den
-- som booket uten konto kunne ikke åpne det.
--
-- - bookings.bevis_kode: en personlig kode i lenken, så beviset kan åpnes
--   uten innlogging (api/kursbevis.php?booking=…&k=…). Lages første gang
--   lenken trengs (Booking::bevisLenke).
-- - Malen får {kursbevis}: «Her er kursbeviset ditt fra <kurs>:» og lenken,
--   eller tomt når beviset er trukket tilbake.

ALTER TABLE bookings
    ADD COLUMN IF NOT EXISTS bevis_kode CHAR(32) NULL
        COMMENT 'Personlig kode i lenken til kursbeviset (uten innlogging)';

UPDATE notification_templates
   SET tekst = REPLACE(tekst,
         'opplevelse.\n\nVi setter stor pris',
         'opplevelse.\n\n{kursbevis}\n\nVi setter stor pris')
 WHERE navn = 'anmeldelse'
   AND tekst NOT LIKE '%{kursbevis}%';
