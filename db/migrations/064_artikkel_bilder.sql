-- Flere bilder i én artikkel.
--
-- Bestilt 26. august, punkt 9. En artikkel har ett bilde: articles.bilde.
-- Det er bildet lista viser og det som foelger med naar noen deler lenken.
-- Bestillingen ber om bilder inne i teksten, med bildetekst, alt-tekst,
-- plassering og stoerrelse.
--
-- articles.bilde beholdes. Den brukes av lista og av delingsbildet, og skal
-- ikke rives ut for aa lage plass til noe nytt.
--
--   rekkefolge   hvilket avsnitt bildet staar etter. 0 = foerst i artikkelen,
--                1 = etter foerste avsnitt, og saa videre. To bilder med
--                samme tall staar etter hverandre, i id-rekkefolge.
--   alt_tekst    hva bildet viser, for den som ikke ser det. Tom er lov —
--                da er bildet pynt, og skjermleseren hopper over det. Det er
--                riktig for et bilde som ikke sier noe teksten ikke sier.
--   plassering   full bredde, flytende til venstre eller hoyre, eller
--                midtstilt i spalta.
--   storrelse    liten, medium eller stor. Gjelder ikke naar plasseringen er
--                «full» — da er bildet saa bredt som spalta.

CREATE TABLE IF NOT EXISTS artikkel_bilder (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    artikkel_id  BIGINT UNSIGNED NOT NULL,
    fil          VARCHAR(255) NOT NULL COMMENT 'Adressen til bildefila',
    rekkefolge   SMALLINT UNSIGNED NOT NULL DEFAULT 0
                 COMMENT 'Hvilket avsnitt bildet staar etter',
    bildetekst   VARCHAR(255) NULL,
    alt_tekst    VARCHAR(255) NULL,
    plassering   ENUM('full','venstre','hoyre','midtstilt') NOT NULL DEFAULT 'full',
    storrelse    ENUM('liten','medium','stor') NOT NULL DEFAULT 'medium',
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_artikkel (artikkel_id, rekkefolge, id),
    -- Slettes artikkelen, foelger bildene med. Radene her har ingen mening
    -- uten artikkelen de staar i.
    CONSTRAINT fk_artbilde_artikkel FOREIGN KEY (artikkel_id)
        REFERENCES articles (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
