-- Handlelista tar imot oensker skrevet i fritekst.
--
-- Eieren, 15. september 2026: «handlelista kan jo vaere tom, medlemmene skal
-- legge inn oensker her, saa den maa vaere synlig». Til naa kunne medlemmet
-- bare velge blant varer verkstedet hadde merket «Kan bestilles» — og
-- kortet var skjult naar ingen var merket. Naa kan medlemmet ogsaa skrive
-- selv («hvit steingods, 10 kg»), og kortet staar der saa lenge bryteren er
-- paa.
--
-- En fritekstlinje har ingen vare: product_id er tom, og teksten staar i
-- «tekst». I admin staar den som «(oenske)» i samlelista, faar pris som de
-- andre, og gaar med i Vipps-kravet. Bestillingen til leverandoeren tar den
-- ikke med — den har ingen leverandoer; verkstedet skaffer den selv.

ALTER TABLE handleliste_linjer
    MODIFY product_id BIGINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS tekst VARCHAR(191) NULL COMMENT 'Oenske i fritekst, naar det ikke er en vare' AFTER product_id;
