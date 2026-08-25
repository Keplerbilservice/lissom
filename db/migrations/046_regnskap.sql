-- Dagsoppgjor til regnskapet.
--
-- Salgene laa i basen, men veien derfra til Tripletex gikk gjennom en
-- CSV-fil noen matte punche inn. Regnskapsforeren har sagt hvordan det skal
-- se ut: ett dagsoppgjor per dag som bilag, ikke én faktura per salg.
--
-- Kontoene og mva-kodene staar her og ikke i koden, fordi det er
-- regnskapsforeren som eier dem. Endrer hun mening, endres et felt i admin —
-- ingen ny utlegging av nettsiden.
--
-- Verdiene under er de hun oppga 25. august 2026:
--
--   Kurs        konto 3200, mva-kode 6 (0 %)
--   Medlemskap  konto 3000, mva-kode 3 (25 %)
--   Butikksalg  konto 3000, mva-kode 3 (25 %)
--               — 3001 hvis butikken skal skilles fra medlemskapet
--
-- To ting sa hun ingenting om, og de staar derfor tomme med vilje. Et bilag
-- med en gjettet konto er verre enn et bilag som sier at kontoen mangler:
--
--   Drop-in     er det kurs (0 %) eller en tjeneste (25 %)?
--   Gavekort    et solgt gavekort er ikke inntekt for det loses inn. Det
--               hoerer normalt hjemme som gjeld, ikke paa en 3000-konto.
--
-- Motkontoene — hvor pengene lander — sa hun heller ingenting om, og de er
-- ikke de samme for Vipps, kontant og faktura. Uten dem gaar ikke bilaget i
-- balanse, saa de staar ogsaa tomme til hun svarer.

INSERT INTO innstillinger (nokkel, verdi) VALUES
    ('regnskap_konto_kurs',        '3200'),
    ('regnskap_mva_kurs',          '6'),
    ('regnskap_konto_medlemskap',  '3000'),
    ('regnskap_mva_medlemskap',    '3'),
    ('regnskap_konto_butikk',      '3000'),
    ('regnskap_mva_butikk',        '3'),
    ('regnskap_konto_dropin',      ''),
    ('regnskap_mva_dropin',        ''),
    ('regnskap_konto_gavekort',    ''),
    ('regnskap_mva_gavekort',      ''),
    ('regnskap_motkonto_vipps',    ''),
    ('regnskap_motkonto_kontant',  ''),
    ('regnskap_motkonto_faktura',  '')
ON DUPLICATE KEY UPDATE nokkel = nokkel;
