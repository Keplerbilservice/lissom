-- Kontonumrene, slik regnskapsfoereren satte dem opp.
--
-- Eieren spurte henne 1. september, og fikk svar paa alle punktene:
--
--   1. CSV-en: «Det skal ikke vaere debet- og kreditkolonner men man bruker
--      fortegn i beloep (positivt beloep = debet, negativt beloep = kredit).
--      For oevrig ser det bra ut.»  — rettet i api/admin/dagsoppgjor.php
--
--   2. «Butikk boer skilles fra medlemskap slik at man kan beregne
--      bruttofortjeneste. Jeg har opprettet kontonummer i Tripletex.»
--
--   3. Kurs 3200 (mva 6), Medlemskap 3000 (mva 3), Varer i butikk 3020
--      (mva 3).
--
--   4. Gavekort foeres som gjeld paa 2905 uten mva, og blir inntekt foerst
--      naar kortet loeses inn: «Ja».
--
--   5. Motkonto Vipps 1510, kontant 1900, faktura 1920: «Korrekt».
--
-- Butikken sto paa 3000, samme konto som medlemskapet. Naa staar den for seg.
--
-- Drop-in er ikke med. Tilbudet ble tatt ned med migrasjon 110 og 111, og
-- eieren 1. september: «vi har ikke drop-inn ... aldri ha det med». Sto det
-- en drop-in-konto fra for, ryddes den bort her.
--
-- Verdiene staar fortsatt i innstillinger og kan endres under OEkonomi →
-- Regnskap; denne filen kjoerer én gang.

INSERT INTO innstillinger (nokkel, verdi) VALUES
  ('regnskap_konto_kurs',        '3200'),
  ('regnskap_mva_kurs',          '6'),
  ('regnskap_konto_medlemskap',  '3000'),
  ('regnskap_mva_medlemskap',    '3'),
  ('regnskap_konto_butikk',      '3020'),
  ('regnskap_mva_butikk',        '3'),
  ('regnskap_konto_gavekort',    '2905'),
  ('regnskap_mva_gavekort',      ''),
  ('regnskap_motkonto_vipps',    '1510'),
  ('regnskap_motkonto_kontant',  '1900'),
  ('regnskap_motkonto_faktura',  '1920')
ON DUPLICATE KEY UPDATE verdi = VALUES(verdi);

DELETE FROM innstillinger
 WHERE nokkel IN ('regnskap_konto_dropin', 'regnskap_mva_dropin');
