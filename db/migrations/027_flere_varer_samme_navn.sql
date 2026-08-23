-- Varenavnet var unikt i basen. Det var feil.
--
-- Et verksted lager ti kopper som alle heter «Kopp». De er ikke den samme
-- varen — de har hver sin glasur, hvert sitt antall og hvert sitt bilde — men
-- basen tillot bare én rad med det navnet. Skrev eieren inn den andre, ble
-- den forste overskrevet, uten at noe sa fra. Da saa det ut som om varen
-- forsvant.
--
-- Nokkelen byttes med en vanlig indeks: oppslag paa navn skal fortsatt vaere
-- raskt, men to varer kan hete det samme. Radnummeret er identiteten, og har
-- alltid vaert det — ordrelinjer, handlekurv og bildevalg peker paa id.

ALTER TABLE products DROP INDEX uq_products_tittel;
ALTER TABLE products ADD INDEX ix_products_tittel (tittel);
