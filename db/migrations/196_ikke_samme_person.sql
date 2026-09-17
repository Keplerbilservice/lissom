-- «Dette er to personer», sagt av et menneske.
--
-- Eieren, 17. september 2026, med bilde av dubletter-skjermen: «Ellen har
-- betalt med vipps, det er ikke samme som Monica, men hun brukte hennes
-- data».
--
-- Dubletter-skjermen regner samme e-post som samme menneske — «to personer
-- deler ikke innboks». Her gjorde de det likevel: Ellen betalte med Vipps og
-- oppga en e-post som alt sto paa en annen rad. Da sto de to som «SAMME
-- PERSON», med en knapp som ville slaatt to mennesker sammen til én, og
-- ingen maate aa si fra paa. Paret ble staaende for alltid.
--
-- Her staar parene noen har sett paa og sagt fra om. De vises ikke igjen, og
-- kan ikke slaas sammen ved et uhell.
--
-- Lav og hoy, ikke «a» og «b»: paret er det samme uansett hvilken vei det
-- ble lest, og den unike noekkelen skal fange begge.
--
-- «ON DELETE CASCADE»: forsvinner medlemmet, er det ingenting aa holde fra
-- hverandre lenger.

CREATE TABLE IF NOT EXISTS dublett_ikke_samme (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    medlem_lav  BIGINT UNSIGNED NOT NULL,
    medlem_hoy  BIGINT UNSIGNED NOT NULL,
    av          BIGINT UNSIGNED NULL COMMENT 'Hvem som sa fra',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_ikke_samme (medlem_lav, medlem_hoy),
    KEY ix_ikke_samme_hoy (medlem_hoy),
    CONSTRAINT fk_ikke_samme_lav FOREIGN KEY (medlem_lav)
        REFERENCES members (id) ON DELETE CASCADE,
    CONSTRAINT fk_ikke_samme_hoy FOREIGN KEY (medlem_hoy)
        REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
