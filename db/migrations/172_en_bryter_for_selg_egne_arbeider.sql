-- «Selg egne arbeider» blir én bryter, ikke to.
--
-- Eieren, 12. september 2026: «Har vi ikke alt for mange brytere for samme
-- tema?». Det var to: «Selg egne arbeider» paa Oversikt (Vis/medlemssalg) og
-- «"Selg keramikk" paa Min side» paa Butikken (Vis/salgsskjema). Koden krevde
-- at BEGGE sto paa. Slo du paa den ene, skjedde ingenting, og skjermen sa
-- ikke hvilken av dem som manglet.
--
-- Naa leser begge skjermene den samme noekkelen, Vis/medlemssalg. Verdiene
-- slaas sammen forst: sto én av de to paa «nei», blir den nye «nei» ogsaa.
-- Ingenting slaas paa av seg selv.
--
-- En bryter som mangler i basen staar paa — se bryterPaa() i nettsida. Derfor
-- trengs ingen rad naar begge sto paa.
--
-- «Medlemskolleksjonen i butikken» og «Salgsuke-kampanjen» roeres ikke — de
-- gjelder butikken for kjoperne, ikke skjemaet paa Min side.

-- Den indre spoerringa pakkes i en avledet tabell. MySQL leser ellers ikke fra
-- den samme tabellen som den skriver til.
INSERT INTO content_blocks (nokkel, verdi)
SELECT 'Vis/medlemssalg', 'nei'
  FROM (SELECT 1) AS d
 WHERE EXISTS (
   SELECT 1 FROM (
     SELECT verdi FROM content_blocks
      WHERE nokkel IN ('Vis/medlemssalg', 'Vis/salgsskjema')
        AND verdi = 'nei'
   ) AS gammel
 )
ON DUPLICATE KEY UPDATE verdi = 'nei';

DELETE FROM content_blocks WHERE nokkel = 'Vis/salgsskjema';
