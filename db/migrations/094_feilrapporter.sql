-- Feil som meldes inn, og feil som melder seg selv.
--
-- Bakgrunnen: nedtrekket for betalingsmaate sto tomt paa iPhone i flere
-- dager. Ingen visste det. Serverfeil gaar til feilloggen i cPanel, som
-- ingen leser; feil i nettleseren gikk ingen steder i det hele tatt.
--
-- To slag havner her, og de fanger hver sin type feil:
--
--   «automatisk»  et unntak i nettleseren, eller et API-kall som svarte
--                 500. Kommer av seg selv, uten at noen gjor noe.
--   «melding»     det et menneske skriver. Det er det eneste som fanger
--                 en feil som ikke kaster noe — som nettopp det tomme
--                 nedtrekket, der siden fungerte helt fint teknisk sett
--                 og bare manglet innholdet.
--
-- Fingeravtrykket er sha1 av det som gjor feilen til *denne* feilen:
-- meldingen, kilden, siden og nettleserfamilien. Samme feil hos tjue
-- besokende blir én rad med antall=20, ikke tjue rader. Meldinger fra
-- mennesker faar et tilfeldig avtrykk, for to like formuleringer er
-- fortsatt to henvendelser.

CREATE TABLE IF NOT EXISTS feilrapporter (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slag          VARCHAR(16)  NOT NULL DEFAULT 'automatisk',
    melding       TEXT         NULL,
    kontakt       VARCHAR(191) NULL,
    feiltekst     VARCHAR(500) NULL,
    kilde         VARCHAR(300) NULL,
    side          VARCHAR(300) NULL,
    nettleser     VARCHAR(300) NULL,
    skjerm        VARCHAR(32)  NULL,
    member_id     INT UNSIGNED NULL,
    rolle         VARCHAR(32)  NULL,
    fingeravtrykk CHAR(40)     NOT NULL,
    antall        INT UNSIGNED NOT NULL DEFAULT 1,
    status        VARCHAR(16)  NOT NULL DEFAULT 'ny',
    sist_sett     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_feil_avtrykk (fingeravtrykk),
    KEY ix_feil_status (status, sist_sett)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Knappen er tidsbegrenset med vilje: eieren slaar den paa i en uke etter en
-- endring, og den slaar seg av selv. En dato, ikke en av/paa-bryter, saa det
-- ikke blir enda en ting som maa huskes.
--
-- Tom verdi = av. Den automatiske fangsten staar alltid paa, uavhengig av
-- denne — den koster ingen ting for den besokende og er det eneste som ser
-- feil som skjer naar ingen sitter og folger med.
INSERT INTO innstillinger (nokkel, verdi)
     VALUES ('feilmelding_til', '')
ON DUPLICATE KEY UPDATE nokkel = nokkel;
