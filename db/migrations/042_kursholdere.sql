-- Kursholdere, og timene de foerer.
--
-- Skjermen «Kursholdere» viste tre faste navn — to av dem oppdiktede — med
-- timetall ingen hadde foert. Paa den ekte sida sto lista tom, og knappene
-- «Ny kursholder» og «Registrer arbeidstimer» aapnet dialoger som lukket seg
-- igjen. Det fantes ingen tabell bak.
--
-- Instruktoeren paa et kurs staar fortsatt som tekst i courses.instruktor.
-- Den roeres ikke: kursbevisene er skrevet ut med det navnet, og en peker til
-- en tabell ville gjort gamle bevis avhengige av en rad som kan slettes.
-- Kursholderne her er hvem verkstedet har, og hva de har jobbet.

CREATE TABLE IF NOT EXISTS kursholdere (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    navn          VARCHAR(191) NOT NULL,
    rolle         VARCHAR(96)  NULL COMMENT 'Eier, keramiker, vikar …',
    epost         VARCHAR(191) NULL,
    telefon       VARCHAR(32)  NULL,
    kurs          VARCHAR(300) NULL COMMENT 'Hvilke kurs hen holder, som tekst',
    timesats_ore  INT UNSIGNED NULL,
    vises_paa_nett TINYINT(1) NOT NULL DEFAULT 0,
    aktiv         TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_kursholder_aktiv (aktiv, navn)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Timene. En rad per foering, ikke et tall som overskrives: da kan man se
-- hva som ble foert naar, og rette én dag uten aa regne om resten.
CREATE TABLE IF NOT EXISTS kursholder_timer (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    kursholder_id  BIGINT UNSIGNED NOT NULL,
    dato           DATE NOT NULL,
    timer          DECIMAL(5,2) NOT NULL,
    hva            VARCHAR(96) NULL COMMENT 'Kurs, forberedelse, brenning …',
    notat          VARCHAR(300) NULL,
    lagt_inn_av    BIGINT UNSIGNED NULL,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_timer_holder (kursholder_id, dato),
    CONSTRAINT fk_timer_kursholder FOREIGN KEY (kursholder_id)
        REFERENCES kursholdere (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
