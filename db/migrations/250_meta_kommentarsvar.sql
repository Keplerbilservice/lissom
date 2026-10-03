-- AI-kommentarsvar (eieren godkjente skissen 3. oktober 2026). Én rad per
-- kommentar paa Instagram/Facebook som jobben har sett paa: hva AI-en mente
-- (klasse), hva som skjedde (status), og forslaget som venter paa Monica.
--
-- Raden lages FOER noe kall (status «behandles»), saa to kjoringer aldri
-- svarer paa den samme kommentaren. Kommentarsvar (app/lib/kommentarsvar.php)
-- gjoer ingenting foer tabellen finnes.
--
-- Bare tabellen, ingen data. Trygg aa kjoere to ganger.

CREATE TABLE IF NOT EXISTS meta_kommentarer (
    kommentar_id  VARCHAR(191) NOT NULL,
    kanal         VARCHAR(16) NOT NULL COMMENT 'Instagram eller Facebook',
    klasse        VARCHAR(16) NULL COMMENT 'liker, svar eller venter (AI-ens valg)',
    status        VARCHAR(16) NOT NULL DEFAULT 'behandles'
                  COMMENT 'behandles, liket, svart, venter, ikke_svar, svart_manuelt, feil, ingen_handling',
    kommentar     TEXT NULL,
    forslag       TEXT NULL,
    svar          TEXT NULL,
    forsok        INT NOT NULL DEFAULT 0,
    feil          VARCHAR(500) NULL,
    kostnad_ore   INT NOT NULL DEFAULT 0,
    behandlet_av  BIGINT UNSIGNED NULL COMMENT 'members.id; NULL = jobben',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (kommentar_id),
    KEY ix_meta_kommentarer_status (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
