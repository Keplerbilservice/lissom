-- Medlemmenes salg.
--
-- Tabellen fantes fra forste dag, men ingenting skrev til den. Skjemaet paa
-- Min side la varen i skjermbildet, der den forsvant ved neste sidelasting,
-- og admin hadde ingenting aa godkjenne. Butikken viste i stedet fem
-- oppdiktede selgere med telefonnumre og e-postadresser.
--
-- Feltene her er de skjemaet alt spor om. Betalingen gaar direkte mellom
-- kjoper og selger — Lissom formidler, og rorer aldri pengene.

ALTER TABLE member_sales
  ADD COLUMN IF NOT EXISTS produsent VARCHAR(96) NULL
    COMMENT 'Navnet som vises: «Laget av Ingrid». Ikke noedvendigvis fullt navn'
    AFTER tittel;

ALTER TABLE member_sales
  ADD COLUMN IF NOT EXISTS kategori VARCHAR(32) NULL
    COMMENT 'Kopper, Boller, Fat, Annet'
    AFTER pris_ore;

ALTER TABLE member_sales
  ADD COLUMN IF NOT EXISTS antall SMALLINT UNSIGNED NOT NULL DEFAULT 1
    AFTER kategori;

-- Kontaktopplysningen selgeren selv oppgir. Vises sammen med varen, saa
-- kjoperen kan avtale overlevering.
ALTER TABLE member_sales
  ADD COLUMN IF NOT EXISTS kontakt VARCHAR(191) NULL
    AFTER vippsnummer;

-- «skjult» mangler i den opprinnelige lista: en vare som har vaert ute og
-- skal tas ned er verken avvist eller solgt.
ALTER TABLE member_sales
  MODIFY COLUMN status ENUM('til_godkjenning','publisert','avvist','solgt','skjult')
    NOT NULL DEFAULT 'til_godkjenning';

ALTER TABLE member_sales
  ADD COLUMN IF NOT EXISTS updated_at DATETIME NOT NULL
    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
