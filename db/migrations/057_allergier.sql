-- Det deltakeren selv sier om allergier og annet arrangoren maa vite.
--
-- Ingenting av dette kunne registreres i dag. Paameldingsskjemaet spor ikke,
-- og det finnes ingen kolonne aa legge svaret i.
--
-- Hvorfor en egen kolonne, og ikke en av dem som finnes:
--
--   notat          er systemets eget. Der staar «Fra ventelista», og skriver
--                  vi over det, ser en manuell paamelding ut som en
--                  nettbestilling i ettertid.
--   internt_notat  er verkstedets. «Gronn skaal, hylle 3.» Blander vi inn
--                  allergier der, kan de bli slettet av noen som rydder i
--                  sitt eget notat og ikke vet hva de tar bort.
--
-- Tre ulike kilder — systemet, verkstedet og deltakeren — og de skal ikke
-- kunne overskrive hverandre.
--
-- Dette er helseopplysninger. De vises bare i admin, aldri for andre
-- deltakere, og de brukes ikke til noe annet enn aa gjore kurset trygt.

ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS allergier TEXT NULL COMMENT 'Deltakerens egne opplysninger. Sensitivt. Vises kun i admin.';
