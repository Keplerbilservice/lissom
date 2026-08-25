-- Svarene fra regnskapsforeren, 25. august 2026.
--
-- Migrasjon 046 satte kontoene hun hadde oppgitt, og lot tre staa tomme
-- fordi hun ikke hadde sagt noe om dem. Naa har hun det.
--
--   Motkonto Vipps      1510
--   Motkonto kontant    1900
--   Motkonto faktura    1920
--
-- Drop-in: hun spurte tilbake om det er undervisning eller om aa bruke
-- verkstedet paa egen haand — uten undervisning er det en tjeneste med 25 %.
-- Verkstedets egen tekst svarer paa det: «To timer i verkstedet der du jobber
-- med det du vil. Du maa ha gaatt kurs hos oss, eller komme sammen med et
-- aktivt medlem.» Ingen undervisning. Altsaa tjeneste, 25 %, og samme konto
-- som medlemskap — det er den samme varen: tilgang til verkstedet, solgt per
-- time i stedet for per maaned.
--
-- Gavekort: et solgt gavekort er ikke inntekt. Det er gjeld til den som
-- eier kortet, og foeres mot 2905 uten mva. Naar det loeses inn, blir det
-- inntekt paa den kontoen det brukes til — kurs eller tjeneste — og gjelden
-- trekkes ned igjen. Derfor staar 2905 paa begge sider av bilaget: kredit
-- naar kortet selges, debet naar det brukes.

INSERT INTO innstillinger (nokkel, verdi) VALUES
    ('regnskap_motkonto_vipps',   '1510'),
    ('regnskap_motkonto_kontant', '1900'),
    ('regnskap_motkonto_faktura', '1920'),
    ('regnskap_konto_dropin',     '3000'),
    ('regnskap_mva_dropin',       '3'),
    ('regnskap_konto_gavekort',   '2905'),
    ('regnskap_mva_gavekort',     '')
ON DUPLICATE KEY UPDATE verdi = VALUES(verdi);
