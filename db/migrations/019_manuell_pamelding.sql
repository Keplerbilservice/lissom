-- Paameldte som ikke kom via nettsiden.
--
-- Ikke alle bestiller paa nett. Noen ringer, noen staar i doera, noen
-- betaler kontant eller mot faktura. De maa staa paa deltakerlista som alle
-- andre — ellers ma verkstedet holde to lister, og den ene stemmer aldri.
--
-- En manuell paamelding er en helt vanlig booking uten betaling knyttet til
-- seg. To felter skiller den fra en nettbestilling: hvem som la den inn, og
-- hvordan den ble gjort opp.

ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS betalt_maate VARCHAR(32) NULL
    COMMENT 'Kontant, Faktura, Vipps i verkstedet, Gratis — kun manuelle paameldinger'
    AFTER payment_id;

ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS lagt_inn_av BIGINT UNSIGNED NULL
    COMMENT 'Admin som la paameldingen inn for haand. NULL = kom via nettsiden'
    AFTER betalt_maate;

ALTER TABLE bookings
  ADD COLUMN IF NOT EXISTS notat VARCHAR(255) NULL
    COMMENT 'Kort notat fra verkstedet, f.eks. «betaler ved oppmote»'
    AFTER lagt_inn_av;
