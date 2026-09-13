-- Beskjedene fra verkstedet skal staa paa Min side, ikke bare i innboksen.
--
-- Eieren, 13. september 2026: «paa min side, saa ser det ut til at beskjeder
-- til medlemmene ikke vises».
--
-- Han hadde rett, og koden sa det selv. I renderVals sto det: «Beskjeder fra
-- verkstedet finnes ikke som endepunkt ennaa, saa lista er tom paa den ekte
-- siden.» Kortet «Beskjeder — Fra verkstedet» har staatt paa forsiden hele
-- tiden, men det har aldri vaert noe aa hente inn i det.
--
-- Grunnen: api/admin/beskjed.php legger meldingen i varselkoen og sender
-- e-post og SMS. Ingenting ble lagret. Er e-posten lest og slettet, finnes
-- beskjeden ingen steder.
--
-- Denne tabellen er oppslagstavla. Én rad per beskjed til medlemmene — ikke
-- én per mottaker: alle aktive medlemmer ser den samme tavla, slik de ville
-- sett den samme lappen paa doera. «type» er tom naar beskjeden gikk til
-- alle, og staar med medlemskapstypen naar admin valgte én.
--
-- Beskjed til deltakerne paa en kursdato lagres ikke: de er ikke medlemmer,
-- de har ingen Min side, og tavla er medlemmenes.

CREATE TABLE IF NOT EXISTS medlemsbeskjeder (
    id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tittel    VARCHAR(191)    NOT NULL COMMENT 'Emnet admin skrev.',
    tekst     TEXT            NOT NULL,
    type      VARCHAR(64)     NOT NULL DEFAULT '' COMMENT 'Tom = alle medlemmer. Ellers medlemskapstypen.',
    av        VARCHAR(191)    NOT NULL DEFAULT '' COMMENT 'Hvem i verkstedet som sendte den.',
    opprettet DATETIME        NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    KEY opprettet (opprettet)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
