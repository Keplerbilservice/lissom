-- Kursbeviset skal kunne rettes og trekkes tilbake.
--
-- Beviset bygges av paameldingen: navn, kurs, dato, instruktoer. Alt kommer
-- fra basen, ingenting skrives inn — og det er riktig, helt til noe er feil.
-- Er navnet stavet feil ved paamelding, eller sto det «Kurs boller» der det
-- egentlig var et dreiekurs, hadde verkstedet ingen vei til aa rette det.
-- Og gikk noen fra kurset for tidlig, kunne beviset ikke trekkes.
--
-- Derfor tre felter paa paameldingen, alle tomme til noen tar dem i bruk:
-- bevis_navn og bevis_kurs overstyrer det som staar paa arket, og
-- bevis_sperret gjor at beviset ikke utstedes.
--
-- Ingen egen bevistabell. Beviset er ikke et dokument vi lagrer — det tegnes
-- naar noen ber om det, og da holder det aa vite hva som skal staa paa det.

ALTER TABLE bookings
    ADD COLUMN bevis_navn    VARCHAR(191) NULL COMMENT 'Overstyrer navnet paa kursbeviset' AFTER notat,
    ADD COLUMN bevis_kurs    VARCHAR(191) NULL COMMENT 'Overstyrer kursnavnet paa kursbeviset' AFTER bevis_navn,
    ADD COLUMN bevis_sperret TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Beviset utstedes ikke' AFTER bevis_kurs;
