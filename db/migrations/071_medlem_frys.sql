-- Frys av medlemskap: soknaden, svaret og perioden.
--
-- «Frys av medlemskap» sto som en bryter under Nettsiden, og slo eieren den
-- paa fikk medlemmene en knapp paa Min side som ikke gjorde noe: det fantes
-- ingen soknad, ingen godkjenning og ingen periode. Bryteren ble derfor tatt
-- bort, med en lapp om aa gjore det manuelt.
--
-- Her er det som manglet. Medlemmet soker med en periode og en grunn,
-- verkstedet svarer ja eller nei, og perioden staar med start og slutt slik
-- at medlemskapet aapner seg igjen av seg selv naar den er over.
--
-- Trekket stoppes ikke herfra. Vipps kan ikke sette en avtale paa pause —
-- den maa stoppes, og medlemmet setter opp en ny naar det kommer tilbake.
-- Det staar i klartekst begge steder, saa ingen tror pengene slutter aa gaa
-- av seg selv.

CREATE TABLE IF NOT EXISTS medlem_frys (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    member_id     BIGINT UNSIGNED NOT NULL,
    fra_dato      DATE NOT NULL,
    til_dato      DATE NOT NULL,
    begrunnelse   VARCHAR(500) NULL,
    -- «sokt» venter paa svar, «godkjent» loper eller har lopt,
    -- «avslatt» ble sagt nei til, «trukket» angret medlemmet selv,
    -- «avsluttet» ble avbrutt for tida var ute.
    status        ENUM('sokt','godkjent','avslatt','trukket','avsluttet')
                  NOT NULL DEFAULT 'sokt',
    svar          VARCHAR(500) NULL,
    -- Statusen medlemmet hadde for frysen, saa det kommer tilbake til den
    -- samme og ikke til «aktiv» uansett hva det var for.
    status_for    VARCHAR(16) NULL,
    behandlet_av  BIGINT UNSIGNED NULL,
    behandlet_at  DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY medlem_frys_medlem (member_id),
    KEY medlem_frys_status (status),
    KEY medlem_frys_til (til_dato)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
