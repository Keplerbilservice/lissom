-- Handlelista paa Min side, og samlebestillingen i admin.
--
-- Eieren, 13. september 2026: «kan vi faa til en handleliste som vises paa min
-- side medlemmer ... medlemmene maa kunne samle opp og trykk send ...
-- handlelisten maa inneholde medlemsnavn, artikkelnummer, navn paa produkt og
-- antall. Listen som oversendes admin maa slaas sammen til en liste, her maa
-- admin kunne trykke vekk produkter, ikke paa lager. Maa kunne legge inn pris
-- pr varelinje, kunne kreve inn betaling ved vipps ... prisene maa legge paa
-- adm gebyr 5%.» Maalet hans: «aa samle innkjop paa min lissom konto for aa
-- faa rabatter».
--
-- Internbutikken blir staaende som den er. Den selger det som ligger paa
-- lager, med Vipps i kassa med en gang. Handlelista er den andre veien: det
-- som maa skaffes. Eieren valgte «begge deler» 13. september.
--
-- Fire ting legges inn:
--
--   leverandorer          hvem vi bestiller fra, og hvordan
--   products.artikkelnr   leverandorens nummer, det som staar i bestillingen
--   handleliste_linjer    én rad per vare et medlem vil ha
--   gebyrsatsen           som innstilling, ikke i koden
--
-- Linja er hele historien om én vare, fra medlemmet skriver den opp til den
-- er betalt og bestilt. Derfor ligger pris, ordre og bestillingstidspunkt paa
-- samme rad — ingen egen bestillingstabell aa holde i takt.
--
--   apen     medlemmet samler fortsatt paa den
--   sendt    sendt til verkstedet, ligger i lista i admin
--   fjernet  admin tok den bort — ikke paa lager
--   ferdig   bestilt hos leverandoren
--
-- Prisen staar i ore paa linja, satt av admin naar bestillingen gjores. Den
-- kopieres ikke fra products.pris_ore: internbutikkens pris er prisen over
-- disk, og et samlekjop koster noe annet.

CREATE TABLE IF NOT EXISTS leverandorer (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    navn             VARCHAR(191)    NOT NULL,
    epost            VARCHAR(191)    NOT NULL DEFAULT '' COMMENT 'Dit bestillingen sendes. Eieren fyller den inn selv.',
    bestillingsmaate ENUM('epost','api') NOT NULL DEFAULT 'epost',
    aktiv            TINYINT(1)      NOT NULL DEFAULT 1,
    opprettet        DATETIME        NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    UNIQUE KEY uq_leverandor_navn (navn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- De to eieren handler hos. Ingen av dem har et API i dag, saa begge staar
-- paa e-post. Adressene staar tomme med vilje: de gjettes ikke.
INSERT IGNORE INTO leverandorer (navn) VALUES ('Cerama'), ('Waldemar Ellefsen');

ALTER TABLE products ADD COLUMN IF NOT EXISTS artikkelnr VARCHAR(64) NOT NULL DEFAULT ''
    COMMENT 'Leverandorens artikkelnummer. Staar paa linja og i bestillingen.';
ALTER TABLE products ADD COLUMN IF NOT EXISTS leverandor_id BIGINT UNSIGNED NULL;
ALTER TABLE products ADD COLUMN IF NOT EXISTS kan_bestilles TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Vises i handlelista paa Min side.';
ALTER TABLE products ADD KEY IF NOT EXISTS ix_products_bestilles (kan_bestilles, leverandor_id);

CREATE TABLE IF NOT EXISTS handleliste_linjer (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id   BIGINT UNSIGNED NOT NULL,
    product_id  BIGINT UNSIGNED NOT NULL,
    antall      SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    status      ENUM('apen','sendt','fjernet','ferdig') NOT NULL DEFAULT 'apen',
    pris_ore    INT UNSIGNED   NULL COMMENT 'Stykkpris admin skrev inn. NULL til den er satt.',
    order_id    BIGINT UNSIGNED NULL COMMENT 'Ordren Vipps-kravet ble sendt paa.',
    sendt_at    DATETIME       NULL,
    bestilt_at  DATETIME       NULL,
    opprettet   DATETIME       NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    KEY ix_handleliste_medlem (member_id, status),
    KEY ix_handleliste_status (status),
    KEY ix_handleliste_vare (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Gebyret ligger her, ikke i koden, saa eieren kan endre satsen selv.
INSERT IGNORE INTO innstillinger (nokkel, verdi) VALUES ('handleliste_gebyr_prosent', '5');

-- Kortet staar av til varene har faatt artikkelnummer og leverandor. Uten
-- det ville medlemmene sett en tom liste dagen dette ble lagt ut.
INSERT IGNORE INTO content_blocks (nokkel, verdi) VALUES ('Vis/handleliste', 'nei');

-- Teksten til leverandoren er en mal, som alle andre utsendelser. Eieren,
-- 1. september 2026: «hvorfor kan ikke alle vaere redigerbare? og ligge i et
-- eget kort paa oversikt som heter maler». Da kan ordlyden endres under Maler
-- uten at noen roerer koden. «{varer}» er varelinjene, delt opp per medlem.
INSERT IGNORE INTO notification_templates (navn, kanal, emne, tekst, gruppe) VALUES (
    'leverandorbestilling',
    'epost',
    'Bestilling {nummer} — Lissom Keramikk & Håndverk',
    'Hei! Vi vil gjerne bestille varene under. Bestillingen er delt opp per person — vi ber om at hver bestilling pakkes for seg og merkes med navnet.

Leveres til: Nordre Løkkevei 15, 3120 Nøtterøy

{varer}

Gi beskjed om noe ikke er på lager, så tar vi det ut av bestillingen.',
    'ordre'
);
