-- Motkontoene og gavekortkontoen, slik Lissom oppga dem 27. august.
--
--   Vipps    1510
--   Kontant  1900
--   Faktura  1920
--   Gavekort 2905  (gjeld — ikke inntekt for det loeses inn)
--
-- Disse styrer dagsoppgjoret: inntekten foeres paa kontoen for det som ble
-- solgt, og innbetalingen paa motkontoen for maaten den kom inn paa. Uten
-- dem staar bilaget uten kontonummer, og regnskapsforeren maa fylle dem inn
-- for haand hver gang.
--
-- Verdiene settes bare der feltet staar tomt. Har noen skrevet inn noe under
-- OEkonomi → Regnskap, er det det som gjelder — kontoplanen er
-- regnskapsforerens, ikke min.
--
-- Merk hva som IKKE settes her: kontoene for kurs, medlemskap, butikk og
-- drop-in, og alle mva-kodene. De er ikke oppgitt, og et kontonummer jeg har
-- funnet paa er verre enn et tomt felt — det tomme feltet sier fra.

INSERT INTO innstillinger (nokkel, verdi) VALUES
    ('regnskap_motkonto_vipps',   '1510'),
    ('regnskap_motkonto_kontant', '1900'),
    ('regnskap_motkonto_faktura', '1920'),
    ('regnskap_konto_gavekort',   '2905')
ON DUPLICATE KEY UPDATE
    verdi = IF(innstillinger.verdi IS NULL OR innstillinger.verdi = '',
               VALUES(verdi), innstillinger.verdi);
