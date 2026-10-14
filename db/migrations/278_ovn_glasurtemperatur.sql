-- Hurtigstatusen for glasurbrann viser hvilken av de to temperaturene som er satt.
ALTER TABLE ovn_tomt
    ADD COLUMN IF NOT EXISTS temperatur SMALLINT UNSIGNED NULL
    COMMENT '1220 eller 1250 for glasurbrann' AFTER slag;
