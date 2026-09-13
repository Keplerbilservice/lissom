-- Én bryter for hele medlemssalget.
--
-- Eieren, 12. september 2026: «Jeg skal ha 1 bryter. Den skal slaa av og paa
-- funksjonen selg egne arbeider».
--
-- Migrasjon 172 slo sammen de to bryterne som styrte skjemaet paa Min side.
-- Igjen sto «Medlemskolleksjonen i butikken» (Vis/medlemskolleksjon), som
-- styrte om kjoperne saa fanen i nettbutikken. Sto skjemaet paa og
-- kolleksjonen av, kunne medlemmene legge ut varer og Monica godkjenne dem
-- uten at en eneste kunde saa dem — og ingen skjerm sa fra.
--
-- Naa foelger begge deler Vis/medlemssalg. Verdiene slaas sammen forst: sto
-- én av de to paa «nei», blir den nye «nei» ogsaa. Ingenting slaas paa av seg
-- selv. En bryter som mangler i basen staar paa — se bryterPaa() i nettsida.
--
-- Varer som alt er godkjent slettes ikke. Slaas salget av, forsvinner de fra
-- butikken; slaas det paa igjen, staar de der som for.

-- Den indre spoerringa pakkes i en avledet tabell. MySQL leser ellers ikke fra
-- den samme tabellen som den skriver til.
INSERT INTO content_blocks (nokkel, verdi)
SELECT 'Vis/medlemssalg', 'nei'
  FROM (SELECT 1) AS d
 WHERE EXISTS (
   SELECT 1 FROM (
     SELECT verdi FROM content_blocks
      WHERE nokkel IN ('Vis/medlemssalg', 'Vis/medlemskolleksjon')
        AND verdi = 'nei'
   ) AS gammel
 )
ON DUPLICATE KEY UPDATE verdi = 'nei';

DELETE FROM content_blocks WHERE nokkel = 'Vis/medlemskolleksjon';
