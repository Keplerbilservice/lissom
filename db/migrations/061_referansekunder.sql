-- Kundene som har bestilt keramikk av oss, og som vi har lov til aa vise.
--
-- Bestilt: en pen seksjon paa forsida med ett kundekort om gangen, samme
-- automatiske skifte som events og medlemskap alt har.
--
-- Hvorfor en egen tabell: dette er ikke innhold i én tekst, det er en liste
-- med rekkefolge og av/paa per rad. content_blocks er nokkel til tekst og
-- kan ikke holde det uten aa bli en JSON-klump ingen kan flytte én rad i.
--
-- «aktiv» og ikke sletting: en kunde kan trekke samtykket sitt, og da skal
-- kortet av nettsida med det samme — uten at teksten og bildet er borte om
-- de sier ja igjen senere.
--
-- Merk: det er verkstedets ansvar aa ha lov til aa vise navn, logo, bilde og
-- sitat. Det kan ikke koden avgjore, og derfor staar samtykket som et eget
-- felt man maa krysse av for at kortet skal kunne staa ute.

CREATE TABLE IF NOT EXISTS referansekunder (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    navn       VARCHAR(191) NOT NULL,
    -- Filnavnet fra Bilder::taImot(). Logo, bilde av kunden, eller bilde av
    -- keramikken de bestilte.
    bilde      VARCHAR(64) NULL,
    tekst      TEXT NULL,
    sitat      VARCHAR(500) NULL,
    -- Hvem sitatet er fra, naar det er et sitat.
    sitat_av   VARCHAR(191) NULL,
    lenke      VARCHAR(255) NULL,
    sortering  SMALLINT NOT NULL DEFAULT 0,
    aktiv      TINYINT(1) NOT NULL DEFAULT 0,
    -- Har kunden sagt ja til aa staa her? Uten dette kan kortet ikke
    -- publiseres, uansett hva «aktiv» staar paa.
    samtykke   TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_ref_synlig (aktiv, samtykke, sortering)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
