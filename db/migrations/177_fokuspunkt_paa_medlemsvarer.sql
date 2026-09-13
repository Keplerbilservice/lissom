-- Fokuspunktet medlemmet velger paa sitt eget produktbilde.
--
-- Eieren, 13. september 2026, med bilde fra en telefon: «Et aarsmedlem
-- forsoeker aa legge ut et produkt for salg. Jeg forsoekte aa laste opp et
-- bilde men det ser saann ut. Bilde info er korrekt.»
--
-- Ruta viste filnavnet og ikke bildet. Rett under sto «Fokuspunkt — velg
-- hvilken del av bildet som skal ligge i midten», saa hun skulle peke ut et
-- utsnitt av et bilde hun ikke saa.
--
-- Og valget gikk ingen steder: settFokus() lagret det lokalt og sendte det
-- til api/admin/bilder.php, som krever admin. api/medlemssalg.php tok ikke
-- imot noe fokuspunkt i det hele tatt. Naa foelger det med produktet.
--
-- «50% 50%» er midten, det samme fokusFor() gir naar ingenting er valgt.
-- Gamle rader far den, og staar noeyaktig som for.

ALTER TABLE member_sales
  ADD COLUMN fokus VARCHAR(16) NOT NULL DEFAULT '50% 50%' AFTER bilde;
