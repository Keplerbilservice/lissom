-- Kort i kortet: hver keramikkmal som sitt eget kort under «Keramikk maler».
--
-- Eieren, 11. september 2026: «under kortet keramikk maler saa maa en og en
-- mal vaere et eget kort». Det er 71 maler, hver med resultatbilde, Mal.pdf
-- og monteringsguide. Som flat liste i ett kort ville det blitt over 200
-- filer der 65 av dem heter «Mal».
--
-- Et underkort er en vanlig rad i verksted_kategorier med forelder_id satt.
-- Alt som alt finnes — dokumentene, visningen, utskriften, AI-teksten —
-- virker uendret paa det, fordi et dokument fortsatt hoerer til ett kort.
--
-- Bryteren «Vis paa medlemssiden» staar paa forelderen og gjelder alle
-- underkortene. Et underkort har ingen egen bryter: eieren skal slaa paa
-- malene én gang, ikke 71 ganger.

ALTER TABLE verksted_kategorier
    ADD COLUMN forelder_id INT UNSIGNED NULL
        COMMENT 'Kortet dette ligger inni. NULL = et av hovedkortene.'
        AFTER id,
    ADD COLUMN under VARCHAR(191) NOT NULL DEFAULT ''
        COMMENT 'Linja under navnet paa kortet, f.eks. «Mal 01».'
        AFTER navn,
    ADD COLUMN bilde VARCHAR(191) NULL
        COMMENT 'Bildet paa kortet. Filnavn i opplastinger/dokumenter, laget av oss.'
        AFTER under,
    ADD KEY forelder (forelder_id, sortering),
    ADD CONSTRAINT fk_kat_forelder FOREIGN KEY (forelder_id)
        REFERENCES verksted_kategorier (id) ON DELETE CASCADE;

-- Hvor et importert dokument kom fra (stien i db/dokumenter/manifest.json).
-- Unik, saa «Kjør oppdateringer» kan trykkes saa mange ganger man vil uten at
-- det samme dokumentet kommer inn to ganger. NULL for alt som lastes opp
-- for haand.
ALTER TABLE verksted_dokumenter
    ADD COLUMN kilde VARCHAR(191) NULL
        COMMENT 'Stien i importpakka dokumentet kom fra. NULL = lastet opp for haand.'
        AFTER lastet_opp_av,
    ADD UNIQUE KEY kilde (kilde);
