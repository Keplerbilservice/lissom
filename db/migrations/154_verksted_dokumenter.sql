-- Dokumentene i verkstedet: seks kort, og filene som ligger i dem.
--
-- Eieren, 10. september 2026: «inne paa admin paa verksted menyen, saa kunne
-- jeg godt tenke meg noen kort, det ene skal hete kontrakter, her vil jeg
-- kunne lagre kontrakter, aapne og skrive ut, jeg vil ogsaa laste opp» — og
-- deretter fem kort til med de samme funksjonene.
--
-- Kortene er rader og ikke en liste i koden, av én grunn: bryteren «Vis paa
-- medlemssiden» staar paa kortet. Den maa kunne slaas av og paa uten en
-- utrulling, og den maa kunne leses av bade admin, medlemssida og AI-en.
--
-- To navn krasjer med noe som fantes fra for, og det er med vilje:
--   «Brenning» i menyen er brenneloggen for ovnene (tabellen brenninger).
--   «Maler» i menyen er tekstmalene til e-post og SMS.
-- Ingen av dem roeres. Kortene her er et arkiv, ikke de skjermene.
--
-- Filene ligger IKKE i basen. De legges i opplastinger/dokumenter, ved siden
-- av bildene, utenfor det som publiseres — utrullingen speiler repoet og
-- sletter det som ikke finnes lokalt. De serveres gjennom api/dokument.php,
-- som sjekker hvem som spor for den leverer noe.

CREATE TABLE IF NOT EXISTS verksted_kategorier (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    -- Kortnavnet slik det staar i adressen. Endres ikke naar navnet endres.
    slug        VARCHAR(64)     NOT NULL,
    navn        VARCHAR(191)    NOT NULL,
    sortering   INT             NOT NULL DEFAULT 0,
    -- Bryteren paa kortet. Av med vilje: ingenting vises for medlemmer for
    -- eieren har slaatt det paa selv.
    vis_medlem  TINYINT(1)      NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS verksted_dokumenter (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kategori_id   INT UNSIGNED    NOT NULL,
    -- Navnet paa disken. Vi lager det selv; det som ble lastet opp kan hete
    -- hva som helst.
    filnavn       VARCHAR(191)    NOT NULL,
    -- Navnet eieren kjenner igjen. Det er dette som staar i lista.
    originalnavn  VARCHAR(191)    NOT NULL,
    mime          VARCHAR(127)    NOT NULL DEFAULT '',
    storrelse     INT UNSIGNED    NOT NULL DEFAULT 0,
    -- Teksten AI-en kan lese. Tom betyr at «Spor verkstedet» ikke kan svare
    -- fra dette dokumentet — en PDF er en binaerfil, og et skannet ark har
    -- ingen tekst i seg i det hele tatt.
    tekst         MEDIUMTEXT          NULL,
    lastet_opp_av BIGINT UNSIGNED     NULL,
    opprettet     DATETIME        NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    KEY kategori (kategori_id, opprettet),
    CONSTRAINT fk_dok_kategori FOREIGN KEY (kategori_id)
        REFERENCES verksted_kategorier (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- De seks kortene eieren ba om, i den rekkefolgen han skrev dem.
-- «Brenning (dokumenter)» heter det for aa skille det fra brenneloggen som
-- alt staar i menyen.
INSERT IGNORE INTO verksted_kategorier (slug, navn, sortering) VALUES
    ('kontrakter',     'Kontrakter',                             1),
    ('maler',          'Keramikk maler',                         2),
    ('engober',        'Engober, pigmenter og oksider',          3),
    ('glassering',     'Glassering',                             4),
    ('brenning',       'Brenning (dokumenter)',                  5),
    ('dekorasjon',     'Dekorasjonsteknikker og glasurhåndbok',  6);
