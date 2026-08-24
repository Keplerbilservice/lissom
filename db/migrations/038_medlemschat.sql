-- Medlemschatten, paa ordentlig.
--
-- Ruta fantes fra for: et felt, en sendeknapp og bobler. Men meldingene ble
-- lagret i localStorage — i nettleseren til den som skrev dem. Ingen andre
-- saa dem noen gang. Den gikk altsaa bare én vei, og det kom aldri et varsel,
-- fordi det aldri kom noe inn.
--
-- Én rad per melding. Navnet hentes fra medlemmet ved lesing, ikke lagres
-- her: bytter noen navn, skal det staa riktig ogsaa paa gamle meldinger.
--
-- Slettede meldinger fjernes ikke. Den som angrer skal kunne ta bort sin
-- egen, men en tom rad er lettere aa forstaa i ettertid enn en som er borte
-- — og i et rom flere deler, hoerer det med aa kunne se at noe ble sagt.

CREATE TABLE IF NOT EXISTS chat_meldinger (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id  BIGINT UNSIGNED NOT NULL,
    tekst      VARCHAR(500) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    slettet_at DATETIME NULL COMMENT 'Angret av den som skrev den',
    PRIMARY KEY (id),
    KEY ix_chat_tid (created_at),
    KEY ix_chat_medlem (member_id),
    CONSTRAINT fk_chat_member FOREIGN KEY (member_id)
        REFERENCES members (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
